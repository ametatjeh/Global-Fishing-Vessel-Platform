/**
 * Global Vessel Map Controller using MapLibre GL JS
 * Global Fishing Vessel Intelligence Platform (GFVIP)
 * ESRI World Dark Gray Canvas Basemap & Live Maritime Intelligence
 */
import * as maplibregl from 'maplibre-gl';
import maplibreWorkerUrl from 'maplibre-gl/dist/maplibre-gl-worker.mjs?url';
import 'maplibre-gl/dist/maplibre-gl.css';

// Ensure MapLibre Web Worker is loaded correctly via Vite asset pipeline
if (maplibregl.config) {
    maplibregl.config.WORKER_URL = maplibreWorkerUrl;
}
if (typeof window !== 'undefined') {
    window.maplibregl = maplibregl;
}

export class GlobalMapController {
    constructor(containerId, options = {}) {
        this.containerId = containerId;
        this.options = options;
        this.map = null;
        this.activeLayers = Object.assign({
            fao: true,
            eez: true,
            encounters: true,
            vessels: true,
            darkVessels: true,
            sarScenes: true,
        }, options.layers || {});

        this.loadingState = {
            fao: false,
            eez: false,
            encounters: false,
            vessels: false,
            darkVessels: false,
            sarScenes: false,
        };

        this.debounceTimer = null;
        this.currentPopup = null;
        this.basemapErrorNotified = false;

        this.init();
    }

    init() {
        const container = document.getElementById(this.containerId);
        if (!container) {
            console.warn(`[GlobalMapController] Map container #${this.containerId} not found in DOM.`);
            return;
        }

        // Ensure container has minimum height if zero
        if (container.clientHeight === 0) {
            container.style.minHeight = '480px';
            container.style.height = '100%';
        }

        this.createMap();
        this.initializeControls();
        this.setupErrorHandling();
        this.setupLifecycleHooks();
    }

    createMap() {
        const style = this.getBasemapStyle();

        this.map = new maplibregl.Map({
            container: this.containerId,
            style: style,
            center: this.options.center || [100.0, 5.0],
            zoom: this.options.zoom || 3.5,
            attributionControl: false // Handled cleanly via custom or bottom attribution
        });
    }

    getBasemapStyle() {
        // ESRI World Dark Gray Canvas (Watermark-Free, Production Stable, No API Key Required)
        return {
            version: 8,
            sources: {
                'esri-dark-base': {
                    type: 'raster',
                    tiles: [
                        'https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Dark_Gray_Base/MapServer/tile/{z}/{y}/{x}'
                    ],
                    tileSize: 256,
                    attribution: 'Tiles &copy; Esri &mdash; Esri, DeLorme, NAVTEQ, USGS, FAO, NOAA'
                },
                'esri-dark-reference': {
                    type: 'raster',
                    tiles: [
                        'https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Dark_Gray_Reference/MapServer/tile/{z}/{y}/{x}'
                    ],
                    tileSize: 256
                }
            },
            layers: [
                {
                    id: 'esri-dark-base-layer',
                    type: 'raster',
                    source: 'esri-dark-base',
                    minzoom: 0,
                    maxzoom: 19
                },
                {
                    id: 'esri-dark-reference-layer',
                    type: 'raster',
                    source: 'esri-dark-reference',
                    minzoom: 0,
                    maxzoom: 19
                }
            ]
        };
    }

    initializeControls() {
        // Navigation (Zoom, Compass, Pitch)
        this.map.addControl(new maplibregl.NavigationControl({
            showCompass: true,
            showZoom: true,
            visualizePitch: true
        }), 'top-right');

        // Fullscreen Control
        this.map.addControl(new maplibregl.FullscreenControl(), 'top-right');

        // Nautical Distance Scale Control
        this.map.addControl(new maplibregl.ScaleControl({
            maxWidth: 160,
            unit: 'nautical'
        }), 'bottom-left');

        // Compact Attribution Control
        this.map.addControl(new maplibregl.AttributionControl({
            compact: true,
            customAttribution: 'GFVIP &bull; ESRI Canvas &bull; Sentinel-1 C-SAR'
        }), 'bottom-right');
    }

