@extends('layouts.app')

@section('header_title', 'Global Vessel Map — MapLibre GL JS Spatial Platform')

@section('content')
@php
    $flags = $filterMeta['flags'] ?? [];
    $vesselTypes = $filterMeta['vessel_types'] ?? [];
    $reviewStatuses = $filterMeta['review_statuses'] ?? [];
    $correlationStatuses = $filterMeta['correlation_statuses'] ?? [];
    $platforms = $filterMeta['platforms'] ?? [];
@endphp
<div class="relative h-[calc(100vh-8.5rem)] min-h-[580px] rounded-2xl overflow-hidden border border-slate-800 shadow-2xl flex flex-col bg-slate-950">

    <!-- Top Floating Toolbar: Quick Actions, Search & Fit Controls -->
    <div class="absolute top-4 left-4 right-4 z-10 flex flex-wrap items-center justify-between gap-3 pointer-events-none">
        
        <!-- Search, Filter & View Navigation Controls -->
        <div class="pointer-events-auto flex flex-wrap items-center gap-2 p-1.5 rounded-xl glass-panel border border-slate-700/80 shadow-lg text-xs relative">
            <div class="relative">
                <input type="text" id="inputVesselSearch" placeholder="Search vessel, MMSI, or candidate..."
                    class="pl-7 pr-7 py-1.5 rounded-lg bg-slate-900 border border-slate-700 text-slate-200 placeholder-slate-500 focus:outline-none focus:border-teal-400 w-56 sm:w-72 text-xs font-mono">
                <span class="absolute left-2.5 top-1.5 text-slate-400">🔍</span>
                <button type="button" id="btnClearSearch" class="hidden absolute right-2 top-1.5 text-slate-400 hover:text-white text-xs font-bold" title="Hapus pencarian">&times;</button>
            </div>

            <!-- Unified Search Dropdown Panel (Live Query Results) -->
            <div id="searchResultsDropdown" class="hidden absolute top-full left-0 mt-2 w-72 sm:w-96 max-h-80 overflow-y-auto glass-panel p-2 rounded-xl border border-slate-700/90 shadow-2xl z-30 space-y-2 text-xs divide-y divide-slate-800/80">
                <!-- Injected via JavaScript from /api/map/search -->
            </div>

            <button type="button" id="btnFilterSearch" class="px-3 py-1.5 rounded-lg bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold transition flex items-center gap-1.5">
                <span>Cari</span>
            </button>

            <!-- Advanced Filters Toggle Button with Sliders Icon & Active Count Badge -->
            <button type="button" id="btnToggleAdvancedFilters" class="px-3 py-1.5 rounded-lg bg-slate-900 hover:bg-slate-800 text-teal-300 font-semibold transition border border-slate-700 flex items-center gap-1.5 relative" title="Buka/Tutup Panel Advanced Filters">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="4" y1="21" x2="4" y2="14"></line>
                    <line x1="4" y1="10" x2="4" y2="3"></line>
                    <line x1="12" y1="21" x2="12" y2="12"></line>
                    <line x1="12" y1="8" x2="12" y2="3"></line>
                    <line x1="20" y1="21" x2="20" y2="16"></line>
                    <line x1="20" y1="12" x2="20" y2="3"></line>
                    <line x1="1" y1="14" x2="7" y2="14"></line>
                    <line x1="9" y1="8" x2="15" y2="8"></line>
                    <line x1="17" y1="16" x2="23" y2="16"></line>
                </svg>
                <span>Filters</span>
                <span id="activeFilterBadgeToolbar" class="active-filter-badge hidden px-1.5 py-0.2 rounded-full text-[9px] bg-teal-400 text-slate-950 font-bold">0</span>
            </button>

            <!-- Reset View Button -->
            <button type="button" id="btnResetView" class="px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 font-medium transition border border-slate-700" title="Pusatkan peta ke tampilan awal">
                Reset View
            </button>
        </div>

        <!-- Coverage & Metadata Modal Trigger -->
        <div class="pointer-events-auto flex items-center gap-2 p-1.5 rounded-xl glass-panel border border-slate-700/80 shadow-lg text-[11px]">
            <span class="text-slate-400 font-semibold px-2">Kawasan: <strong class="text-emerald-400">ASEAN EEZ &amp; AIS Fleet</strong></span>
            <button type="button" id="btnOpenMetadataModal" class="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-teal-300 border border-slate-700 transition">
                📜 Provenance & Legal
            </button>
        </div>
    </div>

    <!-- Active Vessel Track Controller Banner (Tahap 11) -->
    <div id="activeTrackBanner" class="hidden absolute top-16 left-4 z-20 pointer-events-auto flex flex-wrap items-center gap-2 p-1.5 rounded-xl glass-panel border border-cyan-500/50 shadow-2xl text-xs backdrop-blur-md">
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

    <!-- Map Canvas Container -->
    <div id="global-map-canvas" class="w-full h-full flex-1 bg-slate-950 min-h-[480px]"></div>

    <!-- Bottom Coordinates & Map Scale Status Bar -->
    <div class="absolute bottom-2 left-4 z-10 pointer-events-none flex flex-wrap items-center gap-2">
        <div class="pointer-events-auto px-2.5 py-1 rounded-lg glass-panel border border-slate-700/80 shadow-md text-[11px] font-mono text-slate-300 flex items-center gap-2">
            <span class="text-teal-400 font-bold">📍</span>
            <span id="cursorCoordinates">Lat: 0.00000°, Lon: 0.00000° | Zoom: 3.50</span>
        </div>
        <div class="pointer-events-auto hidden md:flex items-center gap-1.5 px-2.5 py-1 rounded-lg glass-panel border border-slate-700/80 shadow-md text-[11px] font-mono text-slate-400">
            <span>Kawasan:</span>
            <span class="text-emerald-400 font-semibold">ASEAN Exclusive Economic Zones</span>
        </div>
    </div>

    <!-- Floating Right-Side Vessel Detail Panel (Open Waters AIS Approach) -->
    <div id="vesselDetailPanel" class="hidden absolute top-4 right-4 bottom-4 z-20 w-[calc(100%-2rem)] sm:w-96 max-w-sm max-h-[calc(100%-2rem)] flex flex-col bg-slate-900/95 border border-slate-700/80 rounded-2xl shadow-2xl backdrop-blur-md overflow-hidden transition-all duration-300 ease-in-out text-xs">
        <!-- Header -->
        <div class="p-3.5 border-b border-slate-700/80 bg-slate-950/70 flex items-start justify-between gap-2 shrink-0">
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2">
                    <span id="panelVesselFlagEmoji" class="text-xl shrink-0">🌐</span>
                    <h2 id="panelVesselName" class="font-bold text-white text-sm truncate font-sans tracking-wide">Nama Kapal</h2>
                </div>
                <div class="flex items-center gap-2 mt-1 text-[11px] font-mono">
                    <span id="panelVesselFlagName" class="text-slate-400 truncate">Indonesia</span>
                    <span id="panelVesselTypeBadge" class="px-1.5 py-0.2 rounded text-[9px] font-bold uppercase bg-emerald-950 text-emerald-300 border border-emerald-700">Fishing</span>
                </div>
            </div>
            <div class="flex items-center gap-1.5 shrink-0">
                <span id="panelVesselFreshnessBadge" class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/40">● LIVE</span>
                <button type="button" id="btnCloseVesselPanel" class="p-1 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition text-lg font-bold leading-none" title="Tutup panel detail (Esc)">&times;</button>
            </div>
        </div>

        <!-- Scrollable Content -->
        <div class="flex-1 overflow-y-auto p-4 space-y-4">
            <!-- Telemetry Summary Cards -->
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
                        <span id="panelVesselHeading">-</span>
                    </div>
                </div>
                <div class="p-2.5 rounded-xl bg-slate-950/80 border border-slate-800">
                    <div class="text-[10px] text-slate-400 uppercase tracking-wider">Status Nav</div>
                    <div id="panelVesselNavStatus" class="text-xs font-semibold text-slate-200 mt-0.5 truncate capitalize">
                        Under way
                    </div>
                </div>
            </div>

            <!-- Position, Orientation & Timestamp -->
            <div class="p-2.5 rounded-xl bg-slate-950/80 border border-slate-800 space-y-1.5 font-mono text-[11px]">
                <div class="flex items-center justify-between">
                    <span class="text-slate-400">Koordinat:</span>
                    <span id="panelVesselCoords" class="text-slate-200 font-bold select-all">0.0000°, 0.0000°</span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-slate-400">Orientasi:</span>
                    <span id="panelVesselOrientation" class="text-slate-300 font-semibold">-</span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-slate-400">Laporan Terakhir:</span>
                    <span id="panelVesselTime" class="text-slate-300 truncate max-w-[180px]" title="-">-</span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-slate-400">Sumber Data:</span>
                    <span id="panelVesselProvider" class="text-teal-300 font-semibold">AISStream Gateway</span>
                </div>
            </div>

            <!-- Identification Table -->
            <div class="space-y-1.5 font-mono text-[11px]">
                <div class="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Identitas Kapal</div>
                <div class="p-2.5 rounded-xl bg-slate-950/80 border border-slate-800 space-y-1.5 divide-y divide-slate-800/80">
                    <div class="flex items-center justify-between pt-1 first:pt-0">
                        <span class="text-slate-400">MMSI:</span>
                        <strong id="panelVesselMmsi" class="text-white font-bold select-all">-</strong>
                    </div>
                    <div class="flex items-center justify-between pt-1">
                        <span class="text-slate-400">IMO:</span>
                        <strong id="panelVesselImo" class="text-white">-</strong>
                    </div>
                    <div class="flex items-center justify-between pt-1">
                        <span class="text-slate-400">Callsign:</span>
                        <strong id="panelVesselCallsign" class="text-white">-</strong>
                    </div>
                    <div class="flex items-center justify-between pt-1">
                        <span class="text-slate-400">Bendera:</span>
                        <span id="panelVesselFlagFull" class="text-slate-200 font-semibold truncate max-w-[160px]">-</span>
                    </div>
                    <div class="flex items-center justify-between pt-1">
                        <span class="text-slate-400">Tipe Kapal:</span>
                        <span id="panelVesselTypeDesc" class="text-slate-200 capitalize">-</span>
                    </div>
                </div>
            </div>

            <!-- Historical Track Section (Open Waters AIS Approach) -->
            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <div class="text-[10px] uppercase font-bold text-slate-400 tracking-wider font-mono">Lintasan Historis AIS</div>
                    <span id="panelTrackPointBadge" class="px-1.5 py-0.2 rounded text-[9px] font-mono font-bold bg-cyan-950 text-cyan-300 border border-cyan-800">0 titik</span>
                </div>
                
                <!-- Time Window Selector Pills -->
                <div class="grid grid-cols-4 gap-1 text-[11px] font-mono">
                    <button type="button" class="btn-track-range px-2 py-1 rounded-lg bg-slate-950 hover:bg-slate-800 border border-slate-800 text-slate-300 transition text-center" data-range="1h">1 Jam</button>
                    <button type="button" class="btn-track-range px-2 py-1 rounded-lg bg-slate-950 hover:bg-slate-800 border border-slate-800 text-slate-300 transition text-center" data-range="6h">6 Jam</button>
                    <button type="button" class="btn-track-range px-2 py-1 rounded-lg bg-cyan-950 border border-cyan-500 text-cyan-300 font-bold transition text-center" data-range="24h">24 Jam</button>
                    <button type="button" class="btn-track-range px-2 py-1 rounded-lg bg-slate-950 hover:bg-slate-800 border border-slate-800 text-slate-300 transition text-center" data-range="7d">7 Hari</button>
                </div>

                <div class="flex items-center gap-1.5 pt-1">
                    <button type="button" id="panelBtnFitTrack" class="flex-1 px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-sky-300 border border-slate-700 font-semibold text-center transition flex items-center justify-center gap-1">
                        <span>🧭</span> <span>Fit Track</span>
                    </button>
                    <button type="button" id="panelBtnToggleTrack" class="flex-1 px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 border border-slate-700 font-semibold text-center transition flex items-center justify-center gap-1">
                        <span id="panelToggleTrackIcon">👁️</span> <span id="panelToggleTrackText">Sembunyikan</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Footer Actions -->
        <div class="p-3 border-t border-slate-700/80 bg-slate-950/80 flex items-center gap-2 shrink-0">
            <a id="panelVesselProfileLink" href="#" class="flex-1 px-3 py-2 rounded-xl bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold text-center transition shadow-sm flex items-center justify-center gap-1.5">
                <span>Profil Kapal Lengkap</span>
                <span>&rarr;</span>
            </a>
            <button type="button" id="panelBtnShareUrl" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 font-semibold transition flex items-center gap-1" title="Salin tautan posisi kapal ini">
                <span>🔗</span>
                <span class="hidden sm:inline">Bagikan</span>
            </button>
        </div>
    </div>

    <!-- LEFT: Modern Advanced Maritime Filters Panel (Overlay, non-blocking) -->
    <div id="advancedFiltersPanel" class="hidden absolute top-20 left-4 z-20 w-84 sm:w-96 max-h-[calc(100%-6rem)] overflow-y-auto glass-panel p-4 rounded-2xl border border-slate-700/80 shadow-2xl space-y-3.5 text-xs">

        <!-- Panel Header -->
        <div class="flex items-center justify-between border-b border-slate-700/80 pb-2.5">
            <div class="flex items-center gap-2">
                <span class="text-base text-teal-400">🎚️</span>
                <div>
                    <h3 class="font-bold uppercase tracking-wider text-white text-[12px]">Advanced Maritime Filters</h3>
                    <p class="text-[10px] text-slate-400">Filter operasi intelijen armada & satelit radar</p>
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

                <!-- SOG Range (Speed Over Ground) -->
                <div>
                    <label class="block text-[10px] uppercase text-slate-400 font-mono mb-1">Rentang Kecepatan SOG (knots)</label>
                    <div class="grid grid-cols-2 gap-2">
                        <input type="number" id="filter-min-sog" placeholder="Min (0 kn)" min="0" max="60" step="0.5" class="px-2 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs focus:outline-none focus:border-teal-400 font-mono">
                        <input type="number" id="filter-max-sog" placeholder="Max (kn)" min="0" max="60" step="0.5" class="px-2 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs focus:outline-none focus:border-teal-400 font-mono">
                    </div>
                </div>

                <!-- COG Range (Course Over Ground) -->
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

                <!-- Review Status -->
                <div>
                    <label for="filter-sar-review" class="block text-[10px] uppercase text-slate-400 font-mono mb-1">Status Review Analis</label>
                    <select id="filter-sar-review" class="w-full px-2.5 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs focus:outline-none focus:border-teal-400">
                        <option value="">Semua Status Review</option>
                        @foreach($reviewStatuses as $val => $lbl)
                            <option value="{{ $val }}">{{ $lbl }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Correlation Status -->
                <div>
                    <label for="filter-sar-correlation" class="block text-[10px] uppercase text-slate-400 font-mono mb-1">Status Korelasi AIS</label>
                    <select id="filter-sar-correlation" class="w-full px-2.5 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs focus:outline-none focus:border-teal-400">
                        <option value="">Semua Status Korelasi</option>
                        @foreach($correlationStatuses as $cVal => $cLbl)
                            <option value="{{ $cVal }}">{{ $cLbl }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- TCR Range -->
                <div>
                    <label class="block text-[10px] uppercase text-slate-400 font-mono mb-1">Rentang TCR Hamburan Radar (dB)</label>
                    <div class="grid grid-cols-2 gap-2">
                        <input type="number" id="filter-min-tcr" placeholder="Min (dB)" step="0.5" class="px-2 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs focus:outline-none focus:border-teal-400 font-mono">
                        <input type="number" id="filter-max-tcr" placeholder="Max (dB)" step="0.5" class="px-2 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs focus:outline-none focus:border-teal-400 font-mono">
                    </div>
                </div>

                <!-- Platform -->
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
                    <span>3. Rentang Waktu & Trajektori</span>
                    <span>⏱️</span>
                </div>

                <!-- Date Range Preset -->
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

                <!-- Custom Date Range Inputs (Shown conditionally) -->
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

                <!-- Track Window -->
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

            <!-- GROUP: MARITIME CONTEXT (ASEAN REGIONAL) -->
            <div class="space-y-1.5 pt-1 border-t border-slate-800">
                <div class="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Konteks Geospasial Maritim Regional</div>

                <!-- ASEAN Exclusive Economic Zones (EEZ) -->
                <label class="flex items-start justify-between p-2 rounded-xl bg-slate-900/80 border border-slate-800 hover:border-emerald-500/50 transition cursor-pointer">
                    <div class="flex items-start gap-2.5">
                        <input type="checkbox" id="toggle-layer-asean-eez" checked class="mt-0.5 rounded bg-slate-800 border-slate-700 text-emerald-500 focus:ring-emerald-400">
                        <div>
                            <div class="font-bold text-slate-200 flex items-center gap-1.5">
                                <span class="w-2.5 h-2.5 rounded-sm bg-emerald-500/20 border border-emerald-500"></span>
                                <span>ASEAN Exclusive Economic Zones (EEZ)</span>
                            </div>
                            <p class="text-[10px] text-slate-400 mt-0.5">Poligon ZEE &amp; Batas Maritim Resmi ASEAN (v12)</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <span id="loading-aseanEez" class="text-[10px] text-emerald-400 animate-spin" style="display: none;">⏳</span>
                        <span id="count-aseanEez" class="text-[10px] font-mono px-1.5 py-0.5 rounded bg-slate-800 text-emerald-300 border border-emerald-900">0</span>
                    </div>
                </label>
            </div>
        </div>

        <!-- Dynamic Maritime Intelligence Legend -->
        <div class="pt-3 border-t border-slate-800 space-y-2.5 text-[11px]">
            <div class="font-bold text-slate-300 uppercase tracking-wider text-[10px] flex items-center justify-between">
                <span>Legenda Simbologi Ilmiah</span>
                <span class="text-teal-400 font-mono text-[9px]">WGS84</span>
            </div>

            <!-- Live AIS Scientific Symbology Legend -->
            <div class="p-2.5 rounded-xl bg-slate-900/90 border border-slate-800 space-y-2 text-[10px] font-mono">
                <div class="text-[9px] uppercase font-bold text-sky-400 tracking-wider flex items-center justify-between">
                    <span>Live AIS Vessels (Posisi Aktual)</span>
                    <span class="w-1.5 h-1.5 rounded-full bg-sky-400 animate-pulse"></span>
                </div>
                <div class="space-y-1.5 text-slate-300">
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

            <!-- ASEAN EEZ Delimitation Status Legend -->
            <div class="p-2.5 rounded-xl bg-slate-900/90 border border-slate-800 space-y-2 text-[10px] font-mono">
                <div class="text-[9px] uppercase font-bold text-emerald-400 tracking-wider flex items-center justify-between">
                    <span>Status Batas Maritim ASEAN</span>
                    <span class="text-slate-400 text-[9px]">Marine Regions v12</span>
                </div>
                <div class="space-y-1.5 text-slate-300">
                    <div class="flex items-center gap-2">
                        <span class="w-4 h-0.5 bg-emerald-500 shrink-0"></span>
                        <span class="truncate">Agreed / Delimitated (Traktat Resmi)</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="w-4 h-0.5 bg-amber-500 border-b border-dashed border-amber-400 shrink-0"></span>
                        <span class="truncate">Undelimited / Unresolved (Median Line)</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="w-4 h-0.5 bg-rose-500 border-b border-dashed border-rose-400 shrink-0"></span>
                        <span class="truncate">Disputed / Overlapping Claim</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="w-4 h-0.5 bg-purple-500 border-b border-dashed border-purple-400 shrink-0"></span>
                        <span class="truncate">Joint Regime Area (Zona Bersama)</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="w-3 h-2 bg-emerald-500/10 border border-emerald-500/40 shrink-0 rounded-sm"></span>
                        <span class="truncate">ASEAN EEZ (Poligon 200 NM)</span>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-2 text-slate-300 text-[10px] font-mono">
                <div class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-emerald-500"></span> Matched AIS</div>
                <div class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-amber-500"></span> Possible Vessel</div>
                <div class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-cyan-400"></span> Possible Ice/Clutter</div>
                <div class="flex items-center gap-1.5"><span class="w-2.5 h-0.5 bg-amber-400"></span> AIS Track</div>
                <div class="flex items-center gap-1.5 col-span-2"><span class="w-2.5 h-2 bg-purple-500/30 border border-purple-400"></span> SAR Footprint</div>
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
                    <div class="font-bold text-teal-300">5. Global Fishing Watch (GFW API v3)</div>
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
                    <span class="animate-spin text-teal-400">⏳</span> Memindai intelijen kapal & SAR...
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
                                ${vessels.map(v => `
                                    <div class="search-result-item p-2 rounded-lg hover:bg-slate-800/80 cursor-pointer transition flex items-center justify-between border border-transparent hover:border-slate-700"
                                         data-type="vessel" data-id="${v.id}" data-lat="${v.lat}" data-lng="${v.lng}" data-title="${escapeHtml(v.title)}">
                                        <div class="space-y-0.5 truncate pr-2">
                                            <div class="font-bold text-white flex items-center gap-1.5 truncate">
                                                <span>${v.flag_emoji || '🌐'}</span>
                                                <span class="truncate">${escapeHtml(v.title)}</span>
                                            </div>
                                            <div class="text-[10px] font-mono text-slate-400 truncate">${v.subtitle}</div>
                                            ${v.sog ? `<div class="text-[9px] font-mono text-sky-300">${v.sog} &bull; ${v.cog || '0°'}</div>` : ''}
                                        </div>
                                        <div class="text-right shrink-0">
                                            <span class="px-1.5 py-0.5 rounded text-[9px] font-mono font-bold bg-sky-950 text-sky-300 border border-sky-800">${v.badge}</span>
                                            <div class="text-[9px] text-teal-400 mt-1 font-mono">Fokus &rarr;</div>
                                        </div>
                                    </div>
                                `).join('')}
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

                // Attach click handlers to search items
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

        // Close search dropdown on click outside
        document.addEventListener('click', (e) => {
            if (searchDropdown && !searchDropdown.contains(e.target) && !searchInput.contains(e.target)) {
                closeSearchDropdown();
            }
        });

        // ----------------------------------------------------
        // ADVANCED FILTERS PANEL LOGIC
        // ----------------------------------------------------
        const panelFilters = document.getElementById('advancedFiltersPanel');
        const btnToggleFilters = document.getElementById('btnToggleAdvancedFilters');
        const btnCloseFilters = document.getElementById('btnCloseFiltersPanel');
        const btnApplyFilters = document.getElementById('btnApplyFilters');
        const btnResetFilters = document.getElementById('btnResetFilters');
        const selectDateRange = document.getElementById('filter-date-range');
        const customDateRangeContainer = document.getElementById('customDateRangeContainer');

        if (btnToggleFilters && panelFilters) {
            btnToggleFilters.addEventListener('click', () => {
                panelFilters.classList.toggle('hidden');
            });
        }

        if (btnCloseFilters && panelFilters) {
            btnCloseFilters.addEventListener('click', () => {
                panelFilters.classList.add('hidden');
            });
        }

        // Close filter panel on Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                if (panelFilters && !panelFilters.classList.contains('hidden')) {
                    panelFilters.classList.add('hidden');
                }
                closeSearchDropdown();
            }
        });

        // Custom date range visibility
        if (selectDateRange && customDateRangeContainer) {
            selectDateRange.addEventListener('change', (e) => {
                customDateRangeContainer.classList.toggle('hidden', e.target.value !== 'custom');
            });
        }

        // Apply filters
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

                // On mobile, automatically hide panel after applying
                if (window.innerWidth < 640 && panelFilters) {
                    panelFilters.classList.add('hidden');
                }
            });
        }

        // Reset filters
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

        // Active Vessel Track Controller (Tahap 11)
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
        // OPEN WATERS AIS: VESSEL DETAIL PANEL & TRACK CONTROLS
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
                        panelBtnShareUrl.innerHTML = '<span>✓</span> <span>Tersalin!</span>';
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

        // Close panels on Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                mapController.closeVesselDetailPanel();
            }
        });

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
