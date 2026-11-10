@extends('layouts.app')

@section('header_title', 'Classification Review & Reconciliation')

@section('content')
<div class="space-y-6">

    <!-- Header & Breadcrumb -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2.5 flex-wrap">
                <a href="{{ route('vessels.index') }}" class="text-xs text-slate-400 hover:text-teal-300 transition">Vessels</a>
                <span class="text-slate-600">/</span>
                <h2 class="text-xl font-bold text-white tracking-wide uppercase">Classification Review & Reconciliation</h2>
                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-mono font-bold tracking-wider uppercase bg-indigo-500/20 text-indigo-300 border border-indigo-500/30">
                    Analyst Review Desk
                </span>
            </div>
            <p class="text-xs text-slate-400 mt-1">
                Review AIS telemetry evidence against manual classifications, resolve cargo/tanker classification conflicts, and enforce Protective Hold on fishing vessels.
            </p>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('vessels.index') }}" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white border border-slate-700 text-xs font-medium transition shadow-sm">
                <span>🚢</span> Back to Vessel Registry
            </a>
        </div>
    </div>

    <!-- Metrics Summary Pills -->
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
        <a href="{{ route('vessels.classification-review.index', ['conflict_status' => 'disputed_only']) }}"
           class="glass-panel p-3 rounded-xl border {{ $conflict === 'disputed_only' ? 'border-rose-500 bg-rose-500/10' : 'border-slate-800 hover:border-slate-700' }} transition">
            <div class="text-[10px] uppercase font-bold text-rose-400 tracking-wider">Disputed Conflict</div>
            <div class="text-xl font-bold text-rose-300 mt-1">{{ number_format($metrics['total_disputed']) }}</div>
            <div class="text-[10px] text-slate-400 mt-0.5">Fishing vs AIS Non-Fishing</div>
        </a>

        <a href="{{ route('vessels.classification-review.index', ['verification_status' => 'unverified_historical']) }}"
           class="glass-panel p-3 rounded-xl border {{ $status === 'unverified_historical' ? 'border-purple-500 bg-purple-500/10' : 'border-slate-800 hover:border-slate-700' }} transition">
            <div class="text-[10px] uppercase font-bold text-purple-400 tracking-wider">Historical Protected</div>
            <div class="text-xl font-bold text-purple-300 mt-1">{{ number_format($metrics['total_historical']) }}</div>
            <div class="text-[10px] text-slate-400 mt-0.5">Mandatory Hold Vessels</div>
        </a>

        <a href="{{ route('vessels.classification-review.index', ['verification_status' => 'under_review']) }}"
           class="glass-panel p-3 rounded-xl border {{ $status === 'under_review' ? 'border-amber-500 bg-amber-500/10' : 'border-slate-800 hover:border-slate-700' }} transition">
            <div class="text-[10px] uppercase font-bold text-amber-400 tracking-wider">Under Review</div>
            <div class="text-xl font-bold text-amber-300 mt-1">{{ number_format($metrics['total_under_review']) }}</div>
            <div class="text-[10px] text-slate-400 mt-0.5">Pending Decision</div>
        </a>

        <a href="{{ route('vessels.classification-review.index', ['verification_status' => 'unverified']) }}"
           class="glass-panel p-3 rounded-xl border {{ $status === 'unverified' ? 'border-amber-500 bg-amber-500/10' : 'border-slate-800 hover:border-slate-700' }} transition">
            <div class="text-[10px] uppercase font-bold text-amber-400 tracking-wider">Unverified</div>
            <div class="text-xl font-bold text-amber-300 mt-1">{{ number_format($metrics['total_unverified']) }}</div>
            <div class="text-[10px] text-slate-400 mt-0.5">No Authoritative Evidence</div>
        </a>

        <a href="{{ route('vessels.classification-review.index', ['verification_status' => 'provider_reported']) }}"
           class="glass-panel p-3 rounded-xl border {{ $status === 'provider_reported' ? 'border-blue-500 bg-blue-500/10' : 'border-slate-800 hover:border-slate-700' }} transition">
            <div class="text-[10px] uppercase font-bold text-blue-400 tracking-wider">Provider Reported</div>
            <div class="text-xl font-bold text-blue-300 mt-1">{{ number_format($metrics['total_provider_reported']) }}</div>
            <div class="text-[10px] text-slate-400 mt-0.5">External Raw Reports</div>
        </a>

        <a href="{{ route('vessels.classification-review.index', ['verification_status' => 'verified', 'vessel_type' => 'fishing']) }}"
           class="glass-panel p-3 rounded-xl border {{ $status === 'verified' && $vesselType === 'fishing' ? 'border-emerald-500 bg-emerald-500/10' : 'border-slate-800 hover:border-slate-700' }} transition">
            <div class="text-[10px] uppercase font-bold text-emerald-400 tracking-wider">Verified Fishing</div>
            <div class="text-xl font-bold text-emerald-300 mt-1">{{ number_format($metrics['total_verified_fishing']) }}</div>
            <div class="text-[10px] text-slate-400 mt-0.5">Protected & Permanent</div>
        </a>
    </div>

    <!-- Filter Bar -->
    <div class="glass-panel p-4 rounded-2xl border border-slate-800 shadow-xl">
        <form action="{{ route('vessels.classification-review.index') }}" method="GET" class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end text-xs">

            <!-- Search Query -->
            <div class="md:col-span-4 space-y-1">
                <label class="block text-slate-400 font-semibold uppercase tracking-wider text-[10px]">
                    Vessel Search
                </label>
                <div class="relative">
                    <input type="text" name="search" value="{{ $search }}" placeholder="Name, MMSI, IMO, Callsign..."
                        class="w-full pl-8 pr-3 py-2 rounded-xl bg-slate-900/90 border border-slate-700 text-slate-200 placeholder-slate-500 focus:outline-none focus:border-indigo-400 font-sans transition">
                    <div class="absolute inset-y-0 left-0 pl-2.5 flex items-center pointer-events-none text-slate-500">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </div>
                </div>
            </div>

            <!-- Verification Status Filter -->
            <div class="md:col-span-2 space-y-1">
                <label class="block text-slate-400 font-semibold uppercase tracking-wider text-[10px]">
                    Verification Status
                </label>
                <select name="verification_status" class="w-full px-3 py-2 rounded-xl bg-slate-900/90 border border-slate-700 text-slate-200 focus:outline-none focus:border-indigo-400 font-sans transition">
                    <option value="all">All Statuses</option>
                    <option value="unverified" {{ $status === 'unverified' ? 'selected' : '' }}>Unverified</option>
                    <option value="provider_reported" {{ $status === 'provider_reported' ? 'selected' : '' }}>Provider Reported</option>
                    <option value="unverified_historical" {{ $status === 'unverified_historical' ? 'selected' : '' }}>Unverified Historical</option>
                    <option value="under_review" {{ $status === 'under_review' ? 'selected' : '' }}>Under Review</option>
                    <option value="verified" {{ $status === 'verified' ? 'selected' : '' }}>Verified</option>
                    <option value="reclassified" {{ $status === 'reclassified' ? 'selected' : '' }}>Reclassified</option>
                    <option value="rejected" {{ $status === 'rejected' ? 'selected' : '' }}>Rejected</option>
                </select>
            </div>

            <!-- Vessel Type Filter -->
            <div class="md:col-span-2 space-y-1">
                <label class="block text-slate-400 font-semibold uppercase tracking-wider text-[10px]">
                    Vessel Type
                </label>
                <select name="vessel_type" class="w-full px-3 py-2 rounded-xl bg-slate-900/90 border border-slate-700 text-slate-200 focus:outline-none focus:border-indigo-400 font-sans transition">
                    <option value="all">All Types</option>
                    <option value="fishing" {{ $vesselType === 'fishing' ? 'selected' : '' }}>Fishing</option>
                    <option value="cargo" {{ $vesselType === 'cargo' ? 'selected' : '' }}>Cargo</option>
                    <option value="tanker" {{ $vesselType === 'tanker' ? 'selected' : '' }}>Tanker</option>
                    <option value="passenger" {{ $vesselType === 'passenger' ? 'selected' : '' }}>Passenger</option>
                    <option value="tug" {{ $vesselType === 'tug' ? 'selected' : '' }}>Tug</option>
                    <option value="pleasure" {{ $vesselType === 'pleasure' ? 'selected' : '' }}>Pleasure Craft</option>
                    <option value="other" {{ $vesselType === 'other' ? 'selected' : '' }}>Other</option>
                    <option value="unknown" {{ $vesselType === 'unknown' ? 'selected' : '' }}>Unknown</option>
                </select>
            </div>

            <!-- Conflict Status Filter -->
            <div class="md:col-span-2 space-y-1">
                <label class="block text-slate-400 font-semibold uppercase tracking-wider text-[10px]">
                    Conflict Status
                </label>
                <select name="conflict_status" class="w-full px-3 py-2 rounded-xl bg-slate-900/90 border border-slate-700 text-slate-200 focus:outline-none focus:border-indigo-400 font-sans transition">
                    <option value="all">All</option>
                    <option value="disputed_only" {{ $conflict === 'disputed_only' ? 'selected' : '' }}>⚠️ Disputed Only (Conflicts)</option>
                    <option value="clean_only" {{ $conflict === 'clean_only' ? 'selected' : '' }}>Clean Only (No Conflicts)</option>
                </select>
            </div>

            <!-- Actions -->
            <div class="md:col-span-2 flex items-center gap-2">
                <button type="submit" class="flex-1 py-2 px-3 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white font-medium text-xs transition shadow-sm flex items-center justify-center gap-1.5">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
                    </svg>
                    Filter
                </button>
                <a href="{{ route('vessels.classification-review.index') }}" class="py-2 px-3 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white border border-slate-700 font-medium text-xs transition" title="Reset filters">
                    Reset
                </a>
            </div>
        </form>
    </div>

    <!-- Review Table -->
    <div class="glass-panel rounded-2xl border border-slate-800 shadow-xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs text-slate-300">
                <thead class="bg-slate-900/80 text-[10px] uppercase font-bold text-slate-400 border-b border-slate-800 tracking-wider">
                    <tr>
                        <th class="px-4 py-3">Vessel Name</th>
                        <th class="px-3 py-3">MMSI</th>
                        <th class="px-3 py-3">IMO / Callsign</th>
                        <th class="px-3 py-3">Current Type</th>
                        <th class="px-3 py-3">AIS ShipType</th>
                        <th class="px-3 py-3">Provenance / Source</th>
                        <th class="px-3 py-3">Verification Status</th>
                        <th class="px-3 py-3">Classified At</th>
                        <th class="px-3 py-3 text-center">Conflict Status</th>
                        <th class="px-3 py-3 text-center">Protective Hold</th>
                        <th class="px-4 py-3 text-right">Review Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60 font-sans">
                    @forelse($vessels as $vessel)
                    @php
                        $isDisputed = $vessel->isClassificationDisputed();
                        $isHeld = $vessel->isUnderProtectiveHold();
                        $isVerifiedFish = $vessel->isVerifiedFishing();
                    @endphp
                    <tr class="hover:bg-slate-800/40 transition {{ $isDisputed ? 'bg-rose-500/5' : '' }}">
                        <!-- Vessel Name -->
                        <td class="px-4 py-3 font-medium text-white">
                            <div class="flex items-center gap-2">
                                <span class="text-sm">🚢</span>
                                <div>
                                    <a href="{{ route('vessels.classification-review.show', $vessel->id) }}" class="hover:text-indigo-300 transition font-semibold">
                                        {{ $vessel->name ?? 'UNKNOWN' }}
                                    </a>
                                    @if(!empty($vessel->display_flag_country))
                                        <div class="text-[10px] text-slate-400">{{ $vessel->display_flag_country }}</div>
                                    @endif
                                </div>
                            </div>
                        </td>

                        <!-- MMSI -->
                        <td class="px-3 py-3 font-mono text-slate-200">
                            {{ $vessel->mmsi ?? '—' }}
                        </td>

                        <!-- IMO / Callsign -->
                        <td class="px-3 py-3 font-mono text-[11px] text-slate-400">
                            <div>IMO: <span class="text-slate-300">{{ $vessel->display_imo }}</span></div>
                            <div>Call: <span class="text-slate-300">{{ $vessel->callsign ?? '—' }}</span></div>
                        </td>

                        <!-- Current Vessel Type -->
                        <td class="px-3 py-3">
                            <span class="px-2 py-0.5 rounded text-[10px] font-semibold uppercase
                                {{ strtolower($vessel->vessel_type) === 'fishing' ? 'bg-teal-500/20 text-teal-300 border border-teal-500/30' : 'bg-slate-800 text-slate-300 border border-slate-700' }}">
                                {{ $vessel->display_vessel_type }}
                            </span>
                        </td>

                        <!-- AIS ShipType Code -->
                        <td class="px-3 py-3 font-mono text-[11px]">
                            @if($vessel->ais_ship_type_code !== null)
                                <span class="px-1.5 py-0.5 rounded bg-slate-800 text-slate-200 border border-slate-700 font-bold">
                                    {{ $vessel->ais_ship_type_code }}
                                </span>
                                <span class="text-[10px] text-slate-400 ml-1">
                                    ({{ \App\Services\Maritime\VesselClassificationService::mapAisCodeToTaxonomy($vessel->ais_ship_type_code) }})
                                </span>
                            @else
                                <span class="text-slate-500 italic">None</span>
                            @endif
                        </td>

                        <!-- Classification Source -->
                        <td class="px-3 py-3 text-[11px] text-slate-300">
                            @if($vessel->classification_source === 'ANALYST_VERIFICATION' || $vessel->classification_source === 'ANALYST_RECLASSIFICATION')
                                <span class="inline-flex items-center gap-1 text-emerald-400 font-medium">
                                    <span>👤</span> Analyst
                                </span>
                            @elseif(str_starts_with($vessel->classification_source ?? '', 'AIS_'))
                                <span class="inline-flex items-center gap-1 text-sky-400 font-medium">
                                    <span>📡</span> {{ $vessel->classification_source }}
                                </span>
                            @elseif($vessel->classification_source)
                                <span class="text-slate-400">{{ $vessel->classification_source }}</span>
                            @else
                                <span class="text-slate-500 italic">Unspecified</span>
                            @endif
                        </td>

                        <!-- Verification Status -->
                        <td class="px-3 py-3">
                            @if($vessel->verification_status === 'verified')
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">
                                    ✓ Verified
                                </span>
                            @elseif($vessel->verification_status === 'reclassified')
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-indigo-500/20 text-indigo-300 border border-indigo-500/30">
                                    ⇄ Reclassified
                                </span>
                            @elseif($vessel->verification_status === 'unverified_historical')
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-purple-500/20 text-purple-300 border border-purple-500/30">
                                    📜 Historical
                                </span>
                            @elseif($vessel->verification_status === 'under_review')
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-amber-500/20 text-amber-300 border border-amber-500/30">
                                    ⏳ Under Review
                                </span>
                            @elseif($vessel->verification_status === 'rejected')
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-rose-500/20 text-rose-300 border border-rose-500/30">
                                    ✕ Rejected
                                </span>
                            @elseif($vessel->verification_status === 'provider_reported')
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-blue-500/20 text-blue-300 border border-blue-500/30">
                                    🌐 Provider
                                </span>
                            @else
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-slate-800 text-slate-400 border border-slate-700">
                                    Unverified
                                </span>
                            @endif
                        </td>

                        <!-- Classified At -->
                        <td class="px-3 py-3 text-[11px] text-slate-400 whitespace-nowrap">
                            {{ $vessel->classified_at ? $vessel->classified_at->format('Y-m-d H:i') : '—' }}
                        </td>

                        <!-- Conflict Status -->
                        <td class="px-3 py-3 text-center">
                            @if($isDisputed)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold bg-rose-500/20 text-rose-300 border border-rose-500/40">
                                    ⚠️ Disputed
                                </span>
                            @elseif($vessel->isHistoricalProtected())
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-semibold bg-purple-500/20 text-purple-300 border border-purple-500/30">
                                    Historical
                                </span>
                            @else
                                <span class="text-slate-500 text-[10px]">Normal</span>
                            @endif
                        </td>

                        <!-- Protective Hold -->
                        <td class="px-3 py-3 text-center">
                            @if($isVerifiedFish)
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/30" title="Permanent: positions are exempt from retention purge">
                                    PERMANENT
                                </span>
                            @elseif($isHeld)
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-500/20 text-amber-300 border border-amber-500/40" title="Protective Hold: positions protected from retention purge cycles">
                                    🛡️ HOLD
                                </span>
                            @else
                                <span class="text-slate-500 text-[10px]">Standard</span>
                            @endif
                        </td>

                        <!-- Review Action -->
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('vessels.classification-review.show', $vessel->id) }}" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-indigo-600/80 hover:bg-indigo-600 text-white font-medium text-[11px] transition shadow-xs">
                                <span>⚖️</span> Review
                            </a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="11" class="px-4 py-8 text-center text-slate-500">
                            No vessels match the review criteria.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination Bar -->
        @if($vessels->hasPages())
        <div class="p-4 border-t border-slate-800 bg-slate-900/60">
            {{ $vessels->links() }}
        </div>
        @endif
    </div>

</div>
@endsection

