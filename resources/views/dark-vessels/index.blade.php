@extends('layouts.app')

@section('header_title', 'Analyst Review Workflow — SAR Candidate Management & Review')

@section('content')
<div class="space-y-6">

    <!-- Top Action Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-teal-500/20 text-teal-300 border border-teal-500/30 uppercase">
                    Stage 7 Operational
                </span>
                <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-amber-500/20 text-amber-300 border border-amber-500/30 uppercase">
                    Status: Validated For Analyst Review
                </span>
            </div>
            <h2 class="text-xl font-bold text-white tracking-wide uppercase mt-1">SAR Analyst Review Workflow &amp; Candidate Management</h2>
            <p class="text-xs text-slate-400 mt-0.5">
                Human analyst evaluation of Sentinel-1 SAR radar anomalies and independent comparison with AIS telemetry coverage.
            </p>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('dark-vessels.export', request()->query()) }}" class="px-3.5 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-teal-300 border border-slate-700 font-bold text-xs transition flex items-center gap-1.5 shadow-sm">
                <span>📥</span>
                <span>Export CSV ({{ number_format($detections->total()) }})</span>
            </a>
            <a href="{{ route('map.index') }}" class="px-4 py-2 rounded-xl bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold text-xs transition shadow-lg shadow-teal-500/20 flex items-center gap-1.5">
                <span>🗺️</span>
                <span>SAR Spatial Map</span>
            </a>
        </div>
    </div>

    <!-- Scientific & Legal Notice Alert -->
    <div class="p-4 rounded-xl bg-amber-950/30 border border-amber-500/30 text-amber-200 text-xs flex items-start gap-3">
        <span class="text-xl shrink-0">⚖️</span>
        <div class="space-y-1">
            <div class="font-bold text-amber-300 flex items-center gap-2">
                <span>Maritime Analyst Scientific Integrity Principles:</span>
            </div>
            <p class="text-[11px] text-slate-300 leading-relaxed">
                Detected radar objects are <strong class="text-amber-200">radar anomaly candidates (SAR Candidates)</strong>, not confirmed vessels (<em class="text-slate-400">Confirmed Vessels</em>). The <code class="text-amber-300 font-mono text-[10px]">insufficient_data</code> status indicates lack of AIS receiver station coverage (coverage gap), not proof that the vessel disabled its AIS (<em class="text-slate-400">Dark Vessel</em>). Every analyst decision is recorded permanently in the audit trail for forensic traceability.
            </p>
        </div>
    </div>

    <!-- Metrics Summary Cards (Tahap 9 Multi-Source Maritime Monitoring) -->
    <div class="grid grid-cols-2 sm:grid-cols-4 xl:grid-cols-8 gap-2.5">
        <div class="glass-panel p-3 rounded-xl border border-slate-800 text-center">
            <div class="text-[9px] uppercase font-bold text-slate-400">Total SAR</div>
            <div class="text-lg font-black text-white font-mono mt-0.5">{{ number_format($stats['total_detections']) }}</div>
            <div class="text-[8px] text-slate-500 mt-0.5">Sentinel-1 SAFE</div>
        </div>
        <div class="glass-panel p-3 rounded-xl border border-amber-500/30 bg-amber-500/5 text-center">
            <div class="text-[9px] uppercase font-bold text-amber-400">Pending</div>
            <div class="text-lg font-black text-amber-300 font-mono mt-0.5">{{ number_format($stats['pending_review']) }}</div>
            <div class="text-[8px] text-amber-400/80 mt-0.5">Pending Review</div>
        </div>
        <div class="glass-panel p-3 rounded-xl border border-teal-500/30 bg-teal-500/5 text-center">
            <div class="text-[9px] uppercase font-bold text-teal-400">Possible Vessel</div>
            <div class="text-lg font-black text-teal-300 font-mono mt-0.5">{{ number_format($stats['possible_vessel']) }}</div>
            <div class="text-[8px] text-teal-400/80 mt-0.5">Consistent Vessel</div>
        </div>
        <div class="glass-panel p-3 rounded-xl border border-cyan-500/30 bg-cyan-500/5 text-center">
            <div class="text-[9px] uppercase font-bold text-cyan-400">Ice / Clutter</div>
            <div class="text-lg font-black text-cyan-300 font-mono mt-0.5">{{ number_format($stats['possible_ice_clutter']) }}</div>
            <div class="text-[8px] text-cyan-400/80 mt-0.5">Sea Ice / Clutter</div>
        </div>
        <div class="glass-panel p-3 rounded-xl border border-slate-700 bg-slate-800/30 text-center">
            <div class="text-[9px] uppercase font-bold text-slate-300">Inconclusive</div>
            <div class="text-lg font-black text-slate-200 font-mono mt-0.5">{{ number_format($stats['inconclusive'] + $stats['reviewed_other']) }}</div>
            <div class="text-[8px] text-slate-400 mt-0.5">Insufficient Evidence</div>
        </div>
        <div class="glass-panel p-3 rounded-xl border border-indigo-500/30 bg-indigo-500/5 text-center">
            <div class="text-[9px] uppercase font-bold text-indigo-400">AIS Gap</div>
            <div class="text-lg font-black text-indigo-300 font-mono mt-0.5">{{ number_format($stats['insufficient_data']) }}</div>
            <div class="text-[8px] text-indigo-400/80 mt-0.5">No Terrestrial AIS</div>
        </div>
        <div class="glass-panel p-3 rounded-xl border border-purple-500/30 bg-purple-500/5 text-center" title="GFW Events API v3: HTTP 403 Forbidden (Research access required)">
            <div class="text-[9px] uppercase font-bold text-purple-400">GFW Events</div>
            <div class="text-lg font-black text-purple-300 font-mono mt-0.5">403</div>
            <div class="text-[8px] text-purple-400/80 mt-0.5">Not Authorized</div>
        </div>
        <div class="glass-panel p-3 rounded-xl border border-slate-800 text-center">
            <div class="text-[9px] uppercase font-bold text-slate-500">Optical Follow-up</div>
            <div class="text-lg font-black text-slate-400 font-mono mt-0.5">N/A</div>
            <div class="text-[8px] text-slate-500 mt-0.5">Not Configured</div>
        </div>
    </div>

    <!-- Filter & Search Toolbar (Tahap 9 Multi-Source Controls) -->
    <div class="glass-panel p-4 rounded-2xl border border-slate-800 shadow-lg">
        <form method="GET" action="{{ route('dark-vessels.index') }}" class="space-y-3">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-xs">
                <!-- Search by ID -->
                <div>
                    <label class="block text-[10px] uppercase font-bold text-slate-400 mb-1">Search Candidate ID</label>
                    <input type="text" name="search" value="{{ request('search') }}" placeholder="e.g. 0001 or 542e73f3..." 
                           class="w-full px-3 py-2 rounded-lg bg-slate-900 border border-slate-700 text-slate-200 focus:outline-none focus:border-teal-500 text-xs font-mono">
                </div>

                <!-- Scene Filter -->
                <div>
                    <label class="block text-[10px] uppercase font-bold text-slate-400 mb-1">Sentinel-1 Scene</label>
                    <select name="scene_id" class="w-full px-3 py-2 rounded-lg bg-slate-900 border border-slate-700 text-slate-200 focus:outline-none focus:border-teal-500 text-xs">
                        <option value="">All Satellite Scenes</option>
                        @foreach($scenes as $sc)
                            <option value="{{ $sc->id }}" {{ request('scene_id') == $sc->id || request('scene_id') == $sc->scene_id ? 'selected' : '' }}>
                                {{ substr($sc->scene_id, 0, 16) }}... ({{ $sc->platform_name }} {{ $sc->sensor_mode }})
                            </option>
                        @endforeach
                    </select>
                </div>

                <!-- Review Status Filter -->
                <div>
                    <label class="block text-[10px] uppercase font-bold text-slate-400 mb-1">Analyst Review Status</label>
                    <select name="review_status" class="w-full px-3 py-2 rounded-lg bg-slate-900 border border-slate-700 text-slate-200 focus:outline-none focus:border-teal-500 text-xs">
                        <option value="">All Review Statuses</option>
                        @foreach($reviewStatuses as $val => $label)
                            <option value="{{ $val }}" {{ request('review_status') == $val ? 'selected' : '' }}>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <!-- AIS Correlation Filter -->
                <div>
                    <label class="block text-[10px] uppercase font-bold text-slate-400 mb-1">AIS Correlation Status</label>
                    <select name="correlation_status" class="w-full px-3 py-2 rounded-lg bg-slate-900 border border-slate-700 text-slate-200 focus:outline-none focus:border-teal-500 text-xs">
                        <option value="all">All Correlation Statuses</option>
                        <option value="insufficient_data" {{ request('correlation_status') == 'insufficient_data' ? 'selected' : '' }}>Insufficient Coverage (54)</option>
                        <option value="matched" {{ request('correlation_status') == 'matched' ? 'selected' : '' }}>Correlated (Matched AIS)</option>
                        <option value="dark_vessel_candidate" {{ request('correlation_status') == 'dark_vessel_candidate' ? 'selected' : '' }}>Dark Vessel Candidate</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 text-xs pt-1 border-t border-slate-800/80">
                <!-- AIS Evidence Filter -->
                <div>
                    <label class="block text-[10px] uppercase font-bold text-slate-400 mb-1">AIS Evidence Filter</label>
                    <select name="ais_evidence" class="w-full px-3 py-2 rounded-lg bg-slate-900 border border-slate-700 text-slate-200 focus:outline-none focus:border-teal-500 text-xs">
                        <option value="">All AIS Evidence</option>
                        <option value="matched" {{ request('ais_evidence') == 'matched' ? 'selected' : '' }}>Correlated Track (&lt; 1500m)</option>
                        <option value="insufficient" {{ request('ais_evidence') == 'insufficient' ? 'selected' : '' }}>High Seas Coverage Gap</option>
                        <option value="dark_candidate" {{ request('ais_evidence') == 'dark_candidate' ? 'selected' : '' }}>Verified Dark Vessel</option>
                    </select>
                </div>

                <!-- GFW Status Filter -->
                <div>
                    <label class="block text-[10px] uppercase font-bold text-slate-400 mb-1">GFW Events Status</label>
                    <select name="gfw_status" class="w-full px-3 py-2 rounded-lg bg-slate-900 border border-slate-700 text-slate-200 focus:outline-none focus:border-teal-500 text-xs">
                        <option value="">All GFW Statuses</option>
                        <option value="forbidden" {{ request('gfw_status') == 'forbidden' ? 'selected' : '' }}>403 Access Not Authorized (Actual)</option>
                        <option value="available" {{ request('gfw_status') == 'available' ? 'selected' : '' }}>Available (Open Access)</option>
                    </select>
                </div>

                <!-- Sea Ice Status Filter -->
                <div>
                    <label class="block text-[10px] uppercase font-bold text-slate-400 mb-1">Sea Ice Status</label>
                    <select name="sea_ice_status" class="w-full px-3 py-2 rounded-lg bg-slate-900 border border-slate-700 text-slate-200 focus:outline-none focus:border-teal-500 text-xs">
                        <option value="all">All Sea Ice Statuses</option>
                        <option value="not_available" {{ request('sea_ice_status') == 'not_available' ? 'selected' : '' }}>Not Available (Caution)</option>
                    </select>
                </div>

                <!-- Analyst Reviewer Filter -->
                <div>
                    <label class="block text-[10px] uppercase font-bold text-slate-400 mb-1">Analyst Reviewer</label>
                    <select name="reviewer_id" class="w-full px-3 py-2 rounded-lg bg-slate-900 border border-slate-700 text-slate-200 focus:outline-none focus:border-teal-500 text-xs">
                        <option value="">All Reviewers</option>
                        <option value="unassigned" {{ request('reviewer_id') == 'unassigned' ? 'selected' : '' }}>Unassigned / Unreviewed</option>
                        @foreach($analysts as $an)
                            <option value="{{ $an->id }}" {{ request('reviewer_id') == $an->id ? 'selected' : '' }}>
                                {{ $an->name }} ({{ $an->role }})
                            </option>
                        @endforeach
                    </select>
                </div>

                <!-- Min TCR (dB) & Submit -->
                <div class="flex items-end gap-2">
                    <div class="flex-1">
                        <label class="block text-[10px] uppercase font-bold text-slate-400 mb-1">Min TCR (dB)</label>
                        <input type="number" step="0.5" name="min_tcr" value="{{ request('min_tcr') }}" placeholder="14.0" 
                               class="w-full px-3 py-2 rounded-lg bg-slate-900 border border-slate-700 text-slate-200 focus:outline-none focus:border-teal-500 text-xs font-mono">
                    </div>
                    <button type="submit" class="px-4 py-2 rounded-lg bg-teal-600 hover:bg-teal-500 text-white font-bold text-xs transition shadow-sm">
                        Filter
                    </button>
                    <a href="{{ route('dark-vessels.index') }}" class="px-2.5 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 font-medium text-xs transition border border-slate-700" title="Reset Filters">
                        ✕
                    </a>
                </div>
            </div>
        </form>
    </div>

    <!-- Candidate Detections Table -->
    <div class="glass-panel rounded-2xl border border-slate-800 overflow-hidden shadow-xl">
        <div class="overflow-x-auto text-xs responsive-table-container">
            <table class="w-full text-left min-w-[900px]">
                <thead class="bg-slate-900/90 text-slate-400 uppercase tracking-wider text-[10px] border-b border-slate-800">
                    <tr>
                        <th class="py-3 px-4">Candidate ID</th>
                        <th class="py-3 px-4">Acquisition Time (UTC)</th>
                        <th class="py-3 px-4">WGS84 Coordinates</th>
                        <th class="py-3 px-4 text-center">Peak TCR</th>
                        <th class="py-3 px-4">Estimated Radar Dimensions</th>
                        <th class="py-3 px-4">AIS Correlation</th>
                        <th class="py-3 px-4">Analyst Review Status</th>
                        <th class="py-3 px-4">Last Reviewer</th>
                        <th class="py-3 px-4 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60">
                    @forelse($detections as $det)
                    <tr class="hover:bg-slate-900/50 transition">
                        <!-- ID -->
                        <td class="py-3.5 px-4">
                            <a href="{{ route('dark-vessels.show', $det->id) }}" class="font-bold text-teal-300 hover:text-teal-200 font-mono transition flex items-center gap-1.5">
                                <span>🎯</span>
                                <span>{{ $det->detection_identifier }}</span>
                            </a>
                            <div class="text-[9px] text-slate-500 font-mono mt-0.5">
                                Scene: {{ substr($det->scene?->scene_id ?? '-', 0, 8) }}... &bull; {{ $det->scene?->polarization ?? 'HH' }}
                            </div>
                        </td>

                        <!-- Time -->
                        <td class="py-3.5 px-4 font-mono text-slate-300">
                            <div>{{ $det->detected_at?->format('Y-m-d') }}</div>
                            <div class="text-[10px] text-slate-400">{{ $det->detected_at?->format('H:i:s') }} UTC</div>
                        </td>

                        <!-- Coordinates -->
                        <td class="py-3.5 px-4 font-mono text-slate-200">
                            <div>{{ number_format($det->latitude, 5) }}&deg; S</div>
                            <div class="text-[10px] text-slate-400">{{ number_format($det->longitude, 5) }}&deg; E</div>
                        </td>

                        <!-- TCR -->
                        <td class="py-3.5 px-4 text-center font-mono">
                            @if($det->tcr_db !== null)
                                <span class="px-2 py-0.5 rounded font-bold text-[11px]
                                    {{ $det->tcr_db >= 18.0 ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30' : ($det->tcr_db >= 15.0 ? 'bg-teal-500/20 text-teal-300 border border-teal-500/30' : 'bg-slate-800 text-slate-300 border border-slate-700') }}">
                                    {{ number_format($det->tcr_db, 1) }} dB
                                </span>
                            @else
                                <span class="text-slate-500">-</span>
                            @endif
                        </td>

                        <!-- Dimensions -->
                        <td class="py-3.5 px-4 font-mono text-slate-300">
                            <div class="font-bold text-slate-100">
                                {{ $det->estimated_length_meters ? number_format($det->estimated_length_meters, 0) . 'm' : '-' }} &times; {{ $det->estimated_width_meters ? number_format($det->estimated_width_meters, 0) . 'm' : '-' }}
                            </div>
                            <div class="text-[9px] text-slate-400">
                                Conf: {{ round($det->confidence_score * 100) }}% &bull; {{ $det->review_metadata['pixel_count'] ?? 1 }} px
                            </div>
                        </td>

                        <!-- AIS Correlation -->
                        <td class="py-3.5 px-4">
                            @if($det->correlation_status === 'matched')
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase font-mono bg-emerald-500/20 text-emerald-300 border border-emerald-500/40">
                                    Matched AIS
                                </span>
                            @elseif($det->correlation_status === 'dark_vessel_candidate')
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase font-mono bg-rose-500/20 text-rose-300 border border-rose-500/40">
                                    Dark Vessel
                                </span>
                            @else
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase font-mono bg-amber-500/20 text-amber-300 border border-amber-500/40" title="No AIS receiver station coverage within geographical radius">
                                    Insufficient Data
                                </span>
                            @endif
                        </td>

                        <!-- Analyst Review Status -->
                        <td class="py-3.5 px-4">
                            @if($det->review_status === \App\Models\SatelliteDetection::REVIEW_POSSIBLE_VESSEL)
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase font-mono bg-teal-500/20 text-teal-300 border border-teal-500/40">
                                    ✓ Possible Vessel
                                </span>
                            @elseif($det->review_status === \App\Models\SatelliteDetection::REVIEW_POSSIBLE_ICE_CLUTTER)
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase font-mono bg-cyan-500/20 text-cyan-300 border border-cyan-500/40">
                                    ❄️ Ice / Clutter
                                </span>
                            @elseif($det->review_status === \App\Models\SatelliteDetection::REVIEW_INCONCLUSIVE)
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase font-mono bg-slate-800 text-slate-300 border border-slate-700">
                                    ❓ Inconclusive
                                </span>
                            @elseif($det->review_status === \App\Models\SatelliteDetection::REVIEW_OTHER)
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase font-mono bg-purple-500/20 text-purple-300 border border-purple-500/40">
                                    📝 Other Review
                                </span>
                            @else
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase font-mono bg-amber-500/15 text-amber-400 border border-amber-500/30">
                                    ⏳ Pending Review
                                </span>
                            @endif
                        </td>

                        <!-- Reviewer Info -->
                        <td class="py-3.5 px-4 text-[11px]">
                            @if($det->reviewer)
                                <div class="font-semibold text-slate-200">{{ $det->reviewer->name }}</div>
                                <div class="text-[9px] text-slate-400 font-mono">{{ $det->reviewed_at?->format('d/m/Y H:i') }} UTC</div>
                            @else
                                <span class="text-slate-500 text-[10px] italic">Not evaluated</span>
                            @endif
                        </td>

                        <!-- Action -->
                        <td class="py-3.5 px-4 text-right">
                            <a href="{{ route('dark-vessels.show', $det->id) }}" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-teal-600/30 hover:bg-teal-500 text-teal-200 hover:text-slate-950 font-bold border border-teal-500/40 transition">
                                <span>Review</span>
                                <span>&rarr;</span>
                            </a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="9" class="py-12 px-4 text-center">
                            <div class="max-w-md mx-auto space-y-3">
                                <span class="text-4xl block">🔍</span>
                                <div class="font-bold text-slate-300 text-sm">No SAR Detection Candidates Found</div>
                                <p class="text-slate-500 text-xs leading-relaxed">
                                    No satellite radar candidates match the current filter criteria. Try clearing search filters or adjusting the TCR threshold.
                                </p>
                                <div>
                                    <a href="{{ route('dark-vessels.index') }}" class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-teal-300 border border-slate-700 text-xs font-semibold inline-block transition">
                                        Reset All Filters
                                    </a>
                                </div>
                            </div>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($detections->hasPages())
        <div class="p-4 border-t border-slate-800 bg-slate-900/60">
            {{ $detections->links() }}
        </div>
        @endif
    </div>

</div>
@endsection
