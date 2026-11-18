@extends('layouts.app')

@section('header_title', 'AIS Data Retention Policy — System Settings & Storage Management')

@section('content')
<div class="max-w-6xl space-y-6">

    <!-- Top Breadcrumbs & Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-slate-800 pb-4">
        <div>
            <div class="flex items-center gap-2 text-xs font-mono text-slate-400 mb-1">
                <a href="{{ route('settings.index') }}" class="hover:text-teal-400 transition flex items-center gap-1">
                    <span>⚙️</span>
                    <span>System Settings</span>
                </a>
                <span>/</span>
                <span class="text-teal-400 font-semibold">Data Retention</span>
            </div>
            <h1 class="text-xl font-bold text-white tracking-wide flex items-center gap-2.5">
                <span>🛡️</span>
                <span>AIS Data Retention Policy</span>
            </h1>
            <p class="text-xs text-slate-400 mt-1">
                Management of historical AIS telemetry retention policies, controlled background cleanup schedules, and permanent protection for fishing vessels.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold bg-emerald-500/10 text-emerald-400 border border-emerald-500/30 flex items-center gap-1.5 shadow-xs">
                <span>🔒</span>
                <span>FISHING: 100% IMMUTABLE</span>
            </span>

            @if($settings->auto_cleanup_enabled)
            <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold bg-teal-500/10 text-teal-300 border border-teal-500/30 flex items-center gap-1.5 shadow-xs">
                <span class="w-2 h-2 rounded-full bg-teal-400 animate-pulse"></span>
                <span>CLEANUP: ACTIVE ({{ strtoupper($settings->cleanup_schedule) }})</span>
            </span>
            @else
            <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold bg-slate-800 text-slate-400 border border-slate-700 flex items-center gap-1.5 shadow-xs">
                <span class="w-2 h-2 rounded-full bg-slate-500"></span>
                <span>CLEANUP: DISABLED</span>
            </span>
            @endif

            <a href="{{ route('settings.index') }}" class="px-3 py-1 rounded-lg text-xs font-medium bg-slate-800 hover:bg-slate-700 text-slate-300 border border-slate-700 transition">
                &larr; Back to System Settings
            </a>
        </div>
    </div>

    <!-- Alert / Status Notifications -->
    @if(session('status'))
    <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 text-xs flex items-center gap-2 shadow-xs">
        <span class="text-base">✓</span>
        <span>{{ session('status') }}</span>
    </div>
    @endif

    @if(session('warning'))
    <div class="p-4 rounded-xl bg-amber-500/10 border border-amber-500/30 text-amber-300 text-xs flex items-center gap-2 shadow-xs">
        <span class="text-base">⚠️</span>
        <span>{{ session('warning') }}</span>
    </div>
    @endif

    @if($errors->any())
    <div class="p-4 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-300 text-xs space-y-1 shadow-xs">
        <strong class="font-bold">Failed to update retention policy:</strong>
        <ul class="list-disc pl-5 space-y-0.5">
            @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    <!-- Live Background Process Banner (If an active job is running or pending) -->
    <div id="active-run-banner" class="{{ $activeRun ? '' : 'hidden' }} glass-panel p-5 rounded-2xl border-2 border-cyan-500/60 bg-gradient-to-r from-cyan-950/40 via-slate-900/80 to-slate-900/80 shadow-2xl space-y-3" data-run-id="{{ $activeRun?->id }}">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-cyan-800/40 pb-3">
            <div class="flex items-center gap-2.5">
                <span class="text-2xl animate-spin">⚙️</span>
                <div>
                    <h3 class="text-sm font-bold text-white flex items-center gap-2">
                        <span id="run-type-label">{{ $activeRun?->typeLabel() ?? 'Retention Task' }}</span>
                        <span id="run-status-badge" class="px-2 py-0.5 rounded text-[10px] font-mono font-bold {{ $activeRun?->statusBadgeClass() ?? 'bg-cyan-500/20 text-cyan-300 border border-cyan-500/40' }}">
                            {{ strtoupper($activeRun?->status ?? 'RUNNING') }}
                        </span>
                    </h3>
                    <p class="text-[11px] text-cyan-200/80 mt-0.5">
                        Proses retensi sedang berjalan di antrean latar belakang (Background Worker). Tidak membebani browser atau menyebabkan timeout.
                    </p>
                </div>
            </div>

            @if(auth()->user()?->isSuperAdmin() && $activeRun)
            <form method="POST" action="{{ route('settings.retention.runs.cancel', $activeRun->id) }}" onsubmit="return confirm('Batalkan proses retensi yang sedang berjalan ini?');">
                @csrf
                <button type="submit" class="px-3 py-1.5 rounded-lg bg-rose-600/30 hover:bg-rose-600 text-rose-200 hover:text-white border border-rose-500/40 text-xs font-mono font-semibold transition cursor-pointer">
                    ⏹ Batalkan Proses
                </button>
            </form>
            @endif
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
            <div class="p-2.5 rounded-xl bg-slate-950/60 border border-cyan-900/40">
                <span class="text-[10px] text-slate-400 font-mono uppercase block">Status</span>
                <span id="run-status-text" class="font-bold text-cyan-300 font-mono capitalize">{{ $activeRun?->status ?? 'Running' }}</span>
            </div>
            <div class="p-2.5 rounded-xl bg-slate-950/60 border border-cyan-900/40">
                <span class="text-[10px] text-slate-400 font-mono uppercase block">Records Purged</span>
                <span id="run-purged-count" class="font-bold text-amber-300 font-mono">{{ number_format($activeRun?->total_records_purged ?? 0) }}</span>
            </div>
            <div class="p-2.5 rounded-xl bg-slate-950/60 border border-cyan-900/40">
                <span class="text-[10px] text-slate-400 font-mono uppercase block">Batches Processed</span>
                <span id="run-batch-count" class="font-bold text-white font-mono">{{ number_format($activeRun?->batch_count ?? 0) }}</span>
            </div>
            <div class="p-2.5 rounded-xl bg-slate-950/60 border border-cyan-900/40">
                <span class="text-[10px] text-slate-400 font-mono uppercase block">Elapsed Duration</span>
                <span id="run-duration" class="font-bold text-teal-300 font-mono">{{ $activeRun?->formattedDuration() ?? '0s' }}</span>
            </div>
        </div>

        <!-- Progress Bar Indicator -->
        <div class="w-full bg-slate-950 rounded-full h-2 overflow-hidden border border-cyan-900/50">
            <div id="run-progress-bar" class="bg-gradient-to-r from-teal-500 via-cyan-500 to-teal-400 h-2 rounded-full transition-all duration-500 animate-pulse" style="width: 100%;"></div>
        </div>
    </div>

    <!-- Storage Statistics Dashboard -->
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-3">
        <!-- 1. Total Positions -->
        <div class="glass-panel p-4 rounded-xl border border-slate-800 shadow-sm flex flex-col justify-between">
            <div class="flex items-center justify-between">
                <span class="text-[10px] font-mono uppercase text-slate-400 tracking-wider">Total AIS Positions</span>
                @if(!empty($stats['is_estimate']))
                <span class="text-[8px] px-1 rounded bg-cyan-950 text-cyan-300 border border-cyan-800 font-mono" title="Estimasi berbasis statistik katalog pg_class untuk performa instan">ESTIMASI</span>
                @endif
            </div>
            <div class="text-2xl font-black text-white font-mono my-1">{{ number_format($stats['total_positions']) }}</div>
            <div class="text-[10px] text-slate-400 truncate">{{ $stats['positions_table_size'] }} (Table)</div>
        </div>

        <!-- 2. Fishing Positions (Permanent) -->
        <div class="glass-panel p-4 rounded-xl border border-emerald-500/40 bg-emerald-950/10 shadow-sm flex flex-col justify-between">
            <div class="flex items-center justify-between">
                <span class="text-[10px] font-mono uppercase text-emerald-400 font-bold tracking-wider">Fishing</span>
                <span class="text-[9px] px-1.5 py-0.5 rounded bg-emerald-500/20 text-emerald-300 font-mono">PERMANENT</span>
            </div>
            <div class="text-2xl font-black text-emerald-300 font-mono my-1">{{ number_format($stats['fishing_positions']) }}</div>
            <div class="text-[10px] text-emerald-400/80">{{ $stats['fishing_percentage'] }}% of total positions</div>
        </div>

        <!-- 3. Non-Fishing Positions -->
        <div class="glass-panel p-4 rounded-xl border border-slate-800 shadow-sm flex flex-col justify-between">
            <div class="text-[10px] font-mono uppercase text-slate-400 tracking-wider">Non-Fishing</div>
            <div class="text-2xl font-black text-amber-300 font-mono my-1">{{ number_format($stats['non_fishing_positions']) }}</div>
            <div class="text-[10px] text-slate-400">{{ $stats['non_fishing_percentage'] }}% subject to retention</div>
        </div>

        <!-- 4. Unknown Positions -->
        <div class="glass-panel p-4 rounded-xl border border-slate-800 shadow-sm flex flex-col justify-between">
            <div class="text-[10px] font-mono uppercase text-slate-400 tracking-wider">Unknown / Unclassified</div>
            <div class="text-2xl font-black text-slate-300 font-mono my-1">{{ number_format($stats['unknown_positions']) }}</div>
            <div class="text-[10px] text-slate-400">{{ $stats['unknown_percentage'] }}% pending verification</div>
        </div>

        <!-- 5. Database Size -->
        <div class="glass-panel p-4 rounded-xl border border-slate-800 shadow-sm flex flex-col justify-between">
            <div class="text-[10px] font-mono uppercase text-slate-400 tracking-wider">Database Size</div>
            <div class="text-2xl font-black text-teal-300 font-mono my-1">{{ $stats['database_size'] }}</div>
            <div class="text-[10px] text-slate-400 truncate">PostgreSQL DB Total</div>
        </div>

        <!-- 6. Candidate Purge Records -->
        <div class="glass-panel p-4 rounded-xl border border-slate-800 shadow-sm flex flex-col justify-between">
            <div class="text-[10px] font-mono uppercase text-slate-400 tracking-wider">Purge Candidates</div>
            <div class="text-2xl font-black font-mono my-1 {{ $stats['candidate_purge_count'] > 0 ? 'text-rose-400' : 'text-slate-400' }}">
                {{ number_format($stats['candidate_purge_count']) }}
            </div>
            <div class="text-[10px] text-slate-400 truncate">Est. freed: {{ $stats['candidate_estimated_freed'] }}</div>
        </div>
    </div>

    <!-- Telemetry Timestamp Span, Cache Refresh & Growth Projection -->
    <div class="glass-panel p-4 rounded-xl border border-slate-800 text-xs flex flex-col lg:flex-row lg:items-center justify-between gap-3 text-slate-300 bg-slate-900/40">
        <div class="flex items-center gap-4 flex-wrap">
            <div>
                <span class="text-slate-500 uppercase font-mono text-[10px] block">Oldest Record:</span>
                <span class="font-mono text-slate-200">{{ $stats['oldest_record'] ?? 'No data available' }}</span>
            </div>
            <div class="border-l border-slate-800 pl-4">
                <span class="text-slate-500 uppercase font-mono text-[10px] block">Newest Record:</span>
                <span class="font-mono text-slate-200">{{ $stats['newest_record'] ?? 'No data available' }}</span>
            </div>
            <div class="border-l border-slate-800 pl-4">
                <span class="text-slate-500 uppercase font-mono text-[10px] block">Average Row Size:</span>
                <span class="font-mono text-slate-200">{{ $stats['avg_row_bytes'] }} Bytes</span>
            </div>
        </div>

        <div class="flex items-center gap-3 flex-wrap">
            <div class="flex items-center gap-2 font-mono text-teal-400 bg-teal-950/20 border border-teal-800/40 px-3 py-1.5 rounded-lg shrink-0">
                <span>📈 Projected Monthly Growth:</span>
                <span class="font-bold">{{ $stats['projected_monthly_growth'] }}</span>
            </div>

            <form method="POST" action="{{ route('settings.retention.refresh-stats') }}" class="inline">
                @csrf
                <button type="submit" class="px-2.5 py-1.5 rounded-lg text-xs font-mono font-medium bg-slate-800 hover:bg-slate-700 text-slate-300 border border-slate-700 transition flex items-center gap-1.5 cursor-pointer shadow-xs" title="Recalculate fresh metrics from database">
                    <span>🔄</span>
                    <span>Refresh Metrics</span>
                </button>
            </form>
        </div>
    </div>

    <!-- Dry-Run Simulation Results (if session exists or latest simulation available) -->
    @if(!empty($simulation) && isset($simulation['breakdown']))
    <div class="glass-panel p-6 rounded-2xl border border-cyan-500/50 bg-cyan-950/20 shadow-xl space-y-4">
        <div class="flex items-center justify-between border-b border-cyan-800/40 pb-3">
            <h2 class="text-sm font-bold text-white uppercase tracking-wider flex items-center gap-2">
                <span>🧪</span>
                <span>Retention Cleanup Simulation Results (Dry-Run Mode)</span>
            </h2>
            <span class="px-2.5 py-0.5 rounded text-[10px] font-mono font-bold bg-cyan-500/20 text-cyan-300 border border-cyan-500/40">
                DRY-RUN SIMULATION (DATABASE UNCHANGED)
            </span>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
            <div class="p-3 rounded-xl bg-slate-900/60 border border-slate-800">
                <div class="text-[10px] text-slate-400 uppercase font-mono">Fishing Positions Deleted</div>
                <div class="text-lg font-black text-emerald-400 font-mono mt-0.5">0 Records</div>
                <div class="text-[10px] text-emerald-400/80">100% Protected (Immune)</div>
            </div>
            <div class="p-3 rounded-xl bg-slate-900/60 border border-slate-800">
                <div class="text-[10px] text-slate-400 uppercase font-mono">Protected Evidence Vessels</div>
                <div class="text-lg font-black text-teal-300 font-mono mt-0.5">{{ $simulation['protected_evidence_vessels'] ?? 0 }} Vessels</div>
                <div class="text-[10px] text-slate-400">SAR / Encounter / Investigation</div>
            </div>
            <div class="p-3 rounded-xl bg-slate-900/60 border border-slate-800">
                <div class="text-[10px] text-slate-400 uppercase font-mono">Candidate Positions for Purge</div>
                <div class="text-lg font-black text-amber-300 font-mono mt-0.5">{{ number_format($simulation['total_candidate_positions'] ?? 0) }}</div>
                <div class="text-[10px] text-slate-400">Non-Fishing Beyond Retention</div>
            </div>
            <div class="p-3 rounded-xl bg-slate-900/60 border border-slate-800">
                <div class="text-[10px] text-slate-400 uppercase font-mono">Estimated Disk Space Freed</div>
                <div class="text-lg font-black text-cyan-300 font-mono mt-0.5">{{ $simulation['estimated_freed_size'] ?? '0 B' }}</div>
                <div class="text-[10px] text-slate-400">Disk capacity reclaimed</div>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs border-collapse">
                <thead>
                    <tr class="border-b border-cyan-800/40 text-cyan-200 uppercase font-mono text-[10px]">
                        <th class="py-2 px-3">Vessel Type</th>
                        <th class="py-2 px-3">Retention Policy</th>
                        <th class="py-2 px-3">Cutoff Date</th>
                        <th class="py-2 px-3">Purge Candidates</th>
                        <th class="py-2 px-3">Protection Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800 font-mono">
                    @foreach($simulation['breakdown'] as $type => $row)
                    <tr class="hover:bg-slate-800/40 transition">
                        <td class="py-2 px-3 font-sans font-semibold text-white">{{ $row['label'] }}</td>
                        <td class="py-2 px-3 text-slate-300">
                            {{ $row['retention_days'] ? $row['retention_days'] . ' Days' : 'Permanent' }}
                        </td>
                        <td class="py-2 px-3 text-slate-400">{{ $row['cutoff_date'] ?: '—' }}</td>
                        <td class="py-2 px-3 font-bold {{ ($row['candidates'] ?? 0) > 0 ? 'text-amber-400' : 'text-slate-400' }}">
                            {{ number_format($row['candidates'] ?? 0) }}
                        </td>
                        <td class="py-2 px-3">
                            @if(($row['status'] ?? '') === 'PROTECTED_PERMANENT')
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">
                                🛡️ PERMANENTLY PROTECTED
                            </span>
                            @else
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-slate-800 text-slate-300 border border-slate-700">
                                CONFIGURED RETENTION
                            </span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    <!-- Main Retention Form -->
    <form method="POST" action="{{ route('settings.retention.update') }}" class="space-y-6">
        @csrf

        <!-- SECTION A: FISHING (Kapal Penangkap Ikan - Permanen & Immutable) -->
        <div class="glass-panel p-6 rounded-2xl border-2 border-emerald-500/50 bg-gradient-to-br from-emerald-950/20 to-slate-900/60 shadow-xl space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-emerald-900/50 pb-3">
                <div class="flex items-center gap-2.5">
                    <span class="text-xl">🎣</span>
                    <div>
                        <h2 class="text-base font-bold text-white uppercase tracking-wider flex items-center gap-2">
                            <span>Section A: Fishing Vessels (Fishing)</span>
                            <span class="px-2 py-0.5 rounded text-[10px] font-mono bg-emerald-500/20 text-emerald-300 border border-emerald-500/40">
                                PERMANENT MANDATE
                            </span>
                        </h2>
                        <p class="text-xs text-slate-400">All AIS telemetry for fishing vessels is permanently protected by GFVIP platform mandate.</p>
                    </div>
                </div>

                <div class="flex items-center gap-2 shrink-0">
                    <span class="px-3 py-1 rounded-lg text-xs font-mono font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/40 flex items-center gap-1.5 shadow-xs">
                        <span>🛡️</span>
                        <span>PERMANENT RETENTION / PERMANENTLY PROTECTED</span>
                    </span>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-xs">
                <div class="space-y-1.5 md:col-span-2">
                    <label class="block font-semibold text-slate-200">Position Telemetry Retention Duration:</label>
                    <div class="relative">
                        <input type="text" value="Permanent (Unlimited / Forever) — Fixed Policy (Immutable)" disabled
                            class="w-full px-3 py-2.5 rounded-xl bg-emerald-950/30 border border-emerald-800/60 text-emerald-300 font-mono text-xs cursor-not-allowed">
                        <span class="absolute right-3 top-2.5 text-xs text-emerald-400">🔒 System Locked</span>
                    </div>
                    <p class="text-[11px] text-slate-400 leading-relaxed mt-1">
                        Per GFVIP architectural specifications, fishing vessel data <strong>CANNOT</strong> be assigned an expiration date or subjected to automated deletion schedulers. All coordinate points, speed, heading, and track history are preserved indefinitely.
                    </p>
                </div>

                <div class="p-3.5 rounded-xl bg-slate-900/60 border border-slate-800 space-y-2">
                    <div class="text-[10px] text-slate-400 uppercase font-mono">Current Fishing Vessel Metrics</div>
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-slate-400">Total Stored Positions:</span>
                        <span class="font-bold text-white font-mono">{{ number_format($stats['fishing_positions']) }}</span>
                    </div>
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-slate-400">AIS Storage Share:</span>
                        <span class="font-bold text-emerald-400 font-mono">{{ $stats['fishing_percentage'] }}%</span>
                    </div>
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-slate-400">Purge Status:</span>
                        <span class="font-bold text-emerald-400 font-mono">0% Purged (Full Immunity)</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- SECTION B: OTHER VESSEL TYPES (Non-Fishing) -->
        <div class="glass-panel p-6 rounded-2xl border border-slate-800 shadow-xl space-y-4">
            <div class="border-b border-slate-800 pb-3">
                <h2 class="text-base font-bold text-white uppercase tracking-wider flex items-center gap-2">
                    <span>🚢</span>
                    <span>Section B: Non-Fishing Vessel Types (Other Categories)</span>
                </h2>
                <p class="text-xs text-slate-400 mt-0.5">
                    Configure AIS position telemetry retention thresholds for commercial and non-fishing fleets. Data exceeding this retention limit can be purged to reclaim disk capacity.
                </p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                @php
                    $nonFishingTypes = [
                        'cargo' => ['icon' => '📦', 'preset' => [7, 30, 90, 180, 365]],
                        'tanker' => ['icon' => '🛢️', 'preset' => [7, 30, 90, 180, 365]],
                        'passenger' => ['icon' => '🛳️', 'preset' => [7, 30, 60, 90, 180]],
                        'tug' => ['icon' => '⚓', 'preset' => [7, 30, 60, 90, 180]],
                        'pleasure' => ['icon' => '🛥️', 'preset' => [7, 30, 60, 90, 180]],
                        'other' => ['icon' => '🚢', 'preset' => [7, 30, 90, 180, 365]],
                    ];
                @endphp

                @foreach($nonFishingTypes as $type => $meta)
                    @php
                        $policy = $policies->firstWhere('vessel_type', $type);
                        $breakdown = $stats['breakdown'][$type] ?? null;
                        $currentDays = $policy ? $policy->retention_days : 90;
                        $isEnabled = $policy ? $policy->is_enabled : true;
                    @endphp

                    <div class="p-4 rounded-xl bg-slate-900/60 border border-slate-800 hover:border-slate-700 transition space-y-3">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="text-base">{{ $meta['icon'] }}</span>
                                <span class="font-bold text-white text-xs">{{ $policy?->label ?? ucfirst($type) }}</span>
                            </div>
                            <span class="text-[10px] font-mono px-1.5 py-0.5 rounded bg-slate-800 text-slate-300 border border-slate-700">
                                {{ number_format($breakdown['count'] ?? 0) }} Positions
                            </span>
                        </div>

                        <div class="space-y-1.5">
                            <div class="flex items-center justify-between text-[11px]">
                                <span class="text-slate-400">Retention Duration:</span>
                                <span class="font-mono text-teal-400 font-bold" id="display_{{ $type }}">{{ $currentDays }} Days</span>
                            </div>

                            <!-- Preset select / custom input -->
                            <div class="flex items-center gap-2">
                                <select onchange="updateRetentionDays('{{ $type }}', this.value)"
                                    class="w-full px-2.5 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs font-mono focus:border-teal-400 focus:outline-hidden">
                                    @foreach($meta['preset'] as $presetDays)
                                    <option value="{{ $presetDays }}" {{ $currentDays == $presetDays ? 'selected' : '' }}>
                                        {{ $presetDays }} Days
                                    </option>
                                    @endforeach
                                    <option value="custom" {{ !in_array($currentDays, $meta['preset']) ? 'selected' : '' }}>Custom...</option>
                                </select>

                                <input type="number" name="policies[{{ $type }}][retention_days]" id="input_{{ $type }}"
                                    value="{{ $currentDays }}" min="1" max="3650"
                                    class="w-24 px-2 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs font-mono text-center focus:border-teal-400 focus:outline-hidden"
                                    title="Duration in days">
                            </div>
                        </div>

                        <div class="flex items-center justify-between pt-2 border-t border-slate-800/80 text-[11px]">
                            <label class="flex items-center gap-1.5 cursor-pointer text-slate-400 hover:text-white transition">
                                <input type="checkbox" name="policies[{{ $type }}][is_enabled]" value="1" {{ $isEnabled ? 'checked' : '' }}
                                    class="rounded border-slate-700 bg-slate-900 text-teal-500 focus:ring-0">
                                <span>Enable Retention</span>
                            </label>
                            <span class="text-[10px] text-slate-500 font-mono">{{ $breakdown['estimated_size'] ?? '0 B' }}</span>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <!-- SECTION C: UNKNOWN / UNCLASSIFIED VESSELS -->
        @php
            $unknownPolicy = $policies->firstWhere('vessel_type', 'unknown');
            $unknownDays = $unknownPolicy ? $unknownPolicy->retention_days : 30;
            $unknownBreakdown = $stats['breakdown']['unknown'] ?? null;
        @endphp
        <div class="glass-panel p-6 rounded-2xl border border-amber-500/40 bg-gradient-to-br from-amber-950/10 to-slate-900/60 shadow-xl space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-amber-900/40 pb-3">
                <div class="flex items-center gap-2.5">
                    <span class="text-xl">❓</span>
                    <div>
                        <h2 class="text-base font-bold text-white uppercase tracking-wider flex items-center gap-2">
                            <span>Section C: Unclassified Vessels (Unknown / Unclassified)</span>
                            <span class="px-2 py-0.5 rounded text-[10px] font-mono bg-amber-500/20 text-amber-300 border border-amber-500/40">
                                SEPARATE RETENTION
                            </span>
                        </h2>
                        <p class="text-xs text-slate-400">Dedicated retention settings for vessels lacking an official AIS ShipType classification.</p>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold bg-slate-900 border border-slate-700 text-slate-300">
                        {{ number_format($unknownBreakdown['count'] ?? 0) }} Positions ({{ $unknownBreakdown['estimated_size'] ?? '0 B' }})
                    </span>
                </div>
            </div>

            <div class="p-4 rounded-xl bg-amber-950/20 border border-amber-800/40 text-xs text-amber-200/90 space-y-2">
                <div class="font-bold flex items-center gap-1.5 text-amber-300">
                    <span>⚠️</span>
                    <span>CLASSIFICATION &amp; RETENTION SAFEGUARDS:</span>
                </div>
                <p class="leading-relaxed text-[11px]">
                    1. Vessels classified as <strong>Unknown</strong> <em>MUST NOT</em> automatically be treated as Fishing vessels.<br>
                    2. A separate retention duration is applied as an operational grace period for analysts to classify the vessel.<br>
                    3. <strong>Automatic Protection:</strong> If a vessel's classification is verified as <strong>Fishing</strong>, all remaining AIS telemetry for that vessel will <strong>immediately become permanently protected</strong>.
                </p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                <div class="space-y-1.5">
                    <label class="block font-semibold text-slate-200">Unknown Vessel Retention Duration (Days):</label>
                    <div class="flex items-center gap-2">
                        <select onchange="updateRetentionDays('unknown', this.value)"
                            class="w-full px-3 py-2 rounded-xl bg-slate-950 border border-slate-700 text-slate-200 text-xs font-mono focus:border-teal-400 focus:outline-hidden">
                            <option value="7" {{ $unknownDays == 7 ? 'selected' : '' }}>7 Days (Strictest)</option>
                            <option value="14" {{ $unknownDays == 14 ? 'selected' : '' }}>14 Days</option>
                            <option value="30" {{ $unknownDays == 30 ? 'selected' : '' }}>30 Days (Default Recommended)</option>
                            <option value="60" {{ $unknownDays == 60 ? 'selected' : '' }}>60 Days</option>
                            <option value="90" {{ $unknownDays == 90 ? 'selected' : '' }}>90 Days</option>
                            <option value="custom" {{ !in_array($unknownDays, [7, 14, 30, 60, 90]) ? 'selected' : '' }}>Custom...</option>
                        </select>

                        <input type="number" name="policies[unknown][retention_days]" id="input_unknown"
                            value="{{ $unknownDays }}" min="1" max="3650"
                            class="w-28 px-3 py-2 rounded-xl bg-slate-950 border border-slate-700 text-slate-200 text-xs font-mono text-center focus:border-teal-400 focus:outline-hidden">
                    </div>
                </div>

                <div class="flex items-center justify-between p-3.5 rounded-xl bg-slate-900/60 border border-slate-800">
                    <div>
                        <div class="font-semibold text-slate-200">Unknown Retention Status</div>
                        <div class="text-[11px] text-slate-400">Enable purging of expired unclassified positions</div>
                    </div>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="policies[unknown][is_enabled]" value="1" {{ ($unknownPolicy?->is_enabled ?? true) ? 'checked' : '' }}
                            class="rounded border-slate-700 bg-slate-900 text-teal-500 focus:ring-0">
                        <span class="text-xs text-slate-300">Enabled</span>
                    </label>
                </div>
            </div>
        </div>

        <!-- SECTION D: AUTOMATIC CLEANUP & SCHEDULING -->
        <div class="glass-panel p-6 rounded-2xl border border-slate-800 shadow-xl space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-800 pb-3">
                <div>
                    <h2 class="text-base font-bold text-white uppercase tracking-wider flex items-center gap-2">
                        <span>⏰</span>
                        <span>Section D: Automatic Cleanup Scheduler</span>
                    </h2>
                    <p class="text-xs text-slate-400 mt-0.5">Configure background scheduler to purge non-fishing telemetry exceeding configured retention periods in batches.</p>
                </div>

                <div>
                    @if($settings->auto_cleanup_enabled)
                    <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold bg-teal-500/20 text-teal-300 border border-teal-500/40">
                        STATUS: ACTIVE (RUNNING)
                    </span>
                    @else
                    <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold bg-rose-500/10 text-rose-300 border border-rose-500/30">
                        STATUS: DISABLED
                    </span>
                    @endif
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 text-xs">
                <!-- Toggle Cleanup -->
                <div class="p-4 rounded-xl bg-slate-900/60 border border-slate-800 space-y-2">
                    <label class="font-semibold text-white block">Enable Automatic Cleanup</label>
                    <p class="text-[11px] text-slate-400">Periodic background purge according to schedule.</p>
                    <div class="pt-2">
                        <label class="inline-flex items-center gap-2.5 cursor-pointer">
                            <input type="checkbox" name="settings[auto_cleanup_enabled]" value="1" {{ $settings->auto_cleanup_enabled ? 'checked' : '' }}
                                class="w-4 h-4 rounded border-slate-700 bg-slate-950 text-teal-500 focus:ring-0">
                            <span class="text-xs font-bold text-slate-200">Enable Cleanup Scheduler</span>
                        </label>
                    </div>
                </div>

                <!-- Schedule Frequency -->
                <div class="p-4 rounded-xl bg-slate-900/60 border border-slate-800 space-y-2">
                    <label class="font-semibold text-white block">Schedule Frequency</label>
                    <p class="text-[11px] text-slate-400">When the cleanup scheduler should run.</p>
                    <select name="settings[cleanup_schedule]"
                        class="w-full px-3 py-2 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs font-mono focus:border-teal-400 focus:outline-hidden">
                        <option value="daily" {{ $settings->cleanup_schedule === 'daily' ? 'selected' : '' }}>Daily</option>
                        <option value="weekly" {{ $settings->cleanup_schedule === 'weekly' ? 'selected' : '' }}>Weekly (Every Sunday)</option>
                        <option value="monthly" {{ $settings->cleanup_schedule === 'monthly' ? 'selected' : '' }}>Monthly (1st of Month)</option>
                    </select>
                </div>

                <!-- Schedule Time -->
                <div class="p-4 rounded-xl bg-slate-900/60 border border-slate-800 space-y-2">
                    <label class="font-semibold text-white block">Execution Time (UTC / Server Time)</label>
                    <p class="text-[11px] text-slate-400">Cleanup hour (24-hour HH:MM format).</p>
                    <input type="text" name="settings[cleanup_time]" value="{{ $settings->cleanup_time ?: '03:00' }}"
                        placeholder="03:00"
                        class="w-full px-3 py-2 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs font-mono focus:border-teal-400 focus:outline-hidden">
                </div>

                <!-- Batch Chunk Size -->
                <div class="p-4 rounded-xl bg-slate-900/60 border border-slate-800 space-y-2">
                    <label class="font-semibold text-white block">Batch Chunk Size</label>
                    <p class="text-[11px] text-slate-400">Records per transaction batch (100–5000).</p>
                    <input type="number" name="settings[chunk_size]" value="{{ $settings->chunk_size ?: 1000 }}" min="100" max="5000" step="100"
                        class="w-full px-3 py-2 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs font-mono focus:border-teal-400 focus:outline-hidden">
                </div>
            </div>

            <!-- Execution Status Metadata -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 p-4 rounded-xl bg-slate-950/60 border border-slate-800/80 text-xs">
                <div>
                    <span class="text-slate-500 uppercase font-mono text-[10px] block">Last Cleanup Time:</span>
                    <span class="font-mono text-slate-200 font-semibold">
                        {{ $settings->last_cleanup_at ? $settings->last_cleanup_at->format('Y-m-d H:i:s T') : 'Never Executed' }}
                    </span>
                    @if($settings->last_cleanup_records_purged > 0)
                    <span class="text-[10px] text-teal-400 block mt-0.5">({{ number_format($settings->last_cleanup_records_purged) }} positions purged)</span>
                    @endif
                </div>

                <div>
                    <span class="text-slate-500 uppercase font-mono text-[10px] block">Next Scheduled Run:</span>
                    <span class="font-mono text-teal-300 font-semibold">
                        {{ $nextRun ? $nextRun->format('Y-m-d H:i:s T') : 'Disabled (Automatic Cleanup Off)' }}
                    </span>
                </div>

                <div>
                    <span class="text-slate-500 uppercase font-mono text-[10px] block">Current Expired Position Candidates:</span>
                    <span class="font-mono font-bold {{ $stats['candidate_purge_count'] > 0 ? 'text-amber-400' : 'text-slate-300' }}">
                        {{ number_format($stats['candidate_purge_count']) }} Records ({{ $stats['candidate_estimated_freed'] }})
                    </span>
                </div>
            </div>
        </div>

        <!-- Submit Bar -->
        <div class="flex items-center justify-between gap-4 pt-2">
            <div class="text-xs text-slate-400 flex items-center gap-1.5">
                <span>🛡️</span>
                <span>Configuration changes will be audited and recorded in platform Activity Logs.</span>
            </div>

            <button type="submit"
                class="px-6 py-2.5 rounded-xl bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold text-xs transition shadow-lg shadow-teal-500/20 flex items-center gap-2 cursor-pointer">
                <span>💾</span>
                <span>Save AIS Retention Policy</span>
            </button>
        </div>
    </form>

    <!-- SECTION E: SIMULATION & MANUAL EXECUTION CONTROL -->
    <div class="glass-panel p-6 rounded-2xl border border-slate-800 shadow-xl space-y-4">
        <div class="border-b border-slate-800 pb-3">
            <h2 class="text-base font-bold text-white uppercase tracking-wider flex items-center gap-2">
                <span>⚡</span>
                <span>Testing &amp; Manual Cleanup Control Center</span>
            </h2>
            <p class="text-xs text-slate-400 mt-0.5">Execute dry-run simulations prior to actual cleanup, or run an ad-hoc purge with administrator authorization.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <!-- 1. Dry Run Simulation Button -->
            <div class="p-4 rounded-xl bg-slate-900/60 border border-slate-800 space-y-3 flex flex-col justify-between">
                <div>
                    <div class="flex items-center gap-2 text-white font-bold text-xs">
                        <span>🧪</span>
                        <span>Cleanup Simulation (Dry-Run Test)</span>
                    </div>
                    <p class="text-[11px] text-slate-400 mt-1 leading-relaxed">
                        Computes all positions eligible for purge under active retention policies without deleting any database records. Verifies 100% protection of Fishing vessels and operational evidence.
                    </p>
                </div>

                <form method="POST" action="{{ route('settings.retention.simulate') }}">
                    @csrf
                    <button type="submit"
                        class="w-full px-4 py-2 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-white font-bold text-xs transition flex items-center justify-center gap-2 shadow-xs cursor-pointer">
                        <span>🧪</span>
                        <span>Run Dry-Run Simulation Now</span>
                    </button>
                </form>
            </div>

            <!-- 2. Manual Purge (Super Admin Only) -->
            <div class="p-4 rounded-xl bg-slate-900/60 border border-slate-800 space-y-3 flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2 text-white font-bold text-xs">
                            <span>🧹</span>
                            <span>Manual Ad-Hoc Purge</span>
                        </div>
                        <span class="text-[9px] px-1.5 py-0.5 rounded bg-rose-500/20 text-rose-300 font-mono">SUPER ADMIN</span>
                    </div>
                    <p class="text-[11px] text-slate-400 mt-1 leading-relaxed">
                        Executes background cleanup of expired non-fishing telemetry in safe, throttled batches. Fishing vessels and SAR/Encounter evidence remain 100% protected.
                    </p>
                </div>

                @if(auth()->user()?->isSuperAdmin())
                <form method="POST" action="{{ route('settings.retention.purge') }}" onsubmit="return confirm('Are you sure you want to execute manual cleanup of expired non-fishing AIS positions? Fishing data and SAR/Encounter evidence will remain 100% protected.');">
                    @csrf
                    <div class="space-y-2">
                        <label class="flex items-center gap-2 cursor-pointer text-slate-300 text-[11px]">
                            <input type="checkbox" name="confirm_purge" value="1" required
                                class="rounded border-slate-700 bg-slate-950 text-rose-500 focus:ring-0">
                            <span>Confirm manual purge of non-fishing telemetry</span>
                        </label>

                        <button type="submit"
                            class="w-full px-4 py-2 rounded-lg bg-rose-600 hover:bg-rose-500 text-white font-bold text-xs transition flex items-center justify-center gap-2 shadow-xs cursor-pointer">
                            <span>⚠️</span>
                            <span>Execute Manual Purge</span>
                        </button>
                    </div>
                </form>
                @else
                <div class="p-2.5 rounded-lg bg-slate-950 border border-slate-800 text-[11px] text-slate-500 text-center">
                    Only Super Admins are authorized to execute manual purges.
                </div>
                @endif
            </div>
        </div>
    </div>

    <!-- SECTION E2: VESSEL ENCOUNTER EVENTS RETENTION & PURGE -->
    <div class="glass-panel p-6 rounded-2xl border border-slate-800 shadow-xl space-y-4" id="encounter-retention-section">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-800 pb-3">
            <div>
                <h2 class="text-base font-bold text-white uppercase tracking-wider flex items-center gap-2">
                    <span>🤝</span>
                    <span>Vessel Encounter Events Retention &amp; Cleanup</span>
                </h2>
                <p class="text-xs text-slate-400 mt-0.5">
                    Manage and safely purge historical observational proximity events between vessels. Vessel profiles, positions, and live AIS tracking remain 100% protected.
                </p>
            </div>
            <div>
                @if($encounterStats['is_empty'] ?? true)
                <span id="encounter-status-badge" class="px-2.5 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider bg-slate-800 text-slate-400 border border-slate-700">
                    STATUS: EMPTY (0 RECORDS)
                </span>
                @else
                <span id="encounter-status-badge" class="px-2.5 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider bg-amber-500/20 text-amber-300 border border-amber-500/30">
                    STATUS: {{ number_format($encounterStats['total_encounters']) }} ACTIVE RECORDS
                </span>
                @endif
            </div>
        </div>

        <!-- Metrics Cards -->
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
            <div class="p-3 rounded-xl bg-slate-900/60 border border-slate-800">
                <div class="text-[10px] text-slate-400 uppercase font-mono">Total Encounters</div>
                <div class="text-xl font-black text-cyan-300 font-mono mt-0.5" id="enc-total-count">
                    {{ number_format($encounterStats['total_encounters'] ?? 0) }}
                </div>
                <div class="text-[10px] text-slate-500 mt-0.5">Events recorded</div>
            </div>

            <div class="p-3 rounded-xl bg-slate-900/60 border border-slate-800">
                <div class="text-[10px] text-slate-400 uppercase font-mono">Participants</div>
                <div class="text-xl font-black text-slate-200 font-mono mt-0.5" id="enc-participants-count">
                    {{ number_format($encounterStats['total_participants'] ?? 0) }}
                </div>
                <div class="text-[10px] text-slate-500 mt-0.5">Vessel associations</div>
            </div>

            <div class="p-3 rounded-xl bg-slate-900/60 border border-slate-800">
                <div class="text-[10px] text-amber-400 uppercase font-mono">Candidate</div>
                <div class="text-xl font-black text-amber-300 font-mono mt-0.5">
                    {{ number_format($encounterStats['candidate'] ?? 0) }}
                </div>
                <div class="text-[10px] text-slate-500 mt-0.5">Pending evaluation</div>
            </div>

            <div class="p-3 rounded-xl bg-slate-900/60 border border-slate-800">
                <div class="text-[10px] text-indigo-400 uppercase font-mono">Needs Review</div>
                <div class="text-xl font-black text-indigo-300 font-mono mt-0.5">
                    {{ number_format($encounterStats['needs_review'] ?? 0) }}
                </div>
                <div class="text-[10px] text-slate-500 mt-0.5">Flagged for analyst</div>
            </div>

            <div class="p-3 rounded-xl bg-slate-900/60 border border-slate-800">
                <div class="text-[10px] text-emerald-400 uppercase font-mono">Verified</div>
                <div class="text-xl font-black text-emerald-300 font-mono mt-0.5">
                    {{ number_format($encounterStats['verified'] ?? 0) }}
                </div>
                <div class="text-[10px] text-slate-500 mt-0.5">Confirmed encounters</div>
            </div>

            <div class="p-3 rounded-xl bg-slate-900/60 border border-slate-800">
                <div class="text-[10px] text-rose-400 uppercase font-mono">Rejected</div>
                <div class="text-xl font-black text-rose-300 font-mono mt-0.5">
                    {{ number_format($encounterStats['rejected'] ?? 0) }}
                </div>
                <div class="text-[10px] text-slate-500 mt-0.5">Disproved proximity</div>
            </div>
        </div>

        <!-- Info & Date Range -->
        <div class="flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-slate-400 bg-slate-950/40 p-3 rounded-xl border border-slate-800/60">
            <div class="flex items-center gap-2">
                <span class="text-cyan-400">📅</span>
                <span>Date Range:</span>
                <span class="font-mono text-slate-200">
                    {{ $encounterStats['oldest'] ? \Carbon\Carbon::parse($encounterStats['oldest'])->format('d M Y H:i') : '—' }}
                </span>
                <span>to</span>
                <span class="font-mono text-slate-200">
                    {{ $encounterStats['newest'] ? \Carbon\Carbon::parse($encounterStats['newest'])->format('d M Y H:i') : '—' }}
                </span>
            </div>
            <div class="text-[11px] text-slate-500">
                Protection: Vessels, AIS tracks, and compliance records are strictly preserved.
            </div>
        </div>

        <!-- Purge Action Control -->
        <div class="pt-2">
            @if(auth()->user()?->isSuperAdmin())
                @if(($encounterStats['total_encounters'] ?? 0) > 0)
                <div class="p-4 rounded-xl bg-rose-950/20 border border-rose-900/40 flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
                    <div class="space-y-1">
                        <div class="flex items-center gap-2 text-rose-300 font-bold text-xs uppercase tracking-wide">
                            <span>⚠️</span>
                            <span>Purge All Historical Vessel Encounters</span>
                        </div>
                        <p class="text-[11px] text-slate-400 leading-relaxed max-w-2xl">
                            Permanently removes all {{ number_format($encounterStats['total_encounters']) }} vessel encounter events and participant linkages across all statuses.
                            This operation runs in throttled batches to protect database performance.
                        </p>
                    </div>

                    <button type="button" onclick="openEncounterPurgeModal()"
                        class="px-4 py-2.5 rounded-lg bg-rose-600 hover:bg-rose-500 text-white font-bold text-xs transition flex items-center justify-center gap-2 shadow-sm cursor-pointer whitespace-nowrap">
                        <span>🗑️</span>
                        <span>Delete All ({{ number_format($encounterStats['total_encounters']) }} Records)</span>
                    </button>
                </div>
                @else
                <div class="p-3.5 rounded-xl bg-slate-900/40 border border-slate-800 text-center flex items-center justify-center gap-2 text-slate-400 text-xs">
                    <span class="text-emerald-400">✓</span>
                    <span>No vessel encounters stored in database. The encounter registry is clean (0 records).</span>
                </div>
                @endif
            @else
                <div class="p-3 rounded-lg bg-slate-950 border border-slate-800 text-[11px] text-slate-500 text-center">
                    Only Super Admins are authorized to execute vessel encounter bulk deletion.
                </div>
            @endif
        </div>
    </div>

    <!-- Encounter Purge Confirmation Modal -->
    <div id="encounter-purge-modal" class="fixed inset-0 z-50 hidden bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="glass-panel max-w-lg w-full p-6 rounded-2xl border border-rose-900/50 shadow-2xl space-y-4 bg-slate-900">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <div class="flex items-center gap-2 text-rose-400 font-bold text-sm uppercase tracking-wide">
                    <span>⚠️</span>
                    <span>Confirm Delete All Encounters</span>
                </div>
                <button type="button" onclick="closeEncounterPurgeModal()" class="text-slate-400 hover:text-white transition text-lg leading-none">&times;</button>
            </div>

            <div class="space-y-3 text-xs text-slate-300">
                <p class="leading-relaxed">
                    You are about to delete <strong class="text-rose-400">{{ number_format($encounterStats['total_encounters'] ?? 0) }}</strong> Vessel Encounter Events and their participant associations.
                </p>

                <div class="p-3 rounded-xl bg-slate-950 border border-slate-800 space-y-2 text-[11px]">
                    <div class="text-emerald-400 font-bold flex items-center gap-1.5">
                        <span>✓</span>
                        <span>What is SAFE &amp; PRESERVED:</span>
                    </div>
                    <ul class="list-disc list-inside text-slate-400 space-y-0.5 ml-1">
                        <li>All vessel identity records (<code class="text-slate-300 font-mono">vessels</code>)</li>
                        <li>All AIS telemetry &amp; positions (<code class="text-slate-300 font-mono">vessel_positions</code>)</li>
                        <li>Live map tracking and search indices</li>
                        <li>Compliance rules &amp; investigation case notes</li>
                    </ul>
                </div>

                <div class="p-3 rounded-xl bg-rose-950/30 border border-rose-900/40 text-[11px] text-rose-300">
                    <strong>PERMANENT ACTION:</strong> Deleted encounters cannot be restored. New encounters will only be detected in open sea waters when the detection algorithm runs.
                </div>
            </div>

            <form id="encounter-purge-form" method="POST" action="{{ route('settings.retention.encounters.purge') }}">
                @csrf
                <div class="space-y-4">
                    <label class="flex items-start gap-2.5 cursor-pointer text-slate-300 text-xs select-none">
                        <input type="checkbox" id="confirm-enc-checkbox" name="confirm_encounter_purge" value="1" required
                            class="mt-0.5 rounded border-slate-700 bg-slate-950 text-rose-500 focus:ring-0">
                        <span>I confirm that I want to delete ALL vessel encounter records permanently.</span>
                    </label>

                    <div class="flex items-center justify-end gap-3 pt-2 border-t border-slate-800">
                        <button type="button" onclick="closeEncounterPurgeModal()"
                            class="px-4 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-bold transition">
                            Cancel
                        </button>
                        <button type="submit" id="btn-submit-enc-purge"
                            class="px-4 py-2 rounded-lg bg-rose-600 hover:bg-rose-500 text-white text-xs font-bold transition flex items-center gap-2 cursor-pointer">
                            <span>🗑️</span>
                            <span>Confirm &amp; Delete All</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <div class="glass-panel p-6 rounded-2xl border border-slate-800 shadow-xl space-y-3">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-slate-800 pb-3">
            <h2 class="text-sm font-bold text-white uppercase tracking-wider flex items-center gap-2">
                <span>📋</span>
                <span>Retention Execution &amp; Cleanup History</span>
            </h2>
            <span class="text-xs text-slate-400 font-mono">
                Total {{ number_format($runs->total()) }} execution records
            </span>
        </div>

        @if($runs->isEmpty())
        <div class="text-center py-6 text-xs text-slate-500">
            No retention execution records logged yet.
        </div>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs border-collapse">
                <thead>
                    <tr class="border-b border-slate-800 text-slate-400 uppercase font-mono text-[10px]">
                        <th class="py-2 px-3">Run ID</th>
                        <th class="py-2 px-3">Timestamp</th>
                        <th class="py-2 px-3">Type</th>
                        <th class="py-2 px-3">Records Purged</th>
                        <th class="py-2 px-3">Batches</th>
                        <th class="py-2 px-3">Duration</th>
                        <th class="py-2 px-3">Status</th>
                        <th class="py-2 px-3">Initiated By</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60 font-mono">
                    @foreach($runs as $r)
                    <tr class="hover:bg-slate-800/30 transition">
                        <td class="py-2.5 px-3 text-slate-400">#{{ $r->id }}</td>
                        <td class="py-2.5 px-3 text-slate-300 whitespace-nowrap">{{ $r->created_at ? $r->created_at->format('Y-m-d H:i:s') : '—' }}</td>
                        <td class="py-2.5 px-3 font-sans font-medium text-white">
                            {{ $r->typeLabel() }}
                            @if($r->is_dry_run)
                            <span class="text-[9px] px-1 py-0.2 rounded bg-cyan-500/20 text-cyan-300 font-mono block w-fit mt-0.5">DRY-RUN</span>
                            @endif
                        </td>
                        <td class="py-2.5 px-3 font-bold {{ $r->total_records_purged > 0 ? 'text-amber-400' : 'text-slate-400' }}">
                            {{ number_format($r->total_records_purged) }}
                        </td>
                        <td class="py-2.5 px-3 text-slate-400">{{ number_format($r->batch_count) }}</td>
                        <td class="py-2.5 px-3 text-slate-300">{{ $r->formattedDuration() }}</td>
                        <td class="py-2.5 px-3">
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase border {{ $r->statusBadgeClass() }}">
                                {{ $r->status }}
                            </span>
                        </td>
                        <td class="py-2.5 px-3 font-sans text-slate-400">
                            {{ $r->initiatedByUser?->name ?? 'System Scheduler' }}
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($runs->hasPages())
        <div class="pt-3 border-t border-slate-800">
            {{ $runs->appends(request()->except('runs_page'))->links() }}
        </div>
        @endif
        @endif
    </div>

    <!-- SECTION G: AUDIT LOGS -->
    <div class="glass-panel p-6 rounded-2xl border border-slate-800 shadow-xl space-y-3">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <h2 class="text-sm font-bold text-white uppercase tracking-wider flex items-center gap-2">
                <span>📜</span>
                <span>AIS Retention Policy Audit Trail</span>
            </h2>
            <a href="{{ route('audit-logs.index') }}" class="text-xs text-teal-400 hover:text-teal-300 transition">
                View All Activity Logs &rarr;
            </a>
        </div>

        @if($recentLogs->isEmpty())
        <div class="text-center py-6 text-xs text-slate-500">
            No retention policy changes recorded yet.
        </div>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs border-collapse">
                <thead>
                    <tr class="border-b border-slate-800 text-slate-400 uppercase font-mono text-[10px]">
                        <th class="py-2 px-3">Timestamp</th>
                        <th class="py-2 px-3">Operator / User</th>
                        <th class="py-2 px-3">Action</th>
                        <th class="py-2 px-3">IP Address</th>
                        <th class="py-2 px-3">Change Details</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60 font-mono">
                    @foreach($recentLogs as $log)
                    <tr class="hover:bg-slate-800/30 transition">
                        <td class="py-2.5 px-3 text-slate-400 whitespace-nowrap">{{ $log->created_at ? $log->created_at->format('Y-m-d H:i:s') : '—' }}</td>
                        <td class="py-2.5 px-3 font-sans font-semibold text-white">
                            {{ $log->user?->name ?? 'System' }}
                            <span class="text-[10px] text-slate-400 font-mono block">({{ $log->user?->email ?? 'Automated Worker' }})</span>
                        </td>
                        <td class="py-2.5 px-3">
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase
                                {{ $log->action === 'delete' ? 'bg-rose-500/20 text-rose-300 border border-rose-500/30' : 'bg-teal-500/20 text-teal-300 border border-teal-500/30' }}">
                                {{ $log->action }}
                            </span>
                        </td>
                        <td class="py-2.5 px-3 text-slate-400">{{ $log->ip_address ?? '—' }}</td>
                        <td class="py-2.5 px-3 text-slate-300 font-sans text-[11px]">
                            @if(isset($log->details['action_label']))
                                {{ $log->details['action_label'] }}
                            @elseif(isset($log->details['total_purged']))
                                Telemetry purge: {{ number_format($log->details['total_purged']) }} records deleted.
                            @else
                                {{ json_encode($log->details) }}
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($recentLogs->hasPages())
        <div class="pt-3 border-t border-slate-800">
            {{ $recentLogs->appends(request()->except('logs_page'))->links() }}
        </div>
        @endif
        @endif
    </div>

