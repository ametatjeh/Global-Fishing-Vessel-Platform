@extends('layouts.app')

@section('header_title', 'Global Maritime Monitoring Map — MapLibre GL JS')

@section('content')
@php
    $flags = $filterMeta['flags'] ?? [];
    $vesselTypes = $filterMeta['vessel_types'] ?? [];
    $reviewStatuses = $filterMeta['review_statuses'] ?? [];
    $correlationStatuses = $filterMeta['correlation_statuses'] ?? [];
    $platforms = $filterMeta['platforms'] ?? [];
@endphp
<div class="relative h-[calc(100vh-8.5rem)] min-h-[580px] rounded-2xl overflow-hidden border border-slate-800 shadow-2xl flex flex-col bg-slate-950 font-sans">

    <!-- Top Floating Toolbar: Quick Actions, Search & Control Popovers -->
    <div class="absolute top-3 left-3 z-30 flex items-center gap-2 pointer-events-none">
        
        <!-- Left: Search & Navigation Toolbar -->
        <div class="pointer-events-auto flex items-center gap-1.5 p-1 rounded-xl glass-panel border border-slate-700/80 shadow-2xl text-xs backdrop-blur-md">
            
            <!-- A. SEARCH WITH LIVE AUTOCOMPLETE -->
            <div class="relative flex items-center">
                <input type="text" id="inputVesselSearch" placeholder="Search vessel, MMSI, or candidate..."
                    class="pl-8 pr-7 py-1.5 rounded-lg bg-slate-900/90 border border-slate-700 text-slate-100 placeholder-slate-500 focus:outline-none focus:border-teal-400 w-44 sm:w-64 text-xs font-mono transition-all">
                <span class="absolute left-2.5 text-slate-400 pointer-events-none text-xs">🔍</span>
                <button type="button" id="btnClearSearch" class="hidden absolute right-2 text-slate-400 hover:text-white text-xs font-bold p-0.5 leading-none" title="Hapus pencarian">&times;</button>
            </div>

            <!-- Unified Search Dropdown Panel (Live Query Results) -->
            <div id="searchResultsDropdown" class="hidden absolute top-full left-0 mt-2 w-72 sm:w-96 max-h-80 overflow-y-auto glass-panel p-2 rounded-xl border border-slate-700/90 shadow-2xl z-40 space-y-2 text-xs divide-y divide-slate-800/80">
                <!-- Injected via JavaScript from /api/map/search -->
            </div>

            <button type="button" id="btnFilterSearch" class="px-2.5 py-1.5 rounded-lg bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold transition flex items-center gap-1" title="Cari kapal atau radar target">
                <span>Cari</span>
            </button>

            <div class="h-4 w-px bg-slate-700/80 mx-0.5"></div>

            <!-- B. FILTER BUTTON -->
            <button type="button" id="btnToggleAdvancedFilters" class="btn-popover-trigger px-2.5 py-1.5 rounded-lg bg-slate-900 hover:bg-slate-800 text-slate-200 hover:text-teal-300 font-medium transition border border-slate-700 flex items-center gap-1.5" title="Buka panel filter kapal & radar">
                <svg class="w-3.5 h-3.5 text-teal-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="4" y1="21" x2="4" y2="14"></line><line x1="4" y1="10" x2="4" y2="3"></line>
                    <line x1="12" y1="21" x2="12" y2="12"></line><line x1="12" y1="8" x2="12" y2="3"></line>
                    <line x1="20" y1="21" x2="20" y2="16"></line><line x1="20" y1="12" x2="20" y2="3"></line>
                    <line x1="1" y1="14" x2="7" y2="14"></line><line x1="9" y1="8" x2="15" y2="8"></line><line x1="17" y1="16" x2="23" y2="16"></line>
                </svg>
                <span class="hidden sm:inline">Filters</span>
                <span id="activeFilterBadgeToolbar" class="active-filter-badge hidden px-1.5 py-0.2 rounded-full text-[9px] bg-teal-400 text-slate-950 font-bold">0</span>
            </button>

            <!-- C. MARITIME LAYERS BUTTON -->
            <button type="button" id="btnToggleLayers" class="btn-popover-trigger px-2.5 py-1.5 rounded-lg bg-slate-900 hover:bg-slate-800 text-slate-200 hover:text-sky-300 font-medium transition border border-slate-700 flex items-center gap-1.5" title="Buka pilihan layer maritim">
                <svg class="w-3.5 h-3.5 text-sky-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="12 2 2 7 12 12 22 7 12 2"></polygon>
                    <polyline points="2 17 12 22 22 17"></polyline>
                    <polyline points="2 12 12 17 22 12"></polyline>
                </svg>
                <span class="hidden sm:inline">Layers</span>
                <span id="badgeLayerCount" class="px-1.5 py-0.2 rounded-full text-[9px] bg-sky-500 text-slate-950 font-bold">5</span>
            </button>

            <!-- D. SCIENTIFIC LEGEND BUTTON -->
            <button type="button" id="btnToggleLegend" class="btn-popover-trigger px-2.5 py-1.5 rounded-lg bg-slate-900 hover:bg-slate-800 text-slate-200 hover:text-amber-300 font-medium transition border border-slate-700 flex items-center gap-1.5" title="Buka legenda simbolik & taksonomi kapal">
                <svg class="w-3.5 h-3.5 text-amber-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
                    <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
                </svg>
                <span class="hidden sm:inline">Legend</span>
            </button>

            <!-- E. MAP SETTINGS / BASEMAP BUTTON -->
            <button type="button" id="btnToggleSettings" class="btn-popover-trigger px-2.5 py-1.5 rounded-lg bg-slate-900 hover:bg-slate-800 text-slate-200 hover:text-emerald-300 font-medium transition border border-slate-700 flex items-center gap-1.5" title="Pengaturan tampilan basemap & atribusi">
                <svg class="w-3.5 h-3.5 text-emerald-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="3"></circle>
                    <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                </svg>
                <span class="hidden sm:inline">Settings</span>
            </button>

            <!-- F. RESET VIEW BUTTON -->
            <button type="button" id="btnResetView" class="px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white font-medium transition border border-slate-700 flex items-center gap-1" title="Pusatkan peta ke kawasan regional ASEAN default">
                <span>🧭</span>
                <span class="hidden sm:inline">Reset View</span>
            </button>
        </div>
    </div>

    <!-- Active Vessel Track Controller Banner -->
    <div id="activeTrackBanner" class="hidden absolute top-14 left-3 z-20 pointer-events-auto flex flex-wrap items-center gap-2 p-1.5 rounded-xl glass-panel border border-cyan-500/50 shadow-2xl text-xs backdrop-blur-md">
        <div class="flex items-center gap-1.5 text-cyan-300 font-semibold font-mono pl-1">
            <span class="animate-pulse">🛰️</span>
            <span id="activeTrackVesselLabel">Track: -</span>
            <span id="activeTrackPointCountBadge" class="px-1.5 py-0.2 rounded text-[10px] bg-cyan-950 text-cyan-300 border border-cyan-700 font-bold">0 pts</span>
        </div>
        <div class="h-4 w-px bg-slate-700 mx-1"></div>
        <button type="button" id="btnFitActiveTrack" class="px-2 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-sky-300 font-bold text-[11px] border border-slate-700 flex items-center gap-1 transition" title="Pusatkan peta ke garis lintasan (Fit Track)">
            <span>🧭</span> Fit Track
        </button>
        <button type="button" id="btnToggleActiveTrack" class="px-2 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold text-[11px] border border-slate-700 flex items-center gap-1 transition" title="Sembunyikan / Tampilkan garis lintasan (Hide/Show)">
            <span id="toggleTrackIcon">👁️</span> <span id="toggleTrackLabel">Hide Track</span>
        </button>
        <button type="button" id="btnClearActiveTrack" class="px-2 py-1 rounded-lg bg-slate-800 hover:bg-rose-950 text-rose-300 font-bold text-[11px] border border-slate-700 hover:border-rose-700 flex items-center gap-1 transition" title="Hapus garis lintasan dari peta (Clear Track)">
            <span>✖️</span> Clear Track
        </button>
    </div>

    <!-- POPOVER 1: ADVANCED MARITIME FILTERS -->
    <div id="advancedFiltersPanel" class="hidden absolute top-14 left-3 z-30 w-84 sm:w-96 max-h-[calc(100%-4.5rem)] overflow-y-auto glass-panel p-4 rounded-2xl border border-slate-700/80 shadow-2xl space-y-3.5 text-xs backdrop-blur-xl">
        <div class="flex items-center justify-between border-b border-slate-700/80 pb-2.5">
            <div class="flex items-center gap-2">
                <span class="text-base text-teal-400">🎚️</span>
                <div>
                    <h3 class="font-bold uppercase tracking-wider text-white text-[12px]">Advanced Maritime Filters</h3>
                    <p class="text-[10px] text-slate-400">Filter pemantauan armada &amp; satelit radar</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <span class="active-filter-badge hidden px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-teal-500/20 text-teal-300 border border-teal-500/40">0 Aktif</span>
                <button type="button" id="btnCloseFiltersPanel" class="text-slate-400 hover:text-white text-lg font-bold p-1 leading-none transition" title="Tutup panel (Esc)">&times;</button>
            </div>
        </div>

        <form id="formAdvancedFilters" class="space-y-3.5" onsubmit="return false;">
            <!-- SECTION A: VESSEL IDENTITY -->
            <div class="space-y-2 p-2.5 rounded-xl bg-slate-900/90 border border-slate-800">
                <div class="text-[10px] uppercase font-bold text-sky-400 tracking-wider flex items-center justify-between">
                    <span>1. Identitas Armada Kapal (AIS)</span>
                    <span>🚢</span>
                </div>

                <!-- Flag State -->
                <div>
                    <label for="filter-vessel-flag" class="block text-[10px] uppercase text-slate-400 font-mono mb-1">Bendera Negara (Flag State)</label>
                    <select id="filter-vessel-flag" class="w-full px-2.5 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs focus:outline-none focus:border-teal-400">
                        <option value="">Semua Bendera Negara (All Flags)</option>
                        @foreach($flags as $f)
                            <option value="{{ $f['code'] }}">{{ $f['label'] }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Vessel Type -->
                <div>
                    <label for="filter-vessel-type" class="block text-[10px] uppercase text-slate-400 font-mono mb-1">Tipe Kapal (Vessel Type)</label>
                    <select id="filter-vessel-type" class="w-full px-2.5 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs focus:outline-none focus:border-teal-400 capitalize">
                        <option value="">Semua Tipe Kapal</option>
                        @foreach($vesselTypes as $vt)
                            <option value="{{ $vt }}">{{ ucfirst($vt) }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- SOG Range -->
                <div>
                    <label class="block text-[10px] uppercase text-slate-400 font-mono mb-1">Rentang Kecepatan SOG (knots)</label>
                    <div class="grid grid-cols-2 gap-2">
                        <input type="number" id="filter-min-sog" placeholder="Min (0 kn)" min="0" max="60" step="0.5" class="px-2 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs focus:outline-none focus:border-teal-400 font-mono">
                        <input type="number" id="filter-max-sog" placeholder="Max (kn)" min="0" max="60" step="0.5" class="px-2 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs focus:outline-none focus:border-teal-400 font-mono">
                    </div>
                </div>

                <!-- COG Range -->
                <div>
                    <label class="block text-[10px] uppercase text-slate-400 font-mono mb-1">Rentang Arah Gerak COG (derajat)</label>
                    <div class="grid grid-cols-2 gap-2">
                        <input type="number" id="filter-min-cog" placeholder="Min (0°)" min="0" max="360" step="1" class="px-2 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs focus:outline-none focus:border-teal-400 font-mono">
                        <input type="number" id="filter-max-cog" placeholder="Max (360°)" min="0" max="360" step="1" class="px-2 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs focus:outline-none focus:border-teal-400 font-mono">
                    </div>
                </div>

                <!-- Heading Availability -->
                <div>
                    <label for="filter-heading-status" class="block text-[10px] uppercase text-slate-400 font-mono mb-1">Ketersediaan Haluan (Heading)</label>
                    <select id="filter-heading-status" class="w-full px-2.5 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs focus:outline-none focus:border-teal-400">
                        <option value="">Semua Status Orientasi</option>
                        <option value="has_heading">Heading Valid (Arah Haluan Lambung)</option>
                        <option value="cog_fallback">COG Fallback (Arah Vektor Gerak)</option>
                        <option value="unavailable">Direction Unavailable (Beacon Netral)</option>
                    </select>
                </div>

                <!-- Data Freshness -->
                <div>
                    <label for="filter-freshness" class="block text-[10px] uppercase text-slate-400 font-mono mb-1">Usia Telemetri AIS (Freshness)</label>
                    <select id="filter-freshness" class="w-full px-2.5 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs focus:outline-none focus:border-teal-400">
                        <option value="">Semua Usia Telemetri</option>
                        <option value="live">● Live AIS (&lt; 2 Jam Terakhir)</option>
                        <option value="stale">⏳ Stale AIS (&ge; 2 Jam Terakhir)</option>
                    </select>
                </div>
            </div>

            <!-- SECTION B: SAR CANDIDATES -->
            <div class="space-y-2 p-2.5 rounded-xl bg-slate-900/90 border border-slate-800">
                <div class="text-[10px] uppercase font-bold text-amber-400 tracking-wider flex items-center justify-between">
                    <span>2. Kandidat Anomali Radar SAR</span>
                    <span>🎯</span>
                </div>

                <div>
                    <label for="filter-sar-review" class="block text-[10px] uppercase text-slate-400 font-mono mb-1">Status Review Analis</label>
                    <select id="filter-sar-review" class="w-full px-2.5 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs focus:outline-none focus:border-teal-400">
                        <option value="">Semua Status Review</option>
                        @foreach($reviewStatuses as $val => $lbl)
                            <option value="{{ $val }}">{{ $lbl }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="filter-sar-correlation" class="block text-[10px] uppercase text-slate-400 font-mono mb-1">Status Korelasi AIS</label>
                    <select id="filter-sar-correlation" class="w-full px-2.5 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs focus:outline-none focus:border-teal-400">
                        <option value="">Semua Status Korelasi</option>
                        @foreach($correlationStatuses as $cVal => $cLbl)
                            <option value="{{ $cVal }}">{{ $cLbl }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-[10px] uppercase text-slate-400 font-mono mb-1">Rentang TCR Hamburan Radar (dB)</label>
                    <div class="grid grid-cols-2 gap-2">
                        <input type="number" id="filter-min-tcr" placeholder="Min (dB)" step="0.5" class="px-2 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs focus:outline-none focus:border-teal-400 font-mono">
                        <input type="number" id="filter-max-tcr" placeholder="Max (dB)" step="0.5" class="px-2 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs focus:outline-none focus:border-teal-400 font-mono">
                    </div>
                </div>

                <div>
                    <label for="filter-sar-platform" class="block text-[10px] uppercase text-slate-400 font-mono mb-1">Platform Satelit Radar</label>
                    <select id="filter-sar-platform" class="w-full px-2.5 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs focus:outline-none focus:border-teal-400">
                        <option value="">Semua Platform Satelit</option>
                        @foreach($platforms as $p)
                            <option value="{{ $p }}">{{ $p }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <!-- SECTION C: TEMPORAL & TRACK WINDOW -->
            <div class="space-y-2 p-2.5 rounded-xl bg-slate-900/90 border border-slate-800">
                <div class="text-[10px] uppercase font-bold text-teal-400 tracking-wider flex items-center justify-between">
                    <span>3. Rentang Waktu &amp; Trajektori</span>
                    <span>⏱️</span>
                </div>

                <div>
                    <label for="filter-date-range" class="block text-[10px] uppercase text-slate-400 font-mono mb-1">Rentang Waktu Observasi</label>
                    <select id="filter-date-range" class="w-full px-2.5 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs focus:outline-none focus:border-teal-400">
                        <option value="all">Semua Waktu Observasi</option>
                        <option value="24h">24 Jam Terakhir</option>
                        <option value="7d">7 Hari Terakhir</option>
                        <option value="30d">30 Hari Terakhir</option>
                        <option value="custom">Rentang Tanggal Khusus...</option>
                    </select>
                </div>

                <div id="customDateRangeContainer" class="hidden grid grid-cols-2 gap-2 pt-1">
                    <div>
                        <label class="block text-[9px] text-slate-400 font-mono mb-0.5">Mulai:</label>
                        <input type="date" id="filter-start-date" class="w-full px-2 py-1 rounded bg-slate-950 border border-slate-700 text-slate-200 text-[11px] font-mono">
                    </div>
                    <div>
                        <label class="block text-[9px] text-slate-400 font-mono mb-0.5">Selesai:</label>
                        <input type="date" id="filter-end-date" class="w-full px-2 py-1 rounded bg-slate-950 border border-slate-700 text-slate-200 text-[11px] font-mono">
                    </div>
                </div>

                <div>
                    <label for="filter-track-window" class="block text-[10px] uppercase text-slate-400 font-mono mb-1">Jendela Historis AIS Track</label>
                    <select id="filter-track-window" class="w-full px-2.5 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs focus:outline-none focus:border-teal-400">
                        <option value="off">Off (Hanya Posisi Terkini)</option>
                        <option value="60">Jendela 60 Menit</option>
                        <option value="180">Jendela 180 Menit (3 Jam)</option>
                        <option value="360">Jendela 360 Menit (6 Jam)</option>
                    </select>
                </div>
            </div>

            <!-- Action Buttons: Apply & Reset -->
            <div class="pt-2 flex items-center gap-2 border-t border-slate-800">
                <button type="button" id="btnApplyFilters" class="flex-1 py-2 px-3 rounded-xl bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold text-center transition flex items-center justify-center gap-1.5 shadow-md">
                    <span>✓</span>
                    <span>Terapkan Filter</span>
                </button>
                <button type="button" id="btnResetFilters" class="py-2 px-3 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-semibold transition border border-slate-700 text-center">
                    Reset
                </button>
            </div>
        </form>
    </div>

    <!-- POPOVER 2: MARITIME LAYERS -->
    <div id="popoverLayers" class="hidden absolute top-14 left-3 sm:left-48 z-30 w-80 sm:w-88 max-h-[calc(100%-4.5rem)] overflow-y-auto glass-panel p-4 rounded-2xl border border-slate-700/80 shadow-2xl space-y-3.5 text-xs backdrop-blur-xl">
        <div class="flex items-center justify-between border-b border-slate-700/80 pb-2">
            <div class="flex items-center gap-2">
                <span class="text-base text-sky-400">🎛️</span>
                <h3 class="font-bold uppercase tracking-wider text-white text-[12px]">Maritime Layers</h3>
            </div>
            <button type="button" id="btnCloseLayersPopover" class="text-slate-400 hover:text-white text-lg font-bold p-1 leading-none transition" title="Tutup panel (Esc)">&times;</button>
        </div>

        <div class="space-y-3">
            <!-- 1. AIS & Navigation -->
            <div class="space-y-1.5">
                <div class="text-[10px] uppercase font-bold text-slate-400 tracking-wider">1. AIS &amp; Navigasi</div>

                <label class="flex items-start justify-between p-2 rounded-xl bg-slate-900/90 border border-slate-800 hover:border-sky-500/50 transition cursor-pointer">
                    <div class="flex items-start gap-2.5">
                        <input type="checkbox" id="toggle-layer-vessels" checked class="mt-0.5 rounded bg-slate-800 border-slate-700 text-sky-500 focus:ring-sky-400">
                        <div>
                            <div class="font-bold text-slate-200 flex items-center gap-1.5">
                                <span class="w-2.5 h-2.5 rounded-full bg-sky-400"></span>
                                <span>Live AIS Vessels</span>
                            </div>
                            <p class="text-[10px] text-slate-400 mt-0.5">Telemetri kapal broadcast aktif real-time</p>
                        </div>
                    </div>
                    <div class="text-right shrink-0">
                        <span id="loading-vessels" class="text-[10px] text-sky-400 animate-spin" style="display: none;">⏳</span>
                        <span id="count-vessels" class="text-[10px] font-mono px-1.5 py-0.5 rounded bg-slate-800 text-sky-300 border border-sky-900">0</span>
                    </div>
                </label>
            </div>

            <!-- 2. Maritime Boundaries -->
            <div class="space-y-1.5 pt-1 border-t border-slate-800">
                <div class="text-[10px] uppercase font-bold text-slate-400 tracking-wider">2. Batas Maritim Regional (EEZ)</div>

                <label class="flex items-start justify-between p-2 rounded-xl bg-slate-900/90 border border-slate-800 hover:border-emerald-500/50 transition cursor-pointer">
                    <div class="flex items-start gap-2.5">
                        <input type="checkbox" id="toggle-layer-asean-eez" checked class="mt-0.5 rounded bg-slate-800 border-slate-700 text-emerald-500 focus:ring-emerald-400">
                        <div>
                            <div class="font-bold text-slate-200 flex items-center gap-1.5">
                                <span class="w-2.5 h-2.5 rounded-sm bg-emerald-500/20 border border-emerald-500"></span>
                                <span>ASEAN Exclusive Economic Zones (EEZ)</span>
                            </div>
                            <p class="text-[10px] text-slate-400 mt-0.5">Garis Batas ZEE &amp; Batas Maritim Resmi ASEAN (v12)</p>
                        </div>
                    </div>
                    <div class="text-right shrink-0">
                        <span id="loading-aseanEez" class="text-[10px] text-emerald-400 animate-spin" style="display: none;">⏳</span>
                        <span id="count-aseanEez" class="text-[10px] font-mono px-1.5 py-0.5 rounded bg-slate-800 text-emerald-300 border border-emerald-900">0</span>
                    </div>
                </label>

                <label class="flex items-start justify-between p-2 rounded-xl bg-slate-900/90 border border-slate-800 hover:border-sky-500/50 transition cursor-pointer">
                    <div class="flex items-start gap-2.5">
                        <input type="checkbox" id="toggle-layer-australia-eez" checked class="mt-0.5 rounded bg-slate-800 border-slate-700 text-sky-500 focus:ring-sky-400">
                        <div>
                            <div class="font-bold text-slate-200 flex items-center gap-1.5">
                                <span class="w-2.5 h-2.5 rounded-sm bg-sky-500/20 border border-sky-400"></span>
                                <span>Australia Exclusive Economic Zone (EEZ)</span>
                            </div>
                            <p class="text-[10px] text-slate-400 mt-0.5">Garis Batas ZEE &amp; Batas Maritim Resmi Australia (AMSIS / AMB / v12)</p>
                        </div>
                    </div>
                    <div class="text-right shrink-0">
                        <span id="loading-australiaEez" class="text-[10px] text-sky-400 animate-spin" style="display: none;">⏳</span>
                        <span id="count-australiaEez" class="text-[10px] font-mono px-1.5 py-0.5 rounded bg-slate-800 text-sky-300 border border-sky-900">0</span>
                    </div>
                </label>
            </div>

            <!-- 3. Monitoring & Analysis -->
            <div class="space-y-1.5 pt-1 border-t border-slate-800">
                <div class="text-[10px] uppercase font-bold text-slate-400 tracking-wider">3. Analisis &amp; Anomali</div>

                <label class="flex items-start justify-between p-2 rounded-xl bg-slate-900/90 border border-slate-800 hover:border-amber-500/50 transition cursor-pointer">
                    <div class="flex items-start gap-2.5">
                        <input type="checkbox" id="toggle-layer-dark-vessels" checked class="mt-0.5 rounded bg-slate-800 border-slate-700 text-amber-500 focus:ring-amber-400">
                        <div>
                            <div class="font-bold text-slate-200 flex items-center gap-1.5">
                                <span class="w-2.5 h-2.5 rounded-full bg-amber-400 border border-amber-300"></span>
                                <span>Unusual Activity / SAR Targets</span>
                            </div>
                            <p class="text-[10px] text-slate-400 mt-0.5">Anomali radar tanpa korelasi AIS aktif</p>
                        </div>
                    </div>
                    <div class="text-right shrink-0">
                        <span id="loading-darkVessels" class="text-[10px] text-amber-400 animate-spin" style="display: none;">⏳</span>
                        <span id="count-darkVessels" class="text-[10px] font-mono px-1.5 py-0.5 rounded bg-slate-800 text-amber-300 border border-amber-900">0</span>
                    </div>
                </label>

                <label class="flex items-start justify-between p-2 rounded-xl bg-slate-900/90 border border-slate-800 hover:border-amber-500/50 transition cursor-pointer">
                    <div class="flex items-start gap-2.5">
                        <input type="checkbox" id="toggle-layer-encounters" checked class="mt-0.5 rounded bg-slate-800 border-slate-700 text-amber-500 focus:ring-amber-400">
                        <div>
                            <div class="font-bold text-slate-200 flex items-center gap-1.5">
                                <span class="w-2.5 h-2.5 rounded-full bg-amber-400"></span>
                                <span>Vessel Encounters</span>
                            </div>
                            <p class="text-[10px] text-slate-400 mt-0.5">Deteksi perjumpaan pasangan kapal di laut lepas</p>
                        </div>
                    </div>
                    <div class="text-right shrink-0">
                        <span id="loading-encounters" class="text-[10px] text-amber-400 animate-spin" style="display: none;">⏳</span>
                        <span id="count-encounters" class="text-[10px] font-mono px-1.5 py-0.5 rounded bg-slate-800 text-amber-300 border border-amber-900">0</span>
                    </div>
                </label>

                <label class="flex items-start justify-between p-2 rounded-xl bg-slate-900/90 border border-slate-800 hover:border-purple-500/50 transition cursor-pointer">
                    <div class="flex items-start gap-2.5">
                        <input type="checkbox" id="toggle-layer-sar-scenes" checked class="mt-0.5 rounded bg-slate-800 border-slate-700 text-purple-500 focus:ring-purple-400">
                        <div>
                            <div class="font-bold text-slate-200 flex items-center gap-1.5">
                                <span class="w-2.5 h-2.5 rounded-sm bg-purple-500 border border-purple-300"></span>
                                <span>SAR Footprint Scene</span>
                            </div>
                            <p class="text-[10px] text-slate-400 mt-0.5">Poligon cakupan radar Sentinel-1 Copernicus</p>
                        </div>
                    </div>
                    <div class="text-right shrink-0">
                        <span id="loading-sarScenes" class="text-[10px] text-purple-400 animate-spin" style="display: none;">⏳</span>
                        <span id="count-sarScenes" class="text-[10px] font-mono px-1.5 py-0.5 rounded bg-slate-800 text-purple-300 border border-purple-900">0</span>
                    </div>
                </label>
            </div>
        </div>
    </div>

    <!-- POPOVER 3: SCIENTIFIC LEGEND -->
    <div id="popoverLegend" class="hidden absolute top-14 left-3 sm:left-64 z-30 w-84 sm:w-96 max-h-[calc(100%-4.5rem)] overflow-y-auto glass-panel p-4 rounded-2xl border border-slate-700/80 shadow-2xl space-y-3.5 text-xs backdrop-blur-xl">
        <div class="flex items-center justify-between border-b border-slate-700/80 pb-2">
            <div class="flex items-center gap-2">
                <span class="text-base text-amber-400">🧭</span>
                <h3 class="font-bold uppercase tracking-wider text-white text-[12px]">Legenda &amp; Simbologi Ilmiah</h3>
            </div>
            <button type="button" id="btnCloseLegendPopover" class="text-slate-400 hover:text-white text-lg font-bold p-1 leading-none transition" title="Tutup panel (Esc)">&times;</button>
        </div>

        <div class="space-y-3">
            <!-- 1. Klasifikasi Kapal -->
            <div class="p-2.5 rounded-xl bg-slate-900/90 border border-slate-800 space-y-2 text-[10px] font-mono">
                <div class="text-[9px] uppercase font-bold text-slate-400 tracking-wider">1. Klasifikasi Armada Kapal</div>
                <div class="grid grid-cols-2 gap-2 text-slate-300">
                    <div class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-[#F59E0B] border border-[#451a03] shadow-[0_0_8px_rgba(245,158,11,0.7)] shrink-0"></span> <strong class="text-[#F59E0B]">Fishing</strong> <span class="text-slate-400">(Perikanan)</span></div>
                    <div class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-sky-400 shrink-0"></span> Passenger (Penumpang)</div>
                    <div class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-indigo-400"></span> Cargo / Tanker</div>
                    <div class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-rose-400"></span> Patrol / Military</div>
                    <div class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-amber-400"></span> Special / Tug</div>
                    <div class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-slate-400"></span> Unknown Vessel</div>
                </div>
            </div>

            <!-- 2. Status Data AIS & Simbologi Haluan -->
            <div class="p-2.5 rounded-xl bg-slate-900/90 border border-slate-800 space-y-2 text-[10px] font-mono">
                <div class="text-[9px] uppercase font-bold text-sky-400 tracking-wider flex items-center justify-between">
                    <span>Live AIS Vessels (Posisi Aktual)</span>
                    <span class="w-1.5 h-1.5 rounded-full bg-sky-400 animate-pulse"></span>
                </div>
                <div class="space-y-1.5 text-slate-300">
                    <div class="flex items-center justify-between">
                        <span class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-emerald-400"></span> Posisi Terbaru (&lt; 2 Jam)</span>
                        <span class="text-emerald-300 font-bold">● Live</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-amber-400"></span> Posisi Usang (2 - 24 Jam)</span>
                        <span class="text-amber-300 font-bold">⏳ Stale</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-slate-500"></span> Tidak Tersedia (&gt; 24 Jam)</span>
                        <span class="text-slate-400 font-bold">○ Offline</span>
                    </div>
                    <div class="pt-1 border-t border-slate-800/80 space-y-1">
                        <div class="flex items-center gap-2">
                            <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none">
                                <path d="M12 2 C16 6 16.5 11 16 15 L8 15 C7.5 11 8 6 12 2 Z" fill="#38bdf8" stroke="#0284c7" stroke-width="1.5"/>
                                <circle cx="12" cy="8.5" r="1.2" fill="#ffffff" stroke="#0284c7" stroke-width="0.5"/>
                            </svg>
                            <span>Heading: Arah haluan (heading valid)</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none">
                                <path d="M12 2 L17.5 14 L14.5 14 L12 10 L9.5 14 L6.5 14 Z" fill="#0284c7" stroke="#38bdf8" stroke-width="1.5" stroke-linejoin="round"/>
                                <line x1="12" y1="5" x2="12" y2="9" stroke="#ffffff" stroke-width="1.2" stroke-linecap="round"/>
                            </svg>
                            <span>COG fallback: Arah gerak lintasan bumi</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none">
                                <circle cx="12" cy="12" r="4.5" fill="#38bdf8" stroke="#ffffff" stroke-width="1.5"/>
                            </svg>
                            <span>Direction unavailable: Orientasi tidak diketahui</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none">
                                <circle cx="12" cy="12" r="5" stroke="#38bdf8" stroke-width="1.5" stroke-dasharray="2 2"/>
                                <circle cx="12" cy="12" r="1.5" fill="#38bdf8"/>
                            </svg>
                            <span>Selected vessel: Cincin sorot kapal terpilih</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 3. Batas Maritim Regional (EEZ) & Overlays -->
            <div class="p-2.5 rounded-xl bg-slate-900/90 border border-slate-800 space-y-2.5 text-[10px] font-mono">
                <!-- ASEAN EEZ Header & Metadata -->
                <div class="space-y-1.5">
                    <div class="text-[9px] uppercase font-bold text-emerald-400 tracking-wider flex items-center justify-between">
                        <span>Status Batas Maritim ASEAN &bull; ASEAN EEZ (Marine Regions v12)</span>
                        <span class="text-slate-400 text-[8px] bg-slate-800 px-1.5 py-0.5 rounded border border-slate-700">1–1.2px &bull; Opacity 70%</span>
                    </div>

                    <!-- ASEAN Country Colors Grid -->
                    <div class="grid grid-cols-2 gap-x-2 gap-y-1 text-[9px] text-slate-300 pt-0.5">
                        <div class="flex items-center gap-1.5">
                            <span class="w-3 h-1 rounded-sm bg-[#06b6d4] shrink-0 shadow-sm"></span>
                            <span class="truncate"><span class="text-slate-400 font-semibold">IDN</span> Indonesia (Cyan)</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span class="w-3 h-1 rounded-sm bg-[#f97316] shrink-0 shadow-sm"></span>
                            <span class="truncate"><span class="text-slate-400 font-semibold">MYS</span> Malaysia (Orange)</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span class="w-3 h-1 rounded-sm bg-[#eab308] shrink-0 shadow-sm"></span>
                            <span class="truncate"><span class="text-slate-400 font-semibold">THA</span> Thailand (Yellow)</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span class="w-3 h-1 rounded-sm bg-[#d946ef] shrink-0 shadow-sm"></span>
                            <span class="truncate"><span class="text-slate-400 font-semibold">VNM</span> Vietnam (Magenta)</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span class="w-3 h-1 rounded-sm bg-[#22c55e] shrink-0 shadow-sm"></span>
                            <span class="truncate"><span class="text-slate-400 font-semibold">PHL</span> Filipina (Green)</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span class="w-3 h-1 rounded-sm bg-[#8b5cf6] shrink-0 shadow-sm"></span>
                            <span class="truncate"><span class="text-slate-400 font-semibold">BRN</span> Brunei (Violet)</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span class="w-3 h-1 rounded-sm bg-[#fb7185] shrink-0 shadow-sm"></span>
                            <span class="truncate"><span class="text-slate-400 font-semibold">SGP</span> Singapura (Coral)</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span class="w-3 h-1 rounded-sm bg-[#3b82f6] shrink-0 shadow-sm"></span>
                            <span class="truncate"><span class="text-slate-400 font-semibold">KHM</span> Kamboja (Blue)</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span class="w-3 h-1 rounded-sm bg-[#ec4899] shrink-0 shadow-sm"></span>
                            <span class="truncate"><span class="text-slate-400 font-semibold">MMR</span> Myanmar (Pink)</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span class="w-3 h-1 rounded-sm bg-[#14b8a6] shrink-0 shadow-sm"></span>
                            <span class="truncate"><span class="text-slate-400 font-semibold">LAO</span> Laos (Teal)</span>
                        </div>
                        <div class="flex items-center gap-1.5 col-span-2">
                            <span class="w-3 h-1 rounded-sm bg-[#a855f7] shrink-0 shadow-sm"></span>
                            <span class="truncate"><span class="text-slate-400 font-semibold">TLS</span> Timor-Leste (Violet-Indigo)</span>
                        </div>
                    </div>

                    <!-- Line Pattern Classification -->
                    <div class="pt-1.5 border-t border-slate-800/80 space-y-1 text-[9px] text-slate-300">
                        <div class="flex items-center gap-2">
                            <span class="w-4 h-0.5 bg-slate-300 shrink-0"></span>
                            <span class="truncate">Agreed / Delimitated (Traktat Bilateral Resmi)</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="w-4 h-0.5 border-b border-dashed border-slate-400 shrink-0"></span>
                            <span class="truncate">Undelimited / Median Line</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="w-4 h-0.5 border-b border-dashed border-rose-400 shrink-0"></span>
                            <span class="truncate">Disputed / Overlapping Claim</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="w-4 h-0.5 border-b border-dotted border-purple-400 shrink-0"></span>
                            <span class="truncate">Joint Regime Area (Zona Bersama)</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="w-4 h-0.5 border-b border-dashed border-slate-400 shrink-0"></span>
                            <span class="truncate">Garis Batas Luar ZEE (200 NM)</span>
                        </div>
                    </div>
                </div>

                <!-- Australia EEZ (Terpisah) -->
                <div class="pt-2 border-t border-slate-800 space-y-1.5">
                    <div class="text-[9px] uppercase font-bold text-red-400 tracking-wider flex items-center justify-between">
                        <span>Australia EEZ (AMSIS / AMB / v12)</span>
                        <span class="text-red-400 text-[8px] font-bold bg-red-950/60 px-1.5 py-0.5 rounded border border-red-800/80">AUS &bull; Merah-Oranye</span>
                    </div>
                    <div class="space-y-1 text-[9px] text-slate-300">
                        <div class="flex items-center gap-2">
                            <span class="w-4 h-0.5 bg-[#ef4444] shrink-0"></span>
                            <span class="truncate">Agreed Bilateral Maritime Treaty (Solid)</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="w-4 h-0.5 border-b border-dashed border-[#ef4444] shrink-0"></span>
                            <span class="truncate">Australia 200 NM Outer Limit (ZEE)</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="w-4 h-0.5 border-b border-dotted border-[#ef4444] shrink-0"></span>
                            <span class="truncate">Median / Equidistant Boundary Line</span>
                        </div>
                    </div>
                </div>

                <!-- Operational Layers Legend -->
                <div class="pt-1.5 border-t border-slate-800/80 grid grid-cols-2 gap-1.5">
                    <div class="flex items-center gap-1.5"><span class="w-2.5 h-0.5 bg-amber-400"></span> AIS Track</div>
                    <div class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-amber-400"></span> Dark Vessel</div>
                    <div class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-amber-500"></span> Encounter Event</div>
                    <div class="flex items-center gap-1.5"><span class="w-2.5 h-2 bg-purple-500/30 border border-purple-400"></span> SAR Footprint</div>
                </div>
            </div>

            <!-- 4. Prinsip Ilmiah (Scientific Invariants) -->
            <div class="p-2.5 rounded-xl bg-slate-950/80 border border-slate-800 space-y-1 text-[9px] text-slate-400 leading-relaxed font-mono">
                <div class="font-bold text-slate-300 uppercase">Prinsip Validitas Forensik:</div>
                <div>&bull; Deteksi target pada citra SAR tidak sama dengan konfirmasi identitas kapal (SAR Target Detection &ne; Vessel Confirmation).</div>
                <div>&bull; Ketiadaan sinyal AIS tidak otomatis membuktikan aktivitas ilegal (AIS Absence &ne; Dark Vessel Confirmation).</div>
            </div>
        </div>
    </div>

    <!-- POPOVER 4: MAP SETTINGS & BASEMAP -->
    <div id="popoverSettings" class="hidden absolute top-14 left-3 sm:left-72 z-30 w-76 sm:w-84 max-h-[calc(100%-4.5rem)] overflow-y-auto glass-panel p-4 rounded-2xl border border-slate-700/80 shadow-2xl space-y-3.5 text-xs backdrop-blur-xl">
        <div class="flex items-center justify-between border-b border-slate-700/80 pb-2">
            <div class="flex items-center gap-2">
                <span class="text-base text-emerald-400">⚙️</span>
                <h3 class="font-bold uppercase tracking-wider text-white text-[12px]">Pengaturan Peta</h3>
            </div>
            <button type="button" id="btnCloseSettingsPopover" class="text-slate-400 hover:text-white text-lg font-bold p-1 leading-none transition" title="Tutup panel (Esc)">&times;</button>
        </div>

        <div class="space-y-3">
            <div class="space-y-1.5">
                <div class="flex items-center justify-between">
                    <div class="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Gaya Tampilan Basemap</div>
                    <span id="currentBasemapLabel" class="text-[10px] text-teal-400 font-mono">Positron High Contrast Clean</span>
                </div>
                <div class="space-y-1" id="basemapRadioGroup">
                    <label class="flex items-center justify-between p-2 rounded-xl bg-slate-900 border border-slate-800 hover:border-teal-500/50 cursor-pointer transition">
                        <div class="flex items-center gap-2">
                            <input type="radio" name="basemap_choice" value="openfreemap-positron" checked class="text-teal-400 focus:ring-teal-400 bg-slate-950 border-slate-700">
                            <span class="text-slate-200 font-medium">Positron High Contrast Clean</span>
                        </div>
                        <span class="text-[9px] px-1.5 py-0.2 rounded bg-teal-950 text-teal-300 border border-teal-700 font-bold">Default</span>
                    </label>
                    <label class="flex items-center justify-between p-2 rounded-xl bg-slate-900 border border-slate-800 hover:border-teal-500/50 cursor-pointer transition">
                        <div class="flex items-center gap-2">
                            <input type="radio" name="basemap_choice" value="openfreemap-dark" class="text-teal-400 focus:ring-teal-400 bg-slate-950 border-slate-700">
                            <span class="text-slate-200 font-medium">Dark Ocean Marine Style</span>
                        </div>
                        <span class="text-[9px] text-slate-400 font-mono">Vector</span>
                    </label>
                    <label class="flex items-center justify-between p-2 rounded-xl bg-slate-900 border border-slate-800 hover:border-teal-500/50 cursor-pointer transition">
                        <div class="flex items-center gap-2">
                            <input type="radio" name="basemap_choice" value="openfreemap-liberty" class="text-teal-400 focus:ring-teal-400 bg-slate-950 border-slate-700">
                            <span class="text-slate-200 font-medium">Nautical Standard Vector</span>
                        </div>
                        <span class="text-[9px] text-slate-400 font-mono">Detail</span>
                    </label>
                    <label class="flex items-center justify-between p-2 rounded-xl bg-slate-900 border border-slate-800 hover:border-teal-500/50 cursor-pointer transition">
                        <div class="flex items-center gap-2">
                            <input type="radio" name="basemap_choice" value="openfreemap-bright" class="text-teal-400 focus:ring-teal-400 bg-slate-950 border-slate-700">
                            <span class="text-slate-200 font-medium">Topographic Bright Canvas</span>
                        </div>
                        <span class="text-[9px] text-slate-400 font-mono">Terrain</span>
                    </label>
                </div>
            </div>

            <div class="pt-2 border-t border-slate-800 space-y-1.5">
                <div class="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Proyeksi Geospasial</div>
                <div class="p-2 rounded-xl bg-slate-900/90 border border-slate-800 text-[10px] text-slate-300 font-mono">
                    <div>Sistem: <strong class="text-teal-300">WGS 84 (EPSG:4326)</strong></div>
                    <div>Tampilan: <span class="text-slate-400">Web Mercator (EPSG:3857)</span></div>
                </div>
            </div>

            <div class="pt-2 border-t border-slate-800">
                <button type="button" id="btnSettingsOpenMetadata" class="w-full py-2 px-3 rounded-xl bg-slate-800 hover:bg-slate-700 text-teal-300 border border-slate-700 font-semibold transition text-center flex items-center justify-center gap-1.5">
                    <span>📜</span>
                    <span>Lihat Atribusi &amp; Lisensi Lengkap</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Map Canvas Container -->
    <div id="global-map-canvas" class="w-full h-full flex-1 bg-slate-950 min-h-[480px]"></div>

    <!-- Bottom Coordinates, Counts & Map Scale Status Bar -->
    <div class="absolute bottom-2 left-3 z-10 pointer-events-none flex flex-wrap items-center gap-2 text-[11px] font-mono">
        <div class="pointer-events-auto px-2.5 py-1 rounded-lg glass-panel border border-slate-700/80 shadow-md text-slate-300 flex items-center gap-2">
            <span class="text-teal-400 font-bold">📍</span>
            <span id="cursorCoordinates">Lat: 0.00000°, Lon: 0.00000° | Zoom: 3.50</span>
        </div>
        <div class="pointer-events-auto px-2.5 py-1 rounded-lg glass-panel border border-slate-700/80 shadow-md text-slate-300 flex items-center gap-1.5">
            <span class="text-sky-400 font-bold">🚢</span>
            <span id="statusVesselCount" class="text-sky-300 font-bold">0 kapal</span>
        </div>
        <div class="pointer-events-auto hidden sm:flex items-center gap-1.5 px-2.5 py-1 rounded-lg glass-panel border border-slate-700/80 shadow-md text-emerald-400">
            <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
            <span>Telemetri Live</span>
        </div>
    </div>

    <!-- Floating Right-Side / Bottom-Sheet Vessel Detail Panel -->
    <div id="vesselDetailPanel" class="hidden absolute top-14 right-3 bottom-12 z-30 w-84 sm:w-96 max-w-sm flex flex-col glass-panel bg-slate-900/95 border border-slate-700/80 rounded-2xl shadow-2xl backdrop-blur-xl overflow-hidden text-xs max-sm:fixed max-sm:inset-x-0 max-sm:bottom-0 max-sm:top-auto max-sm:w-full max-sm:max-h-[85vh] max-sm:rounded-t-2xl max-sm:rounded-b-none max-sm:z-40">
        <!-- Header -->
        <div class="p-3.5 border-b border-slate-700/80 bg-slate-950/70 flex items-start justify-between gap-2 shrink-0">
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2">
                    <span id="panelVesselFlagEmoji" class="text-xl shrink-0">🌐</span>
                    <h2 id="panelVesselName" class="font-bold text-white text-sm truncate font-sans tracking-wide">Nama Kapal</h2>
                </div>
                <div class="flex items-center gap-2 mt-1 text-[11px] font-mono">
                    <span id="panelVesselFlagName" class="text-slate-400 truncate">Indonesia</span>
                    <span id="panelVesselTypeBadge" class="px-1.5 py-0.2 rounded text-[9px] font-bold uppercase bg-[#F59E0B]/20 text-[#F59E0B] border border-[#F59E0B]/60 shadow-[0_0_8px_rgba(245,158,11,0.3)]">Fishing</span>
                </div>
            </div>
            <div class="flex items-center gap-1.5 shrink-0">
                <span id="panelVesselFreshnessBadge" class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/40">● LIVE</span>
                <button type="button" id="btnCloseVesselPanel" class="p-1 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition text-lg font-bold leading-none" title="Tutup panel detail (Esc)">&times;</button>
            </div>
        </div>

        <!-- Scrollable Content -->
        <div class="flex-1 overflow-y-auto p-3.5 space-y-3.5">
            <!-- A. IDENTITAS KAPAL -->
            <div class="space-y-1 font-mono text-[11px]">
                <div class="text-[10px] uppercase font-bold text-slate-400 tracking-wider">A. Identitas Kapal</div>
                <div class="p-2.5 rounded-xl bg-slate-950/80 border border-slate-800 space-y-1 divide-y divide-slate-800/80">
                    <div class="flex items-center justify-between pt-1 first:pt-0">
                        <span class="text-slate-400">MMSI:</span>
                        <strong id="panelVesselMmsi" class="text-white font-bold select-all">Tidak tersedia</strong>
                    </div>
                    <div class="flex items-center justify-between pt-1">
                        <span class="text-slate-400">IMO:</span>
                        <strong id="panelVesselImo" class="text-white">Tidak tersedia</strong>
                    </div>
                    <div class="flex items-center justify-between pt-1">
                        <span class="text-slate-400">Callsign:</span>
                        <strong id="panelVesselCallsign" class="text-white">Tidak tersedia</strong>
                    </div>
                    <div class="flex items-center justify-between pt-1">
                        <span class="text-slate-400">Bendera:</span>
                        <span id="panelVesselFlagFull" class="text-slate-200 font-semibold truncate max-w-[160px]">Tidak tersedia</span>
                    </div>
                    <div class="flex items-center justify-between pt-1">
                        <span class="text-slate-400">Tipe Kapal:</span>
                        <span id="panelVesselTypeDesc" class="text-slate-200 capitalize">Tidak tersedia</span>
                    </div>
                </div>
            </div>

            <!-- B. NAVIGASI & POSISI TERKINI -->
            <div class="space-y-1">
                <div class="text-[10px] uppercase font-bold text-slate-400 tracking-wider font-mono">B. Navigasi &amp; Posisi Terkini</div>
                <div class="grid grid-cols-2 gap-2 font-mono">
                    <div class="p-2.5 rounded-xl bg-slate-950/80 border border-slate-800">
                        <div class="text-[10px] text-slate-400 uppercase tracking-wider">Speed (SOG)</div>
                        <div class="text-base font-bold text-sky-300 mt-0.5 flex items-baseline gap-1">
                            <span id="panelVesselSog">0.0</span>
                            <span class="text-[10px] text-slate-400 font-normal">knots</span>
                        </div>
                    </div>
                    <div class="p-2.5 rounded-xl bg-slate-950/80 border border-slate-800">
                        <div class="text-[10px] text-slate-400 uppercase tracking-wider">Course (COG)</div>
                        <div class="text-base font-bold text-sky-300 mt-0.5 flex items-baseline gap-1">
                            <span id="panelVesselCog">0.0°</span>
                        </div>
                    </div>
                    <div class="p-2.5 rounded-xl bg-slate-950/80 border border-slate-800">
                        <div class="text-[10px] text-slate-400 uppercase tracking-wider">True Heading</div>
                        <div class="text-base font-bold text-emerald-300 mt-0.5 flex items-baseline gap-1">
                            <span id="panelVesselHeading">Tidak tersedia</span>
                        </div>
                    </div>
                    <div class="p-2.5 rounded-xl bg-slate-950/80 border border-slate-800">
                        <div class="text-[10px] text-slate-400 uppercase tracking-wider">Status Nav</div>
                        <div id="panelVesselNavStatus" class="text-xs font-semibold text-slate-200 mt-0.5 truncate capitalize">
                            Under way
                        </div>
                    </div>
                </div>

                <div class="p-2.5 rounded-xl bg-slate-950/80 border border-slate-800 space-y-1 font-mono text-[11px]">
                    <div class="flex items-center justify-between">
                        <span class="text-slate-400">Koordinat:</span>
                        <span id="panelVesselCoords" class="text-slate-200 font-bold select-all">0.0000°, 0.0000°</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-slate-400">Orientasi:</span>
                        <span id="panelVesselOrientation" class="text-slate-300 font-semibold">Tidak tersedia</span>
                    </div>
                </div>
            </div>

            <!-- C. KUALITAS DATA AIS -->
            <div class="space-y-1 font-mono text-[11px]">
                <div class="text-[10px] uppercase font-bold text-slate-400 tracking-wider">C. Kualitas Data AIS</div>
                <div class="p-2.5 rounded-xl bg-slate-950/80 border border-slate-800 space-y-1.5 divide-y divide-slate-800/80">
                    <div class="flex items-center justify-between pt-1 first:pt-0">
                        <span class="text-slate-400">Waktu Laporan:</span>
                        <span id="panelVesselTime" class="text-slate-200 truncate max-w-[170px]" title="Tidak tersedia">Tidak tersedia</span>
                    </div>
                    <div class="flex items-center justify-between pt-1">
                        <span class="text-slate-400">Umur Data:</span>
                        <span id="panelVesselAge" class="text-teal-300 font-bold">Baru saja</span>
                    </div>
                    <div class="flex items-center justify-between pt-1">
                        <span class="text-slate-400">Sumber Gateway:</span>
                        <span id="panelVesselProvider" class="text-slate-300 font-semibold">AISStream Gateway</span>
                    </div>
                </div>
            </div>

            <!-- D. ANALISIS & KORELASI -->
            <div class="space-y-1.5 font-mono text-[11px]">
                <div class="text-[10px] uppercase font-bold text-slate-400 tracking-wider">D. Analisis &amp; Korelasi</div>
                
                <div class="p-2.5 rounded-xl bg-slate-950/80 border border-slate-800 space-y-2">
                    <div class="space-y-1">
                        <div class="flex items-center justify-between">
                            <span class="text-slate-400">Riwayat Lintasan:</span>
                            <span id="panelTrackPointBadge" class="px-1.5 py-0.2 rounded text-[9px] font-mono font-bold bg-cyan-950 text-cyan-300 border border-cyan-800">0 titik</span>
                        </div>
                        <div class="grid grid-cols-4 gap-1 text-[11px]">
                            <button type="button" class="btn-track-range px-2 py-1 rounded-lg bg-slate-950 hover:bg-slate-800 border border-slate-800 text-slate-300 transition text-center" data-range="1h">1 Jam</button>
                            <button type="button" class="btn-track-range px-2 py-1 rounded-lg bg-slate-950 hover:bg-slate-800 border border-slate-800 text-slate-300 transition text-center" data-range="6h">6 Jam</button>
                            <button type="button" class="btn-track-range px-2 py-1 rounded-lg bg-cyan-950 border border-cyan-500 text-cyan-300 font-bold transition text-center" data-range="24h">24 Jam</button>
                            <button type="button" class="btn-track-range px-2 py-1 rounded-lg bg-slate-950 hover:bg-slate-800 border border-slate-800 text-slate-300 transition text-center" data-range="7d">7 Hari</button>
                        </div>
                        <div class="flex items-center gap-1.5 pt-1">
                            <button type="button" id="panelBtnFitTrack" class="flex-1 px-2 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-sky-300 border border-slate-700 font-semibold text-center transition flex items-center justify-center gap-1">
                                <span>🧭</span> <span>Fit Track</span>
                            </button>
                            <button type="button" id="panelBtnToggleTrack" class="flex-1 px-2 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 border border-slate-700 font-semibold text-center transition flex items-center justify-center gap-1">
                                <span id="panelToggleTrackIcon">👁️</span> <span id="panelToggleTrackText">Sembunyikan</span>
                            </button>
                        </div>
                    </div>

                    <div class="pt-2 border-t border-slate-800/80 space-y-1.5">
                        <div class="flex items-center justify-between">
                            <span class="text-slate-400">Anomali / Dark:</span>
                            <span id="panelVesselDarkVesselStatus" class="text-slate-300 font-semibold">Tidak ada anomali terdeteksi</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-slate-400">Pertemuan Encounter:</span>
                            <span id="panelVesselEncounterStatus" class="text-slate-300 font-semibold">0 kejadian terdeteksi</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-slate-400">Korelasi Citra SAR:</span>
                            <span id="panelVesselSarStatus" class="text-slate-300 font-semibold">Tidak ada korelasi citra</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- E. AKSI CEPAT (Footer) -->
        <div class="p-3 border-t border-slate-700/80 bg-slate-950/80 flex flex-wrap items-center gap-1.5 shrink-0">
            <a id="panelVesselProfileLink" href="#" class="flex-1 px-3 py-2 rounded-xl bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold text-center transition shadow-sm flex items-center justify-center gap-1.5">
                <span>Profil Lengkap</span>
                <span>&rarr;</span>
            </a>
            <button type="button" id="panelBtnCenterVessel" class="px-2.5 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-sky-300 border border-slate-700 font-semibold transition flex items-center gap-1" title="Pusatkan peta ke kapal">
                <span>🎯</span>
                <span class="hidden sm:inline">Pusatkan</span>
            </button>
            <button type="button" id="panelBtnCopyMmsi" class="px-2.5 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 font-semibold transition flex items-center gap-1" title="Salin nomor MMSI">
                <span>📋</span>
                <span class="hidden sm:inline">MMSI</span>
            </button>
            <button type="button" id="panelBtnShareUrl" class="px-2.5 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 font-semibold transition flex items-center gap-1" title="Salin tautan posisi kapal ini">
                <span>🔗</span>
            </button>
        </div>
    </div>

</div>

<!-- Data Sources, Compliance & Provenance Modal -->
<div id="metadataModal" class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm hidden items-center justify-center p-4">
    <div class="glass-panel w-full max-w-xl p-6 rounded-2xl border border-slate-700 shadow-2xl space-y-4 text-xs">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <h3 class="text-sm font-bold text-white uppercase tracking-wider flex items-center gap-2">
                <span>📜</span>
                <span>Sumber Data, Lisensi &amp; Atribusi Geospasial</span>
            </h3>
            <button type="button" id="btnCloseMetadataModal" class="text-slate-400 hover:text-white text-lg font-bold">&times;</button>
        </div>

        <div class="space-y-3 max-h-[60vh] overflow-y-auto pr-2 text-slate-300">
            <div class="p-3 rounded-xl bg-slate-900 border border-slate-800 space-y-1">
                <div class="font-bold text-purple-300">1. Sentinel-1 C-SAR Radar Scenes (Copernicus)</div>
                <div>Sumber: European Space Agency (ESA) &bull; Copernicus Data Space Ecosystem</div>
                <div>Lisensi: Copernicus Sentinel Data Legal Notice (Open Access)</div>
                <div class="text-[11px] text-slate-400 italic">Cakupan citra SAR C-band Synthetic Aperture Radar untuk deteksi anomali maritim.</div>
            </div>

            <div class="p-3 rounded-xl bg-slate-900 border border-slate-800 space-y-1">
                <div class="font-bold text-emerald-300">2. ASEAN Exclusive Economic Zones (EEZ) &amp; Maritime Boundaries</div>
                <div>Sumber: Flanders Marine Institute (VLIZ) Marine Regions v12</div>
                <div>Lisensi: Creative Commons Attribution 4.0 International (CC BY 4.0)</div>
                <div class="text-[11px] text-slate-400 italic">Batas yurisdiksi maritim 200 NM dan garis delimitasi resmi negara anggota ASEAN &amp; Timor-Leste (UNCLOS 1982).</div>
            </div>

            <div class="p-3.5 rounded-xl bg-slate-900 border border-teal-500/30 space-y-1.5">
                <div class="flex items-center justify-between">
                    <div class="font-bold text-teal-300">3. Global Fishing Watch (GFW API v3)</div>
                    <span class="px-2 py-0.2 rounded text-[9px] font-mono font-bold uppercase bg-teal-500/20 text-teal-300 border border-teal-500/40">
                        NON-COMMERCIAL RESEARCH
                    </span>
                </div>
                <div>Sumber: <a href="https://globalfishingwatch.org" target="_blank" rel="noopener noreferrer" class="text-teal-400 hover:underline">Global Fishing Watch</a></div>
                <div>Lisensi: GFW Research &amp; Non-Commercial Terms</div>
                <blockquote class="p-2.5 rounded-lg bg-slate-950/70 border border-slate-800 text-[10px] text-slate-300 italic leading-relaxed">
                    &ldquo;This application uses data and services provided by Global Fishing Watch (GFW) for non-commercial research, educational, and public-interest purposes. GFW data are subject to applicable licensing, attribution, and usage restrictions.&rdquo;
                </blockquote>
                <div class="text-[10px] text-amber-300/90">
                    Pemberitahuan: Aktivitas kapal yang ditampilkan merupakan estimasi atau hasil analisis berdasarkan sumber data, bukan bukti final pelanggaran.
                </div>
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
            { id: 'toggle-layer-asean-eez', key: 'aseanEez' },
            { id: 'toggle-layer-australia-eez', key: 'australiaEez' },
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

        // ----------------------------------------------------
        // POPOVER MANAGEMENT (MUTUALLY EXCLUSIVE)
        // ----------------------------------------------------
        const popovers = [
            { id: 'advancedFiltersPanel', btn: 'btnToggleAdvancedFilters' },
            { id: 'popoverLayers', btn: 'btnToggleLayers' },
            { id: 'popoverLegend', btn: 'btnToggleLegend' },
            { id: 'popoverSettings', btn: 'btnToggleSettings' },
        ];

        function closeAllPopovers(exceptId = null) {
            popovers.forEach(p => {
                if (p.id !== exceptId) {
                    const el = document.getElementById(p.id);
                    if (el) el.classList.add('hidden');
                    const btn = document.getElementById(p.btn);
                    if (btn) btn.classList.remove('ring-1', 'ring-teal-400', 'bg-slate-800');
                }
            });
        }

        popovers.forEach(p => {
            const btn = document.getElementById(p.btn);
            const panel = document.getElementById(p.id);
            if (btn && panel) {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const isHidden = panel.classList.contains('hidden');
                    closeAllPopovers(isHidden ? p.id : null);
                    closeSearchDropdown();
                    if (isHidden) {
                        panel.classList.remove('hidden');
                        btn.classList.add('ring-1', 'ring-teal-400', 'bg-slate-800');
                    } else {
                        panel.classList.add('hidden');
                        btn.classList.remove('ring-1', 'ring-teal-400', 'bg-slate-800');
                    }
                });
            }
        });

        document.getElementById('btnCloseFiltersPanel')?.addEventListener('click', () => closeAllPopovers());
        document.getElementById('btnCloseLayersPopover')?.addEventListener('click', () => closeAllPopovers());
        document.getElementById('btnCloseLegendPopover')?.addEventListener('click', () => closeAllPopovers());
        document.getElementById('btnCloseSettingsPopover')?.addEventListener('click', () => closeAllPopovers());

        // Basemap Style Switcher
        document.querySelectorAll('input[name="basemap_choice"]').forEach(radio => {
            radio.addEventListener('change', (e) => {
                if (e.target.checked) {
                    mapController.setBasemapStyle(e.target.value);
                }
            });
        });

        // Synchronize Settings basemap radio and status label with mapController.currentBasemapKey
        const activeBasemapKey = mapController.currentBasemapKey || 'openfreemap-positron';
        const activeRadio = document.querySelector(`input[name="basemap_choice"][value="${activeBasemapKey}"]`)
            || (activeBasemapKey === 'openfreemap-dark' ? document.querySelector('input[name="basemap_choice"][value="esri-dark"]') : null);
        if (activeRadio) {
            activeRadio.checked = true;
        }
        const activeLabelEl = document.getElementById('currentBasemapLabel');
        if (activeLabelEl) {
            const basemapNames = {
                'openfreemap-positron': 'Positron High Contrast Clean',
                'openfreemap-dark': 'Dark Ocean Marine Style',
                'openfreemap-liberty': 'Nautical Standard Vector',
                'openfreemap-bright': 'Topographic Bright Canvas',
                'esri-dark': 'Dark Ocean Marine Style'
            };
            activeLabelEl.textContent = basemapNames[activeBasemapKey] || activeBasemapKey;
        }

        // Settings to Metadata Modal trigger
        document.getElementById('btnSettingsOpenMetadata')?.addEventListener('click', () => {
            closeAllPopovers();
            const m = document.getElementById('metadataModal');
            if (m) {
                m.classList.remove('hidden');
                m.classList.add('flex');
            }
        });

        // ----------------------------------------------------
        // SEARCH & AUTOCOMPLETE LOGIC
        // ----------------------------------------------------
        const searchInput = document.getElementById('inputVesselSearch');
        const searchDropdown = document.getElementById('searchResultsDropdown');
        const btnClearSearch = document.getElementById('btnClearSearch');
        const btnFilterSearch = document.getElementById('btnFilterSearch');
        let searchDebounceTimer = null;

        function closeSearchDropdown() {
            if (searchDropdown) {
                searchDropdown.classList.add('hidden');
                searchDropdown.innerHTML = '';
            }
        }

        async function executeSearchAutocomplete(query) {
            if (!query || query.trim().length < 1) {
                closeSearchDropdown();
                return;
            }

            searchDropdown.classList.remove('hidden');
            searchDropdown.innerHTML = `
                <div class="p-3 text-center text-slate-400 font-mono flex items-center justify-center gap-2">
                    <span class="animate-spin text-teal-400">⏳</span> Memindai data kapal & SAR...
                </div>
            `;

            try {
                const res = await fetch(`/api/map/search?q=${encodeURIComponent(query.trim())}`);
                if (!res.ok) throw new Error(`HTTP Error ${res.status}`);
                const data = await res.json();

                if (!data.results || data.results.length === 0) {
                    searchDropdown.innerHTML = `
                        <div class="p-3 text-center text-slate-400 font-mono">
                            Tidak ada kapal atau kandidat SAR yang cocok dengan "<strong>${escapeHtml(query)}</strong>".
                        </div>
                    `;
                    return;
                }

                // Group results
                const vessels = data.results.filter(r => r.type === 'vessel');
                const sars = data.results.filter(r => r.type === 'sar_candidate');

                let html = '';

                if (vessels.length > 0) {
                    html += `
                        <div class="pt-1 first:pt-0">
                            <div class="px-2 py-1 text-[10px] uppercase font-bold text-sky-400 font-mono tracking-wider flex items-center justify-between">
                                <span>Armada Kapal AIS (${vessels.length})</span>
                                <span>🚢</span>
                            </div>
                            <div class="space-y-1 mt-1">
                                ${vessels.map(v => {
                                    const isFishing = (v.vessel_type || '').toLowerCase() === 'fishing' || (v.badge || '').toLowerCase().includes('fishing') || (v.subtitle || '').toLowerCase().includes('fishing');
                                    const badgeStyle = isFishing
                                        ? 'bg-[#F59E0B]/20 text-[#F59E0B] border border-[#F59E0B]/60 shadow-[0_0_8px_rgba(245,158,11,0.3)] font-bold'
                                        : 'bg-sky-950 text-sky-300 border border-sky-800 font-bold';
                                    const titleClass = isFishing ? 'text-[#F59E0B] font-extrabold' : 'text-white font-bold';
                                    const borderHover = isFishing ? 'hover:border-[#F59E0B]/50 hover:bg-[#F59E0B]/5' : 'hover:border-slate-700 hover:bg-slate-800/80';
                                    return `
                                        <div class="search-result-item p-2 rounded-lg cursor-pointer transition flex items-center justify-between border border-transparent ${borderHover}"
                                             data-type="vessel" data-id="${v.id}" data-lat="${v.lat}" data-lng="${v.lng}" data-title="${escapeHtml(v.title)}">
                                            <div class="space-y-0.5 truncate pr-2">
                                                <div class="${titleClass} flex items-center gap-1.5 truncate">
                                                    <span>${v.flag_emoji || '🌐'}</span>
                                                    <span class="truncate">${escapeHtml(v.title)}</span>
                                                    ${isFishing ? '<span class="text-[9px] px-1 py-0.2 rounded bg-[#F59E0B]/20 text-[#F59E0B] border border-[#F59E0B]/40">🎣</span>' : ''}
                                                </div>
                                                <div class="text-[10px] font-mono text-slate-400 truncate">${v.subtitle}</div>
                                                ${v.sog ? `<div class="text-[9px] font-mono ${isFishing ? 'text-[#F59E0B]' : 'text-sky-300'}">${v.sog} &bull; ${v.cog || '0°'}</div>` : ''}
                                            </div>
                                            <div class="text-right shrink-0">
                                                <span class="px-1.5 py-0.5 rounded text-[9px] font-mono ${badgeStyle}">${v.badge}</span>
                                                <div class="text-[9px] text-teal-400 mt-1 font-mono">Fokus &rarr;</div>
                                            </div>
                                        </div>
                                    `;
                                }).join('')}
                            </div>
                        </div>
                    `;
                }

                if (sars.length > 0) {
                    html += `
                        <div class="pt-2">
                            <div class="px-2 py-1 text-[10px] uppercase font-bold text-amber-400 font-mono tracking-wider flex items-center justify-between">
                                <span>Kandidat Radar SAR (${sars.length})</span>
                                <span>🎯</span>
                            </div>
                            <div class="space-y-1 mt-1">
                                ${sars.map(s => `
                                    <div class="search-result-item p-2 rounded-lg hover:bg-slate-800/80 cursor-pointer transition flex items-center justify-between border border-transparent hover:border-slate-700"
                                         data-type="sar_candidate" data-id="${s.id}" data-lat="${s.lat}" data-lng="${s.lng}" data-title="${escapeHtml(s.title)}">
                                        <div class="space-y-0.5 truncate pr-2">
                                            <div class="font-bold text-amber-300 font-mono flex items-center gap-1.5 truncate">
                                                <span>🎯</span>
                                                <span class="truncate">${escapeHtml(s.title)}</span>
                                            </div>
                                            <div class="text-[10px] font-mono text-slate-400 truncate">${s.subtitle}</div>
                                        </div>
                                        <div class="text-right shrink-0">
                                            <span class="px-1.5 py-0.5 rounded text-[9px] font-mono font-bold bg-amber-950 text-amber-300 border border-amber-800">${s.badge}</span>
                                            <div class="text-[9px] text-teal-400 mt-1 font-mono">Fokus &rarr;</div>
                                        </div>
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                    `;
                }

                searchDropdown.innerHTML = html;

                searchDropdown.querySelectorAll('.search-result-item').forEach(item => {
                    item.addEventListener('click', () => {
                        const type = item.getAttribute('data-type');
                        const id = item.getAttribute('data-id');
                        const lat = parseFloat(item.getAttribute('data-lat'));
                        const lng = parseFloat(item.getAttribute('data-lng'));
                        const title = item.getAttribute('data-title');

                        searchInput.value = title;
                        btnClearSearch.classList.remove('hidden');
                        closeSearchDropdown();

                        mapController.focusEntity(type, id, lat, lng);
                    });
                });

            } catch (err) {
                console.error('[Search] Error fetching autocomplete:', err);
                searchDropdown.innerHTML = `
                    <div class="p-3 text-center text-rose-400 font-mono">
                        Terjadi kesalahan saat memuat hasil pencarian.
                    </div>
                `;
            }
        }

        if (searchInput) {
            searchInput.addEventListener('input', (e) => {
                const val = e.target.value;
                if (btnClearSearch) {
                    btnClearSearch.classList.toggle('hidden', !val);
                }
                clearTimeout(searchDebounceTimer);
                if (val.trim().length >= 1) {
                    searchDebounceTimer = setTimeout(() => executeSearchAutocomplete(val), 250);
                } else {
                    closeSearchDropdown();
                }
            });

            searchInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    closeSearchDropdown();
                    mapController.applyFilters({ search: searchInput.value.trim() });
                }
            });
        }

        if (btnClearSearch) {
            btnClearSearch.addEventListener('click', () => {
                searchInput.value = '';
                btnClearSearch.classList.add('hidden');
                closeSearchDropdown();
                mapController.applyFilters({ search: '' });
            });
        }

        if (btnFilterSearch) {
            btnFilterSearch.addEventListener('click', () => {
                closeSearchDropdown();
                const q = searchInput ? searchInput.value.trim() : '';
                mapController.applyFilters({ search: q });
            });
        }

        // ----------------------------------------------------
        // ADVANCED FILTERS LOGIC
        // ----------------------------------------------------
        const panelFilters = document.getElementById('advancedFiltersPanel');
        const btnApplyFilters = document.getElementById('btnApplyFilters');
        const btnResetFilters = document.getElementById('btnResetFilters');
        const selectDateRange = document.getElementById('filter-date-range');
        const customDateRangeContainer = document.getElementById('customDateRangeContainer');

        if (selectDateRange && customDateRangeContainer) {
            selectDateRange.addEventListener('change', (e) => {
                customDateRangeContainer.classList.toggle('hidden', e.target.value !== 'custom');
            });
        }

        if (btnApplyFilters) {
            btnApplyFilters.addEventListener('click', () => {
                const minSogVal = document.getElementById('filter-min-sog')?.value;
                const maxSogVal = document.getElementById('filter-max-sog')?.value;
                const minCogVal = document.getElementById('filter-min-cog')?.value;
                const maxCogVal = document.getElementById('filter-max-cog')?.value;
                const minTcrVal = document.getElementById('filter-min-tcr')?.value;
                const maxTcrVal = document.getElementById('filter-max-tcr')?.value;

                const collected = {
                    search: searchInput ? searchInput.value.trim() : '',
                    flag: document.getElementById('filter-vessel-flag')?.value || '',
                    vesselType: document.getElementById('filter-vessel-type')?.value || '',
                    minSog: minSogVal !== '' && !isNaN(minSogVal) ? parseFloat(minSogVal) : null,
                    maxSog: maxSogVal !== '' && !isNaN(maxSogVal) ? parseFloat(maxSogVal) : null,
                    minCog: minCogVal !== '' && !isNaN(minCogVal) ? parseFloat(minCogVal) : null,
                    maxCog: maxCogVal !== '' && !isNaN(maxCogVal) ? parseFloat(maxCogVal) : null,
                    headingStatus: document.getElementById('filter-heading-status')?.value || '',
                    freshness: document.getElementById('filter-freshness')?.value || '',
                    reviewStatus: document.getElementById('filter-sar-review')?.value || '',
                    correlationStatus: document.getElementById('filter-sar-correlation')?.value || '',
                    minTcr: minTcrVal !== '' && !isNaN(minTcrVal) ? parseFloat(minTcrVal) : null,
                    maxTcr: maxTcrVal !== '' && !isNaN(maxTcrVal) ? parseFloat(maxTcrVal) : null,
                    platform: document.getElementById('filter-sar-platform')?.value || '',
                    dateRange: selectDateRange?.value || 'all',
                    startDate: document.getElementById('filter-start-date')?.value || '',
                    endDate: document.getElementById('filter-end-date')?.value || '',
                    trackWindow: document.getElementById('filter-track-window')?.value || 'off',
                };

                mapController.applyFilters(collected);
                closeAllPopovers();
            });
        }

        if (btnResetFilters) {
            btnResetFilters.addEventListener('click', () => {
                const form = document.getElementById('formAdvancedFilters');
                if (form) form.reset();
                if (customDateRangeContainer) customDateRangeContainer.classList.add('hidden');
                mapController.resetFilters();
            });
        }

        // Reset View
        const btnReset = document.getElementById('btnResetView');
        if (btnReset) {
            btnReset.addEventListener('click', () => {
                mapController.map.flyTo({ center: [100.0, 5.0], zoom: 3.5 });
            });
        }

        // Active Vessel Track Controller
        const trackBanner = document.getElementById('activeTrackBanner');
        const trackLabel = document.getElementById('activeTrackVesselLabel');
        const trackBadge = document.getElementById('activeTrackPointCountBadge');
        const btnFitTrack = document.getElementById('btnFitActiveTrack');
        const btnToggleTrack = document.getElementById('btnToggleActiveTrack');
        const btnClearTrack = document.getElementById('btnClearActiveTrack');
        const toggleIcon = document.getElementById('toggleTrackIcon');
        const toggleLabel = document.getElementById('toggleTrackLabel');

        mapController.onTrackUpdated = (geoJson) => {
            if (!trackBanner) return;
            if (geoJson && geoJson.properties && geoJson.properties.position_count > 0) {
                trackBanner.classList.remove('hidden');
                if (trackLabel) trackLabel.textContent = `Track: ${geoJson.properties.name || geoJson.properties.mmsi}`;
                if (trackBadge) trackBadge.textContent = `${geoJson.properties.position_count} pts`;
            } else if (geoJson && geoJson.properties && geoJson.properties.track_status === 'empty') {
                trackBanner.classList.remove('hidden');
                if (trackLabel) trackLabel.textContent = `Track: ${geoJson.properties.name || geoJson.properties.mmsi} (Kosong)`;
                if (trackBadge) trackBadge.textContent = `0 pts`;
            } else {
                trackBanner.classList.add('hidden');
            }
        };

        mapController.onTrackVisibilityChanged = (visible) => {
            if (toggleLabel && toggleIcon) {
                toggleLabel.textContent = visible ? 'Hide Track' : 'Show Track';
                toggleIcon.textContent = visible ? '👁️' : '🙈';
            }
        };

        if (btnFitTrack) {
            btnFitTrack.addEventListener('click', () => mapController.fitVesselTrack());
        }
        if (btnToggleTrack) {
            btnToggleTrack.addEventListener('click', () => {
                if (mapController.trackVisible) {
                    mapController.hideVesselTrack();
                } else {
                    mapController.showTrackLayer();
                }
            });
        }
        if (btnClearTrack) {
            btnClearTrack.addEventListener('click', () => mapController.clearVesselTrack());
        }

        // ----------------------------------------------------
        // VESSEL DETAIL PANEL & TRACK CONTROLS
        // ----------------------------------------------------
        const btnCloseVesselPanel = document.getElementById('btnCloseVesselPanel');
        const panelBtnFitTrack = document.getElementById('panelBtnFitTrack');
        const panelBtnToggleTrack = document.getElementById('panelBtnToggleTrack');
        const panelBtnShareUrl = document.getElementById('panelBtnShareUrl');
        const panelToggleIcon = document.getElementById('panelToggleTrackIcon');
        const panelToggleText = document.getElementById('panelToggleTrackText');

        if (btnCloseVesselPanel) {
            btnCloseVesselPanel.addEventListener('click', () => {
                mapController.closeVesselDetailPanel();
            });
        }

        if (panelBtnFitTrack) {
            panelBtnFitTrack.addEventListener('click', () => {
                mapController.fitVesselTrack();
            });
        }

        if (panelBtnToggleTrack) {
            panelBtnToggleTrack.addEventListener('click', () => {
                if (mapController.trackVisible) {
                    mapController.hideVesselTrack();
                    if (panelToggleText) panelToggleText.textContent = 'Tampilkan';
                    if (panelToggleIcon) panelToggleIcon.textContent = '🙈';
                } else {
                    mapController.showTrackLayer();
                    if (panelToggleText) panelToggleText.textContent = 'Sembunyikan';
                    if (panelToggleIcon) panelToggleIcon.textContent = '👁️';
                }
            });
        }

        // Track time window filter buttons (1h, 6h, 24h, 7d)
        document.querySelectorAll('.btn-track-range').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const range = e.currentTarget.getAttribute('data-range');
                document.querySelectorAll('.btn-track-range').forEach(b => {
                    b.className = 'btn-track-range px-2 py-1 rounded-lg bg-slate-950 hover:bg-slate-800 border border-slate-800 text-slate-300 transition text-center';
                });
                e.currentTarget.className = 'btn-track-range px-2 py-1 rounded-lg bg-cyan-950 border border-cyan-500 text-cyan-300 font-bold transition text-center';
                const targetId = mapController.selectedVesselId || mapController.selectedMmsi;
                if (targetId) {
                    mapController.showVesselTrack(targetId, { range: range, fitBounds: false });
                }
            });
        });

        if (panelBtnShareUrl) {
            panelBtnShareUrl.addEventListener('click', () => {
                const url = window.location.href;
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(url).then(() => {
                        const originalHtml = panelBtnShareUrl.innerHTML;
                        panelBtnShareUrl.innerHTML = '<span>✓</span> <span class="hidden sm:inline">Tersalin!</span>';
                        panelBtnShareUrl.classList.add('text-emerald-300', 'border-emerald-500/50');
                        setTimeout(() => {
                            panelBtnShareUrl.innerHTML = originalHtml;
                            panelBtnShareUrl.classList.remove('text-emerald-300', 'border-emerald-500/50');
                        }, 2000);
                    }).catch(() => {
                        prompt('Salin tautan posisi kapal ini:', url);
                    });
                } else {
                    prompt('Salin tautan posisi kapal ini:', url);
                }
            });
        }

        // Close Popovers and Panels on Outside Click
        document.addEventListener('click', (e) => {
            if (searchDropdown && !searchDropdown.contains(e.target) && !searchInput.contains(e.target)) {
                closeSearchDropdown();
            }
            const isInsidePopover = popovers.some(p => {
                const panel = document.getElementById(p.id);
                const btn = document.getElementById(p.btn);
                return (panel && panel.contains(e.target)) || (btn && btn.contains(e.target));
            });
            if (!isInsidePopover) {
                closeAllPopovers();
            }
        });

        // Close panels on Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeAllPopovers();
                closeSearchDropdown();
                mapController.closeVesselDetailPanel();
            }
        });

        // Metadata Modal
        const modal = document.getElementById('metadataModal');
        const btnClose = document.getElementById('btnCloseMetadataModal');
        const btnConfirmClose = document.getElementById('btnConfirmCloseMetadata');

        const closeModal = () => {
            if (modal) {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            }
        };

        if (btnClose) btnClose.addEventListener('click', closeModal);
        if (btnConfirmClose) btnConfirmClose.addEventListener('click', closeModal);
    }

    function escapeHtml(text) {
        if (!text) return '';
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeGlobalMap);
    } else {
        initializeGlobalMap();
    }
</script>
@endpush
