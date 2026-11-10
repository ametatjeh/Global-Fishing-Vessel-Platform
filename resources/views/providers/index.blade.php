@extends('layouts.app')

@section('header_title', 'Data Sources & Providers — Configuration, Health Check & Licensing')

@section('content')
<div class="space-y-6">

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <h2 class="text-xl font-bold text-white tracking-wide uppercase">Data Sources &amp; Provider Catalog</h2>
                <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold tracking-wider uppercase bg-teal-500/20 text-teal-300 border border-teal-500/40">
                    NON-COMMERCIAL RESEARCH
                </span>
            </div>
            <p class="text-xs text-slate-400 mt-1">
                External provider integration matrix: Global Fishing Watch, Copernicus Sentinel-1 SAR, AIS Live Telemetry, FAO, and Marine Regions EEZ.
            </p>
        </div>
        <div class="text-right text-[11px] text-slate-400 font-mono">
            <div>Data Last Updated:</div>
            <div class="text-slate-200 font-bold">{{ now()->format('Y-m-d H:i') }} UTC</div>
        </div>
    </div>

    <!-- Global Fishing Watch Data Attribution Banner -->
    <div class="glass-panel p-5 rounded-2xl border border-teal-500/30 bg-teal-950/15 shadow-xl space-y-3 text-xs">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-teal-500/20 pb-2.5">
            <div class="flex items-center gap-2">
                <span class="text-xl">🌐</span>
                <div>
                    <h3 class="font-bold uppercase tracking-wider text-teal-300">Global Fishing Watch Data Attribution</h3>
                    <div class="text-[11px] text-slate-400">
                        Data Source: <a href="https://globalfishingwatch.org" target="_blank" rel="noopener noreferrer" class="text-teal-400 hover:underline font-semibold">Global Fishing Watch (GFW)</a>
                    </div>
                </div>
            </div>
            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-mono font-bold uppercase bg-teal-500/20 text-teal-300 border border-teal-500/40">
                NON-COMMERCIAL RESEARCH
            </span>
        </div>

        <blockquote class="p-3 rounded-xl bg-slate-900 border border-slate-800 text-slate-300 italic text-[11px] leading-relaxed">
            &ldquo;This application uses data and services provided by Global Fishing Watch (GFW) for non-commercial research, educational, and public-interest purposes. GFW data are subject to applicable licensing, attribution, and usage restrictions.&rdquo;
        </blockquote>

        <div class="p-3 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-300/90 text-[11px] leading-relaxed">
            <strong>Notice: Displayed vessel activity represents estimates or analytical findings based on available data sources, not conclusive proof of infractions.</strong> All features based on GFW data are provided free of charge without commercial subscriptions, paywalls, or data monetization.
        </div>
    </div>

    <!-- Providers Table -->
    <div class="glass-panel rounded-2xl border border-slate-800 overflow-hidden shadow-xl p-5 space-y-4">
        <h3 class="text-xs font-bold uppercase tracking-wider text-teal-300 border-b border-slate-800 pb-2">
            Operational Status &amp; Provider Licensing Rights
        </h3>

        <div class="space-y-4 text-xs">
            @forelse($providers as $provider)
            <div class="p-4 rounded-xl bg-slate-900 border border-slate-800 hover:border-slate-700 transition space-y-3">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-slate-800/80 pb-2.5">
                    <div>
                        <div class="text-sm font-bold text-white flex items-center gap-2">
                            <span>🔌</span>
                            <span>{{ $provider->name }}</span>
                            <span class="text-[10px] px-2 py-0.2 rounded font-mono uppercase bg-slate-800 text-slate-300 border border-slate-700">
                                {{ $provider->type }}
                            </span>
                        </div>
                        <div class="text-[11px] text-slate-400 mt-0.5 font-mono">{{ $provider->api_url ?? 'Internal / Local File' }}</div>
                    </div>

                    <div class="flex items-center gap-2">
                        @if($provider->health_status === 'receiving_data' || $provider->health_status === 'streaming')
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/40">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span> Streaming Live Data
                            </span>
                        @elseif($provider->health_status === 'authenticated')
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold bg-sky-500/20 text-sky-300 border border-sky-500/40">
                                <span class="w-1.5 h-1.5 rounded-full bg-sky-400"></span> Authenticated
                            </span>
                        @elseif($provider->health_status === 'research_access_required')
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold bg-amber-500/20 text-amber-300 border border-amber-500/40" title="Valid token on GFW gateway, but /events endpoint requires Research Access permissions">
                                <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span> Access denied — Research Access required
                            </span>
                        @elseif($provider->health_status === 'unauthenticated')
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold bg-rose-500/20 text-rose-300 border border-rose-500/40">
                                <span class="w-1.5 h-1.5 rounded-full bg-rose-400"></span> Unauthenticated (401)
                            </span>
                        @elseif($provider->health_status === 'rate_limited')
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold bg-amber-500/20 text-amber-300 border border-amber-500/40">
                                <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span> Rate Limited (429)
                            </span>
                        @elseif($provider->health_status === 'network_error')
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold bg-rose-500/20 text-rose-300 border border-rose-500/40">
                                <span class="w-1.5 h-1.5 rounded-full bg-rose-400"></span> Network Error / Timeout
                            </span>
                        @elseif($provider->health_status === 'server_error')
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold bg-rose-500/20 text-rose-300 border border-rose-500/40">
                                <span class="w-1.5 h-1.5 rounded-full bg-rose-400"></span> Server Error (5xx)
                            </span>
                        @elseif($provider->health_status === 'awaiting_telemetry' || $provider->health_status === 'reachable')
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold bg-amber-500/20 text-amber-300 border border-amber-500/40">
                                <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span> Awaiting Telemetry
                            </span>
                        @elseif($provider->health_status === 'stale')
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold bg-amber-500/20 text-amber-300 border border-amber-500/40">
                                <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span> Stale Telemetry (>2h)
                            </span>
                        @elseif($provider->health_status === 'persistence_error')
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold bg-rose-500/20 text-rose-300 border border-rose-500/40">
                                <span class="w-1.5 h-1.5 rounded-full bg-rose-400"></span> Persistence Error
                            </span>
                        @elseif($provider->health_status === 'connected')
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/40">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> Connected
                            </span>
                        @elseif($provider->health_status === 'not_configured')
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold bg-slate-800 text-slate-400 border border-slate-700">
                                <span class="w-1.5 h-1.5 rounded-full bg-slate-500"></span> Not Configured
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold bg-rose-500/20 text-rose-300 border border-rose-500/40">
                                <span class="w-1.5 h-1.5 rounded-full bg-rose-400"></span> {{ strtoupper($provider->health_status) }}
                            </span>
                        @endif

                        <form action="{{ route('providers.health', $provider->slug) }}" method="POST">
                            @csrf
                            <button type="submit" class="px-3 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-teal-300 border border-slate-700 font-semibold transition" title="Run Health Check">
                                Test Health
                            </button>
                        </form>
                    </div>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-[11px]">
                    <div>
                        <span class="text-slate-400">Data License:</span>
                        <div class="font-semibold text-slate-200">{{ $provider->license_type ?? 'Proprietary' }}</div>
                    </div>
                    <div>
                        <span class="text-slate-400">Commercial Rights:</span>
                        <div>
                            @if($provider->commercial_use_allowed)
                                <span class="text-teal-400 font-bold">Permitted</span>
                            @else
                                <span class="text-rose-400 font-bold">Non-Commercial Only</span>
                            @endif
                        </div>
                    </div>
                    <div>
                        <span class="text-slate-400">Retention &amp; Redistribution:</span>
                        <div class="text-slate-300">
                            {{ $provider->retention_allowed ? 'Retention: Yes' : 'Retention: No' }} &bull;
                            {{ $provider->redistribution_allowed ? 'Redistribution: Yes' : 'Redistribution: Restricted' }}
                        </div>
                    </div>
                    <div>
                        <span class="text-slate-400">Last Synchronization:</span>
                        <div class="font-mono text-slate-300">
                            {{ $provider->last_sync_at ? $provider->last_sync_at->diffForHumans() : 'Never' }}
                        </div>
                    </div>
                </div>

                @if($provider->slug === 'ais-stream' && !empty($provider->rate_limit_info))
                @php
                    $aisMeta = $provider->rate_limit_info;
                @endphp
                <div class="p-3 rounded-xl bg-slate-900/90 border border-slate-800 grid grid-cols-2 sm:grid-cols-4 gap-3 text-[11px] font-mono">
                    <div>
                        <span class="text-slate-400">Messages Received:</span>
                        <div class="font-bold text-teal-300">{{ number_format($aisMeta['session_messages_received'] ?? $aisMeta['batch_messages_received'] ?? 0) }}</div>
                    </div>
                    <div>
                        <span class="text-slate-400">New Positions &amp; Updates:</span>
                        <div class="font-bold text-sky-300">{{ number_format($aisMeta['session_inserted'] ?? $aisMeta['batch_inserted'] ?? 0) }}</div>
                    </div>
                    <div>
                        <span class="text-slate-400">Duplicates / Skipped:</span>
                        <div class="font-bold text-slate-300">{{ number_format($aisMeta['session_skipped'] ?? $aisMeta['batch_skipped'] ?? 0) }}</div>
                    </div>
                    <div>
                        <span class="text-slate-400">Total Positions in DB:</span>
                        <div class="font-bold text-amber-300">{{ number_format($aisMeta['total_positions_in_db'] ?? \App\Models\VesselPosition::count()) }}</div>
                    </div>
                </div>
                @endif

                @if($provider->last_error_message)
                <div class="p-2.5 rounded-lg {{ $provider->health_status === 'research_access_required' ? 'bg-amber-950/40 border border-amber-500/30 text-amber-300' : 'bg-rose-950/40 border border-rose-500/30 text-rose-300' }} text-[11px]">
                    <strong>Diagnostic Note:</strong> {{ $provider->last_error_message }}
                </div>
                @endif

                @if($provider->slug === 'global-fishing-watch' && $provider->health_status === 'research_access_required')
                <div class="p-3.5 rounded-xl bg-amber-950/40 border border-amber-500/40 text-amber-200 text-xs space-y-2">
                    <div class="flex items-center gap-2 font-bold text-amber-300">
                        <svg class="w-4 h-4 text-amber-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                        </svg>
                        <span>GFW Access Notice: Research Access Required</span>
                    </div>
                    <p class="text-[11px] text-amber-300/90 leading-relaxed">
                        Your GFW API token is valid and authenticated against the public gateway (the <code>/datasets</code> catalog was successfully queried), but the <code>/events</code> endpoint (vessel encounter &amp; loitering detection) requires <strong>Research Access</strong> permissions from Global Fishing Watch.
                    </p>
                    <div class="flex flex-wrap items-center gap-3 pt-1">
                        <a href="https://globalfishingwatch.org/our-apis/tokens" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-amber-600 hover:bg-amber-500 text-white font-semibold text-[11px] transition shadow">
                            <span>Apply for Research Access on GFW Portal</span>
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                        </a>
                        <span class="text-[10px] text-amber-400/80">
                            * Live AIS telemetry stream, Marine Regions EEZ boundaries, FAO Major Fishing Areas, and Copernicus SAR radar modules continue to operate independently.
                        </span>
                    </div>
                </div>
                @endif
            </div>
            @empty
            <div class="text-center py-6 text-slate-500">No registered data providers found.</div>
            @endforelse
        </div>
    </div>

    <!-- Ingestion Runs Audit History -->
    <div class="glass-panel rounded-2xl border border-slate-800 overflow-hidden shadow-xl p-5 space-y-4">
        <h3 class="text-xs font-bold uppercase tracking-wider text-white border-b border-slate-800 pb-2">
            Synchronization History &amp; Ingestion Runs
        </h3>

        <div class="overflow-x-auto text-xs">
            <table class="w-full text-left">
                <thead class="text-slate-400 uppercase text-[10px] border-b border-slate-800">
                    <tr>
                        <th class="py-2.5">Provider</th>
                        <th class="py-2.5">Job Type</th>
                        <th class="py-2.5">Status</th>
                        <th class="py-2.5">Fetched / Inserted / Skipped</th>
                        <th class="py-2.5">Started At</th>
                        <th class="py-2.5">Completed At</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60">
                    @forelse($recentRuns as $run)
                    <tr>
                        <td class="py-2.5 font-bold text-white">{{ $run->dataProvider?->name ?? 'Provider' }}</td>
                        <td class="py-2.5 font-mono text-[11px] text-teal-300">{{ $run->run_type }}</td>
                        <td class="py-2.5">
                            <span class="px-2 py-0.5 rounded text-[10px] font-mono uppercase
                                {{ $run->status === 'completed' ? 'bg-emerald-500/20 text-emerald-300' : 'bg-rose-500/20 text-rose-300' }}">
                                {{ $run->status }}
                            </span>
                        </td>
                        <td class="py-2.5 font-mono text-slate-300">
                            {{ $run->items_fetched }} / {{ $run->items_inserted }} / {{ $run->items_skipped }}
                        </td>
                        <td class="py-2.5 text-slate-400 font-mono text-[11px]">{{ $run->started_at?->format('Y-m-d H:i:s') }}</td>
                        <td class="py-2.5 text-slate-400 font-mono text-[11px]">{{ $run->completed_at ? $run->completed_at->diffForHumans($run->started_at) : '-' }}</td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="py-6 text-center text-slate-500">No provider synchronization runs recorded yet.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection
