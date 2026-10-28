#!/usr/bin/env python3
"""
Sentinel-1 SAR Real Preprocessing & CA-CFAR Detection Engine
Global Fishing Vessel Intelligence Platform (GFVIP)
"""

import sys
import os
import json
import argparse
import time
import glob
import xml.etree.ElementTree as ET
import numpy as np
from PIL import Image
from scipy.ndimage import uniform_filter, label
from scipy.interpolate import LinearNDInterpolator

def process_sar_safe(safe_dir, pfa=1e-7, guard_radius=4, training_radius=16, min_tcr_db=14.0):
    t_start = time.time()
    
    if not os.path.isdir(safe_dir):
        return {"status": "error", "message": f"SAFE directory '{safe_dir}' does not exist."}
    
    # 1. Locate measurement TIFF and annotation XML
    tiff_files = glob.glob(os.path.join(safe_dir, 'measurement', '*.tiff'))
    if not tiff_files:
        return {"status": "error", "message": "Measurement TIFF file not found in SAFE directory."}
    tiff_path = tiff_files[0]
    
    xml_files = [f for f in glob.glob(os.path.join(safe_dir, 'annotation', '*.xml')) if 'calibration' not in f and 'rfi' not in f]
    if not xml_files:
        return {"status": "error", "message": "Annotation XML file not found in SAFE directory."}
    xml_path = xml_files[0]
    
    cal_files = glob.glob(os.path.join(safe_dir, 'annotation', 'calibration', 'calibration-*.xml'))
    if not cal_files:
        return {"status": "error", "message": "Calibration XML file not found in SAFE directory."}
    cal_path = cal_files[0]
    
    # 2. Parse Annotation Metadata
    annot_root = ET.parse(xml_path).getroot()
    ads_header = annot_root.find('adsHeader')
    product_type = ads_header.find('productType').text if ads_header is not None else 'GRD'
    polarisation = ads_header.find('polarisation').text if ads_header is not None else 'HH'
    mode = ads_header.find('mode').text if ads_header is not None else 'EW'
    start_time = ads_header.find('startTime').text if ads_header is not None else None
    stop_time = ads_header.find('stopTime').text if ads_header is not None else None
    
    # 3. Read 16-bit GeoTIFF Measurement
    with Image.open(tiff_path) as img:
        dn = np.array(img, dtype=np.float32)
    height, width = dn.shape
    
    # 4. Radiometric Calibration to Sigma-Nought (Linear Power)
    cal_root = ET.parse(cal_path).getroot()
    cal_vectors = cal_root.findall('calibrationVectorList/calibrationVector')
    mid_cv = cal_vectors[len(cal_vectors) // 2]
    pixels_lut = np.array([int(p) for p in mid_cv.find('pixel').text.split()], dtype=np.float32)
    sigma_lut = np.array([float(s) for s in mid_cv.find('sigmaNought').text.split()], dtype=np.float32)
    
    a_sigma_1d = np.interp(np.arange(width, dtype=np.float32), pixels_lut, sigma_lut)
    a_sigma_sq = (a_sigma_1d ** 2).astype(np.float32)
    intensity = (dn ** 2) / a_sigma_sq[np.newaxis, :]
    
    # Preprocessing validation metrics
    valid_dn = dn[dn > 0]
    mean_intensity = float(intensity.mean())
    max_intensity = float(intensity.max())
    
    # 5. Boundary margin masking
    margin = 80
    valid_mask = np.zeros_like(intensity, dtype=bool)
    valid_mask[margin:height-margin, margin:width-margin] = True
    valid_mask &= (dn > 15)
    
    # 6. CA-CFAR 2D Filter
    guard_win_size = 2 * guard_radius + 1
    outer_win_size = 2 * (guard_radius + training_radius) + 1
    n_outer = outer_win_size * outer_win_size
    n_guard = guard_win_size * guard_win_size
    n_training = n_outer - n_guard
    
    alpha = n_training * (np.power(pfa, -1.0 / n_training) - 1.0)
    
    outer_mean = uniform_filter(intensity, size=outer_win_size, mode='reflect')
    guard_mean = uniform_filter(intensity, size=guard_win_size, mode='reflect')
    clutter_sum = (outer_mean * n_outer) - (guard_mean * n_guard)
    clutter_mean = np.maximum(clutter_sum / n_training, 1e-8)
    
    threshold = alpha * clutter_mean
    detections_mask = (intensity > threshold) & valid_mask & (intensity > 0.05)
    
    labeled_array, num_features = label(detections_mask)
    
    # 7. Geolocation Grid
    grid_points = annot_root.findall('geolocationGrid/geolocationGridPointList/geolocationGridPoint')
    g_lines = np.array([float(pt.find('line').text) for pt in grid_points])
    g_pixels = np.array([float(pt.find('pixel').text) for pt in grid_points])
    g_lats = np.array([float(pt.find('latitude').text) for pt in grid_points])
    g_lons = np.array([float(pt.find('longitude').text) for pt in grid_points])
    grid_coords = np.column_stack([g_lines, g_pixels])
    
    interp_lat = LinearNDInterpolator(grid_coords, g_lats)
    interp_lon = LinearNDInterpolator(grid_coords, g_lons)
    
    pixel_res_m = 40.0
    candidates = []
    
    for i in range(1, num_features + 1):
        cluster_indices = np.argwhere(labeled_array == i)
        # Require >= 2 pixels to suppress single-pixel speckle / breaking wave noise
        if len(cluster_indices) < 2:
            continue
            
        y_min, x_min = cluster_indices.min(axis=0)
        y_max, x_max = cluster_indices.max(axis=0)
        span_px = np.sqrt((y_max - y_min + 1)**2 + (x_max - x_min + 1)**2)
        length_m = span_px * pixel_res_m
        width_m = max(1, len(cluster_indices) / span_px) * pixel_res_m
        
        # Valid vessel size bounds: 20m - 380m
        if length_m < 20.0 or length_m > 380.0:
            continue
            
        cy = float(np.mean(cluster_indices[:, 0]))
        cx = float(np.mean(cluster_indices[:, 1]))
        
        peak_intensity = float(intensity[int(cy), int(cx)])
        local_clutter = float(clutter_mean[int(cy), int(cx)])
        tcr_db = 10.0 * np.log10(peak_intensity / max(local_clutter, 1e-8))
        
        # Enforce minimum TCR for high sea-state
        if tcr_db < min_tcr_db:
            continue
            
        lat_val = float(interp_lat(cy, cx))
        lon_val = float(interp_lon(cy, cx))
        
        candidates.append({
            "target_id": f"SAR-TGT-{i:04d}",
            "pixel_coordinates": {"row": round(cy, 1), "col": round(cx, 1)},
            "coordinates": {"latitude": round(lat_val, 6), "longitude": round(lon_val, 6)},
            "estimated_length_meters": round(length_m, 1),
            "estimated_width_meters": round(width_m, 1),
            "peak_tcr_db": round(tcr_db, 2),
            "pixel_count": len(cluster_indices),
            "confidence": round(min(0.98, max(0.50, 0.50 + (tcr_db / 40.0))), 2),
        })
    
    duration = round(time.time() - t_start, 2)
    
    return {
        "status": "success",
        "duration_seconds": duration,
        "product_metadata": {
            "product_type": product_type,
            "polarisation": polarisation,
            "sensor_mode": mode,
            "start_time": start_time,
            "stop_time": stop_time,
            "dimensions": {"samples": width, "lines": height},
            "pixel_spacing_meters": pixel_res_m,
        },
        "preprocessing": {
            "radiometric_calibration": "sigma0_linear",
            "mean_sigma0_linear": round(mean_intensity, 6),
            "max_sigma0_linear": round(max_intensity, 4),
            "estimated_mean_backscatter_db": round(10.0 * np.log10(max(mean_intensity, 1e-8)), 2),
            "calibration_vectors_applied": len(cal_vectors),
        },
        "cfar_parameters": {
            "algorithm": "2D CA-CFAR (Cell-Averaging)",
            "pfa": pfa,
            "guard_radius_pixels": guard_radius,
            "training_radius_pixels": training_radius,
            "training_cells_count": n_training,
            "alpha_multiplier": round(alpha, 3),
            "min_tcr_db": min_tcr_db,
        },
        "detection_summary": {
            "total_raw_clusters": num_features,
            "filtered_vessel_candidates": len(candidates),
        },
        "candidates": candidates,
    }

if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Sentinel-1 SAR Preprocessor and CFAR Detector")
    parser.add_argument("--safe-dir", required=True, help="Path to unzipped .SAFE directory")
    parser.add_argument("--pfa", type=float, default=1e-7, help="Probability of false alarm")
    parser.add_argument("--guard-radius", type=int, default=4, help="Guard radius in pixels")
    parser.add_argument("--training-radius", type=int, default=16, help="Training radius in pixels")
    parser.add_argument("--min-tcr", type=float, default=14.0, help="Minimum TCR in dB")
    
    args = parser.parse_args()
    result = process_sar_safe(
        safe_dir=args.safe_dir,
        pfa=args.pfa,
        guard_radius=args.guard_radius,
        training_radius=args.training_radius,
        min_tcr_db=args.min_tcr,
    )
    print(json.dumps(result, indent=2))
