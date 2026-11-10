@extends('layouts.app')

@section('header_title', 'Vessel Registry — Identity, Flag, Ownership & Global Fleet Operators')

@section('content')
<div class="space-y-6">

    <!-- Header Section -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2.5 flex-wrap">
                <h2 class="text-xl font-bold text-white tracking-wide uppercase">Vessel Registry</h2>
                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-mono font-bold tracking-wider uppercase bg-teal-500/15 text-teal-300 border border-teal-500/30">
                    Global Fleet Surveillance
                </span>
                @if($activeFiltersCount > 0)
                <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-semibold bg-amber-500/15 text-amber-300 border border-amber-500/30">
                    <span class="w-1.5 h-1.5 rounded-full bg-amber-400 animate-pulse"></span>
                    {{ $activeFiltersCount }} active filters
                </span>
                @endif
            </div>
            <p class="text-xs text-slate-400 mt-1">
                Verified vessel identities, flag state registrations, fleet operators, and beneficial ownership structures.
            </p>
        </div>

        <div class="flex items-center gap-2 flex-wrap">
            <!-- Bulk Enrichment Action Form -->
            <form action="{{ route('vessels.enrich-bulk') }}" method="POST" class="inline-flex items-center gap-2">
                @csrf
                <input type="hidden" name="limit" value="25">
                <input type="hidden" name="active_only" value="1">
                <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-teal-500/20 hover:bg-teal-500/30 text-teal-300 hover:text-teal-200 border border-teal-500/40 text-xs font-semibold transition shadow-sm" title="Run multi-source data enrichment for 25 active awaiting vessels">
                    <span>⚡</span>
                    <span>Bulk Enrichment (25 Active)</span>
                </button>
            </form>

            @if($activeFiltersCount > 0)
            <a href="{{ route('vessels.index') }}" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white border border-slate-700 text-xs font-medium transition shadow-sm" title="Clear all search filters">
                <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
                Reset Filters
            </a>
            @endif
        </div>
    </div>

    <!-- Registry KPI Summary & Awaiting Static Data Cards -->
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-7 gap-3">
        <!-- 0. Total Registered -->
        <a href="{{ route('vessels.index') }}" class="glass-panel p-3.5 rounded-xl border border-slate-800 hover:border-teal-500/40 transition group shadow-md block" title="Show all registered fleet vessels">
            <div class="flex items-center justify-between text-slate-400 text-xs">
                <span class="font-semibold uppercase tracking-wider text-[10px]">Total Registered</span>
                <span>🚢</span>
            </div>
            <div class="text-xl sm:text-2xl font-black text-white font-mono group-hover:text-teal-300 transition mt-1">
                {{ number_format($totalVessels ?? \App\Models\Vessel::count()) }}
            </div>
            <div class="text-[10px] text-slate-400 mt-0.5 truncate">Fleet in system registry</div>
        </a>

        @if(isset($awaitingStats))
        <!-- 1. Total Awaiting -->
        <a href="{{ route('vessels.index', ['vessel_type' => 'awaiting_static']) }}" class="glass-panel p-3.5 rounded-xl border border-slate-800 hover:border-amber-500/40 transition group shadow-md block">
            <div class="text-[10px] font-mono uppercase text-slate-400 flex items-center justify-between">
                <span>Awaiting Static</span>
                <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span>
            </div>
            <div class="text-xl font-bold font-mono text-amber-300 mt-1">
                {{ number_format($awaitingStats['total_awaiting'] ?? 0) }}
            </div>
            <div class="text-[10px] text-slate-400 mt-0.5 truncate">Total Awaiting AIS</div>
        </a>

        <!-- 2. Active Awaiting -->
        <a href="{{ route('vessels.index', ['vessel_type' => 'awaiting_static', 'activity' => 'active']) }}" class="glass-panel p-3.5 rounded-xl border border-slate-800 hover:border-emerald-500/40 transition group shadow-md block">
            <div class="text-[10px] font-mono uppercase text-slate-400 flex items-center justify-between">
                <span>Active &le; 24h</span>
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
            </div>
            <div class="text-xl font-bold font-mono text-emerald-300 mt-1">
                {{ number_format($awaitingStats['active_awaiting'] ?? 0) }}
            </div>
            <div class="text-[10px] text-slate-400 mt-0.5 truncate">Enrichment Priority</div>
        </a>

        <!-- 3. Enriched -->
        <a href="{{ route('vessels.index', ['enrichment_status' => 'enriched']) }}" class="glass-panel p-3.5 rounded-xl border border-slate-800 hover:border-teal-500/40 transition group shadow-md block">
            <div class="text-[10px] font-mono uppercase text-slate-400 flex items-center justify-between">
                <span>Enriched</span>
                <span class="text-xs">✓</span>
            </div>
            <div class="text-xl font-bold font-mono text-teal-300 mt-1">
                {{ number_format($awaitingStats['enriched_count'] ?? 0) }}
            </div>
            <div class="text-[10px] text-slate-400 mt-0.5 truncate">Enriched (External)</div>
        </a>

        <!-- 4. Partially Enriched -->
        <a href="{{ route('vessels.index', ['enrichment_status' => 'partial']) }}" class="glass-panel p-3.5 rounded-xl border border-slate-800 hover:border-sky-500/40 transition group shadow-md block">
            <div class="text-[10px] font-mono uppercase text-slate-400 flex items-center justify-between">
                <span>Partially Enriched</span>
                <span class="text-xs">◐</span>
            </div>
            <div class="text-xl font-bold font-mono text-sky-300 mt-1">
                {{ number_format($awaitingStats['partial_count'] ?? 0) }}
            </div>
            <div class="text-[10px] text-slate-400 mt-0.5 truncate">Partial Attributes</div>
        </a>

        <!-- 5. Conflicting -->
        <a href="{{ route('vessels.index', ['enrichment_status' => 'conflicting']) }}" class="glass-panel p-3.5 rounded-xl border border-slate-800 hover:border-rose-500/40 transition group shadow-md block">
            <div class="text-[10px] font-mono uppercase text-slate-400 flex items-center justify-between">
                <span>Conflicting Data</span>
                <span class="text-xs">⚠️</span>
            </div>
            <div class="text-xl font-bold font-mono text-rose-300 mt-1">
                {{ number_format($awaitingStats['conflicting_count'] ?? 0) }}
            </div>
            <div class="text-[10px] text-slate-400 mt-0.5 truncate">Analyst Review Required</div>
        </a>

        <!-- 6. Not Found -->
        <a href="{{ route('vessels.index', ['enrichment_status' => 'not_found']) }}" class="glass-panel p-3.5 rounded-xl border border-slate-800 hover:border-slate-600 transition group shadow-md block">
            <div class="text-[10px] font-mono uppercase text-slate-400 flex items-center justify-between">
                <span>Not Found</span>
                <span class="text-xs">🔍</span>
            </div>
            <div class="text-xl font-bold font-mono text-slate-300 mt-1">
                {{ number_format($awaitingStats['not_found_count'] ?? 0) }}
            </div>
            <div class="text-[10px] text-slate-400 mt-0.5 truncate">Not Found</div>
        </a>
        @endif
    </div>

    <!-- Multi-Dimensional Filter Bar (Compact Mode) -->
    <div class="glass-panel p-4 rounded-2xl border border-slate-800 shadow-xl space-y-3">
        <form action="{{ route('vessels.index') }}" method="GET" id="vesselsFilterForm" class="space-y-3 text-xs">
            @if(request()->filled('per_page'))
            <input type="hidden" name="per_page" value="{{ request('per_page') }}">
            @endif

            <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                <!-- 1. Search Query -->
                <div class="md:col-span-4 space-y-1">
                    <label class="block text-slate-400 font-semibold uppercase tracking-wider text-[10px]">
                        Vessel / Entity Search
                    </label>
                    <div class="relative">
                        <input type="text" name="search" value="{{ request('search') }}" placeholder="Name, MMSI, IMO, Callsign, Owner, Operator..."
                            class="w-full pl-8 pr-3 py-2 rounded-xl bg-slate-900/90 border border-slate-700 text-slate-200 placeholder-slate-500 focus:outline-none focus:border-teal-400 font-sans transition">
                        <div class="absolute inset-y-0 left-0 pl-2.5 flex items-center pointer-events-none text-slate-500">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                        </div>
                    </div>
                </div>

                <!-- 2. Flag Country Filter -->
                <div class="md:col-span-3 space-y-1">
                    <label for="flag_country" class="block text-slate-400 font-semibold uppercase tracking-wider text-[10px]">
                        Flag State
                    </label>
                    <select id="flag_country" name="flag_country" onchange="this.form.submit()"
                        class="w-full px-3 py-2 rounded-xl bg-slate-900/90 border border-slate-700 text-slate-200 focus:outline-none focus:border-teal-400 font-sans cursor-pointer transition">
                        <option value="">All Flags</option>
                        @foreach($availableFlagCountries as $country)
                        <option value="{{ $country['iso2'] }}" {{ (request('flag_country') == $country['iso2'] || request('flag_country') == $country['code'] || request('flag_state') == $country['iso2'] || request('flag_state') == $country['code']) ? 'selected' : '' }}>
                            {{ $country['flag_emoji'] }} {{ $country['iso2'] }} • {{ $country['name'] }}
                        </option>
                        @endforeach
                        <option value="unknown" {{ (request('flag_country') === 'unknown' || request('flag_state') === 'unknown') ? 'selected' : '' }}>
                            🌐 Unknown (Unreported Flag)
                        </option>
                    </select>
                </div>

                <!-- 3. Vessel Type Filter -->
                <div class="md:col-span-3 space-y-1">
                    <label for="vessel_type" class="block text-slate-400 font-semibold uppercase tracking-wider text-[10px]">
                        Vessel Type
                    </label>
                    <select id="vessel_type" name="vessel_type" onchange="this.form.submit()"
                        class="w-full px-3 py-2 rounded-xl bg-slate-900/90 border border-slate-700 text-slate-200 focus:outline-none focus:border-teal-400 font-sans cursor-pointer transition">
                        <option value="">All Types</option>
                        <option value="fishing" {{ request('vessel_type') === 'fishing' ? 'selected' : '' }}>Fishing</option>
                        <option value="cargo" {{ request('vessel_type') === 'cargo' ? 'selected' : '' }}>Cargo</option>
                        <option value="tanker" {{ request('vessel_type') === 'tanker' ? 'selected' : '' }}>Tanker</option>
                        <option value="towing" {{ request('vessel_type') === 'towing' ? 'selected' : '' }}>Towing</option>
                        <option value="tug" {{ request('vessel_type') === 'tug' ? 'selected' : '' }}>Tug</option>
                        <option value="passenger" {{ request('vessel_type') === 'passenger' ? 'selected' : '' }}>Passenger</option>
                        <option value="high_speed_craft" {{ request('vessel_type') === 'high_speed_craft' ? 'selected' : '' }}>High Speed Craft (HSC)</option>
                        <option value="pleasure" {{ request('vessel_type') === 'pleasure' ? 'selected' : '' }}>Pleasure Craft</option>
                        <option value="sailing" {{ request('vessel_type') === 'sailing' ? 'selected' : '' }}>Sailing</option>
                        <option value="pilot" {{ request('vessel_type') === 'pilot' ? 'selected' : '' }}>Pilot Vessel</option>
                        <option value="sar" {{ request('vessel_type') === 'sar' ? 'selected' : '' }}>Search and Rescue (SAR)</option>
                        <option value="port_tender" {{ request('vessel_type') === 'port_tender' ? 'selected' : '' }}>Port Tender</option>
                        <option value="law_enforcement" {{ in_array(request('vessel_type'), ['law_enforcement', 'patrol']) ? 'selected' : '' }}>Law Enforcement / Patrol</option>
                        <option value="military" {{ request('vessel_type') === 'military' ? 'selected' : '' }}>Military</option>
                        <option value="dredging" {{ request('vessel_type') === 'dredging' ? 'selected' : '' }}>Dredging / Underwater Ops</option>
                        <option value="diving" {{ request('vessel_type') === 'diving' ? 'selected' : '' }}>Diving Operations</option>
                        <option value="anti_pollution" {{ request('vessel_type') === 'anti_pollution' ? 'selected' : '' }}>Anti-pollution</option>
                        <option value="medical" {{ in_array(request('vessel_type'), ['medical', 'medical_transport']) ? 'selected' : '' }}>Medical Transport</option>
                        <option value="carrier" {{ request('vessel_type') === 'carrier' ? 'selected' : '' }}>Carrier (Reefer)</option>
                        <option value="support" {{ request('vessel_type') === 'support' ? 'selected' : '' }}>Support</option>
                        <option value="other" {{ request('vessel_type') === 'other' ? 'selected' : '' }}>Other</option>
                        <option value="not_available" {{ request('vessel_type') === 'not_available' ? 'selected' : '' }}>Not Available (Code 0)</option>
                        <option value="awaiting_static" {{ request('vessel_type') === 'awaiting_static' ? 'selected' : '' }}>Awaiting Static Data</option>
                        <option value="unmapped" {{ request('vessel_type') === 'unmapped' ? 'selected' : '' }}>Unmapped AIS Code</option>
                    </select>
                </div>

                <!-- 4. Filter Action Buttons -->
                <div class="md:col-span-2 flex items-center gap-2">
                    @if($activeFiltersCount > 0)
                    <a href="{{ route('vessels.index') }}" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 border border-slate-700 text-xs font-semibold transition shrink-0 whitespace-nowrap text-center" title="Reset Filters">
                        Reset Filters
                    </a>
                    @endif
                    <button type="submit" class="w-full py-2 px-3 rounded-xl bg-teal-500 hover:bg-teal-400 text-slate-950 text-xs font-bold transition shadow-md shadow-teal-500/20 whitespace-nowrap text-center">
                        Apply
                    </button>
                </div>
            </div>

            <!-- Row 2: Awaiting Static Data & Enrichment Specialized Filters -->
            <div class="grid grid-cols-1 sm:grid-cols-3 md:grid-cols-12 gap-3 pt-2 border-t border-slate-800/80 items-center">
                <!-- 5. Enrichment Status Filter -->
                <div class="md:col-span-4 space-y-1">
                    <label for="enrichment_status" class="block text-slate-400 font-semibold uppercase tracking-wider text-[10px]">
                        Data Enrichment Status
                    </label>
                    <select id="enrichment_status" name="enrichment_status" onchange="this.form.submit()"
                        class="w-full px-3 py-1.5 rounded-xl bg-slate-900/90 border border-slate-700 text-slate-200 focus:outline-none focus:border-teal-400 font-sans cursor-pointer transition text-xs">
                        <option value="">All Enrichment Statuses</option>
                        <option value="awaiting" {{ request('enrichment_status') === 'awaiting' ? 'selected' : '' }}>Awaiting Static (All Pending)</option>
                        <option value="enriched" {{ request('enrichment_status') === 'enriched' ? 'selected' : '' }}>✓ Enriched</option>
                        <option value="partial" {{ request('enrichment_status') === 'partial' ? 'selected' : '' }}>◐ Partially Enriched</option>
                        <option value="conflicting" {{ request('enrichment_status') === 'conflicting' ? 'selected' : '' }}>⚠️ Conflicting Data (Needs Review)</option>
                        <option value="not_found" {{ request('enrichment_status') === 'not_found' ? 'selected' : '' }}>🔍 Not Found</option>
                        <option value="not_started" {{ request('enrichment_status') === 'not_started' ? 'selected' : '' }}>⏱️ Not Started</option>
                    </select>
                </div>

                <!-- 6. Activity Filter -->
                <div class="md:col-span-4 space-y-1">
                    <label for="activity" class="block text-slate-400 font-semibold uppercase tracking-wider text-[10px]">
                        Telemetry Activity
                    </label>
                    <select id="activity" name="activity" onchange="this.form.submit()"
                        class="w-full px-3 py-1.5 rounded-xl bg-slate-900/90 border border-slate-700 text-slate-200 focus:outline-none focus:border-teal-400 font-sans cursor-pointer transition text-xs">
                        <option value="">All Activity Statuses</option>
                        <option value="active" {{ request('activity') === 'active' ? 'selected' : '' }}>🟢 Active &le; 24h (Priority)</option>
                        <option value="inactive" {{ request('activity') === 'inactive' ? 'selected' : '' }}>⚪ Inactive (&gt; 24h)</option>
                    </select>
                </div>

                <!-- 7. Waiting Duration Filter -->
                <div class="md:col-span-4 space-y-1">
                    <label for="waiting_duration" class="block text-slate-400 font-semibold uppercase tracking-wider text-[10px]">
                        Waiting Duration
                    </label>
                    <select id="waiting_duration" name="waiting_duration" onchange="this.form.submit()"
                        class="w-full px-3 py-1.5 rounded-xl bg-slate-900/90 border border-slate-700 text-slate-200 focus:outline-none focus:border-teal-400 font-sans cursor-pointer transition text-xs">
                        <option value="">All Timeframes</option>
                        <option value="1d" {{ request('waiting_duration') === '1d' ? 'selected' : '' }}>&gt; 1 Day Waiting</option>
                        <option value="7d" {{ request('waiting_duration') === '7d' ? 'selected' : '' }}>&gt; 7 Days Waiting</option>
                        <option value="30d" {{ request('waiting_duration') === '30d' ? 'selected' : '' }}>&gt; 30 Days Waiting</option>
                    </select>
                </div>
            </div>
        </form>
    </div>

    <!-- Vessels Registry Table (Horizontal Scrollable, Compact Single-Line Mode) -->
    <div class="glass-panel rounded-2xl border border-slate-800 overflow-hidden shadow-xl">
        <div class="overflow-x-auto text-xs responsive-table-container">
            <table class="w-full text-left min-w-[850px] xl:min-w-[1000px]">
                <thead class="bg-slate-900/90 text-slate-400 uppercase tracking-wider text-[10px] border-b border-slate-800">
                    <tr>
                        <th class="py-2.5 px-3 text-center w-12 text-slate-400 whitespace-nowrap">No.</th>
                        <th class="py-2.5 px-4 font-semibold text-slate-300 whitespace-nowrap">VESSEL <span class="sr-only">Vessel Name</span></th>
                        <th class="py-2.5 px-4 font-semibold text-slate-300 whitespace-nowrap">MMSI</th>
                        <th class="py-2.5 px-4 font-semibold text-slate-300 whitespace-nowrap">IMO</th>
                        <th class="py-2.5 px-4 font-semibold text-slate-300 whitespace-nowrap">FLAG</th>
                        <th class="py-2.5 px-4 font-semibold text-slate-300 whitespace-nowrap">OPERATOR</th>
                        <th class="py-2.5 px-4 font-semibold text-slate-300 whitespace-nowrap">OWNER</th>
                        <th class="py-2.5 px-4 font-semibold text-slate-300 whitespace-nowrap">VESSEL TYPE</th>
                        <th class="py-2.5 px-4 font-semibold text-slate-300 whitespace-nowrap">ENRICHMENT</th>
                        <th class="py-2.5 px-4 font-semibold text-slate-300 whitespace-nowrap">LAST SEEN</th>
                        <th class="py-2.5 px-4 text-right font-semibold text-slate-300 whitespace-nowrap">ACTIONS</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60">
                    @forelse($vessels as $vessel)
                    @php
                        $flagInfo = $vessel->flag_info;
                    @endphp
                    <tr class="hover:bg-slate-900/40 transition">

                        <!-- 0. Sequential Row Number -->
                        <td class="py-2.5 px-3 text-center font-mono text-slate-400 text-xs whitespace-nowrap">
                            {{ $loop->iteration + ($vessels->firstItem() ? $vessels->firstItem() - 1 : 0) }}
                        </td>

                        <!-- 1. VESSEL Name (Strictly Single Line, without Callsign or UUID) -->
                        <td class="py-2.5 px-4 whitespace-nowrap">
                            <a href="{{ route('vessels.show', $vessel->id) }}" class="font-bold text-white hover:text-teal-300 transition inline-flex items-center gap-1.5 group max-w-[200px]" title="{{ $vessel->name }}">
                                <span class="text-sm shrink-0">🚢</span>
                                <span class="underline-offset-2 group-hover:underline truncate">{{ $vessel->name }}</span>
                            </a>
                        </td>

                        <!-- 2. MMSI -->
                        <td class="py-2.5 px-4 font-mono text-teal-300 font-semibold text-xs whitespace-nowrap">
                            {{ $vessel->mmsi ?? '-' }}
                        </td>

                        <!-- 3. IMO -->
                        <td class="py-2.5 px-4 font-mono text-slate-300 text-xs whitespace-nowrap">
                            @if($vessel->display_imo !== '—')
                                <span class="font-semibold text-slate-200">{{ $vessel->display_imo }}</span>
                            @else
                                <span class="text-slate-500 italic text-[11px]">—</span>
                            @endif
                        </td>

                        <!-- 4. FLAG (🇰🇷 KR • Korea (Republic of)) -->
                        <td class="py-2.5 px-4 whitespace-nowrap">
                            @if(!empty($flagInfo['is_known']) && !empty($flagInfo['iso2']) && $flagInfo['iso2'] !== 'UN' && $flagInfo['status'] !== 'unknown')
                            <div class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded bg-slate-800/90 border border-slate-700/80 text-slate-200 text-xs shadow-sm"
                                title="Flag State: {{ $flagInfo['country_name'] }} ({{ $flagInfo['iso2'] }})">
                                <span class="text-xs leading-none">{{ $flagInfo['flag_emoji'] }}</span>
                                <span class="font-medium font-mono text-[11px] text-teal-300">{{ $flagInfo['iso2'] }}</span>
                                <span class="text-slate-500 text-[10px]">&bull;</span>
                                <span class="font-medium truncate max-w-[150px]">{{ $flagInfo['country_name'] }}</span>
                            </div>
                            @else
                            <div class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-slate-800/40 border border-slate-700/50 text-slate-400 text-xs"
                                title="Flag state not reported or unverified">
                                <span class="text-xs leading-none">🌐</span>
                                <span class="italic">Unknown</span>
                            </div>
                            @endif
                        </td>

                        <!-- 5. OPERATOR (Strictly Single Line, without Flag Icon) -->
                        <td class="py-2.5 px-4 whitespace-nowrap">
                            @if(!empty($vessel->operator))
                            <div class="font-medium text-slate-200 text-xs max-w-[170px] truncate" title="Operator: {{ $vessel->operator }}">
                                {{ $vessel->display_operator }}
                            </div>
                            @else
                            <span class="text-slate-500 italic text-[11px]">{{ $vessel->display_operator }}</span>
                            @endif
                        </td>

                        <!-- 6. OWNER (Strictly Single Line, without Flag Icon) -->
                        <td class="py-2.5 px-4 whitespace-nowrap">
                            @if(!empty($vessel->owner))
                            <div class="font-medium text-slate-200 text-xs max-w-[170px] truncate" title="Owner: {{ $vessel->owner }}">
                                {{ $vessel->display_owner }}
                            </div>
                            @else
                            <span class="text-slate-500 italic text-[11px]">{{ $vessel->display_owner }}</span>
                            @endif
                        </td>

                        <!-- 7. VESSEL TYPE (Strictly Single Line Badge) -->
                        <td class="py-2.5 px-4 whitespace-nowrap font-medium">
                            @php
                                $typeKey = strtolower($vessel->vessel_type ?? 'unknown');
                                if ($vessel->ais_ship_type_code === 0 || $typeKey === 'not_available') {
                                    $typeKey = 'not_available';
                                } elseif ((empty($vessel->vessel_type) || $typeKey === 'unknown') && $vessel->ais_ship_type_code === null && empty($vessel->static_data_received_at)) {
                                    $typeKey = 'awaiting_static';
                                } elseif (is_numeric($typeKey)) {
                                    $typeKey = \App\Services\Maritime\VesselClassificationService::mapAisCodeToTaxonomy((int) $typeKey);
                                }
                                $typeBadgeClass = match($typeKey) {
                                    'fishing' => 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30',
                                    'carrier', 'support', 'port_tender' => 'bg-amber-500/15 text-amber-300 border-amber-500/30',
                                    'passenger' => 'bg-sky-500/15 text-sky-300 border-sky-500/30',
                                    'patrol', 'law_enforcement', 'military' => 'bg-rose-500/15 text-rose-300 border-rose-500/30',
                                    'tug', 'towing', 'dredging', 'diving' => 'bg-orange-500/15 text-orange-300 border-orange-500/30',
                                    'pleasure', 'sailing' => 'bg-violet-500/15 text-violet-300 border-violet-500/30',
                                    'research' => 'bg-indigo-500/15 text-indigo-300 border-indigo-500/30',
                                    'sar', 'medical' => 'bg-cyan-500/15 text-cyan-300 border-cyan-500/30',
                                    'high_speed_craft' => 'bg-yellow-500/15 text-yellow-300 border-yellow-500/30',
                                    'cargo' => 'bg-blue-500/15 text-blue-300 border-blue-500/30',
                                    'tanker' => 'bg-purple-500/15 text-purple-300 border-purple-500/30',
                                    'not_available' => 'bg-slate-800 text-slate-400 border-slate-700',
                                    'awaiting_static' => 'bg-slate-800/80 text-amber-300/80 border-amber-500/20',
                                    'unmapped' => 'bg-fuchsia-500/15 text-fuchsia-300 border-fuchsia-500/30',
                                    default => 'bg-slate-800 text-slate-400 border-slate-700',
                                };
                            @endphp
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-mono border {{ $typeBadgeClass }}">
                                {{ $vessel->display_vessel_type }}
                            </span>
                        </td>

                        <!-- 8. ENRICHMENT STATUS (Normalized) -->
                        <td class="py-2.5 px-4 whitespace-nowrap font-medium">
                            @php
                                $enr = $vessel->normalized_enrichment_status;
                                $enrBadge = match($enr) {
                                    'ENRICHED' => 'bg-teal-500/15 text-teal-300 border-teal-500/30',
                                    'PARTIALLY_ENRICHED' => 'bg-sky-500/15 text-sky-300 border-sky-500/30',
                                    'CONFLICTING' => 'bg-rose-500/15 text-rose-300 border-rose-500/30',
                                    'SEARCHING' => 'bg-amber-500/15 text-amber-300 border-amber-500/30 animate-pulse',
                                    'NOT_FOUND' => 'bg-slate-800 text-slate-400 border-slate-700',
                                    'PROVIDER_UNAVAILABLE' => 'bg-red-950 text-red-400 border-red-800',
                                    'NOT_CONFIGURED' => 'bg-slate-800 text-slate-500 border-slate-700',
                                    default => 'bg-slate-800/60 text-slate-400 border-slate-700',
                                };
                            @endphp
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-mono border {{ $enrBadge }}">
                                {{ $enr }}
                            </span>
                        </td>

                        <!-- 9. LAST SEEN (Strictly Single Line, Last Detect) -->
                        <td class="py-2.5 px-4 text-xs font-mono whitespace-nowrap text-slate-300">
                            @php
                                $lastDetect = $vessel->last_seen_at ?? $vessel->latestPosition?->observed_at;
                            @endphp
                            @if($lastDetect)
                            <span class="inline-flex items-center gap-1.5" title="{{ $lastDetect->format('Y-m-d H:i:s') }} UTC @if($vessel->latestPosition) &bull; {{ sprintf('%.2f°, %.2f°', $vessel->latestPosition->latitude, $vessel->latestPosition->longitude) }} @endif">
                                @if($vessel->isRecentlyActive())
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse shrink-0" title="Active (&le; 24h)"></span>
                                @endif
                                <span class="text-slate-300">{{ $lastDetect->diffForHumans() }}</span>
                            </span>
                            @else
                            <span class="text-slate-500 italic text-[11px]" title="No telemetry detected">-</span>
                            @endif
                        </td>

                        <!-- 10. ACTIONS (Strictly Single Line) -->
                        <td class="py-2.5 px-4 text-right whitespace-nowrap">
                            <div class="inline-flex items-center gap-1.5 justify-end">
                                <form action="{{ route('vessels.enrich', $vessel->id) }}" method="POST" class="inline">
                                    @csrf
                                    <button type="submit" class="inline-flex items-center gap-1 px-2 py-1 rounded-lg bg-teal-500/10 hover:bg-teal-500/20 text-teal-300 hover:text-teal-200 border border-teal-500/30 font-semibold transition text-xs shadow-sm" title="Enrich vessel profile via multi-source reconciliation">
                                        <span>⚡</span>
                                        <span>Enrich Data</span>
                                    </button>
                                </form>
                                <a href="{{ route('vessels.show', $vessel->id) }}" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white border border-slate-700 font-semibold transition text-xs shadow-sm" title="View vessel profile">
                                    <span>Profile</span>
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                    </svg>
                                </a>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="11" class="py-12 text-center text-slate-500">
                            <span class="text-4xl block mb-2">🌊</span>
                            Belum ada kapal yang terdaftar dalam database registry saat ini.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $vessels->links('partials.pagination') }}
    </div>

</div>
@endsection