</div>

<script>
function updateRetentionDays(type, value) {
    const input = document.getElementById('input_' + type);
    const display = document.getElementById('display_' + type);
    if (!input) return;

    if (value !== 'custom') {
        input.value = value;
        if (display) display.textContent = value + ' Days';
    } else {
        input.focus();
    }
}

function openEncounterPurgeModal() {
    const modal = document.getElementById('encounter-purge-modal');
    if (modal) modal.classList.remove('hidden');
}

function closeEncounterPurgeModal() {
    const modal = document.getElementById('encounter-purge-modal');
    if (modal) modal.classList.add('hidden');
}

// Live polling for active background retention run
document.addEventListener('DOMContentLoaded', function () {
    const banner = document.getElementById('active-run-banner');
    if (!banner) return;

    const runId = banner.getAttribute('data-run-id');
    if (!runId) return;

    let pollInterval = setInterval(function () {
        fetch('/settings/retention/runs/' + runId + '/status', {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(res => res.json())
        .then(data => {
            if (!data || !data.id) return;

            document.getElementById('run-status-text').textContent = data.status;
            document.getElementById('run-purged-count').textContent = new Intl.NumberFormat().format(data.total_records_purged || 0);
            document.getElementById('run-batch-count').textContent = new Intl.NumberFormat().format(data.batch_count || 0);
            document.getElementById('run-duration').textContent = data.formatted_duration || '—';

            if (!data.is_active) {
                clearInterval(pollInterval);
                document.getElementById('run-status-badge').textContent = data.status.toUpperCase();
                document.getElementById('run-progress-bar').classList.remove('animate-pulse');

                setTimeout(function () {
                    window.location.reload();
                }, 2000);
            }
        })
        .catch(err => {
            console.error('Failed to poll retention run status:', err);
        });
    }, 3000);
});
</script>
@endsection
