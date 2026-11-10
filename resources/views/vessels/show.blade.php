@extends('layouts.app')

@section('header_title', 'Vessel Profile — ' . $vessel->name)

@section('content')
@php
    $flagInfo = $vessel->flag_info;
    $enrichment = $vessel->metadata['enrichment'] ?? [];
    $conflicts = $vessel->enrichment_conflicts;
    $altNames = $vessel->alternative_names;
    $eventsSummary = $vessel->gfw_events_summary;
    $provenance = $enrichment['field_provenance'] ?? [];
    $pos = $vessel->latestPosition;
@endphp

<div class="space-y-6">

    @if(session('success'))
    <div class="p-4 rounded-xl bg-teal-950/60 border border-teal-500/40 text-teal-200 text-xs flex items-center justify-between shadow-lg">
        <div class="flex items-center gap-2">
            <span class="text-base text-teal-400">✓</span>
            <span>{{ session('success') }}</span>
        </div>
        <button type="button" onclick="this.parentElement.remove()" class="text-teal-400 hover:text-white px-2 py-1">&times;</button>
    </div>
    @endif

    @if(session('warning'))
    <div class="p-4 rounded-xl bg-amber-950/60 border border-amber-500/40 text-amber-200 text-xs flex items-center justify-between shadow-lg">
        <div class="flex items-center gap-2">
            <span class="text-base text-amber-400">⚠️</span>
            <span>{{ session('warning') }}</span>
        </div>
        <button type="button" onclick="this.parentElement.remove()" class="text-amber-400 hover:text-white px-2 py-1">&times;</button>
    </div>
    @endif

    @if(session('error'))
    <div class="p-4 rounded-xl bg-rose-950/60 border border-rose-500/40 text-rose-200 text-xs flex items-center justify-between shadow-lg">
        <div class="flex items-center gap-2">
            <span class="text-base text-rose-400">✕</span>
            <span>{{ session('error') }}</span>
        </div>
        <button type="button" onclick="this.parentElement.remove()" class="text-rose-400 hover:text-white px-2 py-1">&times;</button>
    </div>
    @endif

    <!-- Header Card -->
    <div class="glass-panel p-6 rounded-2xl border border-slate-800 shadow-xl flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-3">
                <span class="text-4xl">{{ $flagInfo['flag_emoji'] }}</span>
                <div>
                    <h2 class="text-2xl font-black text-white tracking-wide flex items-center gap-2 flex-wrap">
                        <span>{{ $vessel->name }}</span>
                        <span class="px-2.5 py-0.5 rounded text-xs font-mono font-bold uppercase {{ $flagInfo['status'] === 'verified_explicit' ? 'bg-emerald-950/90 text-emerald-300 border border-emerald-500/40' : ($flagInfo['status'] === 'conflict_detected' ? 'bg-rose-950/90 text-rose-300 border border-rose-500/40' : 'bg-slate-800 text-slate-300 border border-slate-700') }}">
                            @if(!empty($flagInfo['is_known']) && $flagInfo['status'] !== 'unknown')
                                {{ $flagInfo['iso2'] }} &bull; {{ $flagInfo['country_name'] }}
                            @else
                                Unknown
                            @endif
                        </span>

                        <!-- AIS Stream Static Data Telemetry Status -->
                        @if($vessel->ais_static_status === 'RECEIVED')
                        <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold uppercase bg-emerald-500/20 text-emerald-300 border border-emerald-500/40" title="Static data received directly from AISStream telemetry">
                            ✓ AIS Static: Received
                        </span>
                        @elseif($vessel->ais_static_status === 'INVALID')
                        <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold uppercase bg-rose-500/20 text-rose-300 border border-rose-500/40" title="Invalid MMSI for telemetry capture">
                            ✕ AIS: Invalid MMSI
                        </span>
                        @else
                        <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold uppercase bg-amber-500/20 text-amber-300 border border-amber-500/40" title="Awaiting static Type 5/19/24 messages from AISStream">
                            ⏳ AIS: Awaiting Static Data
                        </span>
                        @endif

                        <!-- Multi-Source Enrichment Status -->
                        @php
                            $normEnrich = $vessel->normalized_enrichment_status;
                            $normBadge = match($normEnrich) {
                                'ENRICHED' => 'bg-teal-500/20 text-teal-300 border-teal-500/40',
                                'PARTIALLY_ENRICHED' => 'bg-sky-500/20 text-sky-300 border-sky-500/40',
                                'CONFLICTING' => 'bg-rose-500/20 text-rose-300 border-rose-500/40 animate-pulse',
                                'SEARCHING' => 'bg-amber-500/20 text-amber-300 border-amber-500/40 animate-pulse',
                                'NOT_FOUND' => 'bg-slate-800 text-slate-400 border-slate-700',
                                'PROVIDER_UNAVAILABLE' => 'bg-red-950 text-red-400 border-red-800',
                                'NOT_CONFIGURED' => 'bg-slate-800 text-slate-500 border-slate-700',
                                default => 'bg-slate-800/80 text-slate-400 border-slate-700',
                            };
                        @endphp
                        <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold uppercase border {{ $normBadge }}">
                            Enrichment: {{ $normEnrich }}
                        </span>
                    </h2>
                    <div class="text-xs text-slate-400 mt-1.5 font-mono flex items-center gap-2 flex-wrap">
                        <span>UUID: <span class="text-slate-300">{{ $vessel->id }}</span></span>
                        <span>&bull;</span>
                        <span>Type: <strong class="capitalize text-teal-300 font-sans">{{ $vessel->vessel_type }}</strong></span>
                        <span>&bull;</span>
                        <span class="text-[11px] {{ $flagInfo['is_verified'] ? 'text-emerald-400' : 'text-amber-400' }}">
                            {{ $flagInfo['status'] === 'verified_explicit' ? '✓ Flag Verified' : '⚠️ ' . $flagInfo['status'] }}
                        </span>
                        @if($vessel->last_enriched_at)
                        <span>&bull;</span>
                        <span class="text-slate-400 text-[11px]">Enriched: {{ $vessel->last_enriched_at->diffForHumans() }}</span>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-2 flex-wrap">
            <form action="{{ route('vessels.enrich', $vessel->id) }}" method="POST" class="inline">
                @csrf
                <button type="submit" class="px-3 py-2 rounded-xl bg-teal-500/20 hover:bg-teal-500/30 text-teal-300 border border-teal-500/40 font-semibold text-xs flex items-center gap-1.5 transition shadow-sm" title="Run multi-source data enrichment">
                    <span>⚡</span>
                    <span>Enrich Data</span>
                </button>
            </form>
            <a href="{{ route('vessels.index') }}" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-medium text-xs transition border border-slate-700">
                &larr; Back to Registry
            </a>
            @if($pos)
            <a href="{{ route('map.index') }}#map=12/{{ $pos->latitude }}/{{ $pos->longitude }}&vessel={{ $vessel->mmsi }}" class="px-4 py-2 rounded-xl bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold text-xs transition shadow-lg shadow-teal-500/20 flex items-center gap-1.5">
                <span>🗺️</span>
                <span>View on Global Map</span>
            </a>
            @else
            <a href="{{ route('map.index') }}" class="px-4 py-2 rounded-xl bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold text-xs transition shadow-lg shadow-teal-500/20">
                View on Global Map
            </a>
            @endif
        </div>
    </div>

    <!-- Conflict Warning Banner (if any) -->
    @if(count($conflicts) > 0)
    <div class="p-4 rounded-xl bg-amber-950/40 border border-amber-500/40 text-amber-200 text-xs space-y-2">
        <div class="flex items-center gap-2 font-bold text-amber-300">
            <span>⚠️</span>
            <span>Multi-Source Conflicts Detected (Analyst Review Required)</span>
        </div>
        <div class="space-y-1 text-slate-300">
            @foreach($conflicts as $field => $c)
            <div class="flex items-start gap-2 text-[11px]">
                <span class="px-1.5 py-0.5 rounded bg-amber-500/20 text-amber-300 font-mono text-[10px] uppercase font-bold shrink-0">{{ $field }}</span>
                <span>{{ $c['message'] ?? 'Value conflict between local telemetry and external data provider.' }} (Local: <strong>{{ (string)$c['local'] }}</strong>, GFW: <strong>{{ (string)$c['gfw'] }}</strong>)</span>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    <!-- AWAITING STATIC DATA MULTI-SOURCE ATTRIBUTE COMPARISON & RECONCILIATION MATRIX -->
    <div class="glass-panel p-6 rounded-2xl border border-slate-800 shadow-xl space-y-4">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-slate-800 pb-3">
            <div>
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="text-lg">⚖️</span>
                    <h3 class="text-sm font-bold uppercase tracking-wider text-teal-400 font-mono">
                        Awaiting Static Data — Multi-Source Comparison &amp; Reconciliation Matrix
                    </h3>
                </div>
                <p class="text-xs text-slate-400 mt-1">
                    Multi-source cross-attribute comparison: Active Profile, AISStream (Telemetry), Global Fishing Watch, Official Flag Registry, and FAO Global Record.
                </p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <form action="{{ route('vessels.enrich', $vessel->id) }}" method="POST" class="inline">
                    @csrf
                    <input type="hidden" name="sync" value="1">
                    <button type="submit" class="px-3 py-1.5 rounded-xl bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold text-xs transition shadow-md shadow-teal-500/20 flex items-center gap-1.5" title="Run multi-source data enrichment immediately">
                        <span>⚡</span>
                        <span>Enrich Now</span>
                    </button>
                </form>
            </div>
        </div>

        <div class="overflow-x-auto text-xs responsive-table-container">
            <table class="w-full text-left min-w-[750px]">
                <thead class="bg-slate-900/90 text-slate-400 uppercase tracking-wider text-[10px] border-b border-slate-800">
                    <tr>
                        <th class="py-2.5 px-3 font-semibold text-slate-300">Attribute</th>
                        <th class="py-2.5 px-3 font-semibold text-teal-300">Active Profile</th>
                        <th class="py-2.5 px-3 font-semibold text-slate-300">AISStream</th>
                        <th class="py-2.5 px-3 font-semibold text-slate-300">GFW</th>
                        <th class="py-2.5 px-3 font-semibold text-slate-300">Official Registry</th>
                        <th class="py-2.5 px-3 font-semibold text-slate-300">FAO Record</th>
                        <th class="py-2.5 px-3 text-right font-semibold text-slate-300">Status &amp; Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60 font-mono">
                    @php
                        $attrMatrix = $vessel->attribute_matrix;
                    @endphp
                    @foreach($attrMatrix as $attrKey => $row)
                    <tr class="hover:bg-slate-900/40 transition">
                        <td class="py-2.5 px-3 font-sans font-medium text-slate-300">
                            {{ $row['label'] }}
                        </td>
                        <td class="py-2.5 px-3 font-bold {{ $row['profile'] ? 'text-white' : 'text-slate-500 italic' }}">
                            {{ $row['profile'] ?: '—' }}
                        </td>
                        <td class="py-2.5 px-3 text-slate-300">
                            {{ $row['aisstream'] ?: '—' }}
                        </td>
                        <td class="py-2.5 px-3 text-slate-300">
                            {{ $row['gfw'] ?: '—' }}
                        </td>
                        <td class="py-2.5 px-3 text-slate-300">
                            {{ $row['registry'] ?: '—' }}
                        </td>
                        <td class="py-2.5 px-3 text-slate-300">
                            {{ $row['fao'] ?: '—' }}
                        </td>
                        <td class="py-2.5 px-3 text-right whitespace-nowrap">
                            @if($row['is_conflicting'])
                                <span class="px-2 py-0.5 rounded text-[10px] bg-rose-500/20 text-rose-300 border border-rose-500/40" title="{{ $row['conflict_reason'] }}">
                                    ⚠️ Conflicting
                                </span>
                                @foreach($row['observations'] as $src => $obs)
                                    @if($obs && $obs->is_conflicting)
                                    <form action="{{ route('vessels.observations.accept', [$vessel->id, $obs->id]) }}" method="POST" class="inline ml-1.5">
                                        @csrf
                                        <button type="submit" class="px-2 py-0.5 rounded bg-amber-500/20 hover:bg-amber-500/30 text-amber-300 border border-amber-500/40 text-[10px] font-sans font-semibold transition" title="Accept observation from {{ $src }} to update active profile">
                                            Accept {{ strtoupper($src) }}
                                        </button>
                                    </form>
                                    @endif
                                @endforeach
                            @elseif(!empty($row['profile']))
                                <span class="px-2 py-0.5 rounded text-[10px] bg-teal-500/10 text-teal-300 border border-teal-500/30">
                                    ✓ Concordant
                                </span>
                            @else
                                <span class="text-slate-500 text-[10px] italic">No Observations</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <!-- Main Grid: Sections 1 to 5 -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- SECTION 1: Core Identifiers -->
        <div class="glass-panel p-5 rounded-xl border border-slate-800 space-y-4">
            <h3 class="text-xs font-bold uppercase tracking-wider text-teal-400 border-b border-slate-800 pb-2 flex items-center justify-between">
                <span class="flex items-center gap-1.5">
                    <span>🆔</span>
                    <span>1. Identity &amp; Registration Codes</span>
                </span>
                <span class="text-[10px] px-1.5 py-0.5 rounded font-mono {{ $vessel->isManuallyVerified() ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30' : 'bg-slate-800 text-slate-400' }}">
                    {{ $vessel->verification_status }}
                </span>
            </h3>

            <div class="space-y-2.5 text-xs">
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Primary Name:</span>
                    <span class="font-bold text-white font-mono">{{ $vessel->name }}</span>
                </div>
                @if(count($altNames) > 0)
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Alternative Names:</span>
                    <div class="text-right">
                        @foreach($altNames as $alt)
                        <span class="inline-block px-1.5 py-0.5 rounded bg-slate-800 text-teal-300 font-mono text-[11px] ml-1">{{ $alt }}</span>
                        @endforeach
                    </div>
                </div>
                @endif
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Flag State:</span>
                    <span class="font-mono text-white font-bold flex items-center gap-1.5">
                        <span>{{ $flagInfo['flag_emoji'] }}</span>
                        @if(!empty($flagInfo['is_known']) && $flagInfo['status'] !== 'unknown')
                            <span>{{ $flagInfo['country_name'] }} ({{ $flagInfo['iso2'] }})</span>
                        @else
                            <span>Unknown</span>
                        @endif
                    </span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Flag Status:</span>
                    <span class="text-[10px] font-mono px-2 py-0.5 rounded {{ $flagInfo['status'] === 'verified_explicit' ? 'bg-emerald-950 text-emerald-300' : ($flagInfo['status'] === 'conflict_detected' ? 'bg-rose-950 text-rose-300' : 'bg-slate-800 text-amber-300') }}">
                        {{ $flagInfo['status'] === 'verified_explicit' ? 'Verified Explicit' : ($flagInfo['status'] === 'mid_administrative_indicator' ? 'MID Clue (Unverified)' : $flagInfo['status']) }}
                    </span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">MMSI:</span>
                    <span class="font-mono text-teal-300 font-bold">{{ $vessel->mmsi ?? 'Not recorded' }}</span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">IMO:</span>
                    <span class="font-mono text-white font-bold">{{ $vessel->display_imo !== '—' ? $vessel->display_imo : 'Not recorded' }}</span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Callsign:</span>
                    <span class="font-mono text-white font-bold">{{ $vessel->callsign ?? 'Not recorded' }}</span>
                </div>
                <div class="flex justify-between py-1">
                    <span class="text-slate-400">Hull Code:</span>
                    <span class="font-mono text-slate-300">{{ $vessel->hull_code ?? 'Not recorded' }}</span>
                </div>
            </div>
        </div>

        <!-- SECTION 2: Vessel Classification -->
        <div class="glass-panel p-5 rounded-xl border border-slate-800 space-y-4">
            <h3 class="text-xs font-bold uppercase tracking-wider text-teal-400 border-b border-slate-800 pb-2 flex items-center justify-between">
                <span class="flex items-center gap-1.5">
                    <span>🏷️</span>
                    <span>2. Classification &amp; Fleet Type</span>
                </span>
                @if(isset($provenance['vessel_type']))
                <span class="text-[9px] px-1.5 py-0.5 rounded bg-teal-500/10 text-teal-300 border border-teal-500/30 font-mono">
                    Enriched
                </span>
                @endif
            </h3>

            <div class="space-y-2.5 text-xs">
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Vessel Type:</span>
                    <span class="font-bold {{ $vessel->vessel_type === 'unknown' || empty($vessel->vessel_type) ? 'text-slate-400' : 'text-emerald-300' }}">
                        {{ $vessel->display_vessel_type }}
                    </span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Fleet Category:</span>
                    <span class="text-white capitalize">
                        @if($vessel->vessel_type === 'fishing')
                            Fishing Fleet
                        @elseif($vessel->vessel_type === 'passenger')
                            Commercial Passenger / Yacht
                        @elseif(in_array($vessel->vessel_type, ['patrol', 'military', 'sar']))
                            Law Enforcement / Security
                        @else
                            {{ ucfirst($vessel->vessel_type) }} Fleet
                        @endif
                    </span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Gear Type:</span>
                    <span class="text-white font-mono">
                        {{ $vessel->gear_type ? ucfirst($vessel->gear_type) : ($vessel->vessel_type === 'fishing' ? 'Unrecorded' : 'N/A (Non-Fishing Vessel)') }}
                    </span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Classification Source:</span>
                    <span class="text-[11px] font-mono text-slate-300 text-right max-w-[180px] truncate" title="{{ $provenance['vessel_type']['source'] ?? ($vessel->sourceProvider?->name ?? 'AIS Provider') }}">
                        {{ $provenance['vessel_type']['source'] ?? ($vessel->sourceProvider?->name ?? 'AISStream ITU Code') }}
                    </span>
                </div>
                <div class="flex justify-between py-1">
                    <span class="text-slate-400">Verification Status:</span>
                    <span class="text-[10px] font-mono px-2 py-0.5 rounded {{ $vessel->verification_status === 'verified' ? 'bg-emerald-950 text-emerald-300' : 'bg-slate-800 text-slate-300' }}">
                        {{ $vessel->verification_status }}
                    </span>
                </div>
            </div>
        </div>

        <!-- SECTION 3: Technical Specifications -->
        <div class="glass-panel p-5 rounded-xl border border-slate-800 space-y-4">
            <h3 class="text-xs font-bold uppercase tracking-wider text-teal-400 border-b border-slate-800 pb-2 flex items-center justify-between">
                <span class="flex items-center gap-1.5">
                    <span>📐</span>
                    <span>3. Technical Specifications</span>
                </span>
                <span class="text-[9px] px-1.5 py-0.5 rounded bg-slate-800 text-slate-400 font-mono">Specs</span>
            </h3>

            <div class="space-y-2.5 text-xs">
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Length (LOA):</span>
                    <span class="text-white font-mono font-bold">{{ $vessel->length_meters ? $vessel->length_meters . ' m' : '<not available>' }}</span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Beam (Width):</span>
                    <span class="text-white font-mono">{{ $vessel->beam_meters ? $vessel->beam_meters . ' m' : '<not available>' }}</span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Gross Tonnage (GT):</span>
                    <span class="text-white font-mono font-bold {{ $vessel->tonnage ? 'text-teal-300' : '' }}">
                        {{ $vessel->tonnage ? number_format($vessel->tonnage) . ' GT' : '<not available>' }}
                    </span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Engine Power:</span>
                    <span class="text-white font-mono">{{ $vessel->engine_power_kw ? number_format($vessel->engine_power_kw, 2) . ' kW' : '<not available>' }}</span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Build Year:</span>
                    <span class="text-white font-mono">{{ $vessel->build_year ?: '<not available>' }}</span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Fleet Operator:</span>
                    <span class="text-white font-semibold font-mono text-right max-w-[200px] truncate" title="{{ $vessel->operator ?: 'Not available' }}">
                        {{ $vessel->display_operator }}
                    </span>
                </div>
                <div class="flex justify-between py-1">
                    <span class="text-slate-400">Beneficial Owner:</span>
                    <span class="text-white font-semibold font-mono text-right max-w-[200px] truncate" title="{{ $vessel->owner ?: 'Not available' }}">
                        {{ $vessel->display_owner }}
                    </span>
                </div>
            </div>
        </div>

    </div>

    <!-- SECTION 4: Tracking Information -->
    <div class="glass-panel p-5 rounded-xl border border-slate-800 space-y-4">
        <h3 class="text-xs font-bold uppercase tracking-wider text-sky-400 border-b border-slate-800 pb-2 flex items-center justify-between">
            <span class="flex items-center gap-1.5">
                <span>📍</span>
                <span>4. Latest Telemetry &amp; Tracking</span>
            </span>
            @if($vessel->isRecentlyActive())
            <span class="flex items-center gap-1.5 px-2 py-0.5 rounded bg-emerald-950/80 text-emerald-300 border border-emerald-500/30 text-[10px] font-mono">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                Active (&le; 24h)
            </span>
            @else
            <span class="px-2 py-0.5 rounded bg-slate-800 text-slate-400 text-[10px] font-mono">
                Historical / Stale
            </span>
            @endif
        </h3>

        @if($pos)
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-xs">
            <div class="p-2.5 rounded-lg bg-slate-900/60 border border-slate-800">
                <span class="text-slate-400 text-[11px] block">Current Coordinates:</span>
                <span class="font-mono text-sky-300 font-bold text-sm block mt-0.5">
                    {{ number_format($pos->latitude, 6) }}&deg;, {{ number_format($pos->longitude, 6) }}&deg;
                </span>
            </div>
            <div class="p-2.5 rounded-lg bg-slate-900/60 border border-slate-800">
                <span class="text-slate-400 text-[11px] block">Speed &amp; Course:</span>
                <span class="font-mono text-white font-bold text-sm block mt-0.5">
                    {{ $pos->speed_knots ?? 0 }} kts &bull; {{ $pos->course_degrees ?? 0 }}&deg;
                </span>
            </div>
            <div class="p-2.5 rounded-lg bg-slate-900/60 border border-slate-800">
                <span class="text-slate-400 text-[11px] block">Navigation Status:</span>
                <span class="text-white capitalize font-semibold text-sm block mt-0.5">
                    {{ $pos->nav_status ?? 'under_way' }}
                </span>
            </div>
            <div class="p-2.5 rounded-lg bg-slate-900/60 border border-slate-800">
                <span class="text-slate-400 text-[11px] block">Observation Time:</span>
                <span class="text-slate-200 font-mono text-xs block mt-0.5">
                    {{ $pos->observed_at->format('Y-m-d H:i:s') }} UTC
                </span>
            </div>
        </div>
        <div class="flex flex-wrap items-center justify-between text-[11px] text-slate-400 pt-2 border-t border-slate-800/60">
            <div>
                <span>Telemetry Source: <strong class="text-teal-300 font-mono">{{ $pos->sourceProvider?->name ?? 'AIS Ingestion' }}</strong></span>
            </div>
            <div>
                <span>Signal Quality: <strong class="text-teal-400 font-mono">{{ $pos->data_quality_score }} / 1.0</strong></span>
            </div>
        </div>
        @else
        <div class="py-8 text-center text-slate-500 text-xs">
            <span class="text-3xl block mb-2">📡</span>
            No actual AIS position records currently stored for this vessel.
        </div>
        @endif
    </div>

    <!-- SECTION 5: Automatic GFW Monitoring & Events Suite -->
    <div class="glass-panel p-6 rounded-2xl border border-slate-800 space-y-5" id="gfw-monitoring-panel">
        @php
            $gfwStatus = $vessel->gfw_sync_status;
            $gfwCandidate = $enrichment['raw_gfw_candidate'] ?? null;
            $gfwDetails = $enrichment['raw_vessel_details'] ?? null;
            $searchAttempts = $enrichment['search_attempts'] ?? [];
            $allEncounters = $vessel->encountersAsVessel1->concat($vessel->encountersAsVessel2);
        @endphp

        <!-- Panel Header -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-slate-800 pb-4">
            <div>
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="text-lg">🛰️</span>
                    <h3 class="text-sm font-bold uppercase tracking-wider text-amber-400 font-mono">
                        5. GLOBAL FISHING WATCH MONITORING & EVENTS / GFW API v3
                    </h3>
                    <span class="text-[9px] px-2 py-0.5 rounded font-mono font-bold uppercase bg-teal-500/20 text-teal-300 border border-teal-500/40">
                        NON-COMMERCIAL RESEARCH
                    </span>
                </div>
                <p class="text-xs text-slate-400 mt-1">
                    Automatic multi-source data synchronization via official GFW API v3 (<span class="font-mono text-slate-300">public-global-vessel-identity:latest</span>).
                </p>
            </div>

            <!-- Header Action Controls & Status Badge -->
            <div class="flex items-center gap-2.5 flex-wrap">
                <!-- Sync Status Badge -->
                @if($gfwStatus === 'synced')
                <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold uppercase bg-emerald-950/80 text-emerald-300 border border-emerald-500/40 flex items-center gap-1.5 shadow-sm">
                    <span class="w-2 h-2 rounded-full bg-emerald-400"></span>
                    <span>✓ Synced</span>
                </span>
                @elseif($gfwStatus === 'syncing')
                <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold uppercase bg-sky-950/80 text-sky-300 border border-sky-500/40 flex items-center gap-1.5 animate-pulse shadow-sm">
                    <span class="w-2 h-2 rounded-full bg-sky-400 animate-ping"></span>
                    <span>⏳ Syncing in Background...</span>
                </span>
                @elseif($gfwStatus === 'not_found')
                <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold uppercase bg-slate-800 text-slate-300 border border-slate-700 flex items-center gap-1.5 shadow-sm">
                    <span>🔍</span>
                    <span>Not Found in GFW</span>
                </span>
                @elseif($gfwStatus === 'match_review_required')
                <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold uppercase bg-amber-950/80 text-amber-300 border border-amber-500/40 flex items-center gap-1.5 shadow-sm">
                    <span>⚠️</span>
                    <span>Review Required</span>
                </span>
                @elseif($gfwStatus === 'failed')
                <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold uppercase bg-rose-950/80 text-rose-300 border border-rose-500/40 flex items-center gap-1.5 shadow-sm">
                    <span>✕</span>
                    <span>Sync Failed</span>
                </span>
                @else
                <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold uppercase bg-slate-900 text-slate-400 border border-slate-800 flex items-center gap-1.5 shadow-sm">
                    <span>⏱️</span>
                    <span>Pending Sync</span>
                </span>
                @endif

                <!-- Manual Action Form (Sync Now / Retry) -->
                @if($vessel->isEligibleForGfwSync())
                <form action="{{ route('vessels.gfw-sync', $vessel->id) }}" method="POST" class="inline">
                    @csrf
                    <button type="submit" class="px-3 py-1 rounded-lg bg-teal-500/10 hover:bg-teal-500/20 text-teal-300 border border-teal-500/30 text-xs font-medium flex items-center gap-1.5 transition">
                        <span>🔄</span>
                        <span>{{ in_array($gfwStatus, ['failed', 'not_found']) ? 'Retry' : 'Sync Now' }}</span>
                    </button>
                </form>
                @endif

                <!-- GFW Source External Link -->
                @if($vessel->gfw_vessel_id)
                <a href="https://globalfishingwatch.org/map/vessels/{{ $vessel->gfw_vessel_id }}" target="_blank" rel="noopener noreferrer" class="px-3 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 border border-slate-700 text-xs font-medium flex items-center gap-1.5 transition">
                    <span>🌐</span>
                    <span>View GFW Source ↗</span>
                </a>
                @endif
            </div>
        </div>

        <!-- Metadata Summary Bar -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-xs bg-slate-900/60 p-3 rounded-xl border border-slate-800/80">
            <div>
                <span class="text-slate-400 text-[10px] uppercase font-mono block">GFW Vessel ID:</span>
                @if($vessel->gfw_vessel_id)
                <span class="font-mono text-teal-300 font-bold truncate block mt-0.5" title="{{ $vessel->gfw_vessel_id }}">
                    {{ $vessel->gfw_vessel_id }}
                </span>
                @else
                <span class="text-slate-500 italic block mt-0.5">Not linked to GFW</span>
                @endif
            </div>
            <div>
                <span class="text-slate-400 text-[10px] uppercase font-mono block">Last Synchronized:</span>
                <span class="text-slate-200 font-mono block mt-0.5">
                    @if($vessel->last_enriched_at)
                    {{ $vessel->last_enriched_at->diffForHumans() }}
                    @else
                    <span class="text-slate-500 italic">Never synchronized</span>
                    @endif
                </span>
            </div>
            <div>
                <span class="text-slate-400 text-[10px] uppercase font-mono block">Match Method:</span>
                <span class="text-slate-200 font-mono block mt-0.5 truncate">
                    @if($enrichment['match_method'] ?? null)
                    {{ ucwords(str_replace('_', ' ', $enrichment['match_method'])) }}
                    @elseif($gfwStatus === 'not_found')
                    <span class="text-slate-400">Search completed (0 candidates matched)</span>
                    @elseif($gfwStatus === 'match_review_required')
                    <span class="text-amber-400">Ambiguity / Multi-Candidate</span>
                    @else
                    <span class="text-slate-500 italic">Awaiting evaluation</span>
                    @endif
                </span>
            </div>
            <div>
                <span class="text-slate-400 text-[10px] uppercase font-mono block">Confidence Level:</span>
                <span class="font-mono block mt-0.5">
                    @if(($enrichment['match_confidence'] ?? null) === 'high_authoritative')
                    <span class="text-emerald-400 font-bold">High (IMO Authoritative)</span>
                    @elseif(($enrichment['match_confidence'] ?? null) === 'high_telemetry')
                    <span class="text-teal-400 font-bold">High (MMSI Telemetry)</span>
                    @elseif(($enrichment['match_confidence'] ?? null) === 'medium_verified')
                    <span class="text-sky-400 font-bold">Medium (Verified Name)</span>
                    @elseif($gfwStatus === 'not_found')
                    <span class="text-slate-400">No Candidates Found</span>
                    @elseif($gfwStatus === 'match_review_required')
                    <span class="text-amber-400">Review Required</span>
                    @else
                    <span class="text-slate-500 italic">-</span>
                    @endif
                </span>
            </div>
        </div>

        <!-- 7 Tabs Navigation -->
        <div class="border-b border-slate-800">
            <nav class="flex space-x-1 overflow-x-auto responsive-table-container text-xs" aria-label="GFW Monitoring Tabs">
                <button type="button" data-gfw-tab="identity" class="px-3.5 py-2.5 font-medium border-b-2 text-teal-300 border-teal-400 bg-slate-800/60 rounded-t-lg transition flex items-center gap-1.5 whitespace-nowrap">
                    <span>🪪</span>
                    <span>1. Identity & Registration</span>
                </button>
                <button type="button" data-gfw-tab="fishing" class="px-3.5 py-2.5 font-medium border-b-2 text-slate-400 border-transparent hover:text-slate-200 hover:bg-slate-800/30 rounded-t-lg transition flex items-center gap-1.5 whitespace-nowrap">
                    <span>🎣</span>
                    <span>2. Fishing Activity</span>
                </button>
                <button type="button" data-gfw-tab="encounters" class="px-3.5 py-2.5 font-medium border-b-2 text-slate-400 border-transparent hover:text-slate-200 hover:bg-slate-800/30 rounded-t-lg transition flex items-center gap-1.5 whitespace-nowrap">
                    <span>🚢</span>
                    <span>3. Encounters & Transshipment</span>
                </button>
                <button type="button" data-gfw-tab="loitering" class="px-3.5 py-2.5 font-medium border-b-2 text-slate-400 border-transparent hover:text-slate-200 hover:bg-slate-800/30 rounded-t-lg transition flex items-center gap-1.5 whitespace-nowrap">
                    <span>⚓</span>
                    <span>4. Loitering & Port Visits</span>
                </button>
                <button type="button" data-gfw-tab="gaps" class="px-3.5 py-2.5 font-medium border-b-2 text-slate-400 border-transparent hover:text-slate-200 hover:bg-slate-800/30 rounded-t-lg transition flex items-center gap-1.5 whitespace-nowrap">
                    <span>📡</span>
                    <span>5. AIS Gaps</span>
                </button>
                <button type="button" data-gfw-tab="authorizations" class="px-3.5 py-2.5 font-medium border-b-2 text-slate-400 border-transparent hover:text-slate-200 hover:bg-slate-800/30 rounded-t-lg transition flex items-center gap-1.5 whitespace-nowrap">
                    <span>📜</span>
                    <span>6. Authorizations & RFMO</span>
                </button>
                <button type="button" data-gfw-tab="sources" class="px-3.5 py-2.5 font-medium border-b-2 text-slate-400 border-transparent hover:text-slate-200 hover:bg-slate-800/30 rounded-t-lg transition flex items-center gap-1.5 whitespace-nowrap">
                    <span>ℹ️</span>
                    <span>7. Data Sources & Disclaimer</span>
                </button>
            </nav>
        </div>

        <!-- TAB 1: Identitas & Registrasi (Vessel Identity) -->
        <div data-gfw-panel="identity" class="space-y-4">
            @if($vessel->gfw_vessel_id || $gfwCandidate)
            <!-- Comparison Table -->
            <div class="overflow-x-auto responsive-table-container">
                <table class="w-full text-left text-xs min-w-[620px]">
                    <thead class="text-[10px] text-slate-400 uppercase border-b border-slate-800 bg-slate-900/40">
                        <tr>
                            <th class="py-2 px-3">Identity Parameter</th>
                            <th class="py-2 px-3">Local GFVIP (AIS Telemetry)</th>
                            <th class="py-2 px-3">Global Fishing Watch (GFW v3)</th>
                            <th class="py-2 px-3">Match Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60 font-mono text-[11px]">
                        <tr>
                            <td class="py-2 px-3 text-slate-400 font-sans font-medium">Vessel Name</td>
                            <td class="py-2 px-3 text-white font-bold">{{ $vessel->name }}</td>
                            <td class="py-2 px-3 text-teal-300 font-bold">{{ $gfwCandidate['shipname'] ?? '-' }}</td>
                            <td class="py-2 px-3">
                                @if(strtoupper(trim($vessel->name)) === strtoupper(trim($gfwCandidate['shipname'] ?? '')))
                                <span class="text-emerald-400">✓ Identical</span>
                                @else
                                <span class="text-amber-400">Name Variation</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td class="py-2 px-3 text-slate-400 font-sans font-medium">MMSI / SSVID</td>
                            <td class="py-2 px-3 text-white">{{ $vessel->mmsi ?: '-' }}</td>
                            <td class="py-2 px-3 text-teal-300">{{ $gfwCandidate['ssvid'] ?? '-' }}</td>
                            <td class="py-2 px-3">
                                @if($vessel->mmsi && ($gfwCandidate['ssvid'] ?? '') === $vessel->mmsi)
                                <span class="text-emerald-400">✓ Match (MMSI)</span>
                                @else
                                <span class="text-slate-400">-</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td class="py-2 px-3 text-slate-400 font-sans font-medium">IMO Number</td>
                            <td class="py-2 px-3 text-white">{{ $vessel->display_imo !== '—' ? $vessel->display_imo : '-' }}</td>
                            <td class="py-2 px-3 text-teal-300">{{ $gfwCandidate['imo'] ?? '-' }}</td>
                            <td class="py-2 px-3">
                                @if($vessel->imo && ($gfwCandidate['imo'] ?? '') === $vessel->imo)
                                <span class="text-emerald-400 font-bold">✓ Match (IMO)</span>
                                @else
                                <span class="text-slate-400">-</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td class="py-2 px-3 text-slate-400 font-sans font-medium">Call Sign</td>
                            <td class="py-2 px-3 text-white">{{ $vessel->callsign ?: '-' }}</td>
                            <td class="py-2 px-3 text-teal-300">{{ $gfwCandidate['callsign'] ?? '-' }}</td>
                            <td class="py-2 px-3">
                                @if($vessel->callsign && strtoupper($vessel->callsign) === strtoupper($gfwCandidate['callsign'] ?? ''))
                                <span class="text-emerald-400">✓ Match</span>
                                @else
                                <span class="text-slate-400">-</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td class="py-2 px-3 text-slate-400 font-sans font-medium">Flag State</td>
                            <td class="py-2 px-3 text-white">{{ $vessel->flag_state }}</td>
                            <td class="py-2 px-3 text-teal-300">{{ $gfwCandidate['flag'] ?? '-' }}</td>
                            <td class="py-2 px-3">
                                @if($vessel->flag_state === ($gfwCandidate['flag'] ?? ''))
                                <span class="text-emerald-400">✓ Consistent</span>
                                @else
                                <span class="text-amber-400">Flag Discrepancy</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td class="py-2 px-3 text-slate-400 font-sans font-medium">Vessel Type & Gear</td>
                            <td class="py-2 px-3 text-white capitalize font-sans">{{ $vessel->vessel_type }}</td>
                            <td class="py-2 px-3 text-teal-300 capitalize font-sans">
                                {{ $gfwCandidate['shiptype'] ?? '-' }}
                                @if(!empty($gfwCandidate['geartype']))
                                &bull; <span class="text-amber-300">{{ $gfwCandidate['geartype'] }}</span>
                                @endif
                            </td>
                            <td class="py-2 px-3">
                                <span class="text-teal-400">Synchronized</span>
                            </td>
                        </tr>
                        <tr>
                            <td class="py-2 px-3 text-slate-400 font-sans font-medium">Dimensions (Length & Tonnage)</td>
                            <td class="py-2 px-3 text-white">{{ $vessel->length_meters ? $vessel->length_meters . ' m' : '-' }} &bull; {{ $vessel->tonnage ? $vessel->tonnage . ' GT' : '-' }}</td>
                            <td class="py-2 px-3 text-teal-300">{{ !empty($gfwCandidate['length']) ? $gfwCandidate['length'] . ' m' : '-' }} &bull; {{ !empty($gfwCandidate['tonnage']) ? $gfwCandidate['tonnage'] . ' GT' : '-' }}</td>
                            <td class="py-2 px-3">
                                <span class="text-slate-300">Registry Model</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            @if(count($altNames) > 0)
            <div class="p-3 rounded-lg bg-slate-900/50 border border-slate-800 text-xs flex items-center gap-2">
                <span class="text-slate-400">Historical Names / GFW Aliases:</span>
                <div class="flex items-center gap-1.5 flex-wrap">
                    @foreach($altNames as $alt)
                    <span class="px-2 py-0.5 rounded bg-slate-800 text-teal-300 font-mono text-[11px] border border-slate-700">{{ $alt }}</span>
                    @endforeach
                </div>
            </div>
            @endif

            @elseif($gfwStatus === 'not_found')
            <!-- Professional Informative Not Found Card -->
            <div class="p-6 rounded-xl bg-slate-900/60 border border-slate-800 space-y-3">
                <div class="flex items-start gap-3">
                    <span class="text-3xl shrink-0">🔍</span>
                    <div class="space-y-1">
                        <h4 class="text-sm font-bold text-white">Vessel Not Found in Global Fishing Watch Public Catalog</h4>
                        <p class="text-xs text-slate-400 leading-relaxed">
                            Automated query against the Global Fishing Watch database (<span class="font-mono text-slate-300">public-global-vessel-identity:latest</span>) returned no matching entities for this vessel identity combination.
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5 pt-2 text-xs">
                    <div class="p-2.5 rounded-lg bg-slate-950/70 border border-slate-800/80">
                        <span class="text-slate-500 text-[10px] uppercase font-mono block">Query IMO:</span>
                        <span class="font-mono text-slate-300 font-semibold mt-0.5 block">{{ $vessel->imo ?: 'Unavailable' }}</span>
                        <span class="text-[10px] text-slate-500 block mt-0.5">0 results in public catalog</span>
                    </div>
                    <div class="p-2.5 rounded-lg bg-slate-950/70 border border-slate-800/80">
                        <span class="text-slate-500 text-[10px] uppercase font-mono block">Query MMSI:</span>
                        <span class="font-mono text-slate-300 font-semibold mt-0.5 block">{{ $vessel->mmsi ?: 'Unavailable' }}</span>
                        <span class="text-[10px] text-slate-500 block mt-0.5">0 results in public catalog</span>
                    </div>
                    <div class="p-2.5 rounded-lg bg-slate-950/70 border border-slate-800/80">
                        <span class="text-slate-500 text-[10px] uppercase font-mono block">Query Name:</span>
                        <span class="font-mono text-slate-300 font-semibold mt-0.5 block truncate">{{ $vessel->name }}</span>
                        <span class="text-[10px] text-slate-500 block mt-0.5">Strictly verified against MMSI/IMO</span>
                    </div>
                </div>

                <div class="pt-2 border-t border-slate-800/60 text-[11px] text-slate-400 leading-relaxed space-y-1">
                    <p>
                        <strong class="text-slate-300">Maritime Analysis:</strong> A vessel not being found in GFW commonly occurs when:
                    </p>
                    <ul class="list-disc pl-5 space-y-0.5 text-slate-400">
                        <li>The vessel operates predominantly in coastal territorial waters beyond GFW satellite AIS coverage.</li>
                        <li>The vessel is not yet registered in official international IMO/RFMO fisheries registries.</li>
                        <li>The vessel AIS transponder is low-power (Class B) or utilizes local telemetry identities.</li>
                    </ul>
                    <p class="text-teal-400/90 pt-1">
                        This vessel remains tracked and monitored independently via local GFVIP AIS telemetry, with its classification preserved.
                    </p>
                </div>
            </div>

            @elseif($gfwStatus === 'syncing')
            <div class="py-10 text-center text-slate-400 space-y-2">
                <span class="text-3xl block animate-spin">⏳</span>
                <div class="text-xs font-semibold text-white">GFW Intelligence Synchronization in Progress</div>
                <div class="text-[11px] text-slate-400 max-w-md mx-auto">
                    The system is querying GET /v3/vessels/search on the Global Fishing Watch gateway in the background. Please refresh in a moment.
                </div>
            </div>

            @else
            <div class="py-8 text-center text-slate-500 text-xs space-y-2">
                <span class="text-3xl block mb-1">⏱️</span>
                <div>This vessel is eligible and queued for automated synchronization with GFW API v3.</div>
                <div class="text-[11px] text-slate-400">
                    Click <strong class="text-teal-300">Sync Now</strong> above to trigger immediate background synchronization.
                </div>
            </div>
            @endif
        </div>

        <!-- TAB 2: Aktivitas Penangkapan (Fishing Activity) -->
        <div data-gfw-panel="fishing" class="space-y-4 hidden">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div class="p-3 rounded-xl bg-slate-900 border border-slate-800">
                    <span class="text-[10px] text-slate-400 uppercase font-mono block">Total Fishing Events:</span>
                    <div class="text-xl font-bold font-mono text-white mt-1">
                        @if(($eventsSummary['fishing']['status'] ?? '') === 'available')
                        {{ number_format($eventsSummary['fishing']['count'] ?? 0) }}
                        <span class="text-xs font-normal text-slate-400">events</span>
                        @elseif(($eventsSummary['fishing']['status'] ?? '') === 'permission_restricted')
                        <span class="text-xs text-amber-400 font-sans">Research Access Req.</span>
                        @elseif($gfwStatus === 'not_found')
                        <span class="text-xs text-slate-400 font-sans">0 events</span>
                        @else
                        <span class="text-xs text-slate-500 font-sans">Not synchronized</span>
                        @endif
                    </div>
                </div>
                <div class="p-3 rounded-xl bg-slate-900 border border-slate-800">
                    <span class="text-[10px] text-slate-400 uppercase font-mono block">API Dataset Status:</span>
                    <div class="text-xs font-mono mt-1">
                        @if(($eventsSummary['fishing']['status'] ?? '') === 'available')
                        <span class="text-emerald-400 font-bold">✓ Authorized (HTTP 200)</span>
                        @elseif(($eventsSummary['fishing']['status'] ?? '') === 'permission_restricted')
                        <span class="text-amber-400 font-bold">HTTP 403 (Public Catalog)</span>
                        @else
                        <span class="text-slate-400">public-global-fishing-events</span>
                        @endif
                    </div>
                </div>
                <div class="p-3 rounded-xl bg-slate-900 border border-slate-800">
                    <span class="text-[10px] text-slate-400 uppercase font-mono block">Gear Classification:</span>
                    <div class="text-xs font-semibold text-teal-300 capitalize mt-1 truncate">
                        {{ $vessel->gear_type ?: ($gfwCandidate['geartype'] ?? 'Undefined') }}
                    </div>
                </div>
            </div>

            <div class="p-4 rounded-xl bg-slate-900/40 border border-slate-800 text-xs space-y-2">
                <h4 class="font-bold text-white flex items-center gap-1.5">
                    <span>🎣</span>
                    <span>Apparent Fishing Effort Modeling</span>
                </h4>
                <p class="text-slate-400 text-[11px] leading-relaxed">
                    Global Fishing Watch detects estimated fishing activity using convolutional neural network (CNN) machine learning models. The model analyzes AIS transponder movements including speed reductions (&lt; 4 knots), consistent heading shifts, and maneuvering profiles corresponding to specific gear types (such as drifting longlines, purse seiners, or trawlers).
                </p>
                <div class="p-2.5 rounded bg-slate-950/60 border border-slate-800 text-[10px] text-slate-400 space-y-1">
                    <div class="font-semibold text-slate-300">Public Data Access Notice:</div>
                    <div>
                        Under Global Fishing Watch API v3 access tiers, the <span class="font-mono text-teal-300">public-global-fishing-events:latest</span> dataset requires formal non-commercial Research Access credentials. Spatial vessel activity maps can also be reviewed visually via the public GFW Map portal.
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 3: Encounter & Transshipment (Encounters) -->
        <div data-gfw-panel="encounters" class="space-y-4 hidden">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div class="p-3 rounded-xl bg-slate-900 border border-slate-800">
                    <span class="text-[10px] text-slate-400 uppercase font-mono block">GFW Transshipment Encounters:</span>
                    <div class="text-xl font-bold font-mono text-white mt-1">
                        @if(($eventsSummary['encounters']['status'] ?? '') === 'available')
                        {{ number_format($eventsSummary['encounters']['count'] ?? 0) }}
                        <span class="text-xs font-normal text-slate-400">events</span>
                        @elseif(($eventsSummary['encounters']['status'] ?? '') === 'permission_restricted')
                        <span class="text-xs text-amber-400 font-sans">Research Access Req. (HTTP 403)</span>
                        @elseif($gfwStatus === 'not_found')
                        <span class="text-xs text-slate-400 font-sans">0 events</span>
                        @else
                        <span class="text-xs text-slate-500 font-sans">-</span>
                        @endif
                    </div>
                </div>
                <div class="p-3 rounded-xl bg-slate-900 border border-slate-800">
                    <span class="text-[10px] text-slate-400 uppercase font-mono block">Encounters Detected in GFVIP (Local):</span>
                    <div class="text-xl font-bold font-mono text-teal-300 mt-1">
                        {{ $allEncounters->count() }}
                        <span class="text-xs font-normal text-slate-400">verified events</span>
                    </div>
                </div>
            </div>

            @if($allEncounters->count() > 0)
            <div class="overflow-x-auto responsive-table-container">
                <table class="w-full text-left text-xs min-w-[500px]">
                    <thead class="text-[10px] text-slate-400 uppercase border-b border-slate-800 bg-slate-900/40">
                        <tr>
                            <th class="py-2 px-3">Event Timestamp</th>
                            <th class="py-2 px-3">Partner Vessel</th>
                            <th class="py-2 px-3">Duration</th>
                            <th class="py-2 px-3">Closest Distance</th>
                            <th class="py-2 px-3">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60 font-mono text-[11px]">
                        @foreach($allEncounters->take(5) as $enc)
                        @php
                            $isV1 = $enc->vessel_id_1 === $vessel->id;
                            $partner = $isV1 ? $enc->vessel2 : $enc->vessel1;
                        @endphp
                        <tr>
                            <td class="py-2 px-3 text-white">{{ $enc->started_at?->format('Y-m-d H:i') ?? '-' }} UTC</td>
                            <td class="py-2 px-3 text-teal-300 font-bold">
                                {{ $partner?->name ?? 'MMSI: ' . ($isV1 ? $enc->vessel_id_2 : $enc->vessel_id_1) }}
                            </td>
                            <td class="py-2 px-3 text-slate-300">{{ $enc->duration_minutes ?? '-' }} min</td>
                            <td class="py-2 px-3 text-slate-300">{{ $enc->median_distance_meters ? round($enc->median_distance_meters) . ' m' : '-' }}</td>
                            <td class="py-2 px-3">
                                <span class="px-1.5 py-0.5 rounded text-[9px] uppercase font-bold bg-amber-500/20 text-amber-300">
                                    {{ $enc->status ?? 'detected' }}
                                </span>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @else
            <div class="p-6 rounded-xl bg-slate-900/40 border border-slate-800 text-center text-slate-400 text-xs space-y-1">
                <span class="text-2xl block mb-1">🚢</span>
                <div class="font-semibold text-white">No Encounter Events Detected</div>
                <div class="text-[11px] text-slate-500 max-w-md mx-auto">
                    No close-proximity maritime encounters (&le; 500 meters for &ge; 2 hours) detected between this vessel and reefers or bunker vessels in GFW records or local GFVIP AIS telemetry.
                </div>
            </div>
            @endif
        </div>

        <!-- TAB 4: Loitering & Port Visits -->
        <div data-gfw-panel="loitering" class="space-y-4 hidden">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Loitering Card -->
                <div class="p-4 rounded-xl bg-slate-900/60 border border-slate-800 space-y-2">
                    <div class="flex items-center justify-between border-b border-slate-800 pb-2">
                        <span class="text-xs font-bold uppercase tracking-wider text-amber-400 font-mono flex items-center gap-1.5">
                            <span>⚓</span>
                            <span>Loitering Activity</span>
                        </span>
                        <span class="text-[10px] font-mono text-slate-400">public-global-loitering-events</span>
                    </div>
                    <div class="flex items-baseline gap-2">
                        <span class="text-2xl font-bold font-mono text-white">
                            @if(($eventsSummary['loitering']['status'] ?? '') === 'available')
                            {{ number_format($eventsSummary['loitering']['count'] ?? 0) }}
                            @elseif(($eventsSummary['loitering']['status'] ?? '') === 'permission_restricted')
                            <span class="text-xs text-amber-400">Research Req.</span>
                            @else
                            0
                            @endif
                        </span>
                        <span class="text-xs text-slate-400">recorded events</span>
                    </div>
                    <p class="text-[11px] text-slate-400 leading-relaxed">
                        Loitering is identified when a vessel operates at low speed (&lt; 2 knots) in open waters (outside ports/anchorages) for at least 4 consecutive hours. This behavior often correlates with awaiting support vessels, bunkering, or transshipment preparation.
                    </p>
                </div>

                <!-- Port Visits Card -->
                <div class="p-4 rounded-xl bg-slate-900/60 border border-slate-800 space-y-2">
                    <div class="flex items-center justify-between border-b border-slate-800 pb-2">
                        <span class="text-xs font-bold uppercase tracking-wider text-teal-400 font-mono flex items-center gap-1.5">
                            <span>🏢</span>
                            <span>Port Visits</span>
                        </span>
                        <span class="text-[10px] font-mono text-slate-400">public-global-port-visits-events</span>
                    </div>
                    <div class="flex items-baseline gap-2">
                        <span class="text-2xl font-bold font-mono text-white">
                            @if(($eventsSummary['port_visits']['status'] ?? '') === 'available')
                            {{ number_format($eventsSummary['port_visits']['count'] ?? 0) }}
                            @elseif(($eventsSummary['port_visits']['status'] ?? '') === 'permission_restricted')
                            <span class="text-xs text-amber-400">Research Req.</span>
                            @else
                            0
                            @endif
                        </span>
                        <span class="text-xs text-slate-400">recorded visits</span>
                    </div>
                    <p class="text-[11px] text-slate-400 leading-relaxed">
                        Port visit analytics identify vessel anchorage and berth durations at commercial ports worldwide. Regular port visits indicate standard logistics supply chains for catch offloading and port state measures (PSMA) inspections.
                    </p>
                </div>
            </div>
        </div>

        <!-- TAB 5: AIS Gaps (AIS Disabling Events) -->
        <div data-gfw-panel="gaps" class="space-y-4 hidden">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="p-4 rounded-xl bg-slate-900/60 border border-slate-800 space-y-2">
                    <div class="flex items-center justify-between border-b border-slate-800 pb-2">
                        <span class="text-xs font-bold uppercase tracking-wider text-sky-400 font-mono flex items-center gap-1.5">
                            <span>📡</span>
                            <span>AIS Disabling Events</span>
                        </span>
                        <span class="text-[10px] font-mono text-slate-400">public-global-gaps-events</span>
                    </div>
                    <div class="flex items-baseline gap-2">
                        <span class="text-2xl font-bold font-mono text-white">
                            @if(($eventsSummary['ais_gaps']['status'] ?? '') === 'available')
                            {{ number_format($eventsSummary['ais_gaps']['count'] ?? 0) }}
                            @else
                            0
                            @endif
                        </span>
                        <span class="text-xs text-slate-400">transmission gap anomalies detected</span>
                    </div>
                    <p class="text-[11px] text-slate-400 leading-relaxed">
                        AIS transmission gaps identify timeframes where vessel AIS broadcasting stops for longer than normal operational thresholds while navigating open sea.
                    </p>
                </div>

                <div class="p-4 rounded-xl bg-slate-900/40 border border-slate-800 space-y-2 text-xs">
                    <span class="font-bold text-slate-200 block">Transmission Gap Classification:</span>
                    <div class="space-y-1.5 text-[11px] text-slate-400 leading-relaxed">
                        <div>
                            <strong class="text-slate-300">1. Coverage Gap (Technical):</strong> Signal attenuation caused by high vessel traffic density congesting satellite receivers, or atmospheric ionospheric interference.
                        </div>
                        <div>
                            <strong class="text-amber-400">2. Suspected Disabling (Intentional):</strong> Transponder manually switched off when approaching maritime borders, foreign EEZs, or marine protected areas.
                        </div>
                    </div>
                </div>
            </div>

            <div class="p-4 rounded-xl bg-slate-950/40 border border-slate-800/80 text-xs text-slate-400 flex items-center gap-2">
                <span class="text-emerald-400 text-lg">✓</span>
                <span>No suspicious AIS transmission gaps verified for this vessel identity.</span>
            </div>
        </div>

        <!-- TAB 6: Authorizations & RFMO -->
        <div data-gfw-panel="authorizations" class="space-y-4 hidden">
            <div class="space-y-3">
                <div class="flex items-center justify-between border-b border-slate-800 pb-2">
                    <span class="text-xs font-bold uppercase tracking-wider text-teal-400 font-mono flex items-center gap-1.5">
                        <span>📜</span>
                        <span>RFMO Registry & Fishing Authorizations</span>
                    </span>
                    <span class="text-[10px] text-slate-400">Regional Fisheries Management Organizations</span>
                </div>

                @if($vessel->authorizations->count() > 0)
                <div class="overflow-x-auto responsive-table-container">
                    <table class="w-full text-left text-xs min-w-[500px]">
                        <thead class="text-[10px] text-slate-400 uppercase border-b border-slate-800 bg-slate-900/40">
                            <tr>
                                <th class="py-2 px-3">Authorization Code</th>
                                <th class="py-2 px-3">Permit Type</th>
                                <th class="py-2 px-3">Issuing Authority</th>
                                <th class="py-2 px-3">Maritime Zone</th>
                                <th class="py-2 px-3">Validity Period</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60 font-mono text-[11px]">
                            @foreach($vessel->authorizations as $auth)
                            <tr>
                                <td class="py-2 px-3 text-teal-300 font-bold">{{ $auth->authorization_code }}</td>
                                <td class="py-2 px-3 text-white font-sans">{{ $auth->permit_type }}</td>
                                <td class="py-2 px-3 text-slate-300 font-sans">{{ $auth->issuing_authority }}</td>
                                <td class="py-2 px-3 text-slate-300 font-sans">{{ $auth->maritimeZone?->name ?? 'All Zones' }}</td>
                                <td class="py-2 px-3 text-slate-400">until {{ $auth->valid_to->toDateString() }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @else
                <div class="p-6 rounded-xl bg-slate-900/40 border border-slate-800 text-center text-slate-400 text-xs space-y-1">
                    <span class="text-2xl block mb-1">📜</span>
                    <div class="font-semibold text-white">No Registered RFMO Authorizations</div>
                    <div class="text-[11px] text-slate-500 max-w-md mx-auto">
                        This vessel does not currently have international fisheries authorizations (such as IOTC, WCPFC, ICCAT, CCSBT) or specific zone permits recorded in the system.
                    </div>
                </div>
                @endif
            </div>
        </div>

        <!-- TAB 7: Data Sources & Disclaimer -->
        <div data-gfw-panel="sources" class="space-y-4 hidden">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                <!-- Technical Metadata -->
                <div class="p-4 rounded-xl bg-slate-900/60 border border-slate-800 space-y-2.5">
                    <h4 class="font-bold text-white font-mono uppercase text-[11px] text-teal-400 border-b border-slate-800 pb-1.5">
                        GFW API v3 Gateway Configuration
                    </h4>
                    <div class="space-y-1.5 font-mono text-[11px]">
                        <div class="flex justify-between py-1 border-b border-slate-800/60">
                            <span class="text-slate-400 font-sans">Gateway URL:</span>
                            <span class="text-slate-200">https://gateway.api.globalfishingwatch.org/v3</span>
                        </div>
                        <div class="flex justify-between py-1 border-b border-slate-800/60">
                            <span class="text-slate-400 font-sans">Identity Dataset:</span>
                            <span class="text-teal-300">public-global-vessel-identity:latest</span>
                        </div>
                        <div class="flex justify-between py-1 border-b border-slate-800/60">
                            <span class="text-slate-400 font-sans">Sync Status:</span>
                            <span class="text-white capitalize font-bold">{{ $gfwStatus }}</span>
                        </div>
                        <div class="flex justify-between py-1 border-b border-slate-800/60">
                            <span class="text-slate-400 font-sans">Sync Timestamp:</span>
                            <span class="text-slate-300">{{ $vessel->last_enriched_at?->toIso8601String() ?? 'Never' }}</span>
                        </div>
                        <div class="flex justify-between py-1">
                            <span class="text-slate-400 font-sans">Queue Job Worker:</span>
                            <span class="text-slate-300">EnrichVesselGfwJob</span>
                        </div>
                    </div>
                </div>

                <!-- Query Search History -->
                <div class="p-4 rounded-xl bg-slate-900/60 border border-slate-800 space-y-2.5">
                    <h4 class="font-bold text-white font-mono uppercase text-[11px] text-sky-400 border-b border-slate-800 pb-1.5">
                        Identity Search Request Audit Log
                    </h4>
                    @if(count($searchAttempts) > 0)
                    <div class="space-y-2 text-[11px] font-mono">
                        @foreach($searchAttempts as $attempt)
                        <div class="p-2 rounded bg-slate-950/70 border border-slate-800 flex items-center justify-between">
                            <div>
                                <span class="px-1.5 py-0.5 rounded text-[9px] uppercase font-bold bg-slate-800 text-teal-300">{{ $attempt['type'] ?? 'query' }}</span>
                                <span class="text-slate-300 ml-1">{{ $attempt['query'] ?? '-' }}</span>
                            </div>
                            <div class="text-right">
                                <span class="text-slate-400 font-sans">HTTP {{ $attempt['status'] ?? '-' }}</span>
                                <span class="text-slate-500 text-[10px]">({{ $attempt['total_found'] ?? 0 }} found)</span>
                            </div>
                        </div>
                        @endforeach
                    </div>
                    @else
                    <div class="text-slate-500 italic text-[11px] py-4 text-center">
                        No search request audit log history recorded for this vessel.
                    </div>
                    @endif
                </div>
            </div>

            <!-- Mandatory Attribution & Legal Disclaimers -->
            <div class="p-4 rounded-xl bg-slate-950/80 border border-slate-800 text-xs space-y-2.5">
                <div class="text-[11px] text-slate-300 italic leading-relaxed">
                    &ldquo;This application uses data and services provided by Global Fishing Watch (GFW) for non-commercial research, educational, and public-interest purposes. GFW data are subject to applicable licensing, attribution, and usage restrictions.&rdquo;
                </div>
                <div class="text-[10px] text-amber-300/90 leading-relaxed pt-2 border-t border-slate-800/80 space-y-1">
                    <div>
                        <strong>LEGAL & MARITIME ANALYSIS NOTICE:</strong> Maritime surveillance data presented on this profile represents computational model inferences, machine learning algorithms, and multi-source AIS integrations. Analytical findings must not be construed as definitive proof of illegal, unreported, or unregulated (IUU) fishing or legal evidence without physical verification, official law enforcement investigation, or authorized port inspections.
                    </div>
                    <div>
                        Notice: Displayed vessel activities represent estimates and analytical indicators derived from data sources, not definitive legal proof of infractions.
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- Tab Switching Script -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const tabButtons = document.querySelectorAll('[data-gfw-tab]');
            const tabPanels = document.querySelectorAll('[data-gfw-panel]');

            tabButtons.forEach(btn => {
                btn.addEventListener('click', function () {
                    const target = this.getAttribute('data-gfw-tab');

                    tabButtons.forEach(b => {
                        b.classList.remove('text-teal-300', 'border-teal-400', 'bg-slate-800/60');
                        b.classList.add('text-slate-400', 'border-transparent');
                    });

                    this.classList.remove('text-slate-400', 'border-transparent');
                    this.classList.add('text-teal-300', 'border-teal-400', 'bg-slate-800/60');

                    tabPanels.forEach(panel => {
                        if (panel.getAttribute('data-gfw-panel') === target) {
                            panel.classList.remove('hidden');
                        } else {
                            panel.classList.add('hidden');
                        }
                    });
                });
            });
        });
    </script>

    <!-- SECTION 6: Data Provenance & Authorizations -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        <!-- Provenance Traceability Table -->
        <div class="glass-panel p-5 rounded-xl border border-slate-800 space-y-4">
            <h3 class="text-xs font-bold uppercase tracking-wider text-teal-400 border-b border-slate-800 pb-2 flex items-center gap-1.5">
                <span>🛡️</span>
                <span>6. Data Provenance & Traceability</span>
            </h3>

            @if(count($provenance) > 0)
            <div class="overflow-x-auto responsive-table-container">
                <table class="w-full text-left text-xs min-w-[480px]">
                    <thead class="text-[10px] text-slate-400 uppercase border-b border-slate-800 bg-slate-900/40">
                        <tr>
                            <th class="py-1.5 px-2">Field</th>
                            <th class="py-1.5 px-2">Data Source</th>
                            <th class="py-1.5 px-2">Status & Confidence</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60 font-mono text-[11px]">
                        @foreach($provenance as $fName => $pData)
                        <tr>
                            <td class="py-1.5 px-2 text-white font-bold">{{ $fName }}</td>
                            <td class="py-1.5 px-2 text-slate-300">{{ $pData['source'] }}</td>
                            <td class="py-1.5 px-2">
                                <span class="px-1.5 py-0.5 rounded text-[9px] uppercase font-bold {{ $pData['confidence'] === 'high' ? 'bg-emerald-950 text-emerald-300' : 'bg-slate-800 text-amber-300' }}">
                                    {{ $pData['confidence'] }}
                                </span>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @else
            <div class="py-4 text-slate-500 text-xs italic">
                Profile attributes sourced directly from initial AIS telemetry (provider: {{ $vessel->sourceProvider?->name ?? 'AISStream' }}).
            </div>
            @endif
        </div>

        <!-- Compliance & Authorizations -->
        <div class="glass-panel p-5 rounded-xl border border-slate-800 space-y-4">
            <h3 class="text-xs font-bold uppercase tracking-wider text-amber-400 border-b border-slate-800 pb-2 flex items-center gap-1.5">
                <span>⚖️</span>
                <span>Authorizations & Compliance Record</span>
            </h3>

            <div class="space-y-3 text-xs">
                <div>
                    <span class="text-[10px] text-slate-400 uppercase font-semibold">Fishing Permits:</span>
                    @forelse($vessel->authorizations as $auth)
                    <div class="mt-1 p-2 rounded bg-slate-900 border border-slate-800">
                        <div class="font-bold text-teal-300 font-mono">{{ $auth->authorization_code }}</div>
                        <div class="text-[11px] text-slate-300">{{ $auth->permit_type }} &bull; {{ $auth->issuing_authority }}</div>
                        <div class="text-[10px] text-slate-500 font-mono">Valid until: {{ $auth->valid_to->toDateString() }}</div>
                    </div>
                    @empty
                    <div class="mt-1 text-slate-500 italic">No registered permit records.</div>
                    @endforelse
                </div>

                <div class="pt-2 border-t border-slate-800">
                    <span class="text-[10px] text-slate-400 uppercase font-semibold">Violations / Anomaly Alerts:</span>
                    <div class="text-white font-mono font-bold mt-0.5">
                        {{ $vessel->complianceEvents->count() }} events
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- SECTION 7: Multi-Source Observation History & Audit Trail -->
    <div class="glass-panel p-6 rounded-2xl border border-slate-800 shadow-xl space-y-4">
        <h3 class="text-xs font-bold uppercase tracking-wider text-teal-400 border-b border-slate-800 pb-2 flex items-center justify-between">
            <span class="flex items-center gap-1.5">
                <span>📜</span>
                <span>7. Multi-Source Observation History & Enrichment Audit Log</span>
            </span>
            <span class="text-[10px] font-mono text-slate-400">
                Total: {{ $vessel->attributeObservations->count() }} recorded observations
            </span>
        </h3>

        @if($vessel->attributeObservations->isNotEmpty())
        <div class="overflow-x-auto text-xs responsive-table-container">
            <table class="w-full text-left min-w-[700px]">
                <thead class="bg-slate-900/90 text-slate-400 uppercase tracking-wider text-[10px] border-b border-slate-800">
                    <tr>
                        <th class="py-2 px-3">Retrieved At</th>
                        <th class="py-2 px-3">Data Source</th>
                        <th class="py-2 px-3">Attribute</th>
                        <th class="py-2 px-3">Observed Value</th>
                        <th class="py-2 px-3">External Record ID</th>
                        <th class="py-2 px-3">Confidence</th>
                        <th class="py-2 px-3 text-right">Conflict Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60 font-mono text-[11px]">
                    @foreach($vessel->attributeObservations as $obs)
                    <tr class="hover:bg-slate-900/40 transition">
                        <td class="py-2 px-3 text-slate-400 whitespace-nowrap">
                            {{ $obs->retrieved_at?->format('Y-m-d H:i:s') ?? $obs->created_at->format('Y-m-d H:i:s') }}
                        </td>
                        <td class="py-2 px-3 text-teal-300 font-bold uppercase">
                            {{ $obs->source_name }}
                        </td>
                        <td class="py-2 px-3 text-slate-300 font-sans">
                            {{ $obs->attribute_name }}
                        </td>
                        <td class="py-2 px-3 text-white font-bold max-w-[200px] truncate" title="{{ $obs->attribute_value }}">
                            {{ $obs->attribute_value }}
                        </td>
                        <td class="py-2 px-3 text-slate-400 truncate max-w-[150px]">
                            {{ $obs->external_record_id ?: '—' }}
                        </td>
                        <td class="py-2 px-3 text-slate-300">
                            {{ $obs->confidence_score ? round($obs->confidence_score * 100) . '%' : '—' }}
                        </td>
                        <td class="py-2 px-3 text-right whitespace-nowrap">
                            @if($obs->is_conflicting)
                                <span class="px-2 py-0.5 rounded text-[10px] bg-rose-500/20 text-rose-300 border border-rose-500/40 font-sans font-semibold">
                                    ⚠️ Conflicting
                                </span>
                            @else
                                <span class="px-2 py-0.5 rounded text-[10px] bg-emerald-500/10 text-emerald-300 border border-emerald-500/30 font-sans">
                                    ✓ Valid
                                </span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @else
        <div class="py-6 text-center text-slate-500 text-xs italic">
            No external observations recorded for this vessel yet. Click <strong>Enrich Data</strong> to initiate multi-source reconciliation.
        </div>
        @endif
    </div>

</div>
@endsection
