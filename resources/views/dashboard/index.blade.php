@extends('layouts.app')

@section('header_title', 'Overview Dashboard — Global Fishing Vessel Tracking')

@section('content')
<div class="space-y-6">

    <!-- Operational Banner -->
    <div class="relative overflow-hidden rounded-2xl bg-gradient-to-r from-slate-900 via-teal-950/40 to-slate-900 border border-teal-500/20 p-6 shadow-xl">
        <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <div class="inline-flex items-center gap-2 px-2.5 py-0.5 rounded-full bg-teal-500/10 border border-teal-500/30 text-teal-300 text-xs font-mono font-semibold uppercase mb-2">
                    <span class="w-2 h-2 rounded-full bg-teal-400 animate-ping"></span>
                    <span>Global Situation Room</span>
                </div>
                <h2 class="text-xl sm:text-2xl font-black text-white tracking-wide">
                    Global Fishing Vessel Tracking
                </h2>
            </div>

            <div class="flex items-center gap-3 shrink-0">
                <a href="{{ route('map.index') }}" class="px-4 py-2.5 rounded-xl bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold text-xs uppercase tracking-wider transition shadow-lg shadow-teal-500/20 flex items-center gap-2">
                    <span>🗺️</span>
                    <span>Open Global Map</span>
                </a>
            </div>
        </div>
    </div>

    <!-- Authentic Key Metrics (Non-Fictitious) -->
    <div class="grid grid-cols-1 sm:grid-cols-2 {{ auth()->user()?->role === 'researcher' ? 'lg:grid-cols-4' : 'lg:grid-cols-3 xl:grid-cols-6' }} gap-4">
        <!-- Metric 1: Total Vessels -->
        <div class="glass-panel p-4 rounded-xl border border-slate-800 space-y-2">
            <div class="flex items-center justify-between text-slate-400 text-xs">
                <span class="font-semibold uppercase tracking-wider text-[10px]">Total Registered</span>
                <span>🚢</span>
            </div>
            <div class="text-2xl font-black text-white font-mono">{{ number_format($totalVessels) }}</div>
            <div class="text-[10px] text-slate-400">Fleet in system registry</div>
        </div>

        <!-- Metric 2: Active Positions (24h) -->
        <div class="glass-panel p-4 rounded-xl border border-slate-800 space-y-2">
            <div class="flex items-center justify-between text-slate-400 text-xs">
                <span class="font-semibold uppercase tracking-wider text-[10px]">Active Positions (24h)</span>
                <span>📡</span>
            </div>
            <div class="text-2xl font-black text-teal-300 font-mono">{{ number_format($activeVesselsCount) }}</div>
            <div class="text-[10px] text-slate-400">Vessels with recent broadcast</div>
        </div>

        @if(auth()->user()?->role !== 'researcher')
        <!-- Metric 3: Encounters Needing Review -->
        <div class="glass-panel p-4 rounded-xl border border-slate-800 space-y-2">
            <div class="flex items-center justify-between text-slate-400 text-xs">
                <span class="font-semibold uppercase tracking-wider text-[10px]">Encounter Review</span>
                <span>🤝</span>
            </div>
            <div class="text-2xl font-black text-amber-300 font-mono">{{ number_format($encountersNeedingReview) }}</div>
            <div class="text-[10px] text-slate-400">Pending maritime analyst review</div>
        </div>
        @endif

        <!-- Metric 4: Dark Vessel Candidates -->
        <div class="glass-panel p-4 rounded-xl border border-slate-800 space-y-2">
            <div class="flex items-center justify-between text-slate-400 text-xs">
                <span class="font-semibold uppercase tracking-wider text-[10px]">Dark Vessel Candidates</span>
                <span>🛰️</span>
            </div>
            <div class="text-2xl font-black text-rose-400 font-mono">{{ number_format($darkVesselCandidates) }}</div>
            <div class="text-[10px] text-slate-400">SAR detection without AIS</div>
        </div>

        <!-- Metric 5: Open Compliance Alerts -->
        <div class="glass-panel p-4 rounded-xl border border-slate-800 space-y-2">
            <div class="flex items-center justify-between text-slate-400 text-xs">
                <span class="font-semibold uppercase tracking-wider text-[10px]">Compliance Alerts</span>
                <span>⚖️</span>
            </div>
            <div class="text-2xl font-black text-orange-400 font-mono">{{ number_format($openComplianceAlerts) }}</div>
            <div class="text-[10px] text-slate-400">Active non-compliance indicators</div>
        </div>

        @if(auth()->user()?->role !== 'researcher')
        <!-- Metric 6: Open Investigations -->
        <div class="glass-panel p-4 rounded-xl border border-slate-800 space-y-2">
            <div class="flex items-center justify-between text-slate-400 text-xs">
                <span class="font-semibold uppercase tracking-wider text-[10px]">Investigations Dossier</span>
                <span>📁</span>
            </div>
            <div class="text-2xl font-black text-indigo-300 font-mono">{{ number_format($openInvestigations) }}</div>
            <div class="text-[10px] text-slate-400">Open active dossiers</div>
        </div>
        @endif
    </div>

    <!-- Data Freshness & Data Providers Status -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Telemetry Freshness & Audit Provenance -->
        <div class="glass-panel p-5 rounded-xl border border-slate-800 space-y-4">
            <div class="flex items-center justify-between border-b border-slate-800 pb-2">
                <h3 class="text-xs font-bold uppercase tracking-wider text-white flex items-center gap-2">
                    <span>⏱️</span>
                    <span>Data Freshness &amp; Integrity</span>
                </h3>
                <span class="text-[10px] text-slate-400 font-mono">Real-time Tracker</span>
            </div>

            <div class="space-y-3 text-xs">
                <div class="flex justify-between items-center p-2.5 rounded-lg bg-slate-900/80 border border-slate-800">
                    <span class="text-slate-400">Latest Telemetry Received:</span>
                    <span class="text-teal-300 font-mono font-semibold">{{ $latestTelemetryAge }}</span>
                </div>

                <div class="flex justify-between items-center p-2.5 rounded-lg bg-slate-900/80 border border-slate-800">
                    <span class="text-slate-400">Fusion Policy:</span>
                    <span class="text-slate-200 font-medium">Multi-source Evidence</span>
                </div>

                <div class="p-3 rounded-lg bg-slate-900/90 border border-slate-800 text-[11px] text-slate-400 space-y-1.5 leading-relaxed">
                    <div class="font-semibold text-slate-300 flex items-center gap-1">
                        <span>🛡️</span> Zero Synthetic Data Principle
                    </div>
                    <p>
                        The platform operates without simulating synthetic vessel movements or fictional metrics. Unconfigured external sources transparently show <code>Not Configured</code> or zero values.
                    </p>
                </div>
            </div>
        </div>

        <!-- Providers Health Matrix -->
        <div class="lg:col-span-2 glass-panel p-5 rounded-xl border border-slate-800 space-y-4">
            <div class="flex items-center justify-between border-b border-slate-800 pb-2">
                <h3 class="text-xs font-bold uppercase tracking-wider text-white flex items-center gap-2">
                    <span>🔌</span>
                    <span>Data Sources &amp; License Status</span>
                </h3>
                <a href="{{ route('providers.index') }}" class="text-[11px] text-teal-400 hover:text-teal-300 font-semibold underline">
                    Manage Providers &rarr;
                </a>
            </div>

            <div class="overflow-x-auto text-xs responsive-table-container">
                <table class="w-full text-left min-w-[540px]">
                    <thead>
                        <tr class="text-[10px] text-slate-400 uppercase tracking-wider border-b border-slate-800">
                            <th class="pb-2">Provider</th>
                            <th class="pb-2">Ingestion Type</th>
                            <th class="pb-2">Operational Status</th>
                            <th class="pb-2">Synchronization</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60">
                        @forelse($providers as $provider)
                        <tr>
                            <td class="py-2.5 font-semibold text-slate-200">{{ $provider->name }}</td>
                            <td class="py-2.5 font-mono text-[11px] text-slate-400">{{ $provider->type }}</td>
                            <td class="py-2.5">
                                @if($provider->health_status === 'connected' || $provider->health_status === 'receiving_data' || $provider->health_status === 'streaming')
                                    <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/40">
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> Connected
                                    </span>
                                @elseif($provider->health_status === 'authenticated')
                                    <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[10px] font-bold bg-sky-500/20 text-sky-300 border border-sky-500/40">
                                        <span class="w-1.5 h-1.5 rounded-full bg-sky-400"></span> Authenticated
                                    </span>
                                @elseif($provider->health_status === 'research_access_required')
                                    <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-500/20 text-amber-300 border border-amber-500/40" title="Research Access Required">
                                        <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span> Research Req (403)
                                    </span>
                                @elseif($provider->health_status === 'not_configured')
                                    <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-800 text-slate-400 border border-slate-700">
                                        <span class="w-1.5 h-1.5 rounded-full bg-slate-500"></span> Not Configured
                                    </span>
                                @elseif($provider->health_status === 'delayed' || $provider->health_status === 'awaiting_telemetry' || $provider->health_status === 'stale' || $provider->health_status === 'reachable')
                                    <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-500/20 text-amber-300 border border-amber-500/40">
                                        <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span> Delayed
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-500/20 text-rose-300 border border-rose-500/40">
                                        <span class="w-1.5 h-1.5 rounded-full bg-rose-400"></span> Error
                                    </span>
                                @endif
                            </td>
                            <td class="py-2.5 text-[11px] text-slate-400 font-mono">
                                {{ $provider->last_sync_at ? $provider->last_sync_at->diffForHumans() : 'Never' }}
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="4" class="py-4 text-center text-slate-500">No registered data providers found.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Recent Feeds: Encounters & Compliance Alerts -->
    <div class="grid grid-cols-1 {{ auth()->user()?->role !== 'researcher' ? 'lg:grid-cols-2' : '' }} gap-6">

        @if(auth()->user()?->role !== 'researcher')
        <!-- Recent Encounters -->
        <div class="glass-panel p-5 rounded-xl border border-slate-800 space-y-4">
            <div class="flex items-center justify-between border-b border-slate-800 pb-2">
                <h3 class="text-xs font-bold uppercase tracking-wider text-amber-300 flex items-center gap-2">
                    <span>🤝</span>
                    <span>Recent Proximity Encounters</span>
                </h3>
                <a href="{{ route('encounters.index') }}" class="text-[11px] text-cyan-400 hover:text-cyan-300 font-semibold underline">
                    All Encounters &rarr;
                </a>
            </div>

            <div class="space-y-2.5 text-xs">
                @forelse($recentEncounters as $enc)
                <div class="p-3 rounded-lg bg-slate-900/80 border border-slate-800 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div>
                        <div class="font-bold text-white flex items-center gap-1.5">
                            <span>{{ $enc->encounter_code }}</span>
                            <span class="text-[10px] px-1.5 py-0.2 rounded font-mono uppercase bg-slate-800 text-slate-300 border border-slate-700">
                                {{ $enc->category }}
                            </span>
                        </div>
                        <div class="text-[11px] text-slate-400 mt-1">
                            {{ $enc->vessel1?->name ?? 'Vessel A' }} &bull; {{ $enc->vessel2?->name ?? 'Vessel B' }}
                        </div>
                        <div class="text-[10px] text-slate-500 mt-0.5 font-mono">
                            Distance: {{ $enc->min_distance_meters }}m &bull; Duration: {{ $enc->duration_minutes }}m &bull; {{ $enc->start_time->diffForHumans() }}
                        </div>
                    </div>
                    <div class="text-right shrink-0">
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase font-mono
                            {{ $enc->status === 'verified' ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30' : ($enc->status === 'rejected' ? 'bg-rose-500/20 text-rose-300 border border-rose-500/30' : 'bg-amber-500/20 text-amber-300 border border-amber-500/30') }}">
                            {{ $enc->status }}
                        </span>
                        <div class="mt-1">
                            <a href="{{ route('encounters.show', $enc->id) }}" class="text-[11px] text-cyan-400 hover:underline">Details</a>
                        </div>
                    </div>
                </div>
                @empty
                <div class="py-8 text-center text-slate-500">
                    <span class="text-3xl block mb-1">🌊</span>
                    No encounter events currently detected from AIS telemetry.
                </div>
                @endforelse
            </div>
        </div>
        @endif

        <!-- Recent Compliance Alerts -->
        <div class="glass-panel p-5 rounded-xl border border-slate-800 space-y-4">
            <div class="flex items-center justify-between border-b border-slate-800 pb-2">
                <h3 class="text-xs font-bold uppercase tracking-wider text-rose-400 flex items-center gap-2">
                    <span>⚖️</span>
                    <span>Compliance &amp; Rule Alerts</span>
                </h3>
                <a href="{{ route('compliance.index') }}" class="text-[11px] text-cyan-400 hover:text-cyan-300 font-semibold underline">
                    All Alerts &rarr;
                </a>
            </div>

            <div class="space-y-2.5 text-xs">
                @forelse($recentAlerts as $alert)
                <div class="p-3 rounded-lg bg-slate-900/80 border border-slate-800 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div>
                        <div class="font-bold text-white flex items-center gap-1.5">
                            <span class="text-rose-400">⚠️</span>
                            <span>{{ $alert->event_code }}</span>
                            <span class="text-[10px] px-1.5 py-0.2 rounded font-mono uppercase bg-rose-500/20 text-rose-300 border border-rose-500/30">
                                {{ $alert->severity }}
                            </span>
                        </div>
                        <div class="text-[11px] text-slate-300 mt-1">
                            {{ $alert->rule?->rule_name ?? 'Rule Violation' }} &bull; <strong class="text-white">{{ $alert->vessel?->name ?? 'Vessel' }}</strong>
                        </div>
                        <div class="text-[10px] text-slate-400 mt-0.5">
                            {{ $alert->evidence_summary }}
                        </div>
                    </div>
                    <div class="text-right shrink-0">
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase font-mono bg-slate-800 text-slate-300 border border-slate-700">
                            {{ $alert->status }}
                        </span>
                        <div class="mt-1">
                            <a href="{{ route('compliance.show', $alert->id) }}" class="text-[11px] text-cyan-400 hover:underline">Review</a>
                        </div>
                    </div>
                </div>
                @empty
                <div class="py-8 text-center text-slate-500">
                    <span class="text-3xl block mb-1">✅</span>
                    No open compliance alerts currently in the system.
                </div>
                @endforelse
            </div>
        </div>

    </div>

    <!-- Vessel Type Breakdown Section (Positioned at the very bottom of Dashboard) -->
    <div id="vessel-type-breakdown-card"
        data-breakdown='@json($vesselTypeBreakdown)'
        class="rounded-2xl bg-white border border-slate-200/90 p-5 sm:p-7 shadow-sm text-slate-800 space-y-6">

        <!-- Header: Title & Subtitle with Chart Icon -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-slate-100 pb-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center shrink-0 shadow-xs">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z" />
                    </svg>
                </div>
                <div>
                    <h3 class="text-base sm:text-lg font-bold text-slate-900 tracking-tight flex items-center gap-2">
                        <span>Vessel Type Breakdown</span>
                    </h3>
                    <p class="text-xs text-slate-500 font-medium">
                        Distribution of registered vessels by vessel type &bull; Ringkasan jumlah dan proporsi kapal dalam database
                    </p>
                </div>
            </div>

            <div class="text-[11px] font-mono text-slate-400 sm:text-right">
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-slate-100 text-slate-600 font-semibold">
                    <span class="w-1.5 h-1.5 rounded-full bg-blue-500"></span>
                    Updated: Real-time Telemetry
                </span>
            </div>
        </div>

        <!-- Main Body: 3-Column Layout (Doughnut Chart, Categories Breakdown, Total Vessels Card) -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-center">

            <!-- A. Doughnut Chart Container -->
            <div class="lg:col-span-4 flex flex-col items-center justify-center">
                <div class="relative w-56 h-56 sm:w-64 sm:h-64 flex items-center justify-center">
                    <canvas id="vessel-type-doughnut-chart"></canvas>
                </div>
                <div class="mt-2 text-center">
                    <span class="text-[11px] text-slate-400 font-medium">
                        Hover slice or list item to view details
                    </span>
                </div>
            </div>

            <!-- B. Categories Breakdown List (2 Columns Grid with Scrollbar if needed) -->
            <div class="lg:col-span-5 border-t lg:border-t-0 lg:border-l lg:border-r border-slate-100 lg:px-6 pt-4 lg:pt-0">
                <div class="flex items-center justify-between pb-2 mb-2 border-b border-slate-100 text-[11px] font-semibold text-slate-400 uppercase tracking-wider">
                    <span>Vessel Type</span>
                    <span>Fleet Count / Share</span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-5 gap-y-2 max-h-[300px] overflow-y-auto pr-2" style="scrollbar-width: thin;">
                    @forelse($vesselTypeBreakdown as $idx => $item)
                    <div id="vessel-type-item-{{ $idx }}"
                        data-index="{{ $idx }}"
                        class="vessel-type-item flex items-center justify-between p-1.5 rounded-lg transition-colors duration-150 cursor-pointer hover:bg-slate-50">
                        <div class="flex items-center gap-2 min-w-0 pr-2">
                            <span class="w-2.5 h-2.5 rounded-full shrink-0" style="background-color: {{ $item['color'] }};"></span>
                            <span class="text-xs font-semibold text-slate-700 truncate" title="{{ $item['label'] }}">
                                {{ $item['label'] }}
                            </span>
                        </div>
                        <div class="flex items-center gap-2 shrink-0 font-mono text-xs">
                            <span class="font-bold text-slate-900">{{ number_format($item['count']) }}</span>
                            <span class="text-[11px] font-medium text-slate-500 w-11 text-right">{{ number_format($item['percentage'], 1) }}%</span>
                        </div>
                    </div>
                    @empty
                    <div class="col-span-2 py-6 text-center text-xs text-slate-400">
                        No registered vessels available in the system database.
                    </div>
                    @endforelse
                </div>
            </div>

            <!-- C. Total Vessels Summary Card (Right Column) -->
            <div class="lg:col-span-3 flex flex-col justify-center">
                <div class="rounded-2xl bg-blue-50/70 border border-blue-100/80 p-6 flex flex-col items-center justify-center text-center space-y-3 shadow-xs">
                    <div class="w-12 h-12 rounded-xl bg-blue-600 text-white flex items-center justify-center shadow-md shadow-blue-500/20 text-2xl">
                        🚢
                    </div>

                    <div class="space-y-0.5">
                        <div class="text-3xl sm:text-4xl font-black text-blue-950 font-mono tracking-tight">
                            {{ number_format($totalVessels) }}
                        </div>
                        <div class="text-xs font-bold text-slate-600 uppercase tracking-wider">
                            Total Vessels
                        </div>
                    </div>

                    <div class="pt-2 w-full">
                        <div class="inline-flex items-center justify-center gap-2 px-3.5 py-1.5 rounded-full bg-white border border-blue-200/80 text-blue-700 text-xs font-bold shadow-2xs w-full max-w-[200px]">
                            <svg class="w-3.5 h-3.5 text-blue-600 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M3 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd" />
                            </svg>
                            <span>{{ $vesselTypeCategoryCount }} Categories</span>
                        </div>
                    </div>

                    @if(!empty($topVesselType))
                    <div class="text-[11px] text-slate-500 pt-1">
                        Most common: <strong class="text-slate-700">{{ $topVesselType['label'] }}</strong> ({{ number_format($topVesselType['count']) }})
                    </div>
                    @endif
                </div>
            </div>

        </div>

    </div>

</div>
@endsection