    setupErrorHandling() {
        this.map.on('error', (e) => {
            const err = e.error || e;
            const msg = err?.message || String(err);
            console.warn('[MapLibre Event Warning]:', msg);

            // Check if basemap tile loading failed
            if (msg.includes('tile') || msg.includes('404') || msg.includes('Failed to fetch')) {
                this.handleBasemapDegradation();
            }
        });
    }

    handleBasemapDegradation() {
        if (this.basemapErrorNotified) return;
        this.basemapErrorNotified = true;

        const container = document.getElementById(this.containerId);
        if (!container) return;

        // Ensure container background stays dark maritime slate so vector data is still visible
        container.style.backgroundColor = '#0b1120';

        const existingBanner = document.getElementById(`basemap-fallback-${this.containerId}`);
        if (!existingBanner) {
            const banner = document.createElement('div');
            banner.id = `basemap-fallback-${this.containerId}`;
            banner.className = 'absolute top-2 left-1/2 -translate-x-1/2 z-20 px-3 py-1.5 rounded-xl bg-amber-950/80 border border-amber-600/60 text-amber-200 text-[11px] font-mono shadow-xl backdrop-blur flex items-center gap-2 pointer-events-auto';
            banner.innerHTML = `
                <span>⚠️</span>
                <span>Basemap unavailable. Operational data layers remain available.</span>
                <button type="button" onclick="this.parentElement.remove()" class="text-amber-400 hover:text-white font-bold ml-2">&times;</button>
            `;
            container.style.position = 'relative';
            container.appendChild(banner);
        }
    }

    setupLifecycleHooks() {
        this.map.on('load', () => {
            this.resizeMap();
            this.setupSourcesAndLayers();
            this.attachEventListeners();
            this.refreshAllActiveLayers();
        });

        // Window resize observer
        window.addEventListener('resize', () => {
            this.resizeMap();
        });

        // Periodic resize sync for smooth container transitions
        setTimeout(() => this.resizeMap(), 300);
        setTimeout(() => this.resizeMap(), 1000);

        this.map.on('moveend', () => {
            this.handleViewportChange();
        });
    }

    resizeMap() {
        if (this.map) {
            this.map.resize();
        }
    }

    setupSourcesAndLayers() {
        this.addFaoLayer();
        this.addEezLayer();
        this.addGfwLayer();
        this.addSarSceneLayer();
        this.addSarDetectionLayer();
        this.addAisLayers();
    }

    addFaoLayer() {
        if (this.map.getSource('fao-source')) return;

        this.map.addSource('fao-source', {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: [] }
        });

        this.map.addLayer({
            id: 'fao-fill-layer',
            type: 'fill',
            source: 'fao-source',
            layout: { visibility: this.activeLayers.fao ? 'visible' : 'none' },
            paint: {
                'fill-color': '#6366f1',
                'fill-opacity': 0.08
            }
        });

