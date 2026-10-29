@extends('layouts.app')

@section('header_title', 'Global Vessel Map — MapLibre GL JS Spatial Platform')

@section('content')
<div class="relative h-[calc(100vh-8.5rem)] min-h-[580px] rounded-2xl overflow-hidden border border-slate-800 shadow-2xl flex flex-col bg-slate-950">

    <!-- Top Floating Toolbar: Quick Actions, Search & Fit Controls -->
    <div class="absolute top-4 left-4 right-4 z-10 flex flex-wrap items-center justify-between gap-3 pointer-events-none">
        
        <!-- Search & View Navigation Controls -->
        <div class="pointer-events-auto flex flex-wrap items-center gap-2 p-1.5 rounded-xl glass-panel border border-slate-700/80 shadow-lg text-xs">
            <div class="relative">
                <input type="text" id="inputVesselSearch" placeholder="Cari kapal, MMSI, IMO, Callsign..." 
                    class="pl-7 pr-3 py-1.5 rounded-lg bg-slate-900 border border-slate-700 text-slate-200 placeholder-slate-500 focus:outline-none focus:border-teal-400 w-52 sm:w-64 text-xs font-mono">
                <span class="absolute left-2.5 top-1.5 text-slate-400">🔍</span>
            </div>

            <button type="button" id="btnFilterSearch" class="px-3 py-1.5 rounded-lg bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold transition">
                Cari
            </button>

            <!-- Quick Fit Buttons -->
            <button type="button" id="btnFitVessels" class="px-2.5 py-1.5 rounded-lg bg-slate-900 hover:bg-slate-800 text-sky-300 font-medium transition border border-slate-700 flex items-center gap-1" title="Pusatkan peta ke armada kapal terdeteksi">
                <span>🚢</span>
                <span>Fit Vessels</span>
            </button>

            <button type="button" id="btnFitEez" class="px-2.5 py-1.5 rounded-lg bg-slate-900 hover:bg-slate-800 text-teal-300 font-medium transition border border-slate-700 flex items-center gap-1" title="Pusatkan peta ke batas World EEZ v12">
                <span>🛡️</span>
                <span>Fit EEZ</span>
            </button>

            <button type="button" id="btnFitFao" class="px-2.5 py-1.5 rounded-lg bg-slate-900 hover:bg-slate-800 text-blue-300 font-medium transition border border-slate-700 flex items-center gap-1" title="Pusatkan peta ke wilayah statistik FAO">
                <span>🌐</span>
                <span>Fit FAO</span>
            </button>

            <button type="button" id="btnFitFma" class="px-2.5 py-1.5 rounded-lg bg-slate-900 hover:bg-slate-800 text-purple-300 font-medium transition border border-slate-700 flex items-center gap-1" title="Pusatkan peta ke area pengelolaan perikanan RFMO/RFB">
                <span>🏛️</span>
                <span>Fit RFMO</span>
            </button>

            <button type="button" id="btnFitSarCandidates" class="px-2.5 py-1.5 rounded-lg bg-slate-900 hover:bg-slate-800 text-amber-300 font-medium transition border border-slate-700 flex items-center gap-1">
                <span>🎯</span>
                <span>Fit SAR Candidates</span>
            </button>

            <button type="button" id="btnFitSarScene" class="px-2.5 py-1.5 rounded-lg bg-slate-900 hover:bg-slate-800 text-purple-300 font-medium transition border border-slate-700 flex items-center gap-1">
                <span>🛰️</span>
                <span>Fit SAR Scene</span>
            </button>

            <button type="button" id="btnResetView" class="px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 font-medium transition border border-slate-700">
                Reset View
            </button>
        </div>

        <!-- Coverage & Metadata Modal Trigger -->
        <div class="pointer-events-auto flex items-center gap-2 p-1.5 rounded-xl glass-panel border border-slate-700/80 shadow-lg text-[11px]">
            <span class="text-slate-400 font-semibold px-2">Basemap: <strong class="text-teal-300">ESRI Dark Gray</strong> (EPSG:4326)</span>
            <button type="button" id="btnOpenMetadataModal" class="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-teal-300 border border-slate-700 transition">
                📜 Provenance & Legal
            </button>
        </div>
    </div>

    <!-- Map Canvas Container -->
    <div id="global-map-canvas" class="w-full h-full flex-1 bg-slate-950 min-h-[480px]"></div>

    <!-- Right Floating "Map Layers & Legend" Control Panel -->
    <div class="absolute top-20 right-4 z-10 w-76 max-h-[calc(100%-6rem)] overflow-y-auto glass-panel p-4 rounded-2xl border border-slate-700/80 shadow-2xl space-y-4 text-xs">
        
        <div class="flex items-center justify-between border-b border-slate-700/80 pb-2">
            <h3 class="font-bold uppercase tracking-wider text-white flex items-center gap-1.5">
                <span>🎛️</span>
                <span>Maritime Layers</span>
            </h3>
            <span class="text-[10px] text-teal-400 font-mono">Live Control</span>
        </div>

        <!-- Layer Groups -->
        <div class="space-y-3">
            
            <!-- GROUP: AIS LIVE -->
            <div class="space-y-1.5">
                <div class="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Telemetri Kapal AIS</div>
                
                <label class="flex items-start justify-between p-2 rounded-xl bg-slate-900/80 border border-slate-800 hover:border-sky-500/50 transition cursor-pointer">
                    <div class="flex items-start gap-2.5">
                        <input type="checkbox" id="toggle-layer-vessels" checked class="mt-0.5 rounded bg-slate-800 border-slate-700 text-sky-500 focus:ring-sky-400">
                        <div>
                            <div class="font-bold text-slate-200 flex items-center gap-1.5">
                                <span class="w-2.5 h-2.5 rounded-full bg-sky-400"></span>
                                <span>Live AIS Vessels</span>
                            </div>
                            <p class="text-[10px] text-slate-400 mt-0.5">Telemetri kapal broadcast aktif</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <span id="loading-vessels" class="text-[10px] text-sky-400 animate-spin" style="display: none;">⏳</span>
                        <span id="count-vessels" class="text-[10px] font-mono px-1.5 py-0.5 rounded bg-slate-800 text-sky-300 border border-sky-900">0</span>
                    </div>
                </label>

                <label class="flex items-start justify-between p-2 rounded-xl bg-slate-900/80 border border-slate-800 hover:border-amber-500/50 transition cursor-pointer">
                    <div class="flex items-start gap-2.5">
                        <input type="checkbox" id="toggle-layer-encounters" checked class="mt-0.5 rounded bg-slate-800 border-slate-700 text-amber-500 focus:ring-amber-400">
                        <div>
                            <div class="font-bold text-slate-200 flex items-center gap-1.5">
                                <span class="w-2.5 h-2.5 rounded-full bg-amber-400"></span>
                                <span>Vessel Encounters</span>
                            </div>
                            <p class="text-[10px] text-slate-400 mt-0.5">Deteksi kedekatan pasangan kapal</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <span id="loading-encounters" class="text-[10px] text-amber-400 animate-spin" style="display: none;">⏳</span>
                        <span id="count-encounters" class="text-[10px] font-mono px-1.5 py-0.5 rounded bg-slate-800 text-amber-300 border border-amber-900">0</span>
                    </div>
                </label>
            </div>

            <!-- GROUP: SENTINEL-1 SAR -->
            <div class="space-y-1.5 pt-1 border-t border-slate-800">
                <div class="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Citra Satelit Radar SAR</div>

                <label class="flex items-start justify-between p-2 rounded-xl bg-slate-900/80 border border-slate-800 hover:border-amber-500/50 transition cursor-pointer">
                    <div class="flex items-start gap-2.5">
                        <input type="checkbox" id="toggle-layer-dark-vessels" checked class="mt-0.5 rounded bg-slate-800 border-slate-700 text-amber-500 focus:ring-amber-400">
                        <div>
                            <div class="font-bold text-slate-200 flex items-center gap-1.5">
                                <span class="w-2.5 h-2.5 rounded-full bg-amber-400 border border-amber-300"></span>
                                <span>SAR Candidates (54)</span>
                            </div>
                            <p class="text-[10px] text-slate-400 mt-0.5">Anomali hamburan radar Sentinel-1</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <span id="loading-darkVessels" class="text-[10px] text-amber-400 animate-spin" style="display: none;">⏳</span>
                        <span id="count-darkVessels" class="text-[10px] font-mono px-1.5 py-0.5 rounded bg-slate-800 text-amber-300 border border-amber-900">0</span>
                    </div>
                </label>

                <label class="flex items-start justify-between p-2 rounded-xl bg-slate-900/80 border border-slate-800 hover:border-purple-500/50 transition cursor-pointer">
                    <div class="flex items-start gap-2.5">
                        <input type="checkbox" id="toggle-layer-sar-scenes" checked class="mt-0.5 rounded bg-slate-800 border-slate-700 text-purple-500 focus:ring-purple-400">
                        <div>
                            <div class="font-bold text-slate-200 flex items-center gap-1.5">
                                <span class="w-2.5 h-2.5 rounded-sm bg-purple-500 border border-purple-300"></span>
                                <span>SAR Footprint Scene</span>
                            </div>
                            <p class="text-[10px] text-slate-400 mt-0.5">Poligon cakupan radar Copernicus</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <span id="loading-sarScenes" class="text-[10px] text-purple-400 animate-spin" style="display: none;">⏳</span>
                        <span id="count-sarScenes" class="text-[10px] font-mono px-1.5 py-0.5 rounded bg-slate-800 text-purple-300 border border-purple-900">0</span>
                    </div>
                </label>
            </div>

            <!-- GROUP: MARITIME CONTEXT -->
            <div class="space-y-1.5 pt-1 border-t border-slate-800">
                <div class="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Konteks Geospasial Maritim</div>

                <!-- EEZ Boundaries (Official Treaty & 200NM Lines) -->
                <label class="flex items-start justify-between p-2 rounded-xl bg-slate-900/80 border border-slate-800 hover:border-slate-600 transition cursor-pointer">
                    <div class="flex items-start gap-2.5">
                        <input type="checkbox" id="toggle-layer-eez-boundaries" checked class="mt-0.5 rounded bg-slate-800 border-slate-700 text-slate-400 focus:ring-slate-400">
                        <div>
                            <div class="font-bold text-slate-200 flex items-center gap-1.5">
                                <span class="w-3 h-0.5 bg-slate-400"></span>
                                <span>EEZ Boundaries</span>
                            </div>
                            <p class="text-[10px] text-slate-400 mt-0.5">Garis batas maritim resmi (Treaty & 200NM)</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <span id="loading-eezBoundaries" class="text-[10px] text-slate-400 animate-spin" style="display: none;">⏳</span>
                        <span id="count-eezBoundaries" class="text-[10px] font-mono px-1.5 py-0.5 rounded bg-slate-800 text-slate-300 border border-slate-700">0</span>
                    </div>
                </label>

                <!-- EEZ Zones (Polygons / Fill) -->
                <label class="flex items-start justify-between p-2 rounded-xl bg-slate-900/80 border border-slate-800 hover:border-teal-500/50 transition cursor-pointer">
                    <div class="flex items-start gap-2.5">
                        <input type="checkbox" id="toggle-layer-eez" checked class="mt-0.5 rounded bg-slate-800 border-slate-700 text-teal-500 focus:ring-teal-400">
                        <div>
                            <div class="font-bold text-slate-200 flex items-center gap-1.5">
                                <span class="w-2.5 h-2.5 rounded-sm bg-teal-500/20 border border-teal-500"></span>
                                <span>World EEZ Zones (Poligon)</span>
                            </div>
                            <p class="text-[10px] text-slate-400 mt-0.5">Maritime Boundaries 200NM (VLIZ v12)</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <span id="loading-eez" class="text-[10px] text-teal-400 animate-spin" style="display: none;">⏳</span>
                        <span id="count-eez" class="text-[10px] font-mono px-1.5 py-0.5 rounded bg-slate-800 text-teal-300 border border-teal-900">0</span>
                    </div>
                </label>

                <label class="flex items-start justify-between p-2 rounded-xl bg-slate-900/80 border border-slate-800 hover:border-blue-500/50 transition cursor-pointer">
                    <div class="flex items-start gap-2.5">
                        <input type="checkbox" id="toggle-layer-fao" checked class="mt-0.5 rounded bg-slate-800 border-slate-700 text-blue-500 focus:ring-blue-400">
                        <div>
                            <div class="font-bold text-slate-200 flex items-center gap-1.5">
                                <span class="w-2.5 h-2.5 rounded-sm bg-blue-500/40 border border-blue-400 border-dashed"></span>
                                <span>FAO Major Fishing Areas</span>
                            </div>
                            <p class="text-[10px] text-slate-400 mt-0.5">Wilayah Statistik Perikanan Global</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <span id="loading-fao" class="text-[10px] text-blue-400 animate-spin" style="display: none;">⏳</span>
                        <span id="count-fao" class="text-[10px] font-mono px-1.5 py-0.5 rounded bg-slate-800 text-blue-300 border border-blue-900">0</span>
                    </div>
                </label>

                <label class="flex items-start justify-between p-2 rounded-xl bg-slate-900/80 border border-slate-800 hover:border-purple-500/50 transition cursor-pointer">
                    <div class="flex items-start gap-2.5">
                        <input type="checkbox" id="toggle-layer-fma" checked class="mt-0.5 rounded bg-slate-800 border-slate-700 text-purple-500 focus:ring-purple-400">
                        <div>
                            <div class="font-bold text-slate-200 flex items-center gap-1.5">
                                <span class="w-2.5 h-2.5 rounded-sm bg-purple-500/40 border border-purple-400 border-dotted"></span>
                                <span>Fisheries Management (RFMO)</span>
                            </div>
                            <p class="text-[10px] text-slate-400 mt-0.5">Kompetensi Pengelolaan PBB/FAO</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <span id="loading-fma" class="text-[10px] text-purple-400 animate-spin" style="display: none;">⏳</span>
                        <span id="count-fma" class="text-[10px] font-mono px-1.5 py-0.5 rounded bg-slate-800 text-purple-300 border border-purple-900">0</span>
                    </div>
                </label>
            </div>
        </div>

        <!-- Dynamic Maritime Intelligence Legend -->
        <div class="pt-3 border-t border-slate-800 space-y-2 text-[11px]">
            <div class="font-bold text-slate-300 uppercase tracking-wider text-[10px] flex items-center justify-between">
                <span>Legenda Simbologi Ilmiah</span>
                <span class="text-teal-400 font-mono text-[9px]">WGS84</span>
            </div>
            <div class="grid grid-cols-2 gap-2 text-slate-300 text-[10px] font-mono">
                <div class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-sky-400"></span> Live AIS Vessel</div>
                <div class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-emerald-500"></span> Matched AIS</div>
                <div class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-amber-500"></span> Possible Vessel</div>
                <div class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-cyan-400"></span> Possible Ice/Clutter</div>
                <div class="flex items-center gap-1.5"><span class="w-3 h-0.5 bg-slate-400"></span> EEZ Boundaries</div>
                <div class="flex items-center gap-1.5"><span class="w-2.5 h-2 bg-teal-500/20 border border-teal-500"></span> EEZ Zones (Fill)</div>
                <div class="flex items-center gap-1.5"><span class="w-2.5 h-0.5 bg-amber-400"></span> AIS Track</div>
                <div class="flex items-center gap-1.5"><span class="w-2.5 h-2 bg-purple-500/30 border border-purple-400"></span> SAR Footprint</div>
            </div>
        </div>

        <!-- Scientific Invariants Caution -->
        <div class="pt-2 border-t border-slate-800 text-[9px] text-slate-400 leading-relaxed bg-slate-950/80 p-2.5 rounded-xl border border-slate-800 space-y-1">
            <div class="font-bold text-slate-300 uppercase">Prinsip Validitas Forensik:</div>
            <div>&bull; SAR Detection &ne; Vessel Confirmation</div>
            <div>&bull; AIS Absence &ne; Dark Vessel Confirmation</div>
        </div>

    </div>

