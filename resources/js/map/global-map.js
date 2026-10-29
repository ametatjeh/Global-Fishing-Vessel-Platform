/**
 * Global Vessel Map Controller using MapLibre GL JS
 * Global Fishing Vessel Tracking (GFVT)
 * ESRI World Dark Gray Canvas Basemap & Live Maritime Intelligence
 */
import * as maplibregl from 'maplibre-gl';
import maplibreWorkerUrl from 'maplibre-gl/dist/maplibre-gl-worker.mjs?url';
import 'maplibre-gl/dist/maplibre-gl.css';

// Configure official MapLibre Web Worker built and versioned by Vite
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
            eezBoundaries: true,
            encounters: true,
            vessels: true,
            darkVessels: true,
            sarScenes: true,
        }, options.layers || {});

        this.loadingState = {
            fao: false,
            eez: false,
            eezBoundaries: false,
            encounters: false,
            vessels: false,
            darkVessels: false,
            sarScenes: false,
        };

        // Internal layer GeoJSON cache for reliable bounds fitting and UI interaction
        this.layerData = {
            fao: null,
            eez: null,
            eezBoundaries: null,
            encounters: null,
            vessels: null,
            darkVessels: null,
            sarScenes: null,
        };

        this.debounceTimer = null;
        this.currentPopup = null;
        this.basemapErrorNotified = false;
        this.livePollingInterval = null;

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
            glyphs: 'https://demotiles.maplibre.org/font/{fontstack}/{range}.pbf',
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
            this.registerVesselIcons();
            this.setupSourcesAndLayers();
            this.attachEventListeners();
            this.refreshAllActiveLayers();
            this.startLiveVesselPolling(30000);
        });

        // Window resize observer
        window.addEventListener('resize', () => {
            this.resizeMap();
        });

        window.addEventListener('beforeunload', () => {
            this.stopLiveVesselPolling();
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
        this.addEezBoundariesLayer();
        this.addSarSceneLayer();
        this.addGfwLayer();
        this.addSarDetectionLayer();
        this.addAisLayers();

        this.ensureLayerOrder();
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
                'fill-color': '#3b82f6',
                'fill-opacity': 0.12
            }
        });

        this.map.addLayer({
            id: 'fao-line-layer',
            type: 'line',
            source: 'fao-source',
            layout: { visibility: this.activeLayers.fao ? 'visible' : 'none' },
            paint: {
                'line-color': '#93c5fd',
                'line-width': 1.8,
                'line-opacity': 0.9,
                'line-dasharray': [4, 2]
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
                'fill-color': [
                    'match',
                    ['get', 'pol_type'],
                    'Overlapping claim', '#f59e0b',
                    'Joint regime', '#a855f7',
                    '#0d9488'
                ],
                'fill-opacity': 0.15
            }
        });

        this.map.addLayer({
            id: 'eez-line-layer',
            type: 'line',
            source: 'eez-source',
            layout: { visibility: this.activeLayers.eez ? 'visible' : 'none' },
            paint: {
                'line-color': [
                    'match',
                    ['get', 'pol_type'],
                    'Overlapping claim', '#f59e0b',
                    'Joint regime', '#c084fc',
                    '#0d9488'
                ],
                'line-width': 1.0,
                'line-opacity': 0.35
            }
        });
    }

    addEezBoundariesLayer() {
        if (this.map.getSource('eez-boundaries-source')) return;

        this.map.addSource('eez-boundaries-source', {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: [] }
        });

        this.map.addLayer({
            id: 'eez-boundaries-layer',
            type: 'line',
            source: 'eez-boundaries-source',
            layout: {
                'visibility': this.activeLayers.eezBoundaries ? 'visible' : 'none',
                'line-cap': 'round',
                'line-join': 'round'
            },
            paint: {
                // Dark slate boundary line (#64748b) for high-clarity maritime delimitation
                'line-color': '#64748b',
                'line-opacity': 0.90,
                'line-width': [
                    'interpolate',
                    ['linear'],
                    ['zoom'],
                    2, 1.0,
                    6, 1.4,
                    10, 1.8
                ]
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

    registerVesselIcons() {
        if (!this.map) return;

        try {
            // 1. Live Vessel Chevron (Pointing True North 0°)
            if (!this.map.hasImage('vessel-oriented-live')) {
                const size = 32;
                const canvas = document.createElement('canvas');
                canvas.width = size;
                canvas.height = size;
                const ctx = canvas.getContext('2d');
                if (ctx) {
                    ctx.beginPath();
                    ctx.moveTo(16, 2);   // Bow
                    ctx.lineTo(27, 27);  // Starboard stern outer
                    ctx.lineTo(16, 21);  // Transom center notch
                    ctx.lineTo(5, 27);   // Port stern outer
                    ctx.closePath();

                    ctx.fillStyle = '#38bdf8'; // Bright cyan
                    ctx.fill();
                    ctx.lineWidth = 2.0;
                    ctx.strokeStyle = '#0284c7'; // Deep blue outline
                    ctx.stroke();

                    this.map.addImage('vessel-oriented-live', ctx.getImageData(0, 0, size, size), { pixelRatio: 2 });
                }
            }

            // 2. Stale Vessel Chevron (> 2h, Slate)
            if (!this.map.hasImage('vessel-oriented-stale')) {
                const size = 32;
                const canvas = document.createElement('canvas');
                canvas.width = size;
                canvas.height = size;
                const ctx = canvas.getContext('2d');
                if (ctx) {
                    ctx.beginPath();
                    ctx.moveTo(16, 2);
                    ctx.lineTo(27, 27);
                    ctx.lineTo(16, 21);
                    ctx.lineTo(5, 27);
                    ctx.closePath();

                    ctx.fillStyle = '#64748b'; // Slate 500
                    ctx.fill();
                    ctx.lineWidth = 2.0;
                    ctx.strokeStyle = '#334155'; // Slate 700 outline
                    ctx.stroke();

                    this.map.addImage('vessel-oriented-stale', ctx.getImageData(0, 0, size, size), { pixelRatio: 2 });
                }
            }
        } catch (e) {
            console.warn('[GlobalMapController] Canvas image registration warning:', e);
        }
    }

    addAisLayers() {
        if (this.map.getSource('vessels-source')) return;

        this.registerVesselIcons();

        // High-performance GeoJSON source for individual vessel markers
        this.map.addSource('vessels-source', {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: [] }
        });

        // 1. Individual Vessels with Valid Orientation (Heading or COG Chevron)
        this.map.addLayer({
            id: 'vessels-oriented-layer',
            type: 'symbol',
            source: 'vessels-source',
            filter: ['==', ['get', 'has_orientation'], true],
            layout: {
                'icon-image': [
                    'case',
                    ['boolean', ['get', 'is_fresh'], false],
                    'vessel-oriented-live',
                    'vessel-oriented-stale'
                ],
                'icon-rotate': ['get', 'orientation_degrees'],
                'icon-rotation-alignment': 'map',
                'icon-pitch-alignment': 'map',
                'icon-allow-overlap': true,
                'icon-ignore-placement': true,
                'icon-size': 0.85,
                'visibility': this.activeLayers.vessels ? 'visible' : 'none'
            }
        });

        // 2. Individual Vessels with Neutral / Unknown Orientation (Beacon Circle)
        this.map.addLayer({
            id: 'vessels-circle-layer',
            type: 'circle',
            source: 'vessels-source',
            filter: ['!=', ['get', 'has_orientation'], true],
            layout: { visibility: this.activeLayers.vessels ? 'visible' : 'none' },
            paint: {
                'circle-radius': [
                    'case',
                    ['boolean', ['get', 'is_fresh'], false],
                    5.5,
                    4.0
                ],
                'circle-color': [
                    'case',
                    ['boolean', ['get', 'is_fresh'], false],
                    '#38bdf8', // Live (< 2h) - Bright sky cyan
                    '#64748b'  // Stale (> 2h) - Muted slate
                ],
                'circle-stroke-width': 1.5,
                'circle-stroke-color': '#ffffff'
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

    ensureLayerOrder() {
        if (!this.map) return;

        // Visual rendering sequence (from bottom to topmost):
        // 1. Basemap and ocean (Raster tiles: esri-dark-base, esri-dark-reference)
        // 2. Polygons / Fills (FAO Major Areas, RFMO/FMA, EEZ Zones)
        // 3. Official EEZ Maritime Boundaries Lines (eez-boundaries-layer)
        // 4. Overlays & Analysis (SAR scenes footprint, encounters, dark vessel detections)
        // 5. Vessels — strictly topmost (vessels-circle-layer)
        const orderedLayers = [
            // 2. Polygons & Fills
            'fao-fill-layer',
            'eez-fill-layer',
            'sar-scenes-fill-layer',

            // 2b. Context outlines
            'fao-line-layer',
            'eez-line-layer',

            // 3. Official EEZ Boundaries line layer (Dark, crisp, distinct)
            'eez-boundaries-layer',

            // 4. Analysis & Detection layers
            'sar-scenes-line-layer',
            'encounters-circle-layer',
            'dark-vessels-circle-layer',

            // 5. Vessels — strictly topmost individual oriented and neutral markers
            'vessels-circle-layer',
            'vessels-oriented-layer'
        ];

        orderedLayers.forEach((layerId) => {
            if (this.map.getLayer(layerId)) {
                try {
                    this.map.moveLayer(layerId);
                } catch (e) {
                    // Ignore transient layer state
                }
            }
        });

        // Unconditional enforcement: vessel layers remain on top of everything
        ['vessels-circle-layer', 'vessels-oriented-layer'].forEach((id) => {
            if (this.map.getLayer(id)) {
                try {
                    this.map.moveLayer(id);
                } catch (e) {}
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

        // Individual Vessel Click (Oriented Chevron)
        this.map.on('click', 'vessels-oriented-layer', (e) => {
            this.handleVesselClick(e);
        });

        // Individual Vessel Click (Neutral Circle)
        this.map.on('click', 'vessels-circle-layer', (e) => {
            this.handleVesselClick(e);
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
                    <div class="flex items-center gap-1.5 font-bold text-blue-300 border-b border-blue-900 pb-1">
                        <span>🌐</span>
                        <span>FAO Statistical Area ${props.area_code || ''}</span>
                    </div>
                    <div class="text-slate-100 font-semibold">${props.name_en || 'Fishing Area'}</div>
                    <div class="text-[11px] text-blue-200">Ocean Basin: <strong class="text-white">${props.ocean_basin || '-'}</strong></div>
                    <div class="text-[10px] text-blue-300/80 bg-blue-950/60 p-2 rounded border border-blue-800/40">
                        <strong>Perhatian:</strong> Poligon FAO adalah wilayah statistik pelaporan perikanan global, bukan batas yurisdiksi atau kewenangan hukum.
                    </div>
                </div>
            `;
            this.showPopup(e.lngLat, html);
        });

        // EEZ Boundaries Click Popup
        this.map.on('click', 'eez-fill-layer', (e) => {
            if (!e.features.length) return;
            const props = e.features[0].properties;
            const polBadge = props.pol_type === 'Overlapping claim' 
                ? '<span class="px-1.5 py-0.5 rounded text-[9px] bg-amber-500/20 text-amber-300 border border-amber-500/40 font-bold uppercase">Klaim Tumpang Tindih</span>'
                : (props.pol_type === 'Joint regime' 
                    ? '<span class="px-1.5 py-0.5 rounded text-[9px] bg-purple-500/20 text-purple-300 border border-purple-500/40 font-bold uppercase">Joint Regime</span>'
                    : '<span class="px-1.5 py-0.5 rounded text-[9px] bg-teal-500/20 text-teal-300 border border-teal-500/40 font-bold uppercase">200 NM EEZ</span>');

            const html = `
                <div class="space-y-2 text-xs font-sans">
                    <div class="flex items-center justify-between border-b border-teal-900 pb-1">
                        <span class="font-bold text-teal-300 flex items-center gap-1.5">
                            <span>🛡️</span> World EEZ v12
                        </span>
                        ${polBadge}
                    </div>
                    <div class="text-slate-100 font-semibold">${props.geoname || 'EEZ Zone'}</div>
                    <div class="text-[11px] text-teal-200">Negara: <strong class="text-white">${props.sovereign_state || '-'}</strong> ${props.sovereign_iso3 ? `(${props.sovereign_iso3})` : ''}</div>
                    <div class="text-[10px] text-slate-300">Luas: <strong class="text-white">${props.area_sqkm ? Number(props.area_sqkm).toLocaleString() + ' km²' : '-'}</strong> &bull; MRGID: <strong class="text-teal-300">${props.mrgid || '-'}</strong></div>
                    <div class="text-[10px] text-teal-300/90 bg-teal-950/70 p-2 rounded border border-teal-800/40">
                        <strong>Status Hukum (UNCLOS):</strong> ${props.disclaimer || 'Batas yurisdiksi hak ekonomi maritim UNCLOS / traktat bilateral. Bukan penetapan batas hukum final untuk klaim sengketa.'}
                    </div>
                </div>
            `;
            this.showPopup(e.lngLat, html);
        });

        // Official EEZ Boundaries Line Click Popup
        this.map.on('click', 'eez-boundaries-layer', (e) => {
            if (!e.features.length) return;
            const props = e.features[0].properties;
            const sovInfo = props.sovereign2 
                ? `${props.sovereign1 || '-'} &bull; ${props.sovereign2}`
                : (props.sovereign1 || 'International / UNCLOS');

            const html = `
                <div class="space-y-2 text-xs font-sans">
                    <div class="flex items-center justify-between border-b border-slate-700 pb-1">
                        <span class="font-bold text-slate-200 flex items-center gap-1.5">
                            <span>📏</span> EEZ Boundary Line
                        </span>
                        <span class="px-1.5 py-0.5 rounded text-[9px] bg-slate-800 text-slate-300 border border-slate-700 font-bold uppercase">
                            ${props.line_type || 'Delimitation'}
                        </span>
                    </div>
                    <div class="text-white font-semibold">${props.line_name || 'Maritime Boundary'}</div>
                    <div class="text-[11px] text-slate-300">Negara: <strong class="text-teal-300">${sovInfo}</strong></div>
                    <div class="text-[10px] text-slate-400 font-mono">Panjang: <strong class="text-white">${props.length_km ? Number(props.length_km).toLocaleString() + ' km' : '-'}</strong> &bull; Line ID: ${props.line_id || '-'}</div>
                    <div class="text-[10px] text-slate-400 bg-slate-900/80 p-2 rounded border border-slate-800">
                        Sumber: ${props.source1 || 'VLIZ Marine Regions v12'} ${props.doc_date ? `(${props.doc_date})` : ''}
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
            'eez-boundaries-layer',
            'encounters-circle-layer',
            'vessels-oriented-layer',
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

    handleVesselClick(e) {
        if (!e.features || !e.features.length) return;
        const feat = e.features[0];
        const props = feat.properties;
        const coords = feat.geometry?.coordinates || [e.lngLat.lng, e.lngLat.lat];
        this.showVesselPopup(props, { lng: coords[0], lat: coords[1] });
    }

    showVesselPopup(props, lngLat) {
        const isFresh = props.is_fresh === true || props.is_fresh === 'true';
        const freshnessBadge = isFresh
            ? `<span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/40">● LIVE (&lt; 2h)</span>`
            : `<span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-slate-800 text-amber-300 border border-amber-500/30">⏳ KEDALUWARSA</span>`;

        const dateStr = props.observed_at ? new Date(props.observed_at).toUTCString() : 'N/A';
        const coordsStr = `${lngLat.lat.toFixed(4)}°, ${lngLat.lng.toFixed(4)}°`;

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

        // Orientation status badge
        let orientationBadge = '<span class="text-slate-400">Netral (Tanpa Haluan)</span>';
        if (props.orientation_type === 'heading') {
            orientationBadge = `<span class="text-emerald-300 font-bold">🧭 Heading Aktual (${Number(props.heading_degrees).toFixed(1)}°)</span>`;
        } else if (props.orientation_type === 'cog' || (props.course_degrees !== null && props.course_degrees !== undefined)) {
            orientationBadge = `<span class="text-sky-300 font-bold">⚓ COG Fallback (${Number(props.course_degrees).toFixed(1)}°)</span>`;
        }

        const headingDisplay = (props.heading_degrees !== null && props.heading_degrees !== undefined && props.heading_degrees !== '')
            ? `${Number(props.heading_degrees).toFixed(1)}°`
            : '<span class="text-slate-500 italic">N/A</span>';

        const cogDisplay = (props.course_degrees !== null && props.course_degrees !== undefined && props.course_degrees !== '')
            ? `${Number(props.course_degrees).toFixed(1)}°`
            : '<span class="text-slate-500 italic">N/A</span>';

        const sogDisplay = (props.speed_knots !== null && props.speed_knots !== undefined && props.speed_knots !== '')
            ? `${Number(props.speed_knots).toFixed(1)} kn`
            : '0.0 kn';

        const html = `
            <div class="space-y-2 text-xs font-sans max-w-xs">
                <div class="flex items-center justify-between border-b border-sky-900 pb-1">
                    <span class="font-bold text-sky-300 flex items-center gap-1.5 truncate mr-2">
                        <span class="text-sm">${flagEmoji}</span>
                        <span class="truncate">${props.name || 'AIS Vessel'}</span>
                    </span>
                    <div class="flex items-center gap-1 shrink-0">
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
                            <span class="text-white font-semibold flex items-center gap-1 truncate max-w-[130px]" title="${flagCountry}">
                                <span class="truncate">${flagCountry}</span>
                                <span class="text-[10px] text-teal-300">(${flagStateCode})</span>
                            </span>
                            ${flagStatusBadge}
                        </div>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-2 text-[11px] font-mono">
                    <div><span class="text-slate-400">MMSI:</span> <strong class="text-white">${props.mmsi || '-'}</strong></div>
                    <div><span class="text-slate-400">IMO:</span> <strong class="text-white">${props.imo || '-'}</strong></div>
                    <div><span class="text-slate-400">Callsign:</span> <strong class="text-white">${props.callsign || '-'}</strong></div>
                    <div><span class="text-slate-400">Tipe:</span> <span class="text-slate-300 capitalize">${props.vessel_type || 'vessel'}</span></div>
                    <div><span class="text-slate-400">SOG (Kecepatan):</span> <strong class="text-sky-300">${sogDisplay}</strong></div>
                    <div><span class="text-slate-400">COG (Arah Gerak):</span> <strong class="text-sky-300">${cogDisplay}</strong></div>
                    <div><span class="text-slate-400">Heading (Haluan):</span> <strong class="text-emerald-300">${headingDisplay}</strong></div>
                    <div><span class="text-slate-400">Status Nav:</span> <span class="text-slate-300 capitalize">${props.nav_status || 'under_way'}</span></div>
                </div>
                <div class="p-1 rounded bg-slate-900 border border-slate-800 text-[10px] font-mono flex items-center justify-between">
                    <span class="text-slate-400">Arah Simbol:</span>
                    <div>${orientationBadge}</div>
                </div>
                <div class="text-[10px] font-mono text-slate-400">
                    Posisi: <span class="text-slate-200">${coordsStr}</span>
                </div>
                <div class="text-[10px] text-slate-400 pt-1 border-t border-slate-800 flex justify-between">
                    <span>Waktu: ${dateStr}</span>
                    <span>${props.provider || 'AIS Live Gateway'}</span>
                </div>
                ${props.vessel_id ? `
                <div class="pt-1 text-right">
                    <a href="/vessels/${props.vessel_id}" class="text-[11px] text-sky-400 hover:text-sky-300 font-semibold underline">Profil & Track Kapal &rarr;</a>
                </div>` : ''}
            </div>
        `;
        this.showPopup(lngLat, html);
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
            eezBoundaries: ['eez-boundaries-layer'],
            encounters: ['encounters-circle-layer'],
            vessels: [
                'vessels-oriented-layer',
                'vessels-circle-layer'
            ],
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

        this.ensureLayerOrder();
    }

    refreshAllActiveLayers() {
        Object.keys(this.activeLayers).forEach((key) => {
            if (this.activeLayers[key]) {
                this.fetchLayerData(key);
            }
        });
    }

    async fetchLayerData(layerKey, options = {}) {
        const bbox = this.getViewportBBoxString();
        const urls = {
            fao: `/api/layers/fao?bbox=${bbox || ''}`,
            eez: `/api/layers/eez?bbox=${bbox || ''}`,
            eezBoundaries: `/api/layers/eez-boundaries?bbox=${bbox || ''}`,
            encounters: `/api/layers/encounters?bbox=${bbox || ''}`,
            vessels: `/api/layers/vessels?bbox=${bbox || ''}`,
            darkVessels: `/api/layers/dark-vessels?bbox=${bbox || ''}`,
            sarScenes: `/api/layers/sar-scenes?bbox=${bbox || ''}`,
        };

        const sources = {
            fao: 'fao-source',
            eez: 'eez-source',
            eezBoundaries: 'eez-boundaries-source',
            encounters: 'encounters-source',
            vessels: 'vessels-source',
            darkVessels: 'dark-vessels-source',
            sarScenes: 'sar-scenes-source',
        };

        const sourceId = sources[layerKey];
        if (!sourceId || !this.map || !this.map.getSource(sourceId)) return;

        if (!options.silent) {
            this.setLoadingBadge(layerKey, true);
        }

        try {
            let requestUrl = urls[layerKey];
            if (layerKey === 'vessels') {
                const searchInput = document.getElementById('inputVesselSearch');
                if (searchInput && searchInput.value && searchInput.value.trim()) {
                    requestUrl += `&q=${encodeURIComponent(searchInput.value.trim())}`;
                }
            }

            const res = await fetch(requestUrl);
            if (!res.ok) throw new Error(`HTTP Error ${res.status}`);
            const geojson = await res.json();

            // Validate coordinates before updating map
            if (geojson && Array.isArray(geojson.features)) {
                geojson.features = geojson.features.filter((f) => {
                    const coords = f.geometry?.coordinates;
                    return coords && Array.isArray(coords) &&
                           this.isValidCoord(coords[0], coords[1]);
                });
            }

            // Cache data in controller instance
            this.layerData[layerKey] = geojson;

            const source = this.map.getSource(sourceId);
            if (source && typeof source.setData === 'function') {
                source.setData(geojson);
            }
            this.updateLayerCountBadge(layerKey, geojson.features ? geojson.features.length : 0);
            this.ensureLayerOrder();
        } catch (err) {
            console.error(`[GlobalMapController] Error loading layer ${layerKey}:`, err);
            if (!options.silent) {
                this.showLayerError(layerKey);
            }
        } finally {
            if (!options.silent) {
                this.setLoadingBadge(layerKey, false);
            }
        }
    }

    startLiveVesselPolling(intervalMs = 30000) {
        this.stopLiveVesselPolling();
        this.livePollingInterval = setInterval(() => {
            if (this.activeLayers.vessels && this.map) {
                this.fetchLayerData('vessels', { silent: true });
            }
        }, intervalMs);
    }

    stopLiveVesselPolling() {
        if (this.livePollingInterval) {
            clearInterval(this.livePollingInterval);
            this.livePollingInterval = null;
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
     * Prioritizes valid, finite coordinates across Point, Polygon, and MultiPolygon
     */
    fitToData(type) {
        if (!this.map) return;

        let sourceId = 'vessels-source';
        let layerKey = 'vessels';
        if (type === 'sar-candidates' || type === 'dark-vessels') {
            sourceId = 'dark-vessels-source';
            layerKey = 'darkVessels';
        } else if (type === 'sar-scenes') {
            sourceId = 'sar-scenes-source';
            layerKey = 'sarScenes';
        } else if (type === 'vessels') {
            sourceId = 'vessels-source';
            layerKey = 'vessels';
        } else if (type === 'eez') {
            sourceId = 'eez-source';
            layerKey = 'eez';
        } else if (type === 'eez-boundaries' || type === 'boundaries') {
            sourceId = 'eez-boundaries-source';
            layerKey = 'eezBoundaries';
        } else if (type === 'fao') {
            sourceId = 'fao-source';
            layerKey = 'fao';
        }

        const source = this.map.getSource(sourceId);
        const cached = this.layerData[layerKey];
        const features = cached?.features || source?._data?.geojson?.features || source?._data?.features || [];

        if (!features || !features.length) {
            // Default view: Southeast Asia / Global Maritime Baseline
            this.map.flyTo({ center: [100.0, 5.0], zoom: 4 });
            return;
        }

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
            } else if (geom.type === 'LineString' && Array.isArray(geom.coordinates)) {
                geom.coordinates.forEach(([lng, lat]) => {
                    if (this.isValidCoord(lng, lat)) {
                        bounds.extend([lng, lat]);
                        validCoords++;
                    }
                });
            } else if (geom.type === 'MultiLineString' && Array.isArray(geom.coordinates)) {
                geom.coordinates.forEach((line) => {
                    if (Array.isArray(line)) {
                        line.forEach(([lng, lat]) => {
                            if (this.isValidCoord(lng, lat)) {
                                bounds.extend([lng, lat]);
                                validCoords++;
                            }
                        });
                    }
                });
            } else if (geom.type === 'Polygon' && Array.isArray(geom.coordinates)) {
                geom.coordinates[0].forEach(([lng, lat]) => {
                    if (this.isValidCoord(lng, lat)) {
                        bounds.extend([lng, lat]);
                        validCoords++;
                    }
                });
            } else if (geom.type === 'MultiPolygon' && Array.isArray(geom.coordinates)) {
                geom.coordinates.forEach((poly) => {
                    if (Array.isArray(poly) && Array.isArray(poly[0])) {
                        poly[0].forEach(([lng, lat]) => {
                            if (this.isValidCoord(lng, lat)) {
                                bounds.extend([lng, lat]);
                                validCoords++;
                            }
                        });
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