        this.map.addLayer({
            id: 'fao-line-layer',
            type: 'line',
            source: 'fao-source',
            layout: { visibility: this.activeLayers.fao ? 'visible' : 'none' },
            paint: {
                'line-color': '#818cf8',
                'line-width': 1.5,
                'line-dasharray': [3, 2]
            }
        });
    }

    addEezLayer() {
        if (this.map.getSource('eez-source')) return;

        this.map.addSource('eez-source', {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: [] }
        });

        this.map.addLayer({
            id: 'eez-fill-layer',
            type: 'fill',
            source: 'eez-source',
            layout: { visibility: this.activeLayers.eez ? 'visible' : 'none' },
            paint: {
                'fill-color': '#0d9488',
                'fill-opacity': 0.10
            }
        });

        this.map.addLayer({
            id: 'eez-line-layer',
            type: 'line',
            source: 'eez-source',
            layout: { visibility: this.activeLayers.eez ? 'visible' : 'none' },
            paint: {
                'line-color': '#2dd4bf',
                'line-width': 1.8
            }
        });
    }

    addGfwLayer() {
        if (this.map.getSource('encounters-source')) return;

        this.map.addSource('encounters-source', {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: [] }
        });

        this.map.addLayer({
            id: 'encounters-circle-layer',
            type: 'circle',
            source: 'encounters-source',
            layout: { visibility: this.activeLayers.encounters ? 'visible' : 'none' },
            paint: {
                'circle-radius': 7,
                'circle-color': [
                    'match',
                    ['get', 'category'],
                    'fishing_carrier', '#f59e0b',
                    'fishing_bunker', '#ec4899',
                    'fishing_support', '#06b6d4',
                    '#3b82f6'
                ],
                'circle-stroke-width': 2,
                'circle-stroke-color': '#ffffff'
            }
        });
    }

    addAisLayers() {
        if (this.map.getSource('vessels-source')) return;

        this.map.addSource('vessels-source', {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: [] }
        });

        this.map.addLayer({
            id: 'vessels-circle-layer',
            type: 'circle',
            source: 'vessels-source',
            layout: { visibility: this.activeLayers.vessels ? 'visible' : 'none' },
            paint: {
                'circle-radius': [
                    'case',
                    ['boolean', ['get', 'is_fresh'], false],
                    6.0,
                    4.5
                ],
                'circle-color': [
                    'case',
                    ['boolean', ['get', 'is_fresh'], false],
                    '#38bdf8', // Live (< 2h) - Bright sky cyan
                    '#64748b'  // Stale (> 2h) - Muted slate
                ],
                'circle-stroke-width': 1.5,
                'circle-stroke-color': [
                    'case',
                    ['boolean', ['get', 'is_fresh'], false],
                    '#0284c7',
                    '#334155'
                ]
            }
        });
    }

    addSarSceneLayer() {
        if (this.map.getSource('sar-scenes-source')) return;

        this.map.addSource('sar-scenes-source', {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: [] }
        });

        this.map.addLayer({
            id: 'sar-scenes-fill-layer',
            type: 'fill',
            source: 'sar-scenes-source',
            layout: { visibility: this.activeLayers.sarScenes ? 'visible' : 'none' },
            paint: {
                'fill-color': '#a855f7',
                'fill-opacity': 0.12
            }
        });

        this.map.addLayer({
            id: 'sar-scenes-line-layer',
            type: 'line',
            source: 'sar-scenes-source',
            layout: { visibility: this.activeLayers.sarScenes ? 'visible' : 'none' },
            paint: {
                'line-color': '#c084fc',
                'line-width': 2.0,
                'line-dasharray': [4, 2]
            }
        });
    }

    addSarDetectionLayer() {
        if (this.map.getSource('dark-vessels-source')) return;

        this.map.addSource('dark-vessels-source', {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: [] }
        });

        /**
         * SAR Candidates Scientific Color Hierarchy:
         * matched                  → green (#10b981)
         * possible vessel          → amber/orange (#f59e0b)
         * possible ice / clutter   → cyan (#06b6d4)
         * inconclusive             → gray (#94a3b8)
         * pending review           → yellow (#eab308)
         * other                    → purple (#a855f7)
         */
        this.map.addLayer({
            id: 'dark-vessels-circle-layer',
            type: 'circle',
            source: 'dark-vessels-source',
            layout: { visibility: this.activeLayers.darkVessels ? 'visible' : 'none' },
            paint: {
                'circle-radius': 7.5,
                'circle-color': [
                    'case',
                    ['==', ['get', 'correlation_status'], 'matched'], '#10b981',
                    ['==', ['get', 'review_status'], 'possible_vessel'], '#f59e0b',
                    ['==', ['get', 'review_status'], 'possible_ice_or_clutter'], '#06b6d4',
                    ['==', ['get', 'review_status'], 'inconclusive'], '#94a3b8',
                    ['==', ['get', 'review_status'], 'reviewed_other'], '#a855f7',
                    '#eab308' // default: pending review / yellow
                ],
                'circle-stroke-width': 2.0,
                'circle-stroke-color': '#ffffff'
            }
        });
    }

    attachEventListeners() {
        // Sentinel-1 SAR Scene Click Popup
        this.map.on('click', 'sar-scenes-fill-layer', (e) => {
            if (!e.features.length) return;
            const props = e.features[0].properties;
            const dateStr = props.acquisition_time || props.start_time || 'N/A';
            const html = `
                <div class="space-y-2 text-xs font-sans">
                    <div class="flex items-center gap-1.5 font-bold text-purple-300 border-b border-purple-900 pb-1">
                        <span>🛰️</span>
                        <span>Sentinel-1 SAR Footprint</span>
                    </div>
                    <div class="text-slate-200 font-semibold font-mono text-[11px] truncate">${props.scene_id || props.id}</div>
                    <div class="grid grid-cols-2 gap-2 text-[11px] pt-1 font-mono">
                        <div><span class="text-slate-400">Platform:</span> <span class="text-white">${props.platform_name || 'Sentinel-1'}</span></div>
                        <div><span class="text-slate-400">Sensor:</span> <span class="text-purple-300">${props.sensor_mode || 'EW'}</span></div>
                        <div><span class="text-slate-400">Polarisasi:</span> <span class="text-white">${props.polarization || 'HH'}</span></div>
                        <div><span class="text-slate-400">Waktu:</span> <span class="text-slate-300">${dateStr}</span></div>
                    </div>
                    <div class="text-[10px] text-slate-400 pt-1 border-t border-slate-800">
                        Status: <strong class="text-purple-300 uppercase">${props.processing_status || 'PROCESSED'}</strong>
                    </div>
                </div>
            `;
            this.showPopup(e.lngLat, html);
        });

        // Live AIS Vessels Click Popup
        this.map.on('click', 'vessels-circle-layer', (e) => {
            if (!e.features.length) return;
            const props = e.features[0].properties;
            const isFresh = props.is_fresh === true || props.is_fresh === 'true';
            const freshnessBadge = isFresh
                ? `<span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/40">● LIVE (&lt; 2h)</span>`
                : `<span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-slate-800 text-amber-300 border border-amber-500/30">⏳ KEDALUWARSA</span>`;

            const dateStr = props.observed_at ? new Date(props.observed_at).toUTCString() : 'N/A';
            const coordsStr = e.lngLat ? `${e.lngLat.lat.toFixed(4)}° S, ${e.lngLat.lng.toFixed(4)}° E` : 'N/A';

            const flagEmoji = props.flag_emoji || '🌐';
            const flagCountry = props.flag_country || 'Flag state unknown';
            const flagStateCode = props.flag_state || 'UN';
            const flagStatus = props.flag_status || 'unknown';
            const flagStatusBadge = flagStatus === 'verified_explicit'
                ? `<span class="px-1 py-0.2 rounded text-[9px] bg-emerald-950 text-emerald-300 border border-emerald-500/40 font-bold">Verified</span>`
                : (flagStatus === 'mid_administrative_indicator'
                    ? `<span class="px-1 py-0.2 rounded text-[9px] bg-slate-800 text-amber-300 border border-amber-500/40 font-bold" title="MMSI MID ITU, bukan bukti definitif flag state">MID Clue</span>`
                    : (flagStatus === 'conflict_detected'
                        ? `<span class="px-1 py-0.2 rounded text-[9px] bg-rose-950 text-rose-300 border border-rose-500/40 font-bold">Conflict</span>`
                        : `<span class="px-1 py-0.2 rounded text-[9px] bg-slate-800 text-slate-400">Unknown</span>`));

            const html = `
                <div class="space-y-2 text-xs font-sans">
                    <div class="flex items-center justify-between border-b border-sky-900 pb-1">
                        <span class="font-bold text-sky-300 flex items-center gap-1.5">
                            <span class="text-sm">${flagEmoji}</span>
                            <span>${props.name || 'AIS Vessel'}</span>
                        </span>
                        <div class="flex items-center gap-1">
                            ${freshnessBadge}
                            <span class="px-1.5 py-0.5 rounded text-[10px] font-mono bg-sky-500/20 text-sky-300 border border-sky-500/30 flex items-center gap-1">
                                <span>${flagEmoji}</span>
                                <span>${flagStateCode}</span>
                            </span>
                        </div>
                    </div>
                    <div class="p-1.5 rounded-lg bg-slate-900/90 border border-slate-800 space-y-1 text-[11px] font-mono">
                        <div class="flex items-center justify-between">
                            <span class="text-slate-400">Flag State:</span>
                            <div class="flex items-center gap-1.5">
                                <span class="text-white font-semibold flex items-center gap-1">
                                    <span>${flagEmoji}</span>
                                    <span>${flagCountry}</span>
                                    <span class="text-[10px] text-teal-300">(${flagStateCode})</span>
                                </span>
                                ${flagStatusBadge}
                            </div>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-2 text-[11px] font-mono">
                        <div><span class="text-slate-400">MMSI:</span> <span class="font-bold text-white">${props.mmsi || '-'}</span></div>
                        <div><span class="text-slate-400">IMO:</span> <span class="font-bold text-white">${props.imo || '-'}</span></div>
                        <div><span class="text-slate-400">Callsign:</span> <span class="font-bold text-white">${props.callsign || '-'}</span></div>
                        <div><span class="text-slate-400">Tipe:</span> <span class="font-bold text-slate-300 capitalize">${props.vessel_type || 'vessel'}</span></div>
                        <div><span class="text-slate-400">SOG (Kecepatan):</span> <span class="text-sky-300 font-bold">${props.speed_knots || 0} kn</span></div>
                        <div><span class="text-slate-400">COG (Haluan):</span> <span class="text-sky-300 font-bold">${props.course_degrees || 0}&deg;</span></div>
                    </div>
                    <div class="text-[10px] font-mono text-slate-400">
                        Posisi: <span class="text-slate-200">${coordsStr}</span>
                    </div>
                    <div class="text-[10px] text-slate-400 pt-1 border-t border-slate-800 flex justify-between">
                        <span>Waktu: ${dateStr}</span>
                        <span>${props.provider || 'AIS Stream'}</span>
                    </div>
                    ${props.vessel_id ? `
                    <div class="pt-1 text-right">
                        <a href="/vessels/${props.vessel_id}" class="text-[11px] text-sky-400 hover:text-sky-300 font-semibold underline">Profil & Track Kapal &rarr;</a>
                    </div>` : ''}
                </div>
            `;
            this.showPopup(e.lngLat, html);
        });

        // SAR Candidate / Dark Vessel Click Popup
        this.map.on('click', 'dark-vessels-circle-layer', (e) => {
            if (!e.features.length) return;
            const props = e.features[0].properties;
            const lat = props.latitude ?? e.lngLat.lat;
            const lng = props.longitude ?? e.lngLat.lng;
            const isInsufficient = props.correlation_status === 'insufficient_data';
            const isMatched = props.correlation_status === 'matched';

            const statusBadge = isMatched
                ? `<span class="px-1.5 py-0.5 rounded text-[10px] font-mono font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/40">MATCHED AIS</span>`
                : (isInsufficient
                    ? `<span class="px-1.5 py-0.5 rounded text-[10px] font-mono font-bold bg-amber-500/20 text-amber-300 border border-amber-500/40">INSUFFICIENT AIS COVERAGE</span>`
                    : `<span class="px-1.5 py-0.5 rounded text-[10px] font-mono font-bold bg-rose-500/20 text-rose-300 border border-rose-500/40">SAR CANDIDATE</span>`);

            const reviewBadge = props.review_status_label || props.review_status || 'Pending Review';

            const html = `
                <div class="space-y-2 text-xs font-sans max-w-xs">
                    <div class="flex items-center justify-between border-b border-amber-900 pb-1">
                        <span class="font-bold text-amber-300 flex items-center gap-1">
                            <span>🎯</span> SAR Candidate
                        </span>
                        <div>${statusBadge}</div>
                    </div>
                    <div class="text-[11px] font-mono text-slate-200">
                        Target ID: <strong>${props.identifier || props.id}</strong>
                    </div>
                    <div class="grid grid-cols-2 gap-2 text-[11px] font-mono text-slate-300">
                        <div>Koordinat: <span class="text-teal-300">${Number(lat).toFixed(4)}°, ${Number(lng).toFixed(4)}°</span></div>
                        <div>Peak TCR: <span class="text-emerald-300">${props.tcr_db !== null && props.tcr_db !== undefined ? props.tcr_db + ' dB' : '-'}</span></div>
                        <div>Radar Dimensi: <span class="text-white">${props.estimated_length_meters || '-'}m &times; ${props.estimated_width_meters || '-'}m</span></div>
                        <div>Review Status: <span class="text-amber-300">${reviewBadge}</span></div>
                    </div>
                    ${props.scene_id ? `<div class="text-[10px] font-mono text-slate-400 truncate">Scene: ${props.scene_id}</div>` : ''}
                    <div class="text-[10px] font-mono text-slate-400">Waktu Akuisisi: ${props.detected_at ? props.detected_at.replace('T', ' ').substring(0, 19) : '-'} UTC</div>
                    
                    ${isInsufficient ? `
                    <div class="text-[10px] text-amber-300/90 bg-amber-950/60 p-2 rounded border border-amber-800/40 leading-snug">
                        <strong>INSUFFICIENT AIS COVERAGE:</strong> Ketiadaan data AIS pada zona ini tidak membuktikan bahwa objek adalah dark vessel terkonfirmasi.
                    </div>` : ''}

                    <div class="text-[9px] text-slate-400 italic">
                        SAR Detection &ne; Vessel Confirmation &bull; AIS Absence &ne; Dark Vessel Confirmation.
                    </div>

                    <div class="pt-1 text-right">
                        <a href="/dark-vessels/${props.id}" class="text-[11px] text-teal-400 hover:text-teal-300 font-bold underline">Buka Evidence Inspector &rarr;</a>
                    </div>
                </div>
            `;
            this.showPopup(e.lngLat, html);
        });

        // FAO Areas Click Popup
        this.map.on('click', 'fao-fill-layer', (e) => {
            if (!e.features.length) return;
            const props = e.features[0].properties;
            const html = `
                <div class="space-y-2 text-xs font-sans">
                    <div class="flex items-center gap-1.5 font-bold text-indigo-300 border-b border-indigo-900 pb-1">
                        <span>🌐</span>
                        <span>FAO Statistical Area ${props.area_code || ''}</span>
                    </div>
                    <div class="text-slate-200 font-semibold">${props.name_en || 'Fishing Area'}</div>
                    <div class="text-[10px] text-indigo-300/80 bg-indigo-950/60 p-2 rounded border border-indigo-800/40">
                        <strong>Perhatian:</strong> Poligon FAO adalah batas statistik pelaporan perikanan global, bukan batas larangan tangkap.
                    </div>
                </div>
            `;
            this.showPopup(e.lngLat, html);
        });

        // EEZ Boundaries Click Popup
        this.map.on('click', 'eez-fill-layer', (e) => {
            if (!e.features.length) return;
            const props = e.features[0].properties;
            const html = `
                <div class="space-y-2 text-xs font-sans">
                    <div class="flex items-center gap-1.5 font-bold text-teal-300 border-b border-teal-900 pb-1">
                        <span>🛡️</span>
                        <span>Exclusive Economic Zone (200NM)</span>
                    </div>
                    <div class="text-slate-200 font-semibold">${props.geoname || 'EEZ Zone'}</div>
                    <div class="text-[11px] text-teal-200 font-medium">Sovereign: ${props.sovereign_state || '-'}</div>
                    <div class="text-[10px] text-teal-300/80 bg-teal-950/60 p-2 rounded border border-teal-800/40">
                        <strong>Status Hukum:</strong> Batas yurisdiksi hak ekonomi maritim UNCLOS. Berada di EEZ bukan bukti otomatis pelanggaran tanpa verifikasi izin.
                    </div>
                </div>
            `;
            this.showPopup(e.lngLat, html);
        });

        // Encounters Click Popup
        this.map.on('click', 'encounters-circle-layer', (e) => {
            if (!e.features.length) return;
            const props = e.features[0].properties;
            const html = `
                <div class="space-y-2 text-xs font-sans">
                    <div class="flex items-center justify-between border-b border-amber-900 pb-1">
                        <span class="font-bold text-amber-300 flex items-center gap-1">
                            <span>🤝</span> ${props.code || 'Encounter'}
                        </span>
                        <span class="px-1.5 py-0.5 rounded text-[10px] uppercase font-mono font-bold bg-amber-500/20 text-amber-300 border border-amber-500/40">
                            ${props.status || 'detected'}
                        </span>
                    </div>
                    <div class="text-[10px] text-slate-300">Jarak Min: <strong class="text-white">${props.min_distance_meters || 0} m</strong> &bull; Durasi: <strong class="text-white">${props.duration_minutes || 0} mnt</strong></div>
                    <div class="pt-1 text-right">
                        <a href="/encounters/${props.id}" class="text-[11px] text-cyan-400 hover:text-cyan-300 font-semibold underline">Detail Encounter &rarr;</a>
                    </div>
                </div>
            `;
            this.showPopup(e.lngLat, html);
        });

        // Cursor pointer styling on hover
        const clickableLayers = [
            'fao-fill-layer',
            'eez-fill-layer',
            'encounters-circle-layer',
            'vessels-circle-layer',
            'dark-vessels-circle-layer',
            'sar-scenes-fill-layer'
        ];
        clickableLayers.forEach((layerId) => {
            this.map.on('mouseenter', layerId, () => {
                if (this.map.getCanvas()) this.map.getCanvas().style.cursor = 'pointer';
            });
            this.map.on('mouseleave', layerId, () => {
                if (this.map.getCanvas()) this.map.getCanvas().style.cursor = '';
            });
        });
    }

    showPopup(lngLat, htmlContent) {
        if (this.currentPopup) {
            this.currentPopup.remove();
        }

        this.currentPopup = new maplibregl.Popup({ offset: 12, closeButton: true, className: 'gfvip-custom-popup' })
            .setLngLat(lngLat)
            .setHTML(htmlContent)
            .addTo(this.map);
    }

    handleViewportChange() {
        clearTimeout(this.debounceTimer);
        this.debounceTimer = setTimeout(() => {
            this.refreshAllActiveLayers();
        }, 300);
    }

    getViewportBBoxString() {
        if (!this.map) return null;
        try {
            const bounds = this.map.getBounds();
            return `${bounds.getWest()},${bounds.getSouth()},${bounds.getEast()},${bounds.getNorth()}`;
        } catch (e) {
            return null;
        }
    }

    toggleLayer(layerKey, visible) {
        this.activeLayers[layerKey] = visible;

        const layerMap = {
            fao: ['fao-fill-layer', 'fao-line-layer'],
            eez: ['eez-fill-layer', 'eez-line-layer'],
            encounters: ['encounters-circle-layer'],
            vessels: ['vessels-circle-layer'],
            darkVessels: ['dark-vessels-circle-layer'],
            sarScenes: ['sar-scenes-fill-layer', 'sar-scenes-line-layer'],
        };

        const targetIds = layerMap[layerKey] || [];
        targetIds.forEach((id) => {
            if (this.map && this.map.getLayer(id)) {
                this.map.setLayoutProperty(id, 'visibility', visible ? 'visible' : 'none');
            }
        });

        if (visible) {
            this.fetchLayerData(layerKey);
        }
    }

    refreshAllActiveLayers() {
        Object.keys(this.activeLayers).forEach((key) => {
            if (this.activeLayers[key]) {
                this.fetchLayerData(key);
            }
        });
    }

    async fetchLayerData(layerKey) {
        const bbox = this.getViewportBBoxString();
        const urls = {
            fao: `/api/layers/fao?bbox=${bbox || ''}`,
            eez: `/api/layers/eez?bbox=${bbox || ''}`,
            encounters: `/api/layers/encounters?bbox=${bbox || ''}`,
            vessels: `/api/layers/vessels?bbox=${bbox || ''}`,
            darkVessels: `/api/layers/dark-vessels?bbox=${bbox || ''}`,
            sarScenes: `/api/layers/sar-scenes?bbox=${bbox || ''}`,
        };

        const sources = {
            fao: 'fao-source',
            eez: 'eez-source',
            encounters: 'encounters-source',
            vessels: 'vessels-source',
            darkVessels: 'dark-vessels-source',
            sarScenes: 'sar-scenes-source',
        };

        const sourceId = sources[layerKey];
        if (!sourceId || !this.map || !this.map.getSource(sourceId)) return;

        this.setLoadingBadge(layerKey, true);

        try {
            const res = await fetch(urls[layerKey]);
            if (!res.ok) throw new Error(`HTTP Error ${res.status}`);
            const geojson = await res.json();

            if (this.map.getSource(sourceId)) {
                this.map.getSource(sourceId).setData(geojson);
            }
            this.updateLayerCountBadge(layerKey, geojson.features ? geojson.features.length : 0);
        } catch (err) {
            console.error(`[GlobalMapController] Error loading layer ${layerKey}:`, err);
            this.showLayerError(layerKey);
        } finally {
            this.setLoadingBadge(layerKey, false);
        }
    }

    setLoadingBadge(layerKey, isLoading) {
        const badge = document.getElementById(`loading-${layerKey}`);
        if (badge) {
            badge.style.display = isLoading ? 'inline-block' : 'none';
        }
    }

    updateLayerCountBadge(layerKey, count) {
        const badge = document.getElementById(`count-${layerKey}`);
        if (badge) {
            badge.innerText = count.toLocaleString();
            badge.classList.remove('text-red-400');
        }
    }

    showLayerError(layerKey) {
        const badge = document.getElementById(`count-${layerKey}`);
        if (badge) {
            badge.innerText = 'Err';
            badge.classList.add('text-red-400');
        }
    }

    /**
     * Safe Auto-Fit to Data Types
     * Prioritizes valid, finite coordinates
     */
    fitToData(type) {
        if (!this.map) return;

        let sourceId = 'vessels-source';
        if (type === 'sar-candidates' || type === 'dark-vessels') sourceId = 'dark-vessels-source';
        else if (type === 'sar-scenes') sourceId = 'sar-scenes-source';
        else if (type === 'vessels') sourceId = 'vessels-source';

        const source = this.map.getSource(sourceId);
        if (!source || !source._data || !source._data.features || !source._data.features.length) {
            // Default view: Southeast Asia / Global Maritime Baseline
            this.map.flyTo({ center: [100.0, 5.0], zoom: 4 });
            return;
        }

        const features = source._data.features;
        const bounds = new maplibregl.LngLatBounds();
        let validCoords = 0;

        features.forEach((feat) => {
            if (!feat.geometry) return;
            const geom = feat.geometry;

            if (geom.type === 'Point' && Array.isArray(geom.coordinates)) {
                const [lng, lat] = geom.coordinates;
                if (this.isValidCoord(lng, lat)) {
                    bounds.extend([lng, lat]);
                    validCoords++;
                }
            } else if (geom.type === 'Polygon' && Array.isArray(geom.coordinates)) {
                geom.coordinates[0].forEach(([lng, lat]) => {
                    if (this.isValidCoord(lng, lat)) {
                        bounds.extend([lng, lat]);
                        validCoords++;
                    }
                });
            }
        });

        if (validCoords > 0) {
            this.map.fitBounds(bounds, { padding: 60, maxZoom: 12 });
        } else {
            this.map.flyTo({ center: [100.0, 5.0], zoom: 4 });
        }
    }

    isValidCoord(lng, lat) {
        return typeof lng === 'number' && typeof lat === 'number' &&
               isFinite(lng) && isFinite(lat) &&
               lng >= -180 && lng <= 180 &&
               lat >= -90 && lat <= 90;
    }
}