</div>

<!-- Data Sources & Provenance Modal -->
<div id="metadataModal" class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm hidden items-center justify-center p-4">
    <div class="glass-panel w-full max-w-xl p-6 rounded-2xl border border-slate-700 shadow-2xl space-y-4 text-xs">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <h3 class="text-sm font-bold text-white uppercase tracking-wider flex items-center gap-2">
                <span>📜</span>
                <span>Sumber Data, Lisensi & Atribusi Geospasial</span>
            </h3>
            <button type="button" id="btnCloseMetadataModal" class="text-slate-400 hover:text-white text-lg font-bold">&times;</button>
        </div>

        <div class="space-y-3 max-h-[60vh] overflow-y-auto pr-2 text-slate-300">
            <div class="p-3 rounded-xl bg-slate-900 border border-slate-800 space-y-1">
                <div class="font-bold text-teal-300">1. ESRI World Dark Gray Canvas Basemap</div>
                <div>Sumber: Esri, DeLorme, NAVTEQ, USGS, FAO, NOAA</div>
                <div>Lisensi: Esri Master License Agreement / Open Web Map Service</div>
                <div class="text-[11px] text-slate-400 italic">Peta kanvas abu-abu gelap dengan kontras tinggi khusus visualisasi maritim.</div>
            </div>

            <div class="p-3 rounded-xl bg-slate-900 border border-slate-800 space-y-1">
                <div class="font-bold text-purple-300">2. Sentinel-1 C-SAR Radar Scenes (Copernicus)</div>
                <div>Sumber: European Space Agency (ESA) &bull; Copernicus Data Space Ecosystem</div>
                <div>Lisensi: Copernicus Sentinel Data Legal Notice (Open Access)</div>
                <div class="text-[11px] text-slate-400 italic">Cakupan citra SAR C-band Synthetic Aperture Radar untuk deteksi anomali maritim.</div>
            </div>

            <div class="p-3 rounded-xl bg-slate-900 border border-slate-800 space-y-1">
                <div class="font-bold text-indigo-300">3. FAO Major Fishing Areas for Statistical Purposes</div>
                <div>Sumber: FAO Fisheries and Aquaculture Division &bull; FAO GeoNetwork</div>
                <div>Lisensi: FAO Open Access Terms / CC-BY-NC-SA 3.0 IGO</div>
                <div class="text-[11px] text-slate-400 italic">Dataset resmi untuk tujuan agregasi statistik, asesmen stok, dan pelaporan perikanan internasional.</div>
            </div>

            <div class="p-3 rounded-xl bg-slate-900 border border-slate-800 space-y-1">
                <div class="font-bold text-teal-300">4. Maritime Boundaries and Exclusive Economic Zones (200NM)</div>
                <div>Sumber: Flanders Marine Institute (VLIZ) Marine Regions v12</div>
                <div>Lisensi: Creative Commons Attribution 4.0 International (CC BY 4.0)</div>
                <div class="text-[11px] text-slate-400 italic">Batas yurisdiksi maritim negara pantai sesuai konvensi hukum laut UNCLOS 1982.</div>
            </div>
        </div>

        <div class="pt-3 border-t border-slate-800 text-right">
            <button type="button" id="btnConfirmCloseMetadata" class="px-4 py-2 rounded-xl bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold">
                Tutup Informasi
            </button>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    function initializeGlobalMap() {
        if (typeof window.GlobalMapController !== 'function') {
            if (typeof window.loadMapLibreEngine === 'function') {
                window.loadMapLibreEngine().then(initializeGlobalMap);
                return;
            }
            setTimeout(initializeGlobalMap, 50);
            return;
        }

        // Initialize MapLibre Controller
        const mapController = new window.GlobalMapController('global-map-canvas');
        window.activeMapController = mapController;

        // Checkbox Layer Toggles
        const layerToggles = [
            { id: 'toggle-layer-eez-boundaries', key: 'eezBoundaries' },
            { id: 'toggle-layer-eez', key: 'eez' },
            { id: 'toggle-layer-fao', key: 'fao' },
            { id: 'toggle-layer-fma', key: 'fma' },
            { id: 'toggle-layer-encounters', key: 'encounters' },
            { id: 'toggle-layer-vessels', key: 'vessels' },
            { id: 'toggle-layer-dark-vessels', key: 'darkVessels' },
            { id: 'toggle-layer-sar-scenes', key: 'sarScenes' },
        ];

        layerToggles.forEach(({ id, key }) => {
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener('change', (e) => {
                    mapController.toggleLayer(key, e.target.checked);
                });
            }
        });

        // Search Vessel
        const btnFilter = document.getElementById('btnFilterSearch');
        if (btnFilter) {
            btnFilter.addEventListener('click', () => {
                mapController.fetchLayerData('vessels');
            });
        }

        // Quick Fit Controls
        const fitMappings = [
            { id: 'btnFitVessels', type: 'vessels' },
            { id: 'btnFitEez', type: 'eez' },
            { id: 'btnFitFao', type: 'fao' },
            { id: 'btnFitFma', type: 'fma' },
            { id: 'btnFitSarCandidates', type: 'sar-candidates' },
            { id: 'btnFitSarScene', type: 'sar-scenes' },
        ];

        fitMappings.forEach(({ id, type }) => {
            const btn = document.getElementById(id);
            if (btn) {
                btn.addEventListener('click', () => {
                    mapController.fitToData(type);
                });
            }
        });

        // Reset View
        const btnReset = document.getElementById('btnResetView');
        if (btnReset) {
            btnReset.addEventListener('click', () => {
                mapController.map.flyTo({ center: [100.0, 5.0], zoom: 3.5 });
            });
        }

        // Metadata Modal
        const modal = document.getElementById('metadataModal');
        const btnOpen = document.getElementById('btnOpenMetadataModal');
        const btnClose = document.getElementById('btnCloseMetadataModal');
        const btnConfirmClose = document.getElementById('btnConfirmCloseMetadata');

        if (modal && btnOpen) {
            btnOpen.addEventListener('click', () => {
                modal.classList.remove('hidden');
                modal.classList.add('flex');
            });
        }

        const closeModal = () => {
            if (modal) {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            }
        };

        if (btnClose) btnClose.addEventListener('click', closeModal);
        if (btnConfirmClose) btnConfirmClose.addEventListener('click', closeModal);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeGlobalMap);
    } else {
        initializeGlobalMap();
    }
</script>
@endpush
