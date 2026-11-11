@extends('layouts.app')

@section('header_title', 'Global Maritime Monitoring Map — MapLibre GL JS')
@section('header_title_short', 'Global Vessel Map')

@section('content')
@php
    $flags = $filterMeta['flags'] ?? [];
    $vesselTypes = $filterMeta['vessel_types'] ?? [];
    $reviewStatuses = $filterMeta['review_statuses'] ?? [];
    $correlationStatuses = $filterMeta['correlation_statuses'] ?? [];
    $platforms = $filterMeta['platforms'] ?? [];
@endphp
<div class="relative h-[calc(100vh-4rem)] sm:h-[calc(100vh-8.5rem)] min-h-0 sm:min-h-[580px] rounded-none sm:rounded-2xl overflow-hidden border-0 sm:border sm:border-slate-800 shadow-none sm:shadow-2xl flex flex-col bg-slate-950 font-sans">

    <!-- Top Floating Toolbar: Quick Actions, Search & Control Popovers -->
    <div class="absolute top-2 sm:top-3 left-2 sm:left-3 z-30 flex items-center gap-1.5 sm:gap-2 pointer-events-none max-w-[calc(100%-3.5rem)] sm:max-w-none">
        
        <!-- Left: Search & Navigation Toolbar -->
        <div class="pointer-events-auto flex items-center gap-1 sm:gap-1.5 p-1 rounded-xl glass-panel border border-slate-700/80 shadow-2xl text-xs backdrop-blur-md">
            
            <!-- A. SEARCH WITH LIVE AUTOCOMPLETE -->
            <div class="relative flex items-center">
                <input type="text" id="inputVesselSearch" placeholder="Search vessel, MMSI, or candidate..."
                    class="pl-7 sm:pl-8 pr-6 sm:pr-7 py-1 sm:py-1.5 rounded-lg bg-slate-900/90 border border-slate-700 text-slate-100 placeholder-slate-500 focus:outline-none focus:border-teal-400 w-28 sm:w-64 text-[11px] sm:text-xs font-mono transition-all">
                <span class="absolute left-2 sm:left-2.5 text-slate-400 pointer-events-none text-[11px] sm:text-xs">🔍</span>
                <button type="button" id="btnClearSearch" class="hidden absolute right-1.5 sm:right-2 text-slate-400 hover:text-white text-xs font-bold p-0.5 leading-none" title="Clear search">&times;</button>
            </div>

            <!-- Unified Search Dropdown Panel (Live Query Results) -->
            <div id="searchResultsDropdown" class="hidden absolute top-full left-0 mt-2 w-72 sm:w-96 max-w-[calc(100vw-1.5rem)] max-h-80 overflow-y-auto glass-panel p-2 rounded-xl border border-slate-700/90 shadow-2xl z-40 space-y-2 text-xs divide-y divide-slate-800/80">
                <!-- Injected via JavaScript from /api/map/search -->
            </div>

            <button type="button" id="btnFilterSearch" class="px-2 sm:px-2.5 py-1 sm:py-1.5 rounded-lg bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold transition flex items-center justify-center shrink-0 text-[11px] sm:text-xs" title="Search vessel or radar target">
                <svg class="w-3.5 h-3.5 sm:hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                <span class="hidden sm:inline">Search</span>
            </button>

            <div class="h-4 w-px bg-slate-700/80 mx-0.5 shrink-0"></div>

            <!-- B. FILTER BUTTON -->
            <button type="button" id="btnToggleAdvancedFilters" class="btn-popover-trigger px-2 sm:px-2.5 py-1 sm:py-1.5 rounded-lg bg-slate-900 hover:bg-slate-800 text-slate-200 hover:text-teal-300 font-medium transition border border-slate-700 flex items-center justify-center gap-1 shrink-0 text-xs" title="Open vessel & radar filter panel">
                <svg class="w-3.5 h-3.5 text-teal-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="4" y1="21" x2="4" y2="14"></line><line x1="4" y1="10" x2="4" y2="3"></line>
                    <line x1="12" y1="21" x2="12" y2="12"></line><line x1="12" y1="8" x2="12" y2="3"></line>
                    <line x1="20" y1="21" x2="20" y2="16"></line><line x1="20" y1="12" x2="20" y2="3"></line>
                    <line x1="1" y1="14" x2="7" y2="14"></line><line x1="9" y1="8" x2="15" y2="8"></line><line x1="17" y1="16" x2="23" y2="16"></line>
                </svg>
                <span class="hidden sm:inline">Filters</span>
                <span id="activeFilterBadgeToolbar" class="active-filter-badge hidden px-1 sm:px-1.5 py-0.2 rounded-full text-[9px] bg-teal-400 text-slate-950 font-bold">0</span>
            </button>

            <!-- C. MARITIME LAYERS BUTTON -->
            <button type="button" id="btnToggleLayers" class="btn-popover-trigger px-2 sm:px-2.5 py-1 sm:py-1.5 rounded-lg bg-slate-900 hover:bg-slate-800 text-slate-200 hover:text-sky-300 font-medium transition border border-slate-700 flex items-center justify-center gap-1 shrink-0 text-xs" title="Open maritime layer options">
                <svg class="w-3.5 h-3.5 text-sky-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="12 2 2 7 12 12 22 7 12 2"></polygon>
                    <polyline points="2 17 12 22 22 17"></polyline>
                    <polyline points="2 12 12 17 22 12"></polyline>
                </svg>
                <span class="hidden sm:inline">Layers</span>
                <span id="badgeLayerCount" class="px-1 sm:px-1.5 py-0.2 rounded-full text-[9px] bg-sky-500 text-slate-950 font-bold">5</span>
            </button>

            <!-- D. SCIENTIFIC LEGEND BUTTON -->
            <button type="button" id="btnToggleLegend" class="btn-popover-trigger px-2 sm:px-2.5 py-1 sm:py-1.5 rounded-lg bg-slate-900 hover:bg-slate-800 text-slate-200 hover:text-amber-300 font-medium transition border border-slate-700 flex items-center justify-center gap-1 shrink-0 text-xs" title="Open symbolic legend & vessel taxonomy">
                <svg class="w-3.5 h-3.5 text-amber-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
                    <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
                </svg>
                <span class="hidden sm:inline">Legend</span>
            </button>

            <!-- E. MAP SETTINGS / BASEMAP BUTTON -->
            <button type="button" id="btnToggleSettings" class="btn-popover-trigger px-2 sm:px-2.5 py-1 sm:py-1.5 rounded-lg bg-slate-900 hover:bg-slate-800 text-slate-200 hover:text-emerald-300 font-medium transition border border-slate-700 flex items-center justify-center gap-1 shrink-0 text-xs" title="Basemap display settings & attribution">
                <svg class="w-3.5 h-3.5 text-emerald-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="3"></circle>
                    <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                </svg>
                <span class="hidden sm:inline">Settings</span>
            </button>

            <!-- F. RESET VIEW BUTTON -->
            <button type="button" id="btnResetView" class="px-2 sm:px-2.5 py-1 sm:py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white font-medium transition border border-slate-700 flex items-center justify-center gap-1 shrink-0 text-xs" title="Center map to default ASEAN regional view">
                <span class="text-xs">🧭</span>
                <span class="hidden sm:inline">Reset View</span>
            </button>
        </div>
    </div>


    <!-- POPOVER 1: ADVANCED MARITIME FILTERS -->
    <div id="advancedFiltersPanel" class="hidden absolute top-12 sm:top-14 inset-x-2 sm:inset-x-auto sm:left-3 z-30 w-auto sm:w-96 max-h-[calc(100vh-7rem)] sm:max-h-[calc(100%-4.5rem)] overflow-y-auto glass-panel p-3.5 sm:p-4 rounded-xl sm:rounded-2xl border border-slate-700/80 shadow-2xl space-y-3 sm:space-y-3.5 text-xs backdrop-blur-xl">
        <div class="flex items-center justify-between border-b border-slate-700/80 pb-2.5">
            <div class="flex items-center gap-2">
                <span class="text-base text-teal-400">🎚️</span>
                <div>
                    <h3 class="font-bold uppercase tracking-wider text-white text-[12px]">Advanced Maritime Filters</h3>
                    <p class="text-[10px] text-slate-400">Fleet surveillance &amp; radar satellite filters</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <span class="active-filter-badge hidden px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-teal-500/20 text-teal-300 border border-teal-500/40">0 Active</span>
                <button type="button" id="btnCloseFiltersPanel" class="text-slate-400 hover:text-white text-lg font-bold p-1 leading-none transition" title="Close panel (Esc)">&times;</button>
            </div>
        </div>

        <form id="formAdvancedFilters" class="space-y-3.5" onsubmit="return false;">
            <!-- SECTION A: VESSEL IDENTITY -->
            <div class="space-y-2 p-2.5 rounded-xl bg-slate-900/90 border border-slate-800">
                <div class="text-[10px] uppercase font-bold text-sky-400 tracking-wider flex items-center justify-between">
                    <span>1. Vessel Fleet Identity (AIS)</span>
                    <span>🚢</span>
                </div>

                <!-- Flag State -->
                <div>
                    <label for="filter-vessel-flag" class="block text-[10px] uppercase text-slate-400 font-mono mb-1">Flag State</label>
                    <select id="filter-vessel-flag" class="w-full px-2.5 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs focus:outline-none focus:border-teal-400">
                        <option value="">All Flags</option>
                        @foreach($flags as $f)
                            <option value="{{ $f['code'] }}">{{ $f['label'] }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Vessel Type -->
                <div>
                    <label for="filter-vessel-type" class="block text-[10px] uppercase text-slate-400 font-mono mb-1">Vessel Type</label>
                    <select id="filter-vessel-type" class="w-full px-2.5 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs focus:outline-none focus:border-teal-400 capitalize">
                        <option value="">All Vessel Types</option>
                        @foreach($vesselTypes as $vt)
                            <option value="{{ $vt }}">{{ ucfirst($vt) }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- SOG Range -->
                <div>
                    <label class="block text-[10px] uppercase text-slate-400 font-mono mb-1">Speed SOG Range (knots)</label>
                    <div class="grid grid-cols-2 gap-2">
                        <input type="number" id="filter-min-sog" placeholder="Min (0 kn)" min="0" max="60" step="0.5" class="px-2 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs focus:outline-none focus:border-teal-400 font-mono">
                        <input type="number" id="filter-max-sog" placeholder="Max (kn)" min="0" max="60" step="0.5" class="px-2 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs focus:outline-none focus:border-teal-400 font-mono">
                    </div>
                </div>

                <!-- COG Range -->
                <div>
                    <label class="block text-[10px] uppercase text-slate-400 font-mono mb-1">Course COG Range (degrees)</label>
                    <div class="grid grid-cols-2 gap-2">
                        <input type="number" id="filter-min-cog" placeholder="Min (0°)" min="0" max="360" step="1" class="px-2 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs focus:outline-none focus:border-teal-400 font-mono">
                        <input type="number" id="filter-max-cog" placeholder="Max (360°)" min="0" max="360" step="1" class="px-2 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs focus:outline-none focus:border-teal-400 font-mono">
                    </div>
                </div>

                <!-- Heading Availability -->
                <div>
                    <label for="filter-heading-status" class="block text-[10px] uppercase text-slate-400 font-mono mb-1">Heading Availability</label>
                    <select id="filter-heading-status" class="w-full px-2.5 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs focus:outline-none focus:border-teal-400">
                        <option value="">All Orientation Statuses</option>
                        <option value="has_heading">Valid Heading (True Vessel Heading)</option>
                        <option value="cog_fallback">COG Fallback (Course Vector)</option>
                        <option value="unavailable">Direction Unavailable (Neutral Beacon)</option>
                    </select>
                </div>

                <!-- Data Freshness -->
                <div>
                    <label for="filter-freshness" class="block text-[10px] uppercase text-slate-400 font-mono mb-1">AIS Telemetry Freshness</label>
                    <select id="filter-freshness" class="w-full px-2.5 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs focus:outline-none focus:border-teal-400">
                        <option value="">All Telemetry Ages</option>
                        <option value="live">● Live AIS (&lt; 2h)</option>
                        <option value="stale">⏳ Stale AIS (&ge; 2h)</option>
                    </select>
                </div>
            </div>

            <!-- SECTION B: SAR CANDIDATES -->
            <div class="space-y-2 p-2.5 rounded-xl bg-slate-900/90 border border-slate-800">
                <div class="text-[10px] uppercase font-bold text-amber-400 tracking-wider flex items-center justify-between">
                    <span>2. SAR Radar Anomaly Candidates</span>
                    <span>🎯</span>
                </div>

                <div>
                    <label for="filter-sar-review" class="block text-[10px] uppercase text-slate-400 font-mono mb-1">Analyst Review Status</label>
                    <select id="filter-sar-review" class="w-full px-2.5 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs focus:outline-none focus:border-teal-400">
                        <option value="">All Review Statuses</option>
                        @foreach($reviewStatuses as $val => $lbl)
                            <option value="{{ $val }}">{{ $lbl }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="filter-sar-correlation" class="block text-[10px] uppercase text-slate-400 font-mono mb-1">AIS Correlation Status</label>
                    <select id="filter-sar-correlation" class="w-full px-2.5 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs focus:outline-none focus:border-teal-400">
                        <option value="">All Correlation Statuses</option>
                        @foreach($correlationStatuses as $cVal => $cLbl)
                            <option value="{{ $cVal }}">{{ $cLbl }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-[10px] uppercase text-slate-400 font-mono mb-1">Radar Backscatter TCR Range (dB)</label>
                    <div class="grid grid-cols-2 gap-2">
                        <input type="number" id="filter-min-tcr" placeholder="Min (dB)" step="0.5" class="px-2 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs focus:outline-none focus:border-teal-400 font-mono">
                        <input type="number" id="filter-max-tcr" placeholder="Max (dB)" step="0.5" class="px-2 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs focus:outline-none focus:border-teal-400 font-mono">
                    </div>
                </div>

                <div>
                    <label for="filter-sar-platform" class="block text-[10px] uppercase text-slate-400 font-mono mb-1">Radar Satellite Platform</label>
                    <select id="filter-sar-platform" class="w-full px-2.5 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs focus:outline-none focus:border-teal-400">
                        <option value="">All Satellite Platforms</option>
                        @foreach($platforms as $p)
                            <option value="{{ $p }}">{{ $p }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <!-- SECTION C: TEMPORAL & TRACK WINDOW -->
            <div class="space-y-2 p-2.5 rounded-xl bg-slate-900/90 border border-slate-800">
                <div class="text-[10px] uppercase font-bold text-teal-400 tracking-wider flex items-center justify-between">
                    <span>3. Time Range &amp; Trajectory</span>
                    <span>⏱️</span>
                </div>

                <div>
                    <label for="filter-date-range" class="block text-[10px] uppercase text-slate-400 font-mono mb-1">Observation Time Window</label>
                    <select id="filter-date-range" class="w-full px-2.5 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs focus:outline-none focus:border-teal-400">
                        <option value="all">All Observation Times</option>
                        <option value="24h">Last 24 Hours</option>
                        <option value="7d">Last 7 Days</option>
                        <option value="30d">Last 30 Days</option>
                        <option value="custom">Custom Date Range...</option>
                    </select>
                </div>

                <div id="customDateRangeContainer" class="hidden grid grid-cols-2 gap-2 pt-1">
                    <div>
                        <label class="block text-[9px] text-slate-400 font-mono mb-0.5">Start:</label>
                        <input type="date" id="filter-start-date" class="w-full px-2 py-1 rounded bg-slate-950 border border-slate-700 text-slate-200 text-[11px] font-mono">
                    </div>
                    <div>
                        <label class="block text-[9px] text-slate-400 font-mono mb-0.5">End:</label>
                        <input type="date" id="filter-end-date" class="w-full px-2 py-1 rounded bg-slate-950 border border-slate-700 text-slate-200 text-[11px] font-mono">
                    </div>
                </div>

                <div>
                    <label for="filter-track-window" class="block text-[10px] uppercase text-slate-400 font-mono mb-1">AIS Track History Window</label>
                    <select id="filter-track-window" class="w-full px-2.5 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs focus:outline-none focus:border-teal-400">
                        <option value="off">Off (Current Position Only)</option>
                        <option value="60">60-Minute Window</option>
                        <option value="180">180-Minute Window (3 Hours)</option>
                        <option value="360">360-Minute Window (6 Hours)</option>
                    </select>
                </div>
            </div>

            <!-- Action Buttons: Apply & Reset -->
            <div class="pt-2 flex items-center gap-2 border-t border-slate-800">
                <button type="button" id="btnApplyFilters" class="flex-1 py-2 px-3 rounded-xl bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold text-center transition flex items-center justify-center gap-1.5 shadow-md">
                    <span>✓</span>
                    <span>Apply Filters</span>
                </button>
                <button type="button" id="btnResetFilters" class="py-2 px-3 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-semibold transition border border-slate-700 text-center">
                    Reset
                </button>
            </div>
            <div id="filterFeedbackMsg" class="hidden text-[10px] font-mono text-center py-1 rounded bg-teal-950/60 border border-teal-500/30 text-teal-300 transition-all"></div>
        </form>
    </div>

    <!-- POPOVER 2: MARITIME LAYERS -->
    <div id="popoverLayers" class="hidden absolute top-12 sm:top-14 inset-x-2 sm:inset-x-auto sm:left-48 z-30 w-auto sm:w-88 max-h-[calc(100vh-7rem)] sm:max-h-[calc(100%-4.5rem)] overflow-y-auto glass-panel p-3.5 sm:p-4 rounded-xl sm:rounded-2xl border border-slate-700/80 shadow-2xl space-y-3 sm:space-y-3.5 text-xs backdrop-blur-xl">
        <div class="flex items-center justify-between border-b border-slate-700/80 pb-2">
            <div class="flex items-center gap-2">
                <span class="text-base text-sky-400">🎛️</span>
                <h3 class="font-bold uppercase tracking-wider text-white text-[12px]">Maritime Layers</h3>
            </div>
            <button type="button" id="btnCloseLayersPopover" class="text-slate-400 hover:text-white text-lg font-bold p-1 leading-none transition" title="Close panel (Esc)">&times;</button>
        </div>

        <div class="space-y-3">
            <!-- 1. AIS & Navigation -->
            <div class="space-y-1.5">
                <div class="text-[10px] uppercase font-bold text-slate-400 tracking-wider">1. AIS &amp; Navigation</div>

                <label class="flex items-start justify-between p-2 rounded-xl bg-slate-900/90 border border-slate-800 hover:border-sky-500/50 transition cursor-pointer">
                    <div class="flex items-start gap-2.5">
                        <input type="checkbox" id="toggle-layer-vessels" checked class="mt-0.5 rounded bg-slate-800 border-slate-700 text-sky-500 focus:ring-sky-400">
                        <div>
                            <div class="font-bold text-slate-200 flex items-center gap-1.5">
                                <span class="w-2.5 h-2.5 rounded-full bg-sky-400"></span>
                                <span>Live AIS Vessels</span>
                            </div>
                            <p class="text-[10px] text-slate-400 mt-0.5">Active real-time broadcast vessel telemetry (max 5,000 displayed)</p>
                            <div id="vesselsStatsBreakdown" class="text-[9px] text-slate-400 mt-1 font-mono flex flex-wrap gap-x-2 gap-y-0.5"></div>
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
                <div class="text-[10px] uppercase font-bold text-slate-400 tracking-wider">2. Regional Maritime Boundaries (EEZ)</div>

                <label class="flex items-start justify-between p-2 rounded-xl bg-slate-900/90 border border-slate-800 hover:border-emerald-500/50 transition cursor-pointer">
                    <div class="flex items-start gap-2.5">
                        <input type="checkbox" id="toggle-layer-asean-eez" checked class="mt-0.5 rounded bg-slate-800 border-slate-700 text-emerald-500 focus:ring-emerald-400">
                        <div>
                            <div class="font-bold text-slate-200 flex items-center gap-1.5">
                                <span class="w-2.5 h-2.5 rounded-sm bg-emerald-500/20 border border-emerald-500"></span>
                                <span>ASEAN Exclusive Economic Zones (EEZ)</span>
                            </div>
                            <p class="text-[10px] text-slate-400 mt-0.5">Official ASEAN EEZ &amp; Maritime Boundaries (v12)</p>
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
                            <p class="text-[10px] text-slate-400 mt-0.5">Official Australia EEZ &amp; Maritime Boundaries (AMSIS / AMB / v12)</p>
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
                <div class="text-[10px] uppercase font-bold text-slate-400 tracking-wider">3. Analysis &amp; Anomalies</div>

                <label class="flex items-start justify-between p-2 rounded-xl bg-slate-900/90 border border-slate-800 hover:border-amber-500/50 transition cursor-pointer">
                    <div class="flex items-start gap-2.5">
                        <input type="checkbox" id="toggle-layer-dark-vessels" checked class="mt-0.5 rounded bg-slate-800 border-slate-700 text-amber-500 focus:ring-amber-400">
                        <div>
                            <div class="font-bold text-slate-200 flex items-center gap-1.5">
                                <span class="w-2.5 h-2.5 rounded-full bg-amber-400 border border-amber-300"></span>
                                <span>Unusual Activity / SAR Targets</span>
                            </div>
                            <p class="text-[10px] text-slate-400 mt-0.5">Radar anomaly without active AIS correlation</p>
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
                            <p class="text-[10px] text-slate-400 mt-0.5">Vessel pair rendezvous and encounter detection</p>
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
                            <p class="text-[10px] text-slate-400 mt-0.5">Sentinel-1 Copernicus radar footprint polygons</p>
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
    <div id="popoverLegend" class="hidden absolute top-12 sm:top-14 inset-x-2 sm:inset-x-auto sm:left-64 z-30 w-auto sm:w-96 max-h-[calc(100vh-7rem)] sm:max-h-[calc(100%-4.5rem)] overflow-y-auto glass-panel p-3.5 sm:p-4 rounded-xl sm:rounded-2xl border border-slate-700/80 shadow-2xl space-y-3 sm:space-y-3.5 text-xs backdrop-blur-xl">
        <div class="flex items-center justify-between border-b border-slate-700/80 pb-2">
            <div class="flex items-center gap-2">
                <span class="text-base text-amber-400">🧭</span>
                <h3 class="font-bold uppercase tracking-wider text-white text-[12px]">Scientific Legend &amp; Symbology</h3>
            </div>
            <button type="button" id="btnCloseLegendPopover" class="text-slate-400 hover:text-white text-lg font-bold p-1 leading-none transition" title="Close panel (Esc)">&times;</button>
        </div>

        <div class="space-y-3">
            <!-- 1. Klasifikasi Armada Kapal (Directional Triangles) -->
            <div class="p-2.5 rounded-xl bg-slate-900/90 border border-slate-800 space-y-2 text-[10px] font-mono">
                <div class="text-[9px] uppercase font-bold text-slate-400 tracking-wider">1. Vessel Classification (Type)</div>
                <div class="grid grid-cols-2 gap-2 text-slate-300">
                    <div class="flex items-center gap-1.5">
                        <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 32 32">
                            <path d="M 16 2 L 28 27.5 L 16 22.5 L 4 27.5 Z" fill="#F59E0B" stroke="#FFFFFF" stroke-width="2"/>
                            <line x1="9" y1="17" x2="23" y2="17" stroke="#FFFFFF" stroke-width="1.6" stroke-linecap="round"/>
                            <circle cx="16" cy="13" r="2.2" fill="#1e293b" stroke="#FFFFFF" stroke-width="1"/>
                        </svg>
                        <strong class="text-[#F59E0B]">Fishing (Amber)</strong>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 32 32">
                            <path d="M 16 2.5 L 27.5 26.5 L 16 22 L 4.5 26.5 Z" fill="#2563EB" stroke="#FFFFFF" stroke-width="2"/>
                        </svg>
                        <span class="text-blue-400 font-semibold">Cargo (Blue)</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 32 32">
                            <path d="M 16 2.5 L 27.5 26.5 L 16 22 L 4.5 26.5 Z" fill="#9333EA" stroke="#FFFFFF" stroke-width="2"/>
                        </svg>
                        <span class="text-purple-400 font-semibold">Tanker (Purple)</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 32 32">
                            <path d="M 16 2.5 L 27.5 26.5 L 16 22 L 4.5 26.5 Z" fill="#06B6D4" stroke="#FFFFFF" stroke-width="2"/>
                        </svg>
                        <span class="text-cyan-400 font-semibold">Passenger (Cyan)</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 32 32">
                            <path d="M 16 2.5 L 27.5 26.5 L 16 22 L 4.5 26.5 Z" fill="#64748B" stroke="#FFFFFF" stroke-width="2"/>
                        </svg>
                        <span class="text-slate-300 font-semibold">Support (Slate)</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 32 32">
                            <path d="M 16 2.5 L 27.5 26.5 L 16 22 L 4.5 26.5 Z" fill="#DC2626" stroke="#FFFFFF" stroke-width="2"/>
                        </svg>
                        <span class="text-rose-400 font-semibold">Patrol (Red)</span>
                    </div>
                    <div class="flex items-center gap-1.5 col-span-2">
                        <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 32 32">
                            <path d="M 16 2.5 L 27.5 26.5 L 16 22 L 4.5 26.5 Z" fill="#94A3B8" stroke="#FFFFFF" stroke-width="2"/>
                        </svg>
                        <span class="text-slate-400 font-semibold">Unknown Vessel (Gray)</span>
                    </div>
                </div>
            </div>

            <!-- 2. Status Data AIS & Simbologi Haluan -->
            <div class="p-2.5 rounded-xl bg-slate-900/90 border border-slate-800 space-y-2 text-[10px] font-mono">
                <div class="text-[9px] uppercase font-bold text-sky-400 tracking-wider flex items-center justify-between">
                    <span>Course Over Ground (COG)</span>
                    <span class="w-1.5 h-1.5 rounded-full bg-sky-400 animate-pulse"></span>
                </div>
                <div class="space-y-1.5 text-slate-300">
                    <p class="text-[9px] text-slate-400 leading-relaxed italic">
                        Triangle orientation indicates Course Over Ground (COG).
                    </p>
                    <div class="flex items-center justify-between">
                        <span class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-emerald-400"></span> Latest Position (&lt; 2h)</span>
                        <span class="text-emerald-300 font-bold">● Live</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-amber-400"></span> Stale Position (2 - 24h)</span>
                        <span class="text-amber-300 font-bold">⏳ Stale</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-slate-500"></span> Offline (&gt; 24h)</span>
                        <span class="text-slate-400 font-bold">○ Offline</span>
                    </div>
                    <div class="pt-1 border-t border-slate-800/80 space-y-1">
                        <div class="flex items-center gap-2">
                            <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 32 32">
                                <path d="M 16 2 L 28 27.5 L 16 22.5 L 4 27.5 Z" fill="#F59E0B" stroke="#FFFFFF" stroke-width="2"/>
                                <line x1="9" y1="17" x2="23" y2="17" stroke="#FFFFFF" stroke-width="1.6"/>
                            </svg>
                            <span>COG Direction: Triangle oriented along vessel track</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none">
                                <circle cx="12" cy="12" r="4.5" fill="#F59E0B" stroke="#451a03" stroke-width="1.5"/>
                            </svg>
                            <span>Direction unavailable: Omnidirectional circular beacon</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none">
                                <circle cx="12" cy="12" r="5" stroke="#F59E0B" stroke-width="1.5" stroke-dasharray="2 2"/>
                                <circle cx="12" cy="12" r="1.5" fill="#F59E0B"/>
                            </svg>
                            <span>Selected vessel: Highlight ring indicator</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 3. Batas Maritim Regional (EEZ) & Overlays -->
            <div class="p-2.5 rounded-xl bg-slate-900/90 border border-slate-800 space-y-2.5 text-[10px] font-mono">
                <!-- ASEAN EEZ Header & Metadata -->
                <div class="space-y-1.5">
                    <div class="text-[9px] uppercase font-bold text-emerald-400 tracking-wider flex items-center justify-between">
                        <span>ASEAN Maritime Boundary Status &bull; ASEAN EEZ (Marine Regions v12)</span>
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
                            <span class="truncate"><span class="text-slate-400 font-semibold">PHL</span> Philippines (Green)</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span class="w-3 h-1 rounded-sm bg-[#8b5cf6] shrink-0 shadow-sm"></span>
                            <span class="truncate"><span class="text-slate-400 font-semibold">BRN</span> Brunei (Violet)</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span class="w-3 h-1 rounded-sm bg-[#fb7185] shrink-0 shadow-sm"></span>
                            <span class="truncate"><span class="text-slate-400 font-semibold">SGP</span> Singapore (Coral)</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span class="w-3 h-1 rounded-sm bg-[#3b82f6] shrink-0 shadow-sm"></span>
                            <span class="truncate"><span class="text-slate-400 font-semibold">KHM</span> Cambodia (Blue)</span>
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
                            <span class="truncate">Agreed / Delimitated (Official Bilateral Treaty)</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="w-4 h-0.5 border-b border-dashed border-slate-400 shrink-0"></span>
                            <span class="truncate">Undelimited / Unresolved (Median Line)</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="w-4 h-0.5 border-b border-dashed border-rose-400 shrink-0"></span>
                            <span class="truncate">Disputed / Overlapping Claim</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="w-4 h-0.5 border-b border-dotted border-purple-400 shrink-0"></span>
                            <span class="truncate">Joint Regime Area</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="w-4 h-0.5 border-b border-dashed border-slate-400 shrink-0"></span>
                            <span class="truncate">EEZ Outer Limit (200 NM)</span>
                        </div>
                    </div>
                </div>

                <!-- Australia EEZ (Terpisah) -->
                <div class="pt-2 border-t border-slate-800 space-y-1.5">
                    <div class="text-[9px] uppercase font-bold text-red-400 tracking-wider flex items-center justify-between">
                        <span>Australia EEZ (AMSIS / AMB / v12)</span>
                        <span class="text-red-400 text-[8px] font-bold bg-red-950/60 px-1.5 py-0.5 rounded border border-red-800/80">AUS &bull; Red-Orange</span>
                    </div>
                    <div class="space-y-1 text-[9px] text-slate-300">
                        <div class="flex items-center gap-2">
                            <span class="w-4 h-0.5 bg-[#ef4444] shrink-0"></span>
                            <span class="truncate">Agreed Bilateral Maritime Treaty (Solid)</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="w-4 h-0.5 border-b border-dashed border-[#ef4444] shrink-0"></span>
                            <span class="truncate">Australia 200 NM Outer Limit (EEZ)</span>
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
                    <div class="flex items-center gap-1.5"><span class="w-2.5 h-2 rounded-full bg-amber-400"></span> Dark Vessel</div>
                    <div class="flex items-center gap-1.5"><span class="w-2.5 h-2 rounded-full bg-amber-500"></span> Encounter Event</div>
                    <div class="flex items-center gap-1.5"><span class="w-2.5 h-2 bg-purple-500/30 border border-purple-400"></span> SAR Footprint</div>
                </div>
            </div>

            <!-- 4. Prinsip Ilmiah (Scientific Invariants) -->
            <div class="p-2.5 rounded-xl bg-slate-950/80 border border-slate-800 space-y-1 text-[9px] text-slate-400 leading-relaxed font-mono">
                <div class="font-bold text-slate-300 uppercase">Forensic Validity Principles:</div>
                <div>&bull; SAR target detection does not constitute vessel identity confirmation (SAR Target Detection &ne; Vessel Confirmation).</div>
                <div>&bull; Absence of AIS broadcast does not prove illicit activity (AIS Absence &ne; Dark Vessel Confirmation).</div>
            </div>
        </div>
    </div>

    <!-- POPOVER 4: MAP SETTINGS & BASEMAP -->
    <div id="popoverSettings" class="hidden absolute top-12 sm:top-14 inset-x-2 sm:inset-x-auto sm:left-72 z-30 w-auto sm:w-84 max-h-[calc(100vh-7rem)] sm:max-h-[calc(100%-4.5rem)] overflow-y-auto glass-panel p-3.5 sm:p-4 rounded-xl sm:rounded-2xl border border-slate-700/80 shadow-2xl space-y-3 sm:space-y-3.5 text-xs backdrop-blur-xl">
        <div class="flex items-center justify-between border-b border-slate-700/80 pb-2">
            <div class="flex items-center gap-2">
                <span class="text-base text-emerald-400">⚙️</span>
                <h3 class="font-bold uppercase tracking-wider text-white text-[12px]">Map Settings</h3>
            </div>
            <button type="button" id="btnCloseSettingsPopover" class="text-slate-400 hover:text-white text-lg font-bold p-1 leading-none transition" title="Close panel (Esc)">&times;</button>
        </div>

        <div class="space-y-3">
            <div class="space-y-1.5">
                <div class="flex items-center justify-between">
                    <div class="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Basemap Display Style</div>
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
                <div class="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Geospatial Projection</div>
                <div class="p-2 rounded-xl bg-slate-900/90 border border-slate-800 text-[10px] text-slate-300 font-mono">
                    <div>System: <strong class="text-teal-300">WGS 84 (EPSG:4326)</strong></div>
                    <div>Display: <span class="text-slate-400">Web Mercator (EPSG:3857)</span></div>
                </div>
            </div>

            <div class="pt-2 border-t border-slate-800">
                <button type="button" id="btnSettingsOpenMetadata" class="w-full py-2 px-3 rounded-xl bg-slate-800 hover:bg-slate-700 text-teal-300 border border-slate-700 font-semibold transition text-center flex items-center justify-center gap-1.5">
                    <span>📜</span>
                    <span>View Full Attribution &amp; Licenses</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Map Canvas Container -->
    <div id="global-map-canvas" class="w-full h-full flex-1 bg-slate-950 min-h-0 sm:min-h-[480px]"></div>

    <!-- Bottom Coordinates, Counts & Map Scale Status Bar -->
    <div class="absolute bottom-2 left-3 z-10 pointer-events-none flex flex-wrap items-center gap-2 text-[11px] font-mono">
        <div class="pointer-events-auto hidden sm:flex items-center gap-2 px-2.5 py-1 rounded-lg glass-panel border border-slate-700/80 shadow-md text-slate-300">
            <span class="text-teal-400 font-bold">📍</span>
            <span id="cursorCoordinates">Lat: 0.00000°, Lon: 0.00000° | Zoom: 3.50</span>
        </div>
        <div class="pointer-events-auto px-2.5 py-1 rounded-lg glass-panel border border-slate-700/80 shadow-md text-slate-300 flex items-center gap-1.5" id="statusVesselContainer" title="Vessel Fleet Statistics">
            <span class="text-sky-400 font-bold">🚢</span>
            <span id="statusVesselCount" class="text-sky-300 font-bold">0 vessels</span>
            <span id="statusVesselDetails" class="hidden sm:inline text-[10px] text-slate-400 font-normal"></span>
        </div>
        <div class="pointer-events-auto hidden sm:flex items-center gap-1.5 px-2.5 py-1 rounded-lg glass-panel border border-slate-700/80 shadow-md text-emerald-400">
            <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
            <span>Live Telemetry</span>
        </div>
    </div>

    <!-- Floating Right-Side / Bottom-Sheet Vessel Detail Panel -->
    <div id="vesselDetailPanel" class="hidden absolute top-14 right-3 bottom-12 z-30 w-84 sm:w-96 max-w-sm flex flex-col glass-panel bg-slate-900/95 border border-slate-700/80 rounded-2xl shadow-2xl backdrop-blur-xl overflow-hidden text-xs max-sm:fixed max-sm:inset-x-0 max-sm:bottom-0 max-sm:top-auto max-sm:w-full max-sm:max-h-[85vh] max-sm:rounded-t-2xl max-sm:rounded-b-none max-sm:z-40">
        <!-- Header -->
        <div class="p-3.5 border-b border-slate-700/80 bg-slate-950/70 flex items-start justify-between gap-2 shrink-0">
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2">
                    <span id="panelVesselFlagEmoji" class="text-xl shrink-0">🌐</span>
                    <h2 id="panelVesselName" class="font-bold text-white text-sm truncate font-sans tracking-wide">Vessel Name</h2>
                </div>
                <div class="flex items-center gap-2 mt-1 text-[11px] font-mono">
                    <span id="panelVesselFlagName" class="text-slate-400 truncate">Indonesia</span>
                    <span id="panelVesselTypeBadge" class="px-1.5 py-0.2 rounded text-[9px] font-bold uppercase bg-[#F59E0B]/20 text-[#F59E0B] border border-[#F59E0B]/60 shadow-[0_0_8px_rgba(245,158,11,0.3)]">Fishing</span>
                </div>
            </div>
            <div class="flex items-center gap-1.5 shrink-0">
                <span id="panelVesselFreshnessBadge" class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/40">● LIVE</span>
                <button type="button" id="btnCloseVesselPanel" class="p-1 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition text-lg font-bold leading-none" title="Close vessel details (Esc)">&times;</button>
            </div>
        </div>

        <!-- Scrollable Content -->
        <div class="flex-1 overflow-y-auto p-3.5 space-y-3.5">
            <!-- A. IDENTITAS KAPAL -->
            <div class="space-y-1 font-mono text-[11px]">
                <div class="text-[10px] uppercase font-bold text-slate-400 tracking-wider">A. Vessel Identity</div>
                <div class="p-2.5 rounded-xl bg-slate-950/80 border border-slate-800 space-y-1 divide-y divide-slate-800/80">
                    <div class="flex items-center justify-between pt-1 first:pt-0">
                        <span class="text-slate-400">MMSI:</span>
                        <strong id="panelVesselMmsi" class="text-white font-bold select-all">Not available</strong>
                    </div>
                    <div class="flex items-center justify-between pt-1">
                        <span class="text-slate-400">IMO:</span>
                        <strong id="panelVesselImo" class="text-white">Not available</strong>
                    </div>
                    <div class="flex items-center justify-between pt-1">
                        <span class="text-slate-400">Callsign:</span>
                        <strong id="panelVesselCallsign" class="text-white">Not available</strong>
                    </div>
                    <div class="flex items-center justify-between pt-1">
                        <span class="text-slate-400">Flag State:</span>
                        <span id="panelVesselFlagFull" class="text-slate-200 font-semibold truncate max-w-[160px]">Not available</span>
                    </div>
                    <div class="flex items-center justify-between pt-1">
                        <span class="text-slate-400">Vessel Type:</span>
                        <span id="panelVesselTypeDesc" class="text-slate-200 capitalize">Not available</span>
                    </div>
                </div>
            </div>

            <!-- B. NAVIGASI & POSISI TERKINI -->
            <div class="space-y-1">
                <div class="text-[10px] uppercase font-bold text-slate-400 tracking-wider font-mono">B. Navigation &amp; Current Position</div>
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
                            <span id="panelVesselHeading">Not available</span>
                        </div>
                    </div>
                    <div class="p-2.5 rounded-xl bg-slate-950/80 border border-slate-800">
                        <div class="text-[10px] text-slate-400 uppercase tracking-wider">Nav Status</div>
                        <div id="panelVesselNavStatus" class="text-xs font-semibold text-slate-200 mt-0.5 truncate capitalize">
                            Under way
                        </div>
                    </div>
                </div>

                <div class="p-2.5 rounded-xl bg-slate-950/80 border border-slate-800 space-y-1 font-mono text-[11px]">
                    <div class="flex items-center justify-between">
                        <span class="text-slate-400">Coordinates:</span>
                        <span id="panelVesselCoords" class="text-slate-200 font-bold select-all">0.0000°, 0.0000°</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-slate-400">Orientation:</span>
                        <span id="panelVesselOrientation" class="text-slate-300 font-semibold">Not available</span>
                    </div>
                </div>
            </div>

            <!-- C. KUALITAS DATA AIS -->
            <div class="space-y-1 font-mono text-[11px]">
                <div class="text-[10px] uppercase font-bold text-slate-400 tracking-wider">C. AIS Data Quality</div>
                <div class="p-2.5 rounded-xl bg-slate-950/80 border border-slate-800 space-y-1.5 divide-y divide-slate-800/80">
                    <div class="flex items-center justify-between pt-1 first:pt-0">
                        <span class="text-slate-400">Reported At:</span>
                        <span id="panelVesselTime" class="text-slate-200 truncate max-w-[170px]" title="Not available">Not available</span>
                    </div>
                    <div class="flex items-center justify-between pt-1">
                        <span class="text-slate-400">Data Freshness:</span>
                        <span id="panelVesselAge" class="text-teal-300 font-bold">Just now</span>
                    </div>
                    <div class="flex items-center justify-between pt-1">
                        <span class="text-slate-400">Gateway Source:</span>
                        <span id="panelVesselProvider" class="text-slate-300 font-semibold">AISStream Gateway</span>
                    </div>
                </div>
            </div>

            <!-- D. ANALISIS & KORELASI -->
            <div class="space-y-1.5 font-mono text-[11px]">
                <div class="text-[10px] uppercase font-bold text-slate-400 tracking-wider">D. Analysis &amp; Correlation</div>
                
                <div class="p-2.5 rounded-xl bg-slate-950/80 border border-slate-800 space-y-2">
                    <div class="space-y-1">
                        <div class="flex items-center justify-between">
                            <span class="text-slate-400">Track History:</span>
                            <span id="panelTrackPointBadge" class="px-1.5 py-0.2 rounded text-[9px] font-mono font-bold bg-cyan-950 text-cyan-300 border border-cyan-800">0 points</span>
                        </div>
                        <div class="grid grid-cols-4 gap-1 text-[11px]">
                            <button type="button" class="btn-track-range px-2 py-1 rounded-lg bg-slate-950 hover:bg-slate-800 border border-slate-800 text-slate-300 transition text-center" data-range="1h">1 Hour</button>
                            <button type="button" class="btn-track-range px-2 py-1 rounded-lg bg-slate-950 hover:bg-slate-800 border border-slate-800 text-slate-300 transition text-center" data-range="6h">6 Hours</button>
                            <button type="button" class="btn-track-range px-2 py-1 rounded-lg bg-cyan-950 border border-cyan-500 text-cyan-300 font-bold transition text-center" data-range="24h">24 Hours</button>
                            <button type="button" class="btn-track-range px-2 py-1 rounded-lg bg-slate-950 hover:bg-slate-800 border border-slate-800 text-slate-300 transition text-center" data-range="7d">7 Days</button>
                        </div>
                        <div class="flex items-center gap-1.5 pt-1">
                            <button type="button" id="panelBtnFitTrack" class="flex-1 px-2 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-sky-300 border border-slate-700 font-semibold text-center transition flex items-center justify-center gap-1">
                                <span>🧭</span> <span>Fit Track</span>
                            </button>
                            <button type="button" id="panelBtnToggleTrack" class="flex-1 px-2 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 border border-slate-700 font-semibold text-center transition flex items-center justify-center gap-1">
                                <span id="panelToggleTrackIcon">👁️</span> <span id="panelToggleTrackText">Hide Track</span>
                            </button>
                        </div>
                    </div>

                    <div class="pt-2 border-t border-slate-800/80 space-y-1.5">
                        <div class="flex items-center justify-between">
                            <span class="text-slate-400">Anomaly / Dark:</span>
                            <span id="panelVesselDarkVesselStatus" class="text-slate-300 font-semibold">No anomaly detected</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-slate-400">Vessel Encounters:</span>
                            <span id="panelVesselEncounterStatus" class="text-slate-300 font-semibold">0 events detected</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-slate-400">SAR Image Correlation:</span>
                            <span id="panelVesselSarStatus" class="text-slate-300 font-semibold">No image correlation</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- E. AKSI CEPAT (Footer) -->
        <div class="p-3 border-t border-slate-700/80 bg-slate-950/80 flex flex-wrap items-center gap-1.5 shrink-0">
            <a id="panelVesselProfileLink" href="#" class="flex-1 px-3 py-2 rounded-xl bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold text-center transition shadow-sm flex items-center justify-center gap-1.5">
                <span>Full Profile</span>
                <span>&rarr;</span>
            </a>
            <button type="button" id="panelBtnCenterVessel" class="px-2.5 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-sky-300 border border-slate-700 font-semibold transition flex items-center gap-1" title="Center map on vessel">
                <span>🎯</span>
                <span class="hidden sm:inline">Center</span>
            </button>
            <button type="button" id="panelBtnCopyMmsi" class="px-2.5 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 font-semibold transition flex items-center gap-1" title="Copy MMSI">
                <span>📋</span>
                <span class="hidden sm:inline">MMSI</span>
            </button>
            <button type="button" id="panelBtnShareUrl" class="px-2.5 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 font-semibold transition flex items-center gap-1" title="Copy link to vessel position">
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
                <span>Data Sources, Licenses &amp; Geospatial Attribution</span>
            </h3>
            <button type="button" id="btnCloseMetadataModal" class="text-slate-400 hover:text-white text-lg font-bold">&times;</button>
        </div>

        <div class="space-y-3 max-h-[60vh] overflow-y-auto pr-2 text-slate-300">
            <div class="p-3 rounded-xl bg-slate-900 border border-slate-800 space-y-1">
                <div class="font-bold text-purple-300">1. Sentinel-1 C-SAR Radar Scenes (Copernicus)</div>
                <div>Source: European Space Agency (ESA) &bull; Copernicus Data Space Ecosystem</div>
                <div>License: Copernicus Sentinel Data Legal Notice (Open Access)</div>
                <div class="text-[11px] text-slate-400 italic">C-band Synthetic Aperture Radar coverage for maritime anomaly detection.</div>
            </div>

            <div class="p-3 rounded-xl bg-slate-900 border border-slate-800 space-y-1">
                <div class="font-bold text-emerald-300">2. ASEAN Exclusive Economic Zones (EEZ) &amp; Maritime Boundaries</div>
                <div>Source: Flanders Marine Institute (VLIZ) Marine Regions v12</div>
                <div>License: Creative Commons Attribution 4.0 International (CC BY 4.0)</div>
                <div class="text-[11px] text-slate-400 italic">200 NM maritime jurisdiction limits and official boundary delimitation for ASEAN member states &amp; Timor-Leste (UNCLOS 1982).</div>
            </div>

            <div class="p-3.5 rounded-xl bg-slate-900 border border-teal-500/30 space-y-1.5">
                <div class="flex items-center justify-between">
                    <div class="font-bold text-teal-300">3. Global Fishing Watch (GFW API v3)</div>
                    <span class="px-2 py-0.2 rounded text-[9px] font-mono font-bold uppercase bg-teal-500/20 text-teal-300 border border-teal-500/40">
                        NON-COMMERCIAL RESEARCH
                    </span>
                </div>
                <div>Source: <a href="https://globalfishingwatch.org" target="_blank" rel="noopener noreferrer" class="text-teal-400 hover:underline">Global Fishing Watch</a></div>
                <div>License: GFW Research &amp; Non-Commercial Terms</div>
                <blockquote class="p-2.5 rounded-lg bg-slate-950/70 border border-slate-800 text-[10px] text-slate-300 italic leading-relaxed">
                    &ldquo;This application uses data and services provided by Global Fishing Watch (GFW) for non-commercial research, educational, and public-interest purposes. GFW data are subject to applicable licensing, attribution, and usage restrictions.&rdquo;
                </blockquote>
                <div class="text-[10px] text-amber-300/90">
                    Notice: Displayed vessel activity represents estimates or analytical findings based on available data sources, not conclusive proof of infractions.
                </div>
            </div>
        </div>

        <div class="pt-3 border-t border-slate-800 text-right">
            <button type="button" id="btnConfirmCloseMetadata" class="px-4 py-2 rounded-xl bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold">
                Close Information
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
                    <span class="animate-spin text-teal-400">⏳</span> Scanning vessel telemetry & SAR...
                </div>
            `;

            try {
                const res = await fetch(`/api/map/search?q=${encodeURIComponent(query.trim())}`);
                if (!res.ok) throw new Error(`HTTP Error ${res.status}`);
                const data = await res.json();

                if (!data.results || data.results.length === 0) {
                    searchDropdown.innerHTML = `
                        <div class="p-3 text-center text-slate-400 font-mono">
                            No vessels or SAR candidates matching "<strong>${escapeHtml(query)}</strong>".
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
                                <span>AIS Fleet (${vessels.length})</span>
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
                                                <div class="text-[9px] text-teal-400 mt-1 font-mono">Focus &rarr;</div>
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
                                <span>SAR Radar Candidates (${sars.length})</span>
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
                                            <div class="text-[9px] text-teal-400 mt-1 font-mono">Focus &rarr;</div>
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
                        An error occurred while loading search results.
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

                const feedback = document.getElementById('filterFeedbackMsg');
                if (feedback) {
                    feedback.textContent = '✓ Filters applied to map';
                    feedback.classList.remove('hidden');
                    setTimeout(() => feedback.classList.add('hidden'), 2500);
                }

                // Auto-close popover on mobile viewports (< 640px)
                if (window.innerWidth < 640) {
                    closeAllPopovers();
                }
            });
        }

        if (btnResetFilters) {
            btnResetFilters.addEventListener('click', () => {
                const form = document.getElementById('formAdvancedFilters');
                if (form) form.reset();
                if (customDateRangeContainer) customDateRangeContainer.classList.add('hidden');
                mapController.resetFilters();

                const feedback = document.getElementById('filterFeedbackMsg');
                if (feedback) {
                    feedback.textContent = '✓ Filters reset to default';
                    feedback.classList.remove('hidden');
                    setTimeout(() => feedback.classList.add('hidden'), 2000);
                }
            });
        }

        // Reset View
        const btnReset = document.getElementById('btnResetView');
        if (btnReset) {
            btnReset.addEventListener('click', () => {
                mapController.map.flyTo({ center: [100.0, 5.0], zoom: 3.5 });
            });
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
                    if (panelToggleText) panelToggleText.textContent = 'Show Track';
                    if (panelToggleIcon) panelToggleIcon.textContent = '🙈';
                } else {
                    mapController.showTrackLayer();
                    if (panelToggleText) panelToggleText.textContent = 'Hide Track';
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
                        panelBtnShareUrl.innerHTML = '<span>✓</span> <span class="hidden sm:inline">Copied!</span>';
                        panelBtnShareUrl.classList.add('text-emerald-300', 'border-emerald-500/50');
                        setTimeout(() => {
                            panelBtnShareUrl.innerHTML = originalHtml;
                            panelBtnShareUrl.classList.remove('text-emerald-300', 'border-emerald-500/50');
                        }, 2000);
                    }).catch(() => {
                        prompt('Copy link to this vessel position:', url);
                    });
                } else {
                    prompt('Copy link to this vessel position:', url);
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
