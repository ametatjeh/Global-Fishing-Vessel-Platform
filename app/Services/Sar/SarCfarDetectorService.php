<?php

namespace App\Services\Sar;

class SarCfarDetectorService
{
    /**
     * Cell-Averaging Constant False Alarm Rate (CA-CFAR) Detector
     *
     * @param array $intensityMatrix 2D array of radar backscatter intensity values (calibrated linear sigma0)
     * @param int $guardCells Number of guard cells surrounding the Cell Under Test (CUT)
     * @param int $trainingCells Number of reference training cells used to estimate sea-clutter background
     * @param float $pfa Probability of False Alarm (standard default: 1e-5)
     * @param float $pixelResolutionMeters Ground pixel resolution in meters (e.g. 10m for Sentinel-1 GRD IW, 40m for EW)
     * @return array Detected candidate target objects
     */
    public function detectCandidates(
        array $intensityMatrix,
        int $guardCells = 5,
        int $trainingCells = 20,
        float $pfa = 1e-5,
        float $pixelResolutionMeters = 10.0
    ): array {
        $rows = count($intensityMatrix);
        if ($rows === 0) return [];
        $cols = count($intensityMatrix[0]);
        if ($cols === 0) return [];

        $halfWindow = $guardCells + $trainingCells;
        if ($rows <= $halfWindow * 2 || $cols <= $halfWindow * 2) {
            return [];
        }

        // Total training cells in 2D sliding ring
        $totalWindowCells = (2 * $halfWindow + 1) * (2 * $halfWindow + 1);
        $guardWindowCells = (2 * $guardCells + 1) * (2 * $guardCells + 1);
        $nTraining = $totalWindowCells - $guardWindowCells;

        // CFAR scale factor alpha for exponential sea-clutter distribution (Rayleigh intensity):
        // alpha = N * (Pfa^(-1/N) - 1)
        $alpha = $nTraining * (pow($pfa, -1.0 / $nTraining) - 1.0);

        $detections = [];

        for ($r = $halfWindow; $r < $rows - $halfWindow; $r++) {
            for ($c = $halfWindow; $c < $cols - $halfWindow; $c++) {
                $cut = $intensityMatrix[$r][$c];

                // Calculate sea clutter background average in training ring
                $sumTraining = 0.0;
                for ($wr = $r - $halfWindow; $wr <= $r + $halfWindow; $wr++) {
                    for ($wc = $c - $halfWindow; $wc <= $c + $halfWindow; $wc++) {
                        // Skip cell if within guard window
                        if (abs($wr - $r) <= $guardCells && abs($wc - $c) <= $guardCells) {
                            continue;
                        }
                        $sumTraining += $intensityMatrix[$wr][$wc];
                    }
                }

                $meanClutter = $sumTraining / $nTraining;
                $threshold = $alpha * $meanClutter;

                if ($cut > $threshold && $meanClutter > 0) {
                    $targetToClutterDb = 10.0 * log10($cut / $meanClutter);
                    $confidence = min(0.99, max(0.50, 0.50 + ($targetToClutterDb / 40.0)));

                    $detections[] = [
                        'pixel_row' => $r,
                        'pixel_col' => $c,
                        'cut_intensity' => $cut,
                        'clutter_mean' => $meanClutter,
                        'threshold' => $threshold,
                        'target_to_clutter_db' => round($targetToClutterDb, 2),
                        'confidence_score' => round($confidence, 2),
                    ];
                }
            }
        }

        // Cluster contiguous detections into candidate objects
        return $this->clusterDetections($detections, $pixelResolutionMeters);
    }

    /**
     * Cluster adjacent detected pixels into distinct candidate vessel objects
     */
    protected function clusterDetections(array $pixels, float $pixelResolution): array
    {
        if (empty($pixels)) return [];

        $clusters = [];
        $visited = [];

        foreach ($pixels as $i => $p) {
            if (isset($visited[$i])) continue;

            $clusterPixels = [$p];
            $visited[$i] = true;

            for ($j = $i + 1; $j < count($pixels); $j++) {
                if (isset($visited[$j])) continue;

                $pj = $pixels[$j];
                // Adjacent if Euclidean distance <= 3 pixels
                $dr = $p['pixel_row'] - $pj['pixel_row'];
                $dc = $p['pixel_col'] - $pj['pixel_col'];
                if (sqrt($dr * $dr + $dc * $dc) <= 3.0) {
                    $clusterPixels[] = $pj;
                    $visited[$j] = true;
                }
            }

            // Estimate object physical dimensions
            $minR = min(array_column($clusterPixels, 'pixel_row'));
            $maxR = max(array_column($clusterPixels, 'pixel_row'));
            $minC = min(array_column($clusterPixels, 'pixel_col'));
            $maxC = max(array_column($clusterPixels, 'pixel_col'));

            $pixelSpan = sqrt(pow($maxR - $minR + 1, 2) + pow($maxC - $minC + 1, 2));
            $lengthMeters = round($pixelSpan * $pixelResolution, 1);
            $widthMeters = round(max(1, count($clusterPixels) / max(1, $pixelSpan)) * $pixelResolution, 1);

            // Filter out unrealistic vessel sizes (e.g. single noisy speckle < 10m or huge cloud artifacts > 450m)
            if ($lengthMeters < 10.0 || $lengthMeters > 450.0) {
                continue;
            }

            $maxConfidence = max(array_column($clusterPixels, 'confidence_score'));
            $avgTcr = array_sum(array_column($clusterPixels, 'target_to_clutter_db')) / count($clusterPixels);

            $clusters[] = [
                'centroid_row' => ($minR + $maxR) / 2.0,
                'centroid_col' => ($minC + $maxC) / 2.0,
                'pixel_count' => count($clusterPixels),
                'estimated_length_meters' => $lengthMeters,
                'estimated_width_meters' => $widthMeters,
                'target_to_clutter_db' => round($avgTcr, 2),
                'confidence_score' => $maxConfidence,
            ];
        }

        return $clusters;
    }
}
