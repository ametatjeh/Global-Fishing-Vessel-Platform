/**
 * Global Vessel Map Controller using MapLibre GL JS
 * Global Fishing Vessel Tracking
 * ESRI World Dark Gray Canvas Basemap & Live Maritime Monitoring
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
            aseanEez: true,
            australiaEez: true,
            encounters: true,
            vessels: true,
            darkVessels: true,
            sarScenes: true,
        }, options.layers || {});

        this.loadingState = {
            aseanEez: false,
            australiaEez: false,
            encounters: false,
            vessels: false,
            darkVessels: false,
            sarScenes: false,
        };

        // Internal layer GeoJSON cache for reliable bounds fitting and UI interaction
        this.layerData = {
            aseanEez: null,
            australiaEez: null,
            encounters: null,
            vessels: null,
            darkVessels: null,
            sarScenes: null,
        };

        this.debounceTimer = null;
        this.hashDebounceTimer = null;
        this.layerAbortControllers = {};
        this.currentPopup = null;
        this.basemapErrorNotified = false;
        this.isStyleLoaded = false;
        this.styleRetryCount = 0;
        this.degradationCheckTimer = null;
        this.livePollingInterval = null;
        this.selectedVesselId = null;
        this.selectedMmsi = null;
        this.currentBasemapKey = this.resolveInitialBasemapKey(options.basemap);
        this.onVesselSelected = options.onVesselSelected || null;

        // Controlled Advanced Filters State
        this.filters = {
            search: '',
            flag: '',
            vesselType: '',
            minSog: null,
            maxSog: null,
            minCog: null,
            maxCog: null,
            headingStatus: '',
            freshness: '',
            reviewStatus: '',
            correlationStatus: '',
            minTcr: null,
            maxTcr: null,
            platform: '',
            dateRange: 'all',
            startDate: '',
            endDate: '',
            trackWindow: 'off',
        };
        this.activeFilterCount = 0;

        // Vessel Track State (Tahap 11 & Open Waters Track Time Ranges)
        this.currentTrackGeoJson = null;
        this.activeTrackVesselId = null;
        this.activeTrackRange = '24h';
        this.trackVisible = true;
        this.onTrackUpdated = null;
        this.onTrackVisibilityChanged = null;
        this.onTrackStatus = null;

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

    parseUrlLocation() {
        if (typeof window === 'undefined') return null;
        let result = null;
        const hash = window.location.hash || '';
        const search = window.location.search || '';

        // Match #map=13.41/1.60898/102.28512
        const mapMatch = hash.match(/#map=([0-9.]+)\/([0-9.-]+)\/([0-9.-]+)/);
        if (mapMatch) {
            const zoom = parseFloat(mapMatch[1]);
            const lat = parseFloat(mapMatch[2]);
            const lng = parseFloat(mapMatch[3]);
            if (isFinite(zoom) && isFinite(lat) && isFinite(lng) && lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180) {
                result = { zoom: Math.min(Math.max(zoom, 1), 22), lat, lng };
            }
        }

        // Match vessel parameter from hash or query string
        let vessel = null;
        const vesselHashMatch = hash.match(/[?&]vessel=([^&]+)/);
        if (vesselHashMatch) {
            vessel = decodeURIComponent(vesselHashMatch[1]);
        } else if (search) {
            const params = new URLSearchParams(search);
            if (params.get('vessel')) {
                vessel = params.get('vessel');
            }
        }

        if (vessel) {
            result = result || { zoom: this.options.zoom || 3.5, lat: 5.0, lng: 100.0 };
            result.vessel = vessel;
        }

        return result;
    }

    updateUrlHash() {
        if (!this.map || typeof window === 'undefined') return;
        try {
            const center = this.map.getCenter();
            const zoom = this.map.getZoom();
            const lat = center.lat.toFixed(5);
            const lng = center.lng.toFixed(5);
            const z = zoom.toFixed(2);
            let hash = `#map=${z}/${lat}/${lng}`;
            if (this.selectedMmsi) {
                hash += `&vessel=${encodeURIComponent(this.selectedMmsi)}`;
            }
            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, '', hash);
            } else {
                window.location.hash = hash;
            }
        } catch (e) {
            // ignore
        }
    }

    resolveInitialBasemapKey(passedKey) {
        const VALID_KEYS = [
            'openfreemap-positron',
            'openfreemap-dark',
            'openfreemap-liberty',
            'openfreemap-bright'
        ];
        const ALIASES = {
            'esri-dark': 'openfreemap-dark',
            'dark-ocean': 'openfreemap-dark',
            'positron': 'openfreemap-positron',
            'liberty': 'openfreemap-liberty',
            'bright': 'openfreemap-bright'
        };

        if (passedKey) {
            const normalized = ALIASES[passedKey] || passedKey;
            if (VALID_KEYS.includes(normalized)) {
                return normalized;
            }
        }

        // Check localStorage for previously saved valid user preference
        try {
            if (typeof window !== 'undefined' && window.localStorage) {
                const saved = window.localStorage.getItem('gfvip_map_basemap');
                if (saved) {
                    const normalized = ALIASES[saved] || saved;
                    if (VALID_KEYS.includes(normalized)) {
                        return normalized;
                    }
                }
            }
        } catch (e) {
            console.warn('[GlobalMapController] Could not read basemap preference from localStorage:', e);
        }

        // Default basemap when no valid preference exists: Positron High Contrast Clean
        return 'openfreemap-positron';
    }

    createMap() {
        const urlLoc = this.parseUrlLocation();
        const initialCenter = urlLoc && isFinite(urlLoc.lng) && isFinite(urlLoc.lat)
            ? [urlLoc.lng, urlLoc.lat]
            : (this.options.center || [100.0, 5.0]);
        const initialZoom = urlLoc && isFinite(urlLoc.zoom)
            ? urlLoc.zoom
            : (this.options.zoom || 3.5);

        const style = this.getBasemapStyle(this.currentBasemapKey);

        this.map = new maplibregl.Map({
            container: this.containerId,
            style: style,
            center: initialCenter,
            zoom: initialZoom,
            attributionControl: false // Handled cleanly via custom or bottom attribution
        });

        // Immediately track style load & enforce English-only country labels
        this.map.on('style.load', () => {
            this.isStyleLoaded = true;
            this.clearBasemapDegradationBanner();
            this.enforceEnglishCountryLabels();
        });
    }

    getBasemapStyle(key = this.currentBasemapKey) {
        if (key === 'openfreemap-positron' || key === 'positron') {
            return 'https://tiles.openfreemap.org/styles/positron';
        }
        if (key === 'openfreemap-dark' || key === 'dark-ocean' || key === 'esri-dark') {
            return 'https://tiles.openfreemap.org/styles/dark';
        }
        if (key === 'openfreemap-liberty' || key === 'liberty') {
            return 'https://tiles.openfreemap.org/styles/liberty';
        }
        if (key === 'openfreemap-bright' || key === 'bright') {
            return 'https://tiles.openfreemap.org/styles/bright';
        }
        return 'https://tiles.openfreemap.org/styles/positron';
    }

    getOfflineCanvasStyle() {
        // Fallback canvas style when all vector basemap endpoints are unreachable
        return {
            version: 8,
            name: 'Offline-Safe Canvas',
            glyphs: 'https://demotiles.maplibre.org/font/{fontstack}/{range}.pbf',
            sources: {
                'offline-canvas-base': {
                    type: 'raster',
                    tiles: [
                        'https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Dark_Gray_Base/MapServer/tile/{z}/{y}/{x}'
                    ],
                    tileSize: 256,
                    attribution: 'Tiles &copy; Esri &mdash; Esri, DeLorme, NAVTEQ, USGS, FAO, NOAA'
                }
            },
            layers: [
                {
                    id: 'offline-canvas-base-layer',
                    type: 'raster',
                    source: 'offline-canvas-base',
                    minzoom: 0,
                    maxzoom: 19
                }
            ]
        };
    }

    getEsriDarkBasemapStyle() {
        return this.getOfflineCanvasStyle();
    }

    setBasemapStyle(styleKey) {
        if (!this.map) return;
        const normalizedKey = (styleKey === 'esri-dark' || styleKey === 'dark-ocean') ? 'openfreemap-dark' : styleKey;
        this.currentBasemapKey = normalizedKey;

        // If switching to Dark Ocean Marine Style, ensure both ASEAN EEZ and Australia EEZ are activated
        if (normalizedKey === 'openfreemap-dark') {
            this.activeLayers.aseanEez = true;
            this.activeLayers.australiaEez = true;
            const aseanCheckbox = document.getElementById('toggle-layer-asean-eez');
            if (aseanCheckbox) aseanCheckbox.checked = true;
            const ausCheckbox = document.getElementById('toggle-layer-australia-eez');
            if (ausCheckbox) ausCheckbox.checked = true;
        }

        // Persist valid preference to localStorage
        try {
            if (typeof window !== 'undefined' && window.localStorage) {
                window.localStorage.setItem('gfvip_map_basemap', normalizedKey);
            }
        } catch (e) {
            console.warn('[GlobalMapController] Could not save basemap preference:', e);
        }

        this.isStyleLoaded = false;
        this.styleRetryCount = 0;
        this.clearBasemapDegradationBanner();

        const style = this.getBasemapStyle(normalizedKey);
        this.map.setStyle(style);

        this.map.once('style.load', () => {
            this.isStyleLoaded = true;
            this.clearBasemapDegradationBanner();
            this.enforceEnglishCountryLabels();
            this.registerVesselIcons();
            this.setupSourcesAndLayers();
            this.restoreCachedLayerData();
            this.refreshAllActiveLayers();
        });

        const labelEl = document.getElementById('currentBasemapLabel');
        if (labelEl) {
            const names = {
                'openfreemap-positron': 'Positron High Contrast Clean',
                'openfreemap-dark': 'Dark Ocean Marine Style',
                'openfreemap-liberty': 'Nautical Standard Vector',
                'openfreemap-bright': 'Topographic Bright Canvas',
                'esri-dark': 'Dark Ocean Marine Style'
            };
            labelEl.textContent = names[normalizedKey] || normalizedKey;
        }

        // Synchronize radio button in Settings popover
        const matchingRadio = document.querySelector(`input[name="basemap_choice"][value="${normalizedKey}"]`)
            || (normalizedKey === 'openfreemap-dark' ? document.querySelector('input[name="basemap_choice"][value="esri-dark"]') : null);
        if (matchingRadio && !matchingRadio.checked) {
            matchingRadio.checked = true;
        }
    }

    restoreCachedLayerData() {
        if (!this.map) return;
        try {
            if (this.layerData.aseanEez && this.map.getSource('asean-eez-source')) {
                this.map.getSource('asean-eez-source').setData(this.layerData.aseanEez);
            }
            if (this.layerData.australiaEez && this.map.getSource('australia-eez-source')) {
                this.map.getSource('australia-eez-source').setData(this.layerData.australiaEez);
            }
            if (this.layerData.vessels && this.map.getSource('vessels-source')) {
                this.map.getSource('vessels-source').setData(this.layerData.vessels);
            }
            if (this.layerData.darkVessels && this.map.getSource('dark-vessels-source')) {
                this.map.getSource('dark-vessels-source').setData(this.layerData.darkVessels);
            }
            if (this.layerData.encounters && this.map.getSource('encounters-source')) {
                this.map.getSource('encounters-source').setData(this.layerData.encounters);
            }
            if (this.layerData.sarScenes && this.map.getSource('sar-scenes-source')) {
                this.map.getSource('sar-scenes-source').setData(this.layerData.sarScenes);
            }
            if (this.currentTrackGeoJson && this.map.getSource('vessel-track-source')) {
                this.map.getSource('vessel-track-source').setData(this.currentTrackGeoJson);
            }
        } catch (err) {
            console.warn('[GlobalMapController] Error restoring cached layer data on style change:', err);
        }
    }

    clearBasemapDegradationBanner() {
        const banner = document.getElementById(`basemap-fallback-${this.containerId}`);
        if (banner) {
            banner.remove();
        }
        this.basemapErrorNotified = false;
        if (this.degradationCheckTimer) {
            clearTimeout(this.degradationCheckTimer);
            this.degradationCheckTimer = null;
        }
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

        // Geolocation Control
        this.map.addControl(new maplibregl.GeolocateControl({
            positionOptions: { enableHighAccuracy: true },
            trackUserLocation: true
        }), 'top-right');

        // Nautical Distance Scale Control
        this.map.addControl(new maplibregl.ScaleControl({
            maxWidth: 160,
            unit: 'nautical'
        }), 'bottom-left');

        // Cursor coordinates tracking
        this.map.on('mousemove', (e) => {
            const coordEl = document.getElementById('cursorCoordinates');
            if (coordEl && e.lngLat) {
                const lat = e.lngLat.lat.toFixed(5);
                const lng = e.lngLat.lng.toFixed(5);
                const zoom = this.map.getZoom().toFixed(2);
                coordEl.textContent = `Lat: ${lat}°, Lon: ${lng}° | Zoom: ${zoom}`;
            }
        });

        this.map.on('mouseout', () => {
            const coordEl = document.getElementById('cursorCoordinates');
            if (coordEl && this.map) {
                const center = this.map.getCenter();
                coordEl.textContent = `Center: ${center.lat.toFixed(5)}°, ${center.lng.toFixed(5)}° | Zoom: ${this.map.getZoom().toFixed(2)}`;
            }
        });
    }

    setupErrorHandling() {
        this.map.on('error', (e) => {
            const err = e.error || e;
            const msg = (err?.message || String(err || '')).toLowerCase();

            // Ignore non-fatal / aborted tile requests during rapid panning or zooming
            const isAborted = msg.includes('abort') || msg.includes('canceled');
            // Ignore optional icons, sprites, or glyph sub-range 404s
            const isOptionalAsset = msg.includes('image') || msg.includes('sprite') || msg.includes('glyph');
            // Ignore single tile 404/500 if the vector style itself is already loaded and functioning
            const isTileGlitch = (msg.includes('tile') || msg.includes('404')) && this.isStyleLoaded;

            if (isAborted || isOptionalAsset || isTileGlitch) {
                return;
            }

            console.warn('[MapLibre Event Warning]:', err?.message || String(err));

            // Only consider degradation if the root style JSON itself failed to fetch, or repeated network drops before style load
            const isFatalStyleFailure = !this.isStyleLoaded && (
                msg.includes('failed to fetch') ||
                msg.includes('networkerror') ||
                msg.includes('style') ||
                msg.includes('openfreemap')
            );

            if (isFatalStyleFailure) {
                this.scheduleBasemapRetryOrDegradation(err?.message || 'Style network error');
            }
        });
    }

    scheduleBasemapRetryOrDegradation(reason) {
        if (this.degradationCheckTimer) return;

        const maxRetries = 2;
        if (this.styleRetryCount < maxRetries) {
            this.styleRetryCount++;
            console.warn(`[GlobalMapController] Vector basemap style load failed (${reason}). Retrying ${this.styleRetryCount}/${maxRetries}...`);
            this.degradationCheckTimer = setTimeout(() => {
                this.degradationCheckTimer = null;
                if (!this.isStyleLoaded && this.map) {
                    try {
                        const styleUrl = this.getBasemapStyle(this.currentBasemapKey);
                        this.map.setStyle(styleUrl);
                    } catch (err) {
                        console.error('[GlobalMapController] Retry failed:', err);
                        this.handleBasemapDegradation(reason);
                    }
                }
            }, 1800);
        } else {
            this.handleBasemapDegradation(reason);
        }
    }

    handleBasemapDegradation(reason = 'Vector basemap unreachable') {
        if (this.basemapErrorNotified) return;
        this.basemapErrorNotified = true;

        const container = document.getElementById(this.containerId);
        if (!container) return;

        // Ensure container background stays dark maritime slate so vector data is still visible
        container.style.backgroundColor = '#0b1120';

        // Auto fallback to offline-safe canvas raster style
        if (this.map) {
            try {
                const fallbackStyle = this.getOfflineCanvasStyle();
                this.map.setStyle(fallbackStyle);
                this.map.once('style.load', () => {
                    this.registerVesselIcons();
                    this.setupSourcesAndLayers();
                    this.restoreCachedLayerData();
                    this.refreshAllActiveLayers();
                });
            } catch (err) {
                console.warn('[GlobalMapController] Could not apply fallback style:', err);
            }
        }

        const existingBanner = document.getElementById(`basemap-fallback-${this.containerId}`);
        if (!existingBanner) {
            const banner = document.createElement('div');
            banner.id = `basemap-fallback-${this.containerId}`;
            banner.className = 'absolute top-3 left-1/2 -translate-x-1/2 z-20 px-3.5 py-2 rounded-xl bg-amber-950/90 border border-amber-500/60 text-amber-200 text-xs font-mono shadow-2xl backdrop-blur-md flex items-center gap-3 pointer-events-auto transition-all';
            banner.innerHTML = `
                <span class="text-amber-400 text-sm">⚠️</span>
                <span>Vector basemap unavailable. Falling back to offline-safe canvas. Operational layers remain active.</span>
                <button type="button" id="btn-retry-basemap-${this.containerId}" class="px-2 py-0.5 rounded bg-amber-500/20 hover:bg-amber-500/30 text-amber-300 hover:text-white border border-amber-500/40 text-[11px] font-sans font-medium transition-colors">
                    Coba Lagi
                </button>
                <button type="button" onclick="this.parentElement.remove()" class="text-amber-400 hover:text-white font-bold ml-1 text-base leading-none">&times;</button>
            `;
            container.style.position = 'relative';
            container.appendChild(banner);

            const retryBtn = document.getElementById(`btn-retry-basemap-${this.containerId}`);
            if (retryBtn) {
                retryBtn.addEventListener('click', () => {
                    this.clearBasemapDegradationBanner();
                    this.styleRetryCount = 0;
                    this.setBasemapStyle(this.currentBasemapKey);
                });
            }
        }
    }

    setupLifecycleHooks() {
        this.map.on('load', () => {
            this.enforceEnglishCountryLabels();
            this.resizeMap();
            this.registerVesselIcons();
            this.setupSourcesAndLayers();
            this.attachEventListeners();
            this.refreshAllActiveLayers();
            this.startLiveVesselPolling(30000);

            // Re-enforce once idle to ensure vector glyphs / late symbol layers are strictly English
            this.map.once('idle', () => {
                this.enforceEnglishCountryLabels();
            });

            // Check if initial URL had a vessel to select
            const urlLoc = this.parseUrlLocation();
            if (urlLoc && urlLoc.vessel) {
                setTimeout(() => {
                    this.selectVesselByIdentifier(urlLoc.vessel);
                }, 600);
            }
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
            this.updateUrlHash();
        });
    }

    resizeMap() {
        if (this.map) {
            this.map.resize();
        }
    }

    setupSourcesAndLayers() {
        this.addAseanEezLayer();
        this.addAustraliaEezLayer();
        this.addSarSceneLayer();
        this.addGfwLayer();
        this.addSarDetectionLayer();
        this.addVesselTrackLayers();
        this.addAisLayers();

        this.ensureLayerOrder();
    }

    addAseanEezLayer() {
        if (this.map.getSource('asean-eez-source')) return;

        this.map.addSource('asean-eez-source', {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: [] }
        });

        // 1. Polygon Fill for ASEAN EEZ (retained internally for geometry/containment, fill hidden with opacity 0)
        this.map.addLayer({
            id: 'asean-eez-fill-layer',
            type: 'fill',
            source: 'asean-eez-source',
            filter: ['==', ['get', 'feature_type'], 'zone'],
            layout: { visibility: this.activeLayers.aseanEez ? 'visible' : 'none' },
            paint: {
                'fill-color': '#000000',
                'fill-opacity': 0.0
            }
        });

        // Shared styling constants for EEZ boundaries:
        // Thin 1.0-1.2px at normal zoom (4-6), 70% opacity to preserve underlying operational tracks
        const aseanLineColor = [
            'match',
            ['get', 'country_code'],
            'IDN', '#06b6d4', // Indonesia: Cyan
            'MYS', '#f97316', // Malaysia: Orange
            'THA', '#eab308', // Thailand: Yellow
            'VNM', '#d946ef', // Vietnam: Magenta
            'PHL', '#22c55e', // Filipina: Green
            'BRN', '#8b5cf6', // Brunei: Violet
            'SGP', '#fb7185', // Singapura: Coral
            'KHM', '#3b82f6', // Kamboja: Blue
            'MMR', '#ec4899', // Myanmar: Pink
            'LAO', '#14b8a6', // Laos: Teal
            'TLS', '#a855f7', // Timor-Leste: Violet/Indigo
            ['coalesce', ['get', 'color'], '#06b6d4']
        ];

        const eezLineWidth = [
            'interpolate', ['linear'], ['zoom'],
            2, 0.8,
            4, 1.0,
            6, 1.2,
            10, 1.8
        ];

        // 2. Subtle Zone Outline
        this.map.addLayer({
            id: 'asean-eez-polygon-outline-layer',
            type: 'line',
            source: 'asean-eez-source',
            filter: ['==', ['get', 'feature_type'], 'zone'],
            layout: { visibility: this.activeLayers.aseanEez ? 'visible' : 'none' },
            paint: {
                'line-color': aseanLineColor,
                'line-width': 0.8,
                'line-opacity': 0.25
            }
        });

        // 3a. AGREED / DELIMITATED Lines (Solid Country Line)
        this.map.addLayer({
            id: 'asean-eez-line-agreed',
            type: 'line',
            source: 'asean-eez-source',
            filter: [
                'all',
                ['==', ['get', 'feature_type'], 'boundary_line'],
                ['==', ['get', 'delimitation_status'], 'AGREED']
            ],
            layout: {
                'visibility': this.activeLayers.aseanEez ? 'visible' : 'none',
                'line-cap': 'round',
                'line-join': 'round'
            },
            paint: {
                'line-color': aseanLineColor,
                'line-opacity': 0.70,
                'line-width': eezLineWidth
            }
        });

        // 3b. UNDELIMITED / UNRESOLVED Lines (Dashed Country Line)
        this.map.addLayer({
            id: 'asean-eez-line-undelimited',
            type: 'line',
            source: 'asean-eez-source',
            filter: [
                'all',
                ['==', ['get', 'feature_type'], 'boundary_line'],
                ['==', ['get', 'delimitation_status'], 'UNDELIMITED']
            ],
            layout: {
                'visibility': this.activeLayers.aseanEez ? 'visible' : 'none',
                'line-cap': 'round',
                'line-join': 'round'
            },
            paint: {
                'line-color': aseanLineColor,
                'line-opacity': 0.70,
                'line-dasharray': [3, 2],
                'line-width': eezLineWidth
            }
        });

        // 3c. DISPUTED / OVERLAPPING CLAIM Lines (Dashed Country Line)
        this.map.addLayer({
            id: 'asean-eez-line-disputed',
            type: 'line',
            source: 'asean-eez-source',
            filter: [
                'all',
                ['==', ['get', 'feature_type'], 'boundary_line'],
                ['==', ['get', 'delimitation_status'], 'DISPUTED']
            ],
            layout: {
                'visibility': this.activeLayers.aseanEez ? 'visible' : 'none',
                'line-cap': 'round',
                'line-join': 'round'
            },
            paint: {
                'line-color': aseanLineColor,
                'line-opacity': 0.70,
                'line-dasharray': [2, 2],
                'line-width': eezLineWidth
            }
        });

        // 3d. JOINT REGIME Lines (Dash-Dotted Country Line)
        this.map.addLayer({
            id: 'asean-eez-line-joint',
            type: 'line',
            source: 'asean-eez-source',
            filter: [
                'all',
                ['==', ['get', 'feature_type'], 'boundary_line'],
                ['==', ['get', 'delimitation_status'], 'JOINT_REGIME']
            ],
            layout: {
                'visibility': this.activeLayers.aseanEez ? 'visible' : 'none',
                'line-cap': 'round',
                'line-join': 'round'
            },
            paint: {
                'line-color': aseanLineColor,
                'line-opacity': 0.70,
                'line-dasharray': [4, 2],
                'line-width': eezLineWidth
            }
        });

        // 3e. UNKNOWN / NOT VERIFIED Lines (Dotted Country Line)
        this.map.addLayer({
            id: 'asean-eez-line-unknown',
            type: 'line',
            source: 'asean-eez-source',
            filter: [
                'all',
                ['==', ['get', 'feature_type'], 'boundary_line'],
                ['==', ['get', 'delimitation_status'], 'UNKNOWN']
            ],
            layout: {
                'visibility': this.activeLayers.aseanEez ? 'visible' : 'none',
                'line-cap': 'round',
                'line-join': 'round'
            },
            paint: {
                'line-color': aseanLineColor,
                'line-opacity': 0.70,
                'line-dasharray': [1, 2],
                'line-width': eezLineWidth
            }
        });
    }

    addAustraliaEezLayer() {
        if (this.map.getSource('australia-eez-source')) return;

        this.map.addSource('australia-eez-source', {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: [] }
        });

        // 1. Polygon Fill for Australia EEZ (internal geometry, zero opacity)
        this.map.addLayer({
            id: 'australia-eez-fill-layer',
            type: 'fill',
            source: 'australia-eez-source',
            filter: ['==', ['get', 'feature_type'], 'zone'],
            layout: { visibility: this.activeLayers.australiaEez ? 'visible' : 'none' },
            paint: {
                'fill-color': '#0284c7',
                'fill-opacity': 0.0
            }
        });

        // Distinct Red-Orange color (#ef4444) for Australia EEZ - highly distinguishable from ASEAN countries
        const australiaLineColor = '#ef4444';
        const australiaLineWidth = [
            'interpolate', ['linear'], ['zoom'],
            2, 0.8,
            4, 1.0,
            6, 1.2,
            10, 1.8
        ];

        // 2. Subtle Zone Outline
        this.map.addLayer({
            id: 'australia-eez-polygon-outline-layer',
            type: 'line',
            source: 'australia-eez-source',
            filter: ['==', ['get', 'feature_type'], 'zone'],
            layout: { visibility: this.activeLayers.australiaEez ? 'visible' : 'none' },
            paint: {
                'line-color': australiaLineColor,
                'line-width': 0.8,
                'line-opacity': 0.25
            }
        });

        // 3a. Bilateral Maritime Treaty Boundaries (Solid Red-Orange Line)
        this.map.addLayer({
            id: 'australia-eez-line-treaty',
            type: 'line',
            source: 'australia-eez-source',
            filter: [
                'all',
                ['==', ['get', 'feature_type'], 'boundary_line'],
                ['==', ['get', 'delimitation_status'], 'AGREED']
            ],
            layout: {
                'visibility': this.activeLayers.australiaEez ? 'visible' : 'none',
                'line-cap': 'round',
                'line-join': 'round'
            },
            paint: {
                'line-color': australiaLineColor,
                'line-opacity': 0.70,
                'line-width': australiaLineWidth
            }
        });

        // 3b. Outer 200 NM EEZ Limit Lines (Dashed Red-Orange Line)
        this.map.addLayer({
            id: 'australia-eez-line-200nm',
            type: 'line',
            source: 'australia-eez-source',
            filter: [
                'all',
                ['==', ['get', 'feature_type'], 'boundary_line'],
                ['==', ['get', 'delimitation_status'], '200NM_LIMIT']
            ],
            layout: {
                'visibility': this.activeLayers.australiaEez ? 'visible' : 'none',
                'line-cap': 'round',
                'line-join': 'round'
            },
            paint: {
                'line-color': australiaLineColor,
                'line-opacity': 0.70,
                'line-dasharray': [4, 2],
                'line-width': australiaLineWidth
            }
        });

        // 3c. Median Lines / Equidistant Boundaries (Dashed Amber/Orange Line)
        this.map.addLayer({
            id: 'australia-eez-line-median',
            type: 'line',
            source: 'australia-eez-source',
            filter: [
                'all',
                ['==', ['get', 'feature_type'], 'boundary_line'],
                ['==', ['get', 'delimitation_status'], 'UNDELIMITED']
            ],
            layout: {
                'visibility': this.activeLayers.australiaEez ? 'visible' : 'none',
                'line-cap': 'round',
                'line-join': 'round'
            },
            paint: {
                'line-color': australiaLineColor,
                'line-opacity': 0.70,
                'line-dasharray': [3, 2],
                'line-width': australiaLineWidth
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
            // Helper to generate crisp canvas ImageBitmap for MapLibre
            const size = 32;

            // 1. Fishing Custom Directional Triangle with fishing trawler silhouette detail
            const createFishingIcon = (isStale = false) => {
                const canvas = document.createElement('canvas');
                canvas.width = size;
                canvas.height = size;
                const ctx = canvas.getContext('2d');
                if (!ctx) return null;

                const fillColor = isStale ? '#92400e' : '#F59E0B'; // Muted dark amber if stale, bright amber #F59E0B if live
                const outlineColor = '#FFFFFF';

                // Outer high-contrast dark drop-shadow/backing for dark/light basemaps
                ctx.beginPath();
                ctx.moveTo(16, 0.5);  // Bow tip
                ctx.lineTo(29.5, 29); // Starboard aft corner
                ctx.lineTo(16, 23.5); // Center inward notch
                ctx.lineTo(2.5, 29);  // Port aft corner
                ctx.closePath();
                ctx.fillStyle = 'rgba(0, 0, 0, 0.65)';
                ctx.fill();
                ctx.lineWidth = 4;
                ctx.strokeStyle = 'rgba(15, 23, 42, 0.85)';
                ctx.stroke();

                // Main Triangle Hull (pointing North/Up)
                ctx.beginPath();
                ctx.moveTo(16, 2);
                ctx.lineTo(28, 27.5);
                ctx.lineTo(16, 22.5);
                ctx.lineTo(4, 27.5);
                ctx.closePath();
                ctx.fillStyle = fillColor;
                ctx.fill();
                ctx.lineWidth = 2.0;
                ctx.strokeStyle = outlineColor;
                ctx.stroke();

                // Fishing detail: Outrigger / trawler boom crossbar line
                ctx.beginPath();
                ctx.moveTo(9, 17);
                ctx.lineTo(23, 17);
                ctx.strokeStyle = '#ffffff';
                ctx.lineWidth = 1.6;
                ctx.lineCap = 'round';
                ctx.stroke();

                // Outrigger stabilizer tip indicators (fishing gear)
                ctx.beginPath();
                ctx.arc(9, 17, 1.2, 0, Math.PI * 2);
                ctx.arc(23, 17, 1.2, 0, Math.PI * 2);
                ctx.fillStyle = '#451a03';
                ctx.fill();

                // Central wheelhouse / deck cabin dot
                ctx.beginPath();
                ctx.arc(16, 13, 2.5, 0, Math.PI * 2);
                ctx.fillStyle = '#1e293b';
                ctx.fill();
                ctx.lineWidth = 1.0;
                ctx.strokeStyle = '#ffffff';
                ctx.stroke();

                // Forward mast / direction centerline
                ctx.beginPath();
                ctx.moveTo(16, 4);
                ctx.lineTo(16, 10);
                ctx.strokeStyle = '#ffffff';
                ctx.lineWidth = 1.4;
                ctx.lineCap = 'round';
                ctx.stroke();

                return ctx.getImageData(0, 0, size, size);
            };

            // 2. Standard Directional Triangle for other vessel categories
            const createStandardTriangleIcon = (fillColor, strokeColor = '#FFFFFF', isStale = false) => {
                const canvas = document.createElement('canvas');
                canvas.width = size;
                canvas.height = size;
                const ctx = canvas.getContext('2d');
                if (!ctx) return null;

                const actualFill = isStale ? '#475569' : fillColor;

                // High-contrast outer stroke / backing
                ctx.beginPath();
                ctx.moveTo(16, 1);
                ctx.lineTo(29, 28);
                ctx.lineTo(16, 23);
                ctx.lineTo(3, 28);
                ctx.closePath();
                ctx.fillStyle = 'rgba(0, 0, 0, 0.55)';
                ctx.fill();
                ctx.lineWidth = 3.5;
                ctx.strokeStyle = 'rgba(15, 23, 42, 0.75)';
                ctx.stroke();

                // Main Triangle Body
                ctx.beginPath();
                ctx.moveTo(16, 2.5);
                ctx.lineTo(27.5, 26.5);
                ctx.lineTo(16, 22);
                ctx.lineTo(4.5, 26.5);
                ctx.closePath();
                ctx.fillStyle = actualFill;
                ctx.fill();
                ctx.lineWidth = 2.0;
                ctx.strokeStyle = strokeColor;
                ctx.stroke();

                // Subtle center dot / mast
                ctx.beginPath();
                ctx.arc(16, 14, 2.0, 0, Math.PI * 2);
                ctx.fillStyle = '#ffffff';
                ctx.fill();

                return ctx.getImageData(0, 0, size, size);
            };

            // Vessel Type Taxonomy mapping per requirement table
            const categories = [
                { id: 'fishing', fill: '#F59E0B', isFishing: true },
                { id: 'cargo', fill: '#2563EB', isFishing: false },
                { id: 'tanker', fill: '#9333EA', isFishing: false },
                { id: 'passenger', fill: '#06B6D4', isFishing: false },
                { id: 'support', fill: '#64748B', isFishing: false },
                { id: 'patrol', fill: '#DC2626', isFishing: false },
                { id: 'military', fill: '#DC2626', isFishing: false },
                { id: 'law_enforcement', fill: '#DC2626', isFishing: false },
                { id: 'unknown', fill: '#94A3B8', isFishing: false },
            ];

            categories.forEach(cat => {
                const liveImg = cat.isFishing
                    ? createFishingIcon(false)
                    : createStandardTriangleIcon(cat.fill, '#FFFFFF', false);

                const staleImg = cat.isFishing
                    ? createFishingIcon(true)
                    : createStandardTriangleIcon(cat.fill, '#E2E8F0', true);

                // Register both heading & cog variants with identical clean triangle iconography
                const names = [
                    `vessel-${cat.id}`,
                    `vessel-${cat.id}-heading`,
                    `vessel-${cat.id}-cog`
                ];

                names.forEach(name => {
                    if (this.map.hasImage(name)) this.map.removeImage(name);
                    if (liveImg) this.map.addImage(name, liveImg, { pixelRatio: 2 });

                    const staleName = `${name}-stale`;
                    if (this.map.hasImage(staleName)) this.map.removeImage(staleName);
                    if (staleImg) this.map.addImage(staleName, staleImg, { pixelRatio: 2 });
                });
            });

            // Global fallbacks
            const defaultLive = createStandardTriangleIcon('#06B6D4', '#FFFFFF', false);
            const defaultStale = createStandardTriangleIcon('#64748B', '#E2E8F0', true);

            ['vessel-heading', 'vessel-cog', 'vessel-oriented-live'].forEach(name => {
                if (this.map.hasImage(name)) this.map.removeImage(name);
                if (defaultLive) this.map.addImage(name, defaultLive, { pixelRatio: 2 });
            });

            ['vessel-heading-stale', 'vessel-cog-stale', 'vessel-oriented-stale'].forEach(name => {
                if (this.map.hasImage(name)) this.map.removeImage(name);
                if (defaultStale) this.map.addImage(name, defaultStale, { pixelRatio: 2 });
            });
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

        // 0. Selected Vessel Highlight Ring (Cincin Sorot Kapal Terpilih)
        this.map.addLayer({
            id: 'vessels-selected-ring',
            type: 'circle',
            source: 'vessels-source',
            filter: ['==', ['get', 'vessel_id'], this.selectedVesselId || ''],
            layout: { visibility: this.activeLayers.vessels ? 'visible' : 'none' },
            paint: {
                'circle-radius': [
                    'case',
                    ['==', ['get', 'vessel_type'], 'fishing'], 20,
                    16
                ],
                'circle-color': [
                    'case',
                    ['==', ['get', 'vessel_type'], 'fishing'], 'rgba(245, 158, 11, 0.30)',
                    'rgba(6, 182, 212, 0.20)'
                ],
                'circle-stroke-color': [
                    'case',
                    ['==', ['get', 'vessel_type'], 'fishing'], '#F59E0B',
                    '#06b6d4'
                ],
                'circle-stroke-width': [
                    'case',
                    ['==', ['get', 'vessel_type'], 'fishing'], 3.5,
                    2.5
                ],
                'circle-stroke-opacity': 0.95,
                'circle-blur': [
                    'case',
                    ['==', ['get', 'vessel_type'], 'fishing'], 0.15,
                    0.0
                ]
            }
        });

        // 1. Individual Vessels with Valid Orientation (Heading or COG)
        this.map.addLayer({
            id: 'vessels-oriented-layer',
            type: 'symbol',
            source: 'vessels-source',
            filter: ['==', ['get', 'has_orientation'], true],
            layout: {
                'icon-image': [
                    'case',
                    ['!=', ['get', 'is_fresh'], true],
                    [
                        'case',
                        ['==', ['get', 'orientation_source'], 'heading'], 'vessel-heading-stale',
                        'vessel-cog-stale'
                    ],
                    [
                        'match',
                        ['get', 'vessel_type'],
                        'fishing', [
                            'case',
                            ['!=', ['get', 'is_fresh'], true], 'vessel-fishing-cog-stale',
                            'vessel-fishing-cog'
                        ],
                        'cargo', [
                            'case',
                            ['!=', ['get', 'is_fresh'], true], 'vessel-cargo-cog-stale',
                            'vessel-cargo-cog'
                        ],
                        'tanker', [
                            'case',
                            ['!=', ['get', 'is_fresh'], true], 'vessel-tanker-cog-stale',
                            'vessel-tanker-cog'
                        ],
                        'passenger', [
                            'case',
                            ['!=', ['get', 'is_fresh'], true], 'vessel-passenger-cog-stale',
                            'vessel-passenger-cog'
                        ],
                        'support', [
                            'case',
                            ['!=', ['get', 'is_fresh'], true], 'vessel-support-cog-stale',
                            'vessel-support-cog'
                        ],
                        'carrier', [
                            'case',
                            ['!=', ['get', 'is_fresh'], true], 'vessel-support-cog-stale',
                            'vessel-support-cog'
                        ],
                        'patrol', [
                            'case',
                            ['!=', ['get', 'is_fresh'], true], 'vessel-patrol-cog-stale',
                            'vessel-patrol-cog'
                        ],
                        'military', [
                            'case',
                            ['!=', ['get', 'is_fresh'], true], 'vessel-military-cog-stale',
                            'vessel-military-cog'
                        ],
                        'law_enforcement', [
                            'case',
                            ['!=', ['get', 'is_fresh'], true], 'vessel-law_enforcement-cog-stale',
                            'vessel-law_enforcement-cog'
                        ],
                        [
                            'case',
                            ['!=', ['get', 'is_fresh'], true], 'vessel-unknown-cog-stale',
                            'vessel-unknown-cog'
                        ]
                    ]
                ],
                'icon-rotate': ['get', 'orientation_degrees'],
                'icon-rotation-alignment': 'map',
                'icon-pitch-alignment': 'map',
                'icon-allow-overlap': true,
                'icon-ignore-placement': true,
                'icon-size': [
                    'interpolate',
                    ['linear'],
                    ['zoom'],
                    2, ['case', ['==', ['get', 'vessel_type'], 'fishing'], 0.60, 0.50],
                    6, ['case', ['==', ['get', 'vessel_type'], 'fishing'], 0.85, 0.70],
                    10, ['case', ['==', ['get', 'vessel_type'], 'fishing'], 1.10, 0.90],
                    14, ['case', ['==', ['get', 'vessel_type'], 'fishing'], 1.40, 1.20],
                    18, ['case', ['==', ['get', 'vessel_type'], 'fishing'], 1.70, 1.50]
                ],
                'visibility': this.activeLayers.vessels ? 'visible' : 'none'
            }
        });

        // 2. Individual Vessels with Direction Unavailable (Neutral Beacon Circle)
        this.map.addLayer({
            id: 'vessels-circle-layer',
            type: 'circle',
            source: 'vessels-source',
            filter: ['!=', ['get', 'has_orientation'], true],
            layout: { visibility: this.activeLayers.vessels ? 'visible' : 'none' },
            paint: {
                'circle-radius': [
                    'interpolate',
                    ['linear'],
                    ['zoom'],
                    2, ['case', ['==', ['get', 'vessel_type'], 'fishing'], 4.5, 3.5],
                    8, ['case', ['==', ['get', 'vessel_type'], 'fishing'], 6.5, 5.0],
                    14, ['case', ['==', ['get', 'vessel_type'], 'fishing'], 9.5, 7.5]
                ],
                'circle-color': [
                    'case',
                    ['!=', ['get', 'is_fresh'], true],
                    '#64748b', // Stale (> 2h)
                    [
                        'match',
                        ['get', 'vessel_type'],
                        'fishing', '#F59E0B', // Amber
                        'cargo', '#2563EB',   // Blue
                        'tanker', '#9333EA',  // Purple
                        'passenger', '#06B6D4', // Cyan
                        'support', '#64748B', // Slate
                        'carrier', '#64748B', // Slate
                        'patrol', '#DC2626',  // Red
                        'military', '#DC2626', // Red
                        'law_enforcement', '#DC2626', // Red
                        '#94A3B8' // Unknown gray
                    ]
                ],
                'circle-stroke-width': [
                    'case',
                    ['==', ['get', 'vessel_type'], 'fishing'], 2.0,
                    1.5
                ],
                'circle-stroke-color': [
                    'case',
                    ['==', ['get', 'vessel_type'], 'fishing'], '#451a03',
                    '#ffffff'
                ]
            }
        });
    }

    addVesselTrackLayers() {
        if (this.map.getSource('vessel-track-source')) return;

        this.map.addSource('vessel-track-source', {
            type: 'geojson',
            data: this.currentTrackGeoJson || { type: 'FeatureCollection', features: [] }
        });

        // 1. Vessel Track Line (LineString)
        this.map.addLayer({
            id: 'vessel-track-line-layer',
            type: 'line',
            source: 'vessel-track-source',
            filter: ['==', ['geometry-type'], 'LineString'],
            layout: {
                'visibility': this.trackVisible ? 'visible' : 'none',
                'line-join': 'round',
                'line-cap': 'round'
            },
            paint: {
                'line-color': '#06b6d4', // cyan-500
                'line-width': 2.5,
                'line-opacity': 0.9,
                'line-dasharray': [2, 1]
            }
        });

        // 2. Intermediate Historical Observation Points
        this.map.addLayer({
            id: 'vessel-track-points-layer',
            type: 'circle',
            source: 'vessel-track-source',
            filter: ['all', ['==', ['geometry-type'], 'Point'], ['!=', ['get', 'is_latest'], true]],
            layout: {
                'visibility': this.trackVisible ? 'visible' : 'none'
            },
            paint: {
                'circle-radius': 3.5,
                'circle-color': '#06b6d4',
                'circle-stroke-color': '#ffffff',
                'circle-stroke-width': 1.0,
                'circle-stroke-opacity': 0.85
            }
        });

        // 2b. Start Observation Point (Departure marker)
        this.map.addLayer({
            id: 'vessel-track-start-point-layer',
            type: 'circle',
            source: 'vessel-track-source',
            filter: ['all', ['==', ['geometry-type'], 'Point'], ['==', ['get', 'is_start'], true], ['!=', ['get', 'is_latest'], true]],
            layout: {
                'visibility': this.trackVisible ? 'visible' : 'none'
            },
            paint: {
                'circle-radius': 5.5,
                'circle-color': '#3b82f6',
                'circle-stroke-color': '#ffffff',
                'circle-stroke-width': 2.0
            }
        });

        // 3. Latest Observation Point (distinct ring)
        this.map.addLayer({
            id: 'vessel-track-latest-point-layer',
            type: 'circle',
            source: 'vessel-track-source',
            filter: ['all', ['==', ['geometry-type'], 'Point'], ['==', ['get', 'is_latest'], true]],
            layout: {
                'visibility': this.trackVisible ? 'visible' : 'none'
            },
            paint: {
                'circle-radius': 6.0,
                'circle-color': '#10b981', // emerald-500
                'circle-stroke-color': '#ffffff',
                'circle-stroke-width': 2.0
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
        // 2. ASEAN EEZ Polygons / Fills (semi-transparent)
        // 3. ASEAN EEZ Boundaries line layers (classified by delimitation status)
        // 4. Overlays & Analysis (SAR scenes footprint, encounters, dark vessel detections)
        // 5. Vessels — strictly topmost (vessels-circle-layer, vessels-oriented-layer)
        const orderedLayers = [
            // 2. Polygons & Fills
            'asean-eez-fill-layer',
            'australia-eez-fill-layer',
            'sar-scenes-fill-layer',

            // 2b. Context outlines
            'asean-eez-polygon-outline-layer',
            'australia-eez-polygon-outline-layer',

            // 3. ASEAN EEZ Boundaries line layers (Status-classified)
            'asean-eez-line-agreed',
            'asean-eez-line-undelimited',
            'asean-eez-line-disputed',
            'asean-eez-line-joint',
            'asean-eez-line-unknown',

            // 3b. Australia EEZ Boundaries line layers
            'australia-eez-line-treaty',
            'australia-eez-line-200nm',
            'australia-eez-line-median',

            // 4. Analysis & Detection layers
            'sar-scenes-line-layer',
            'encounters-circle-layer',
            'dark-vessels-circle-layer',

            // 4b. Vessel Track Line & Observation Points
            'vessel-track-line-layer',
            'vessel-track-points-layer',
            'vessel-track-latest-point-layer',

            // 5. Vessels — strictly topmost individual oriented and neutral markers
            'vessels-selected-ring',
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
        ['vessels-selected-ring', 'vessels-circle-layer', 'vessels-oriented-layer'].forEach((id) => {
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

        // Vessel Track Observation Points Click
        this.map.on('click', 'vessel-track-points-layer', (e) => {
            this.handleTrackPointClick(e);
        });
        this.map.on('click', 'vessel-track-latest-point-layer', (e) => {
            this.handleTrackPointClick(e);
        });

        // SAR Candidate / Dark Vessel Click Popup
        this.map.on('click', 'dark-vessels-circle-layer', (e) => {
            if (!e.features.length) return;
            const props = e.features[0].properties;
            const lat = props.latitude ?? e.lngLat.lat;
            const lng = props.longitude ?? e.lngLat.lng;
            this.showCandidatePopup({ lng: Number(lng), lat: Number(lat) }, props);
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

        // Cursor pointer styling on hover (interactive layers only; maritime boundaries are strictly non-interactive)
        const clickableLayers = [
            'encounters-circle-layer',
            'vessels-oriented-layer',
            'vessels-circle-layer',
            'dark-vessels-circle-layer',
            'sar-scenes-fill-layer',
            'vessel-track-points-layer',
            'vessel-track-start-point-layer',
            'vessel-track-latest-point-layer'
        ];
        clickableLayers.forEach((layerId) => {
            this.map.on('mouseenter', layerId, () => {
                if (this.map.getCanvas()) this.map.getCanvas().style.cursor = 'pointer';
            });
            this.map.on('mouseleave', layerId, () => {
                if (this.map.getCanvas()) this.map.getCanvas().style.cursor = '';
            });
        });

        // Vessel Identity Hover Tooltip (Interactive, non-blocking)
        let vesselHoverPopup = null;
        const showVesselHoverTooltip = (e) => {
            if (!e.features || !e.features.length) return;
            const props = e.features[0].properties || {};
            const coords = e.features[0].geometry?.coordinates || [e.lngLat.lng, e.lngLat.lat];
            const name = props.name || 'Unknown Vessel';
            const mmsi = props.mmsi || '-';
            const vType = (props.vessel_type || 'unknown').toLowerCase();
            const isFishing = vType === 'fishing';
            const flagEmoji = props.flag_emoji || '🌐';
            const sog = props.speed_knots !== null && props.speed_knots !== undefined && isFinite(Number(props.speed_knots))
                ? `${Number(props.speed_knots).toFixed(1)} kn`
                : null;
            const cog = props.course_degrees !== null && props.course_degrees !== undefined && isFinite(Number(props.course_degrees))
                ? `${Number(props.course_degrees).toFixed(1)}°`
                : null;

            if (!vesselHoverPopup) {
                vesselHoverPopup = new maplibregl.Popup({
                    closeButton: false,
                    closeOnClick: false,
                    offset: 14,
                    className: 'vessel-hover-tooltip pointer-events-none'
                });
            }

            const badgeHtml = isFishing
                ? `<span class="px-1.5 py-0.2 rounded text-[9px] font-bold uppercase bg-[#F59E0B]/20 text-[#F59E0B] border border-[#F59E0B]/60 shadow-[0_0_8px_rgba(245,158,11,0.4)]">🎣 Fishing</span>`
                : `<span class="px-1.5 py-0.2 rounded text-[9px] font-semibold uppercase bg-slate-800 text-slate-300 border border-slate-700">${vType}</span>`;

            const html = `
                <div class="px-2.5 py-1.5 rounded-xl bg-slate-950/95 border ${isFishing ? 'border-[#F59E0B]/60 shadow-[0_0_14px_rgba(245,158,11,0.35)]' : 'border-slate-700/80 shadow-xl'} text-xs font-mono backdrop-blur-md space-y-1 pointer-events-none min-w-[160px]">
                    <div class="flex items-center gap-1.5 font-bold ${isFishing ? 'text-[#F59E0B]' : 'text-white'}">
                        <span>${flagEmoji}</span>
                        <span class="truncate max-w-[150px] font-sans">${name}</span>
                    </div>
                    <div class="flex items-center justify-between gap-2 text-[10px] text-slate-300">
                        ${badgeHtml}
                        <span class="text-slate-400">MMSI: <strong class="text-white">${mmsi}</strong></span>
                    </div>
                    ${sog ? `<div class="text-[10px] text-slate-400 flex items-center justify-between border-t border-slate-800/80 pt-0.5">
                        <span>SOG: <strong class="${isFishing ? 'text-[#F59E0B]' : 'text-sky-300'} font-bold">${sog}</strong></span>
                        ${cog ? `<span>COG: <strong class="text-slate-300">${cog}</strong></span>` : ''}
                    </div>` : ''}
                </div>
            `;

            vesselHoverPopup.setLngLat(coords).setHTML(html).addTo(this.map);
        };

        const hideVesselHoverTooltip = () => {
            if (vesselHoverPopup) {
                vesselHoverPopup.remove();
                vesselHoverPopup = null;
            }
        };

        ['vessels-oriented-layer', 'vessels-circle-layer'].forEach((layerId) => {
            this.map.on('mouseenter', layerId, (e) => {
                showVesselHoverTooltip(e);
            });
            this.map.on('mousemove', layerId, (e) => {
                showVesselHoverTooltip(e);
            });
            this.map.on('mouseleave', layerId, () => {
                hideVesselHoverTooltip();
            });
        });

        // Click on historical track observation points
        const handleTrackPointInspect = (e) => {
            if (!e.features || !e.features.length) return;
            const feat = e.features[0];
            const props = feat.properties || {};
            const coords = feat.geometry?.coordinates || [e.lngLat.lng, e.lngLat.lat];
            const dateStr = props.observed_at ? new Date(props.observed_at).toUTCString() : 'Unavailable';
            const sog = props.speed_knots !== null && props.speed_knots !== undefined ? `${props.speed_knots} kn` : '-';
            const cog = props.course_degrees !== null && props.course_degrees !== undefined ? `${props.course_degrees}°` : '-';
            const heading = props.heading_degrees !== null && props.heading_degrees !== undefined ? `${props.heading_degrees}°` : '-';
            const pointType = props.is_start ? '🚀 Departure Origin' : (props.is_latest ? '📍 Latest Position' : '🛰️ AIS Observation Point');

            const html = `
                <div class="space-y-1 text-xs font-mono p-1">
                    <div class="font-bold text-cyan-300 flex items-center justify-between border-b border-cyan-800/80 pb-1">
                        <span>${pointType}</span>
                        <span class="text-[10px] text-slate-400">${props.name || props.mmsi || ''}</span>
                    </div>
                    <div class="text-slate-300">Waktu: <strong class="text-white">${dateStr}</strong></div>
                    <div class="text-slate-300">Posisi: <span class="text-slate-200">${coords[1].toFixed(5)}°, ${coords[0].toFixed(5)}°</span></div>
                    <div class="flex items-center gap-3 text-slate-300 pt-0.5">
                        <span>SOG: <strong class="text-emerald-300">${sog}</strong></span>
                        <span>COG: <strong class="text-sky-300">${cog}</strong></span>
                        <span>HDG: <strong class="text-teal-300">${heading}</strong></span>
                    </div>
                </div>
            `;
            this.showPopup({ lng: coords[0], lat: coords[1] }, html);
        };

        this.map.on('click', 'vessel-track-points-layer', handleTrackPointInspect);
        this.map.on('click', 'vessel-track-start-point-layer', handleTrackPointInspect);
        this.map.on('click', 'vessel-track-latest-point-layer', handleTrackPointInspect);

        // Delegate "Show Track" click inside vessel popup
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('.btn-popup-show-track');
            if (btn) {
                e.preventDefault();
                const vId = btn.getAttribute('data-vessel-id');
                const mmsi = btn.getAttribute('data-mmsi');
                this.showVesselTrack(vId || mmsi, { fitBounds: true });
            }
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

        this.currentPopup.on('close', () => {
            this.setSelectedVessel(null);
        });
    }

    handleVesselClick(e) {
        if (!e.features || !e.features.length) return;
        const feat = e.features[0];
        const props = feat.properties;
        const coords = feat.geometry?.coordinates || [e.lngLat.lng, e.lngLat.lat];
        if (props.vessel_id) {
            this.setSelectedVessel(props.vessel_id);
        }
        if (props.mmsi) {
            this.selectedMmsi = props.mmsi;
        }

        const panel = document.getElementById('vesselDetailPanel');
        if (panel) {
            this.openVesselDetailPanel(props, { lng: coords[0], lat: coords[1] });
        } else {
            this.showVesselPopup(props, { lng: coords[0], lat: coords[1] });
        }

        if (typeof this.onVesselSelected === 'function') {
            this.onVesselSelected(props, { lng: coords[0], lat: coords[1] });
        }

        this.updateUrlHash();
    }

    openVesselDetailPanel(props, coords) {
        const panel = document.getElementById('vesselDetailPanel');
        if (!panel) return;

        this.selectedVesselId = props.vessel_id || null;
        this.selectedMmsi = props.mmsi || null;
        this.selectedVesselCoords = coords;

        if (this.map && this.map.getLayer('vessels-selected-ring')) {
            this.map.setFilter('vessels-selected-ring', ['==', ['get', 'vessel_id'], this.selectedVesselId || '']);
        }

        const isFresh = props.is_fresh === true || props.is_fresh === 'true';
        const nameEl = document.getElementById('panelVesselName');
        const flagEmojiEl = document.getElementById('panelVesselFlagEmoji');
        const flagNameEl = document.getElementById('panelVesselFlagName');
        const flagFullEl = document.getElementById('panelVesselFlagFull');
        const typeBadgeEl = document.getElementById('panelVesselTypeBadge');
        const typeDescEl = document.getElementById('panelVesselTypeDesc');
        const freshnessBadgeEl = document.getElementById('panelVesselFreshnessBadge');
        const mmsiEl = document.getElementById('panelVesselMmsi');
        const imoEl = document.getElementById('panelVesselImo');
        const callsignEl = document.getElementById('panelVesselCallsign');
        const sogEl = document.getElementById('panelVesselSog');
        const cogEl = document.getElementById('panelVesselCog');
        const headingEl = document.getElementById('panelVesselHeading');
        const navStatusEl = document.getElementById('panelVesselNavStatus');
        const coordsEl = document.getElementById('panelVesselCoords');
        const orientationEl = document.getElementById('panelVesselOrientation');
        const timeEl = document.getElementById('panelVesselTime');
        const providerEl = document.getElementById('panelVesselProvider');
        const profileLink = document.getElementById('panelVesselProfileLink');

        if (nameEl) nameEl.textContent = props.name || 'Unknown Vessel';
        if (flagEmojiEl) flagEmojiEl.textContent = props.flag_emoji || '🌐';
        if (flagNameEl) flagNameEl.textContent = `${props.flag_country || 'Flag state unknown'} (${props.flag_state || 'UN'})`;
        if (flagFullEl) flagFullEl.textContent = `${props.flag_emoji || '🌐'} ${props.flag_country || 'Unknown'} (${props.flag_state || 'UN'})`;

        const vType = props.vessel_type || 'unknown';
        if (typeBadgeEl) {
            typeBadgeEl.textContent = vType;
            if (vType === 'fishing') {
                typeBadgeEl.className = 'px-1.5 py-0.2 rounded text-[9px] font-bold uppercase bg-[#F59E0B]/20 text-[#F59E0B] border border-[#F59E0B]/60 shadow-[0_0_10px_rgba(245,158,11,0.35)]';
            } else if (vType === 'passenger') {
                typeBadgeEl.className = 'px-1.5 py-0.2 rounded text-[9px] font-bold uppercase bg-sky-950 text-sky-300 border border-sky-700';
            } else if (vType === 'military' || vType === 'patrol') {
                typeBadgeEl.className = 'px-1.5 py-0.2 rounded text-[9px] font-bold uppercase bg-rose-950 text-rose-300 border border-rose-700';
            } else {
                typeBadgeEl.className = 'px-1.5 py-0.2 rounded text-[9px] font-bold uppercase bg-slate-800 text-slate-300 border border-slate-700';
            }
        }
        if (typeDescEl) typeDescEl.textContent = vType;

        if (freshnessBadgeEl) {
            if (isFresh) {
                freshnessBadgeEl.className = 'px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/40';
                freshnessBadgeEl.innerHTML = '● LIVE (&lt; 2h)';
            } else {
                freshnessBadgeEl.className = 'px-2 py-0.5 rounded text-[10px] font-bold bg-slate-800 text-amber-300 border border-amber-500/30';
                freshnessBadgeEl.innerHTML = '⏳ STALE';
            }
        }

        if (mmsiEl) mmsiEl.textContent = props.mmsi || 'Not available';
        if (imoEl) imoEl.textContent = props.imo || 'Not available';
        if (callsignEl) callsignEl.textContent = props.callsign || 'Not available';

        const sogVal = props.speed_knots !== null && props.speed_knots !== undefined && isFinite(Number(props.speed_knots))
            ? Number(props.speed_knots).toFixed(1)
            : 'Not available';
        if (sogEl) sogEl.textContent = sogVal;

        const cogVal = props.course_degrees !== null && props.course_degrees !== undefined && isFinite(Number(props.course_degrees))
            ? `${Number(props.course_degrees).toFixed(1)}°`
            : 'Not available';
        if (cogEl) cogEl.textContent = cogVal;

        const headingVal = props.heading_degrees !== null && props.heading_degrees !== undefined && isFinite(Number(props.heading_degrees))
            ? `${Number(props.heading_degrees).toFixed(1)}°`
            : 'Not available';
        if (headingEl) headingEl.textContent = headingVal;

        if (navStatusEl) navStatusEl.textContent = (props.nav_status || 'under_way').replace(/_/g, ' ');

        const cLng = coords?.lng !== undefined ? coords.lng : (coords ? coords[0] : 0);
        const cLat = coords?.lat !== undefined ? coords.lat : (coords ? coords[1] : 0);
        if (coordsEl) coordsEl.textContent = `${Number(cLat).toFixed(5)}°, ${Number(cLng).toFixed(5)}°`;

        let oriText = 'Unknown';
        if (props.orientation_source === 'heading') oriText = `🧭 True Heading (${headingVal})`;
        else if (props.orientation_source === 'cog') oriText = `⚓ COG Fallback (${cogVal})`;
        if (orientationEl) orientationEl.textContent = oriText;

        if (timeEl) {
            const d = props.observed_at ? new Date(props.observed_at) : null;
            timeEl.textContent = d ? d.toUTCString() : 'Not available';
            timeEl.title = props.observed_at || '';
        }

        // Relative AIS age
        const ageEl = document.getElementById('panelVesselAge');
        if (ageEl) {
            if (props.observed_at) {
                const diffSec = Math.floor((Date.now() - new Date(props.observed_at).getTime()) / 1000);
                if (diffSec < 60) ageEl.textContent = 'Just now';
                else if (diffSec < 3600) ageEl.textContent = `${Math.floor(diffSec / 60)} min ago`;
                else if (diffSec < 86400) ageEl.textContent = `${Math.floor(diffSec / 3600)} h ago`;
                else ageEl.textContent = `${Math.floor(diffSec / 86400)} d ago`;
            } else {
                ageEl.textContent = 'Not available';
            }
        }

        if (providerEl) providerEl.textContent = props.provider || 'AISStream Gateway';

        // Anomaly / Dark Vessel Correlation
        const darkStatusEl = document.getElementById('panelVesselDarkVesselStatus');
        if (darkStatusEl) {
            if (props.dark_vessel_candidate || props.is_dark_vessel || props.anomaly_flag) {
                darkStatusEl.innerHTML = '<span class="text-amber-400 font-bold">⚠️ Anomaly Indicated</span>';
            } else {
                darkStatusEl.textContent = 'No anomalies detected';
            }
        }

        // Encounter Records
        const encStatusEl = document.getElementById('panelVesselEncounterStatus');
        if (encStatusEl) {
            if (props.encounter_count && Number(props.encounter_count) > 0) {
                encStatusEl.innerHTML = `<span class="text-amber-400 font-bold">⚠️ ${props.encounter_count} Events</span>`;
            } else {
                encStatusEl.textContent = '0 events detected';
            }
        }

        // SAR Scene Correlation
        const sarStatusEl = document.getElementById('panelVesselSarStatus');
        if (sarStatusEl) {
            if (props.sar_scene_id || props.sar_correlated) {
                sarStatusEl.innerHTML = `<span class="text-purple-400 font-bold">🛰️ Correlated Sentinel-1</span>`;
            } else {
                sarStatusEl.textContent = 'No imagery correlation';
            }
        }

        // Quick action: Center Vessel
        const btnCenterVessel = document.getElementById('panelBtnCenterVessel');
        if (btnCenterVessel) {
            btnCenterVessel.onclick = () => {
                const lng = coords?.lng !== undefined ? coords.lng : (coords ? coords[0] : 0);
                const lat = coords?.lat !== undefined ? coords.lat : (coords ? coords[1] : 0);
                if (lng && lat) {
                    this.map.flyTo({ center: [Number(lng), Number(lat)], zoom: Math.max(this.map.getZoom(), 8) });
                }
            };
        }

        // Quick action: Copy MMSI
        const btnCopyMmsi = document.getElementById('panelBtnCopyMmsi');
        if (btnCopyMmsi) {
            btnCopyMmsi.onclick = () => {
                if (props.mmsi && navigator.clipboard) {
                    navigator.clipboard.writeText(String(props.mmsi)).then(() => {
                        const orig = btnCopyMmsi.innerHTML;
                        btnCopyMmsi.innerHTML = '<span>✓</span> <span>Tersalin!</span>';
                        setTimeout(() => { btnCopyMmsi.innerHTML = orig; }, 1800);
                    });
                }
            };
        }

        if (profileLink) {
            if (props.vessel_id) {
                profileLink.href = `/vessels/${props.vessel_id}`;
                profileLink.style.display = 'flex';
            } else {
                profileLink.style.display = 'none';
            }
        }

        panel.classList.remove('hidden');

        // Automatically load track for selected vessel
        this.showVesselTrack(props.vessel_id || props.mmsi, { range: this.activeTrackRange, fitBounds: false });
        this.updateUrlHash();
    }

    closeVesselDetailPanel() {
        const panel = document.getElementById('vesselDetailPanel');
        if (panel) panel.classList.add('hidden');
        this.setSelectedVessel(null);
        this.selectedMmsi = null;
        this.updateUrlHash();
    }

    async selectVesselByIdentifier(identifier) {
        if (!identifier) return;
        const idStr = String(identifier).trim();

        if (this.layerData.vessels && Array.isArray(this.layerData.vessels.features)) {
            const feat = this.layerData.vessels.features.find(f =>
                f.properties?.mmsi === idStr ||
                f.properties?.vessel_id === idStr ||
                f.id === idStr
            );
            if (feat) {
                const coords = feat.geometry?.coordinates || [0, 0];
                this.setSelectedVessel(feat.properties.vessel_id);
                this.selectedMmsi = feat.properties.mmsi;
                this.openVesselDetailPanel(feat.properties, { lng: coords[0], lat: coords[1] });
                return;
            }
        }

        try {
            const res = await fetch(`/api/map/search?q=${encodeURIComponent(idStr)}`);
            if (res.ok) {
                const data = await res.json();
                const vesselResult = data.results?.find(r => r.type === 'vessel' && (r.vessel_id == idStr || r.subtitle?.includes(idStr)));
                if (vesselResult && isFinite(vesselResult.lat) && isFinite(vesselResult.lng)) {
                    this.focusEntity('vessel', vesselResult.id, vesselResult.lat, vesselResult.lng);
                    this.showVesselTrack(vesselResult.id, { range: this.activeTrackRange, fitBounds: true });
                }
            }
        } catch (e) {
            console.warn('[GlobalMap] Failed to select vessel from URL identifier:', e);
        }
    }

    setSelectedVessel(vesselId) {
        this.selectedVesselId = vesselId || null;
        if (this.map && this.map.getLayer('vessels-selected-ring')) {
            this.map.setFilter('vessels-selected-ring', ['==', ['get', 'vessel_id'], this.selectedVesselId || '']);
        }
    }

    setSelectedVesselByMmsi(mmsi) {
        if (!mmsi) {
            this.setSelectedVessel(null);
            return;
        }
        this.selectedVesselId = String(mmsi);
        if (this.map && this.map.getLayer('vessels-selected-ring')) {
            this.map.setFilter('vessels-selected-ring', ['==', ['get', 'mmsi'], String(mmsi)]);
        }
    }

    showVesselPopup(props, lngLat) {
        const isFresh = props.is_fresh === true || props.is_fresh === 'true';
        const freshnessBadge = isFresh
            ? `<span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/40">● LIVE (&lt; 2h)</span>`
            : `<span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-slate-800 text-amber-300 border border-amber-500/30">⏳ KEDALUWARSA</span>`;

        const dateStr = props.observed_at ? new Date(props.observed_at).toUTCString() : 'Unavailable';
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

        // Format Heading, COG, SOG semantically and strictly
        const hasHeading = props.heading_degrees !== null && props.heading_degrees !== undefined && props.heading_degrees !== '' && isFinite(Number(props.heading_degrees));
        const hasCog = props.course_degrees !== null && props.course_degrees !== undefined && props.course_degrees !== '' && isFinite(Number(props.course_degrees));
        const hasSog = props.speed_knots !== null && props.speed_knots !== undefined && props.speed_knots !== '' && isFinite(Number(props.speed_knots));

        const headingDisplay = hasHeading ? `${Number(props.heading_degrees).toFixed(1)}°` : '<span class="text-slate-400 italic">Unavailable</span>';
        const cogDisplay = hasCog ? `${Number(props.course_degrees).toFixed(1)}°` : '<span class="text-slate-400 italic">Unavailable</span>';
        const sogDisplay = hasSog ? `${Number(props.speed_knots).toFixed(1)} kn` : '<span class="text-slate-400 italic">Unavailable</span>';

        let orientationSourceBadge = '<span class="text-slate-400 font-semibold">Unavailable</span>';
        if (props.orientation_source === 'heading' || (!props.orientation_source && props.orientation_type === 'heading')) {
            orientationSourceBadge = `<span class="text-emerald-300 font-bold">🧭 Heading (${headingDisplay})</span>`;
        } else if (props.orientation_source === 'cog' || (!props.orientation_source && (props.orientation_type === 'cog' || hasCog))) {
            orientationSourceBadge = `<span class="text-sky-300 font-bold">⚓ COG fallback (${cogDisplay})</span>`;
        }

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
                    <div><span class="text-slate-400">Type:</span> ${props.vessel_type === 'fishing' ? '<span class="text-[#F59E0B] font-bold uppercase">🎣 Fishing</span>' : `<span class="text-slate-300 capitalize">${props.vessel_type || 'vessel'}</span>`}</div>
                    <div><span class="text-slate-400">Speed (SOG):</span> <strong class="${props.vessel_type === 'fishing' ? 'text-[#F59E0B]' : 'text-sky-300'}">${sogDisplay}</strong></div>
                    <div><span class="text-slate-400">Course (COG):</span> <strong class="text-sky-300">${cogDisplay}</strong></div>
                    <div><span class="text-slate-400">True Heading:</span> <strong class="text-emerald-300">${headingDisplay}</strong></div>
                    <div><span class="text-slate-400">Nav Status:</span> <span class="text-slate-300 capitalize">${props.nav_status || 'under_way'}</span></div>
                </div>
                <div class="p-1 rounded bg-slate-900 border border-slate-800 text-[10px] font-mono flex items-center justify-between">
                    <span class="text-slate-400">Orientation Source:</span>
                    <div>${orientationSourceBadge}</div>
                </div>
                <div class="text-[10px] font-mono text-slate-400">
                    Position: <span class="text-slate-200">${coordsStr}</span>
                </div>
                <div class="text-[10px] text-slate-400 pt-1 border-t border-slate-800 flex justify-between">
                    <span>Time: ${dateStr}</span>
                    <span>${props.provider || 'AIS Live Gateway'}</span>
                </div>
                <div class="pt-2 border-t border-slate-800 flex items-center justify-between gap-1.5">
                    <button type="button" class="btn-popup-show-track px-2 py-1 rounded bg-cyan-500/20 hover:bg-cyan-500/30 text-cyan-300 border border-cyan-500/40 font-mono text-[10px] font-bold flex items-center gap-1 transition"
                            data-vessel-id="${props.vessel_id || ''}" data-mmsi="${props.mmsi || ''}" title="Display AIS track history for this vessel">
                        <span>🗺️</span>
                        <span>Show Track</span>
                    </button>
                    ${props.vessel_id ? `
                    <a href="/vessels/${props.vessel_id}" class="text-[11px] text-sky-400 hover:text-sky-300 font-semibold underline truncate">
                        Profile &rarr;
                    </a>` : ''}
                </div>
            </div>
        `;
        this.showPopup(lngLat, html);
    }

    handleViewportChange() {
        clearTimeout(this.debounceTimer);
        this.debounceTimer = setTimeout(() => {
            this.refreshDynamicActiveLayers();
        }, 300);
    }

    getViewportBBoxString() {
        if (!this.map) return null;
        try {
            const bounds = this.map.getBounds();
            if (!bounds) return null;
            let west = bounds.getWest();
            let south = bounds.getSouth();
            let east = bounds.getEast();
            let north = bounds.getNorth();

            // When zoomed out to global view, clamp to valid coordinates
            if (east - west >= 360) {
                west = -180;
                east = 180;
            } else {
                west = Math.max(-180, Math.min(180, west));
                east = Math.max(-180, Math.min(180, east));
            }
            south = Math.max(-90, Math.min(90, south));
            north = Math.max(-90, Math.min(90, north));

            return `${west.toFixed(4)},${south.toFixed(4)},${east.toFixed(4)},${north.toFixed(4)}`;
        } catch (e) {
            return null;
        }
    }

    toggleLayer(layerKey, visible) {
        this.activeLayers[layerKey] = visible;

        const layerMap = {
            aseanEez: [
                'asean-eez-fill-layer',
                'asean-eez-polygon-outline-layer',
                'asean-eez-line-agreed',
                'asean-eez-line-undelimited',
                'asean-eez-line-disputed',
                'asean-eez-line-joint',
                'asean-eez-line-unknown'
            ],
            australiaEez: [
                'australia-eez-fill-layer',
                'australia-eez-polygon-outline-layer',
                'australia-eez-line-treaty',
                'australia-eez-line-200nm',
                'australia-eez-line-median'
            ],
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
        this.updateActiveLayerCount();
    }

    refreshDynamicActiveLayers() {
        const dynamicLayers = ['vessels', 'darkVessels', 'encounters', 'sarScenes'];
        dynamicLayers.forEach((key) => {
            if (this.activeLayers[key]) {
                this.fetchLayerData(key);
            }
        });
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
            aseanEez: `/api/layers/asean-eez?bbox=${bbox || ''}`,
            australiaEez: `/api/layers/australia-eez?bbox=${bbox || ''}`,
            encounters: `/api/layers/encounters?bbox=${bbox || ''}`,
            vessels: `/api/layers/vessels?bbox=${bbox || ''}`,
            darkVessels: `/api/layers/dark-vessels?bbox=${bbox || ''}`,
            sarScenes: `/api/layers/sar-scenes?bbox=${bbox || ''}`,
        };

        const sources = {
            aseanEez: 'asean-eez-source',
            australiaEez: 'australia-eez-source',
            encounters: 'encounters-source',
            vessels: 'vessels-source',
            darkVessels: 'dark-vessels-source',
            sarScenes: 'sar-scenes-source',
        };

        const sourceId = sources[layerKey];
        if (!sourceId || !this.map || !this.map.getSource(sourceId)) return;

        // Cancel previous pending request for this layer to prevent out-of-order race conditions
        if (this.layerAbortControllers && this.layerAbortControllers[layerKey]) {
            this.layerAbortControllers[layerKey].abort();
        }
        const controller = new AbortController();
        if (this.layerAbortControllers) {
            this.layerAbortControllers[layerKey] = controller;
        }

        if (!options.silent) {
            this.setLoadingBadge(layerKey, true);
        }

        try {
            let requestUrl = urls[layerKey];
            const params = new URLSearchParams();

            if (layerKey === 'vessels') {
                const searchVal = (this.filters.search || '').trim();
                const searchInput = document.getElementById('inputVesselSearch');
                const effectiveSearch = searchVal || (searchInput ? searchInput.value.trim() : '');
                if (effectiveSearch) params.append('q', effectiveSearch);

                if (this.filters.flag) params.append('flag', this.filters.flag);
                if (this.filters.vesselType) params.append('vessel_type', this.filters.vesselType);
                if (this.filters.minSog !== null && this.filters.minSog !== '' && isFinite(this.filters.minSog)) params.append('min_sog', this.filters.minSog);
                if (this.filters.maxSog !== null && this.filters.maxSog !== '' && isFinite(this.filters.maxSog)) params.append('max_sog', this.filters.maxSog);
                if (this.filters.minCog !== null && this.filters.minCog !== '' && isFinite(this.filters.minCog)) params.append('min_cog', this.filters.minCog);
                if (this.filters.maxCog !== null && this.filters.maxCog !== '' && isFinite(this.filters.maxCog)) params.append('max_cog', this.filters.maxCog);
                if (this.filters.headingStatus) params.append('heading_status', this.filters.headingStatus);
                if (this.filters.freshness) params.append('freshness', this.filters.freshness);
                if (this.filters.dateRange && this.filters.dateRange !== 'all') params.append('date_range', this.filters.dateRange);
                if (this.filters.startDate) params.append('start_date', this.filters.startDate);
                if (this.filters.endDate) params.append('end_date', this.filters.endDate);
            } else if (layerKey === 'darkVessels') {
                const searchVal = (this.filters.search || '').trim();
                const searchInput = document.getElementById('inputVesselSearch');
                const effectiveSearch = searchVal || (searchInput ? searchInput.value.trim() : '');
                if (effectiveSearch) params.append('q', effectiveSearch);

                if (this.filters.reviewStatus) params.append('review_status', this.filters.reviewStatus);
                if (this.filters.correlationStatus) params.append('correlation_status', this.filters.correlationStatus);
                if (this.filters.minTcr !== null && this.filters.minTcr !== '' && isFinite(this.filters.minTcr)) params.append('min_tcr', this.filters.minTcr);
                if (this.filters.maxTcr !== null && this.filters.maxTcr !== '' && isFinite(this.filters.maxTcr)) params.append('max_tcr', this.filters.maxTcr);
                if (this.filters.platform) params.append('platform', this.filters.platform);
                if (this.filters.dateRange && this.filters.dateRange !== 'all') params.append('date_range', this.filters.dateRange);
                if (this.filters.startDate) params.append('start_date', this.filters.startDate);
                if (this.filters.endDate) params.append('end_date', this.filters.endDate);
            }

            const qs = params.toString();
            if (qs) {
                requestUrl += (requestUrl.includes('?') ? '&' : '?') + qs;
            }

            const res = await fetch(requestUrl, { signal: controller.signal });
            if (!res.ok) throw new Error(`HTTP Error ${res.status}`);
            const geojson = await res.json();

            // Validate coordinates before updating map (supports Points, Lines, and Polygons)
            if (geojson && Array.isArray(geojson.features)) {
                geojson.features = geojson.features.filter((f) => {
                    if (!f.geometry || !Array.isArray(f.geometry.coordinates)) return false;
                    if (f.geometry.type === 'Point') {
                        return this.isValidCoord(f.geometry.coordinates[0], f.geometry.coordinates[1]);
                    }
                    return f.geometry.coordinates.length > 0;
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
            if (err.name === 'AbortError') {
                return; // Superseded by a newer request, silent exit
            }
            console.error(`[GlobalMapController] Error loading layer ${layerKey}:`, err);
            if (!options.silent) {
                this.showLayerError(layerKey);
            }
        } finally {
            if (this.layerAbortControllers && this.layerAbortControllers[layerKey] === controller) {
                delete this.layerAbortControllers[layerKey];
                if (!options.silent) {
                    this.setLoadingBadge(layerKey, false);
                }
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

    updateActiveLayerCount() {
        const badge = document.getElementById('badgeLayerCount') || document.getElementById('badgeActiveLayers');
        if (badge) {
            const count = Object.values(this.activeLayers).filter(Boolean).length;
            badge.textContent = count;
        }
    }

    updateLayerCountBadge(layerKey, count) {
        const badge = document.getElementById(`count-${layerKey}`);
        if (badge) {
            badge.innerText = count.toLocaleString();
            badge.classList.remove('text-red-400');
        }
        if (layerKey === 'vessels') {
            const statusCount = document.getElementById('statusVesselCount');
            if (statusCount) {
                statusCount.innerText = `${count.toLocaleString()} vessels`;
            }
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
        } else if (type === 'asean-eez' || type === 'aseanEez' || type === 'eez' || type === 'eez-boundaries' || type === 'boundaries') {
            sourceId = 'asean-eez-source';
            layerKey = 'aseanEez';
        } else if (type === 'australia-eez' || type === 'australiaEez') {
            sourceId = 'australia-eez-source';
            layerKey = 'australiaEez';
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

    /**
     * ASEAN EEZ Delimitation Inspector Popup
     * Non-interactive: popups and tooltips disabled per task requirements.
     */
    showAseanEezPopup(lngLat, props) {
        // Disabled: Maritime boundaries are strictly non-interactive lines
        return;
    }

    /**
     * Enforces English-only country labels across basemap vector symbol layers.
     * Replaces multi-language concatenation or local script fallbacks with English-first expressions.
     */
    enforceEnglishCountryLabels() {
        if (!this.map || !this.map.getStyle) return;

        let style;
        try {
            style = this.map.getStyle();
        } catch (e) {
            return;
        }

        if (!style || !Array.isArray(style.layers)) return;

        const englishCountryTextField = [
            'coalesce',
            ['get', 'name:en'],
            ['get', 'name_en'],
            ['get', 'name:latin'],
            ['get', 'name']
        ];

        style.layers.forEach((layer) => {
            if (layer.type !== 'symbol') return;
            const layerId = layer.id || '';
            const lowerId = layerId.toLowerCase();

            // Specifically target country label symbol layers (e.g. label_country_1/2/3, place_country_1/2, country-label)
            // Exclude state, city, town, village, road, or other non-country layers
            if (lowerId.includes('state') || lowerId.includes('city') || lowerId.includes('town') || 
                lowerId.includes('village') || lowerId.includes('road') || lowerId.includes('other')) {
                return;
            }

            let isCountryLayer = false;
            if (lowerId.includes('country')) {
                isCountryLayer = true;
            } else if (layer.filter && Array.isArray(layer.filter)) {
                const str = JSON.stringify(layer.filter).replace(/\s+/g, '');
                if (str.includes('["==",["get","class"],"country"]') || str.includes('["==","class","country"]')) {
                    isCountryLayer = true;
                }
            }

            if (isCountryLayer) {
                try {
                    this.map.setLayoutProperty(layerId, 'text-field', englishCountryTextField);
                } catch (e) {
                    // Ignore transient layer state
                }
            }
        });
    }

    showCandidatePopup(lngLat, props) {
        if (!lngLat || !props) return;
        const lat = props.latitude ?? lngLat.lat;
        const lng = props.longitude ?? lngLat.lng;
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
                    <div>Coordinates: <span class="text-teal-300">${Number(lat).toFixed(4)}°, ${Number(lng).toFixed(4)}°</span></div>
                    <div>Peak TCR: <span class="text-emerald-300">${props.tcr_db !== null && props.tcr_db !== undefined ? props.tcr_db + ' dB' : '-'}</span></div>
                    <div>Radar Dimensions: <span class="text-white">${props.estimated_length_meters || '-'}m &times; ${props.estimated_width_meters || '-'}m</span></div>
                    <div>Review Status: <span class="text-amber-300">${reviewBadge}</span></div>
                </div>
                ${props.scene_id ? `<div class="text-[10px] font-mono text-slate-400 truncate">Scene: ${props.scene_id}</div>` : ''}
                <div class="text-[10px] font-mono text-slate-400">Acquisition Time: ${props.detected_at ? props.detected_at.replace('T', ' ').substring(0, 19) : '-'} UTC</div>

                ${isInsufficient ? `
                <div class="text-[10px] text-amber-300/90 bg-amber-950/60 p-2 rounded border border-amber-800/40 leading-snug">
                    <strong>INSUFFICIENT AIS COVERAGE:</strong> Absence of AIS telemetry in this maritime zone does not confirm that the detected object is an illicit dark vessel.
                </div>` : ''}

                <div class="text-[9px] text-slate-400 italic">
                    SAR Detection &ne; Vessel Confirmation &bull; AIS Absence &ne; Dark Vessel Confirmation.
                </div>

                <div class="pt-1 text-right">
                    <a href="/dark-vessels/${props.id}" class="text-[11px] text-teal-400 hover:text-teal-300 font-bold underline">Open Evidence Dossier &rarr;</a>
                </div>
            </div>
        `;
        this.showPopup(lngLat, html);
    }

    /**
     * Apply Advanced Maritime Filters to Map
     */
    applyFilters(newFilters = {}) {
        this.filters = {
            ...this.filters,
            ...newFilters,
        };

        this.updateActiveFilterCountBadge();

        if (this.activeLayers.vessels) {
            this.fetchLayerData('vessels');
        }
        if (this.activeLayers.darkVessels) {
            this.fetchLayerData('darkVessels');
        }
    }

    /**
     * Reset Filters to Clean Baseline Defaults
     */
    resetFilters() {
        this.filters = {
            search: '',
            flag: '',
            vesselType: '',
            minSog: null,
            maxSog: null,
            minCog: null,
            maxCog: null,
            headingStatus: '',
            freshness: '',
            reviewStatus: '',
            correlationStatus: '',
            minTcr: null,
            maxTcr: null,
            platform: '',
            dateRange: 'all',
            startDate: '',
            endDate: '',
            trackWindow: 'off',
        };

        const searchInput = document.getElementById('inputVesselSearch');
        if (searchInput) searchInput.value = '';

        this.updateActiveFilterCountBadge();

        if (this.activeLayers.vessels) {
            this.fetchLayerData('vessels');
        }
        if (this.activeLayers.darkVessels) {
            this.fetchLayerData('darkVessels');
        }
    }

    /**
     * Count How Many Non-Default Filters are Active
     */
    countActiveFilters() {
        let count = 0;
        if (this.filters.search && this.filters.search.trim()) count++;
        if (this.filters.flag) count++;
        if (this.filters.vesselType) count++;
        if (this.filters.minSog !== null && this.filters.minSog !== '') count++;
        if (this.filters.maxSog !== null && this.filters.maxSog !== '') count++;
        if (this.filters.minCog !== null && this.filters.minCog !== '') count++;
        if (this.filters.maxCog !== null && this.filters.maxCog !== '') count++;
        if (this.filters.headingStatus) count++;
        if (this.filters.freshness) count++;
        if (this.filters.reviewStatus) count++;
        if (this.filters.correlationStatus) count++;
        if (this.filters.minTcr !== null && this.filters.minTcr !== '') count++;
        if (this.filters.maxTcr !== null && this.filters.maxTcr !== '') count++;
        if (this.filters.platform) count++;
        if (this.filters.dateRange && this.filters.dateRange !== 'all') count++;
        if (this.filters.startDate) count++;
        if (this.filters.endDate) count++;
        if (this.filters.trackWindow && this.filters.trackWindow !== 'off') count++;
        return count;
    }

    updateActiveFilterCountBadge() {
        const count = this.countActiveFilters();
        this.activeFilterCount = count;
        const badges = document.querySelectorAll('.active-filter-badge');
        badges.forEach((b) => {
            if (count > 0) {
                b.innerText = count;
                b.classList.remove('hidden');
            } else {
                b.innerText = '0';
                b.classList.add('hidden');
            }
        });
    }

    /**
     * Focus on an entity (Vessel or SAR Candidate) from Search Results
     */
    focusEntity(type, id, lat, lng) {
        if (!this.map || !this.isValidCoord(lng, lat)) return;

        this.map.flyTo({
            center: [lng, lat],
            zoom: 8.5,
            essential: true
        });

        if (type === 'vessel') {
            this.setSelectedVessel(id);

            let feat = null;
            if (this.layerData.vessels && Array.isArray(this.layerData.vessels.features)) {
                feat = this.layerData.vessels.features.find(f => (f.properties?.vessel_id == id || f.properties?.mmsi == id || f.id == id));
            }

            const panel = document.getElementById('vesselDetailPanel');
            if (panel) {
                const props = feat ? feat.properties : { vessel_id: id, mmsi: id, name: `Vessel ${id}` };
                this.openVesselDetailPanel(props, { lng, lat });
            } else if (feat) {
                this.showVesselPopup({ lngLat: { lng, lat }, features: [feat] });
            }
        } else if (type === 'sar_candidate') {
            if (this.layerData.darkVessels && Array.isArray(this.layerData.darkVessels.features)) {
                const feat = this.layerData.darkVessels.features.find(f => (f.properties?.id == id || f.id == id));
                if (feat) {
                    this.showCandidatePopup({ lng, lat }, feat.properties);
                }
            }
        }
    }

    /**
     * Handle click on historical track observation points
     */
    handleTrackPointClick(e) {
        if (!e.features || !e.features.length) return;
        const feat = e.features[0];
        const props = feat.properties || {};
        const coords = feat.geometry?.coordinates || [e.lngLat.lng, e.lngLat.lat];
        const dateStr = props.observed_at ? new Date(props.observed_at).toUTCString() : 'Not available';
        const isLatest = props.is_latest === true || props.is_latest === 'true';

        const sogStr = (props.speed_knots !== null && props.speed_knots !== undefined && props.speed_knots !== '' && isFinite(Number(props.speed_knots)))
            ? `${Number(props.speed_knots).toFixed(1)} kn`
            : '<span class="text-slate-500 italic">Not available</span>';
        const cogStr = (props.course_degrees !== null && props.course_degrees !== undefined && props.course_degrees !== '' && isFinite(Number(props.course_degrees)))
            ? `${Number(props.course_degrees).toFixed(1)}°`
            : '<span class="text-slate-500 italic">Not available</span>';
        const headingStr = (props.heading_degrees !== null && props.heading_degrees !== undefined && props.heading_degrees !== '' && isFinite(Number(props.heading_degrees)))
            ? `${Number(props.heading_degrees).toFixed(1)}°`
            : '<span class="text-slate-500 italic">Not available</span>';

        const badge = isLatest
            ? '<span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/40">● LATEST POSITION</span>'
            : '<span class="px-1.5 py-0.5 rounded text-[10px] font-mono bg-cyan-500/20 text-cyan-300 border border-cyan-500/30">HISTORICAL POINT</span>';

        const html = `
            <div class="space-y-1.5 text-xs font-sans max-w-xs">
                <div class="flex items-center justify-between border-b border-cyan-800 pb-1">
                    <span class="font-bold text-cyan-300 flex items-center gap-1 truncate">
                        <span>📍</span>
                        <span class="truncate">${props.name || 'AIS Vessel'}</span>
                    </span>
                    ${badge}
                </div>
                <div class="grid grid-cols-2 gap-1.5 text-[11px] font-mono">
                    <div>MMSI: <strong class="text-white">${props.mmsi || '-'}</strong></div>
                    <div>SOG: <strong class="text-cyan-300">${sogStr}</strong></div>
                    <div>COG: <strong class="text-cyan-300">${cogStr}</strong></div>
                    <div>Heading: <strong class="text-emerald-300">${headingStr}</strong></div>
                </div>
                <div class="text-[10px] font-mono text-slate-400">
                    <div>Time: <strong class="text-slate-200">${dateStr}</strong></div>
                    <div>Coordinates: ${coords[1].toFixed(4)}°, ${coords[0].toFixed(4)}°</div>
                </div>
            </div>
        `;
        this.showPopup({ lng: coords[0], lat: coords[1] }, html);
    }

    /**
     * Show AIS historical track line and points for a vessel (Tahap 11)
     */
    async showVesselTrack(vesselIdentifier, options = {}) {
        if (!vesselIdentifier) return;
        const fitBounds = options.fitBounds !== false;
        const idStr = String(vesselIdentifier);
        const range = options.range || this.activeTrackRange || '24h';
        this.activeTrackRange = range;

        // Prevent race conditions when user switches vessels quickly
        this.activeTrackVesselId = idStr;
        this.notifyTrackStatus('loading', `Loading historical AIS track (${range})...`);

        try {
            const res = await fetch(`/api/vessels/${encodeURIComponent(idStr)}/track?range=${encodeURIComponent(range)}`);
            if (!res.ok) {
                if (res.status === 404) {
                    this.notifyTrackStatus('error', `Vessel identifier '${idStr}' not found.`);
                } else {
                    this.notifyTrackStatus('error', `Failed to load AIS track (HTTP ${res.status}).`);
                }
                return;
            }

            // Stale check
            if (this.activeTrackVesselId !== idStr) return;

            const geoJson = await res.json();
            this.currentTrackGeoJson = geoJson;

            const src = this.map ? this.map.getSource('vessel-track-source') : null;
            if (src && typeof src.setData === 'function') {
                src.setData(geoJson);
            }

            this.setTrackVisibility(true);

            const count = geoJson.properties?.position_count ?? 0;
            const vesselName = geoJson.properties?.name || geoJson.properties?.mmsi || idStr;

            if (count === 0) {
                this.notifyTrackStatus('warning', `No historical AIS track available (${range}) for ${vesselName}.`);
            } else if (count === 1) {
                this.notifyTrackStatus('info', `Only 1 AIS position available for ${vesselName} (single point).`);
            } else {
                this.notifyTrackStatus('success', `AIS Track (${range}): ${vesselName} (${count} points).`);
            }

            // Update badge in side panel if present
            const panelTrackBadge = document.getElementById('panelTrackPointBadge');
            if (panelTrackBadge) {
                panelTrackBadge.textContent = `${count} points`;
            }

            if (fitBounds && count > 0) {
                this.fitVesselTrack();
            }

            if (typeof this.onTrackUpdated === 'function') {
                this.onTrackUpdated(geoJson);
            }
        } catch (err) {
            console.error('[GlobalMap] Error loading vessel track:', err);
            this.notifyTrackStatus('error', 'Network error while loading vessel track.');
        }
    }

    /**
     * Set visibility of vessel track line and point layers
     */
    setTrackVisibility(visible) {
        this.trackVisible = visible;
        const val = visible ? 'visible' : 'none';
        ['vessel-track-line-layer', 'vessel-track-points-layer', 'vessel-track-start-point-layer', 'vessel-track-latest-point-layer'].forEach(id => {
            if (this.map && this.map.getLayer(id)) {
                this.map.setLayoutProperty(id, 'visibility', val);
            }
        });
        if (typeof this.onTrackVisibilityChanged === 'function') {
            this.onTrackVisibilityChanged(visible);
        }
    }

    hideVesselTrack() {
        this.setTrackVisibility(false);
    }

    showTrackLayer() {
        this.setTrackVisibility(true);
    }

    /**
     * Fit map view to the current active vessel track bounds safely
     */
    fitVesselTrack() {
        if (!this.map || !this.currentTrackGeoJson) return;

        const coords = [];
        const features = this.currentTrackGeoJson.features || [];

        features.forEach(f => {
            if (f.geometry?.type === 'LineString' && Array.isArray(f.geometry.coordinates)) {
                f.geometry.coordinates.forEach(c => {
                    if (Array.isArray(c) && isFinite(c[0]) && isFinite(c[1])) {
                        coords.push(c);
                    }
                });
            } else if (f.geometry?.type === 'Point' && Array.isArray(f.geometry.coordinates)) {
                const c = f.geometry.coordinates;
                if (isFinite(c[0]) && isFinite(c[1])) {
                    coords.push(c);
                }
            }
        });

        if (!coords.length) return;

        if (coords.length === 1) {
            this.map.flyTo({
                center: coords[0],
                zoom: 12,
                essential: true
            });
            return;
        }

        let minLng = coords[0][0], maxLng = coords[0][0];
        let minLat = coords[0][1], maxLat = coords[0][1];

        coords.forEach(([lng, lat]) => {
            if (lng < minLng) minLng = lng;
            if (lng > maxLng) maxLng = lng;
            if (lat < minLat) minLat = lat;
            if (lat > maxLat) maxLat = lat;
        });

        if (minLng === maxLng && minLat === maxLat) {
            this.map.flyTo({ center: [minLng, minLat], zoom: 12, essential: true });
        } else {
            this.map.fitBounds([[minLng, minLat], [maxLng, maxLat]], {
                padding: { top: 90, bottom: 90, left: 90, right: 90 },
                maxZoom: 15,
                duration: 1200
            });
        }
    }

    /**
     * Clear and reset active vessel track visual from map (UI selection only, non-destructive)
     */
    clearVesselTrack() {
        this.activeTrackVesselId = null;
        this.currentTrackGeoJson = null;

        const src = this.map ? this.map.getSource('vessel-track-source') : null;
        if (src && typeof src.setData === 'function') {
            src.setData({ type: 'FeatureCollection', features: [] });
        }

        this.setTrackVisibility(true);

        if (typeof this.onTrackUpdated === 'function') {
            this.onTrackUpdated(null);
        }
    }

    notifyTrackStatus(type, message) {
        if (typeof this.onTrackStatus === 'function') {
            this.onTrackStatus(type, message);
        }
    }

    isValidCoord(lng, lat) {
        return typeof lng === 'number' && typeof lat === 'number' &&
               isFinite(lng) && isFinite(lat) &&
               lng >= -180 && lng <= 180 &&
               lat >= -90 && lat <= 90;
    }
}
