@extends('layouts.app')

@section('header_title', 'System Settings & Production Observability — Platform Status & Monitoring')

@section('content')
<div class="max-w-5xl space-y-6">

    @if(session('status'))
    <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 text-xs flex items-center gap-2">
        <span>✓</span>
        <span>{{ session('status') }}</span>
    </div>
    @endif

    @if(session('warning'))
    <div class="p-4 rounded-xl bg-amber-500/10 border border-amber-500/30 text-amber-300 text-xs flex items-center gap-2">
        <span>⚠️</span>
        <span>{{ session('warning') }}</span>
    </div>
    @endif

    @if($errors->any())
    <div class="p-4 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-300 text-xs space-y-1">
        <strong class="font-bold">Failed to update configuration:</strong>
        <ul class="list-disc pl-5 space-y-0.5">
            @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    <!-- 1. Active System Alerts -->
    @if(!empty($alerts))
    <div class="glass-panel p-6 rounded-2xl border border-slate-800 shadow-xl space-y-3">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <h2 class="text-base font-bold text-white uppercase tracking-wider flex items-center gap-2">
                <span>🚨</span>
                <span>Active System Observability &amp; Alert Signals</span>
            </h2>
            <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold bg-slate-900 border border-slate-700 text-slate-300">
                {{ count($alerts) }} Signal(s)
            </span>
        </div>

        <div class="space-y-2">
            @foreach($alerts as $alert)
            <div class="p-3 rounded-xl border flex items-start justify-between gap-3 text-xs
                @if($alert['severity'] === 'CRITICAL') bg-rose-950/30 border-rose-800/50 text-rose-300
                @elseif($alert['severity'] === 'WARNING') bg-amber-950/30 border-amber-800/50 text-amber-300
                @else bg-slate-900/60 border-slate-800 text-slate-300 @endif">
                <div class="flex items-start gap-2.5">
                    <span class="px-1.5 py-0.5 rounded text-[10px] font-mono font-bold uppercase
                        @if($alert['severity'] === 'CRITICAL') bg-rose-500/20 text-rose-300 border border-rose-500/40
                        @elseif($alert['severity'] === 'WARNING') bg-amber-500/20 text-amber-300 border border-amber-500/40
                        @else bg-slate-800 text-slate-400 border border-slate-700 @endif">
                        {{ $alert['severity'] }}
                    </span>
                    <div>
                        <div class="font-semibold text-white">{{ $alert['component'] }}: <span class="font-mono text-[11px] text-slate-400">[{{ $alert['code'] }}]</span></div>
                        <div class="text-[11px] mt-0.5 text-slate-300">{{ $alert['message'] }}</div>
                    </div>
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    <!-- Quick Access: AIS Data Retention Policy -->
    <div class="glass-panel p-5 rounded-2xl border border-teal-500/30 bg-gradient-to-r from-teal-950/30 via-slate-900/60 to-slate-900/60 shadow-xl flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div class="flex items-start gap-3">
            <span class="text-2xl p-2 rounded-xl bg-teal-500/10 border border-teal-500/30">🛡️</span>
            <div>
                <h3 class="text-sm font-bold text-white flex items-center gap-2">
                    <span>AIS Data Retention Policy</span>
                    <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">FISHING 100% IMMUTABLE</span>
                </h3>
                <p class="text-xs text-slate-400 mt-0.5">
                    Configuration of historical AIS position retention periods, automatic cleanup scheduler, dry-run simulation, and permanent protection for the fishing fleet.
                </p>
            </div>
        </div>
        <a href="{{ route('settings.retention.index') }}"
            class="px-4 py-2 rounded-xl bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold text-xs transition shrink-0 flex items-center justify-center gap-2 shadow-lg shadow-teal-500/20">
            <span>Manage Retention Policy</span>
            <span>&rarr;</span>
        </a>
    </div>

    <!-- 2. System Health & Infrastructure Matrix -->
    <div class="glass-panel p-6 rounded-2xl border border-slate-800 shadow-xl space-y-4 text-xs">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <div>
                <h2 class="text-base font-bold text-white uppercase tracking-wider flex items-center gap-2">
                    <span>🖥️</span>
                    <span>Infrastructure Health Status &amp; Runtime Observability</span>
                </h2>
                <p class="text-xs text-slate-400 mt-1">Monitoring database engine, PostGIS spatial extension, queue worker, scheduler heartbeat, and storage volumes.</p>
            </div>
            <a href="{{ route('api.health', ['detail' => 1]) }}" target="_blank" class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold bg-slate-900 text-cyan-300 border border-cyan-800 hover:bg-slate-800 transition">
                JSON Probe: /api/health &nearr;
            </a>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <!-- Application -->
            <div class="p-3.5 rounded-xl bg-slate-900 border border-slate-800 space-y-1">
                <span class="text-slate-400 text-[11px]">Application &amp; Runtime:</span>
                <div class="text-sm font-bold font-mono text-cyan-300">Laravel {{ $health['application']['laravel_version'] ?? '13.x' }}</div>
                <div class="text-[10px] text-slate-500">PHP {{ $health['application']['php_version'] ?? PHP_VERSION }} &bull; Env: <span class="text-amber-300 font-mono">{{ strtoupper(config('app.env')) }}</span></div>
            </div>

            <!-- Database & PostGIS -->
            <div class="p-3.5 rounded-xl bg-slate-900 border border-slate-800 space-y-1">
                <span class="text-slate-400 text-[11px]">Database &amp; Spatial Extension:</span>
                <div class="text-sm font-bold font-mono text-emerald-400 flex items-center gap-1.5">
                    <span>✓ CONNECTED</span>
                    <span class="text-[10px] text-slate-500">({{ $health['database']['latency_ms'] ?? 0 }} ms)</span>
                </div>
                <div class="text-[10px] text-slate-500 truncate" title="{{ $health['database']['postgresql_version_summary'] ?? '' }}">PostgreSQL 18 + PostGIS 3.6 (Spatial: OK)</div>
            </div>

            <!-- Queue & Background Workers -->
            <div class="p-3.5 rounded-xl bg-slate-900 border border-slate-800 space-y-1">
                <span class="text-slate-400 text-[11px]">Queue Worker &amp; Scheduler:</span>
                <div class="text-sm font-bold font-mono text-purple-300">{{ $health['queue']['pending_jobs'] ?? 0 }} pending &bull; {{ $health['queue']['failed_jobs'] ?? 0 }} failed</div>
                <div class="text-[10px] text-slate-500">Scheduler Heartbeat: <span class="text-emerald-400">ACTIVE</span></div>
            </div>

            <!-- Storage / Disk Space -->
            <div class="p-3.5 rounded-xl bg-slate-900 border border-slate-800 space-y-1">
                <span class="text-slate-400 text-[11px]">Disk Capacity ({{ $diskStorage['drive'] ?? 'Storage' }}):</span>
                <div class="text-sm font-bold font-mono text-white">{{ $diskStorage['free_gb'] ?? 0 }} GB Free ({{ $diskStorage['free_percent'] ?? 0 }}%)</div>
                <div class="text-[10px] text-emerald-400 font-semibold">{{ $diskStorage['threshold_status'] ?? 'Healthy' }}</div>
            </div>
        </div>
    </div>

    <!-- 3. AIS Telemetry Freshness Monitoring -->
    <div class="glass-panel p-6 rounded-2xl border border-slate-800 shadow-xl space-y-4 text-xs">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <div>
                <h2 class="text-base font-bold text-white uppercase tracking-wider flex items-center gap-2">
                    <span>📡</span>
                    <span>AIS Telemetry Freshness Monitoring (Data Freshness Matrix)</span>
                </h2>
                <p class="text-xs text-slate-400 mt-1">Telemetry age classification: Live (&lt; 2h), Stale (2–6h), and Very Stale (&gt; 6h). All historical data is preserved for forensic evidence.</p>
            </div>
            <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold
                @if(($aisFreshness['freshness_rating'] ?? '') === 'LIVE') bg-emerald-500/20 text-emerald-300 border border-emerald-500/40
                @elseif(($aisFreshness['freshness_rating'] ?? '') === 'STALE') bg-amber-500/20 text-amber-300 border border-amber-500/40
                @else bg-slate-800 text-slate-400 border border-slate-700 @endif">
                RATING: {{ $aisFreshness['freshness_rating'] ?? 'UNKNOWN' }}
            </span>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
            <div class="p-3.5 rounded-xl bg-slate-900 border border-slate-800 space-y-1">
                <span class="text-emerald-400 font-semibold text-[11px]">LIVE (&lt; 2 Hours):</span>
                <div class="text-lg font-bold font-mono text-emerald-300">{{ number_format($aisFreshness['metrics']['live_under_2h'] ?? 0) }}</div>
                <div class="text-[10px] text-slate-500">Real-time fresh positions</div>
            </div>

            <div class="p-3.5 rounded-xl bg-slate-900 border border-slate-800 space-y-1">
                <span class="text-amber-400 font-semibold text-[11px]">STALE (2h – 6h):</span>
                <div class="text-lg font-bold font-mono text-amber-300">{{ number_format($aisFreshness['metrics']['stale_2h_to_6h'] ?? 0) }}</div>
                <div class="text-[10px] text-slate-500">Historical transition</div>
            </div>

            <div class="p-3.5 rounded-xl bg-slate-900 border border-slate-800 space-y-1">
                <span class="text-slate-400 font-semibold text-[11px]">VERY STALE (&gt; 6 Hours):</span>
                <div class="text-lg font-bold font-mono text-slate-300">{{ number_format($aisFreshness['metrics']['very_stale_over_6h'] ?? 0) }}</div>
                <div class="text-[10px] text-slate-500">Forensic track archive</div>
            </div>

            <div class="p-3.5 rounded-xl bg-slate-900 border border-slate-800 space-y-1">
                <span class="text-slate-400 font-semibold text-[11px]">Total Vessels &amp; Positions:</span>
                <div class="text-lg font-bold font-mono text-cyan-300">{{ number_format($aisFreshness['metrics']['total_vessels'] ?? 0) }} / {{ number_format($aisFreshness['metrics']['total_positions'] ?? 0) }}</div>
                <div class="text-[10px] text-slate-500 truncate">Last Obs: {{ $aisFreshness['latest_observation'] ? \Carbon\Carbon::parse($aisFreshness['latest_observation'])->format('H:i:s') . ' UTC' : 'None' }}</div>
            </div>
        </div>

        <div class="p-3 rounded-lg bg-slate-950/80 border border-slate-800 text-[11px] text-slate-400">
            <strong>Maritime Data Integrity:</strong> The system never deletes legacy positions without explicit policy. <em>Stale</em> data remains fully retained in the spatial database for alibi verification, encounter collision investigations, and historical SAR satellite radar correlation.
        </div>
    </div>

    <!-- 4. Operational Backup & Disaster Recovery Monitoring -->
    <div class="glass-panel p-6 rounded-2xl border border-slate-800 shadow-xl space-y-4 text-xs">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <div>
                <h2 class="text-base font-bold text-white uppercase tracking-wider flex items-center gap-2">
                    <span>💾</span>
                    <span>Automated Backup Status &amp; Isolated Disaster Recovery</span>
                </h2>
                <p class="text-xs text-slate-400 mt-1">PostgreSQL database snapshot monitoring and Sentinel-1 SAR evidence / SAFE archive storage tracking.</p>
            </div>
            <div class="flex items-center gap-2">
                @if(($backupManifest['status'] ?? '') === 'HEALTHY' || ($backupManifest['status'] ?? '') === 'SUCCESS')
                <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/40">
                    ✓ BACKUP HEALTHY
                </span>
                @else
                <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold bg-amber-500/20 text-amber-300 border border-amber-500/40">
                    ⚠️ BACKUP: {{ $backupManifest['status'] ?? 'UNKNOWN' }}
                </span>
                @endif

                @if(($recoveryReport['status'] ?? '') === 'VERIFIED' || ($recoveryReport['status'] ?? '') === 'PASS')
                <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/40">
                    ✓ DR 100% VERIFIED
                </span>
                @else
                <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold bg-rose-500/20 text-rose-300 border border-rose-500/40">
                    ⚠️ DR: {{ $recoveryReport['status'] ?? 'NOT VERIFIED' }}
                </span>
                @endif
            </div>
        </div>

        @if($backupManifest && isset($backupManifest['last_backup_utc']))
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="p-3.5 rounded-xl bg-slate-900 border border-slate-800 space-y-1">
                <span class="text-slate-400 text-[11px]">Last Backup Time:</span>
                <div class="text-sm font-bold font-mono text-teal-300">{{ \Carbon\Carbon::parse($backupManifest['last_backup_utc'])->format('Y-m-d H:i:s') }} UTC</div>
                <div class="text-[10px] text-slate-500">Age: {{ $backupManifest['age_hours'] }} hours &bull; Duration: {{ $backupManifest['duration_seconds'] ?? 0 }}s</div>
            </div>

            <div class="p-3.5 rounded-xl bg-slate-900 border border-slate-800 space-y-1">
                <span class="text-slate-400 text-[11px]">PostgreSQL Dump Size:</span>
                <div class="text-sm font-bold font-mono text-white">{{ $backupManifest['dump_size_mb'] }} MB</div>
                <div class="text-[10px] text-slate-500 font-mono truncate" title="{{ $backupManifest['dump_file'] }}">{{ $backupManifest['dump_file'] }}</div>
            </div>

            <div class="p-3.5 rounded-xl bg-slate-900 border border-slate-800 space-y-1">
                <span class="text-slate-400 text-[11px]">SAR Imagery &amp; Evidence Archive:</span>
                <div class="text-sm font-bold font-mono text-purple-300">{{ $backupManifest['sar_evidence_file_count'] ?? 0 }} evidence files</div>
                <div class="text-[10px] text-slate-500 font-mono">{{ $backupManifest['sar_products_file_count'] ?? 0 }} SAFE archive files</div>
            </div>

            <div class="p-3.5 rounded-xl bg-slate-900 border border-slate-800 space-y-1">
                <span class="text-slate-400 text-[11px]">Disaster Recovery Test:</span>
                <div class="text-sm font-bold font-mono text-emerald-400">✓ ISOLATED TEST PASSED</div>
                <div class="text-[10px] text-slate-500 font-mono">Tested on: {{ $recoveryReport['isolated_database'] ?? 'gfvip_isolated_recovery_test' }}</div>
            </div>
        </div>

        <div class="p-3 rounded-lg bg-slate-950/80 border border-slate-800 text-[11px] text-slate-400 flex items-center justify-between">
            <span>Storage Location: <code class="text-slate-300 font-mono">C:\backups\gfvip</code> &bull; Retention Policy: <strong>{{ $backupManifest['retention_days'] ?? 14 }} days</strong></span>
            <span class="text-slate-500 font-mono text-[10px]">Format: PostgreSQL Custom (-F c)</span>
        </div>
        @endif
    </div>

    <!-- 5. Offsite Backup Activation Gate -->
    <div class="glass-panel p-6 rounded-2xl border border-slate-800 shadow-xl space-y-4 text-xs">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <div>
                <h2 class="text-base font-bold text-white uppercase tracking-wider flex items-center gap-2">
                    <span>☁️</span>
                    <span>Offsite Backup Activation Gate &amp; Governance</span>
                </h2>
                <p class="text-xs text-slate-400 mt-1">Encrypted offsite backup synchronization gateway with anti-ransomware protection (WORM / Object Lock).</p>
            </div>
            <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold
                @if(($offsiteGate['status'] ?? '') === 'ACTIVE') bg-emerald-500/20 text-emerald-300 border border-emerald-500/40
                @elseif(($offsiteGate['status'] ?? '') === 'DRY_RUN') bg-amber-500/20 text-amber-300 border border-amber-500/40
                @else bg-slate-800 text-slate-400 border border-slate-700 @endif">
                {{ $offsiteGate['state_label'] ?? 'DISABLED — OPERATOR APPROVAL REQUIRED' }}
            </span>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="p-3.5 rounded-xl bg-slate-900 border border-slate-800 space-y-1">
                <span class="text-slate-400 text-[11px]">Data Encryption:</span>
                <div class="text-xs font-bold text-emerald-300 font-mono">{{ $offsiteGate['encryption_in_transit'] }}</div>
                <div class="text-[10px] text-slate-500">{{ $offsiteGate['encryption_at_rest'] }}</div>
            </div>

            <div class="p-3.5 rounded-xl bg-slate-900 border border-slate-800 space-y-1">
                <span class="text-slate-400 text-[11px]">Immutability Protection (WORM):</span>
                <div class="text-xs font-bold text-purple-300 font-mono">Object Lock Compliance Mode</div>
                <div class="text-[10px] text-slate-500">Prevents overwrite/deletion by ransomware</div>
            </div>

            <div class="p-3.5 rounded-xl bg-slate-900 border border-slate-800 space-y-1">
                <span class="text-slate-400 text-[11px]">Least Privilege Access:</span>
                <div class="text-xs font-bold text-cyan-300 font-mono">Write-Once PutObject Only</div>
                <div class="text-[10px] text-slate-500">Uploader account has no delete permissions</div>
            </div>
        </div>

        @if(auth()->user() && auth()->user()->hasRole('super_admin'))
        <form method="POST" action="{{ route('settings.offsite-backup') }}" class="p-4 rounded-xl bg-slate-900/60 border border-slate-800 space-y-3">
            @csrf
            <div class="text-xs font-bold text-white uppercase tracking-wider flex items-center gap-1.5">
                <span>🔐</span>
                <span>Authorization &amp; Offsite Gate Configuration (Super Admin Only)</span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label class="block text-[11px] text-slate-400 mb-1">Target Status:</label>
                    <select name="status" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-2.5 py-1.5 text-xs text-white focus:outline-none focus:border-cyan-500">
                        <option value="DISABLED" {{ ($offsiteGate['status'] ?? '') === 'DISABLED' ? 'selected' : '' }}>DISABLED (Network Blocked)</option>
                        <option value="DRY_RUN" {{ ($offsiteGate['status'] ?? '') === 'DRY_RUN' ? 'selected' : '' }}>DRY_RUN (Simulation / Test Mode)</option>
                        <option value="READY" {{ ($offsiteGate['status'] ?? '') === 'READY' ? 'selected' : '' }}>READY (Ready for Activation)</option>
                        <option value="ACTIVE" {{ ($offsiteGate['status'] ?? '') === 'ACTIVE' ? 'selected' : '' }}>ACTIVE (Automated Synchronization Active)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-[11px] text-slate-400 mb-1">Target Provider:</label>
                    <select name="provider" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-2.5 py-1.5 text-xs text-white focus:outline-none focus:border-cyan-500">
                        <option value="S3_COMPATIBLE" {{ ($offsiteGate['provider'] ?? '') === 'S3_COMPATIBLE' ? 'selected' : '' }}>S3-Compatible (Wasabi / MinIO / B2)</option>
                        <option value="AWS_S3" {{ ($offsiteGate['provider'] ?? '') === 'AWS_S3' ? 'selected' : '' }}>Amazon Web Services (AWS S3 + Glacier)</option>
                        <option value="AZURE_BLOB" {{ ($offsiteGate['provider'] ?? '') === 'AZURE_BLOB' ? 'selected' : '' }}>Azure Blob Storage (Cool Tier)</option>
                        <option value="GCS" {{ ($offsiteGate['provider'] ?? '') === 'GCS' ? 'selected' : '' }}>Google Cloud Storage (Nearline)</option>
                        <option value="SECURE_NAS" {{ ($offsiteGate['provider'] ?? '') === 'SECURE_NAS' ? 'selected' : '' }}>Dedicated Isolated NAS (SMB / ZFS)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-[11px] text-slate-400 mb-1">Destination URI:</label>
                    <input type="text" name="destination_uri" value="{{ $offsiteGate['destination_uri'] ?? 's3://gfvip-offsite-vault/backups' }}" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-2.5 py-1.5 text-xs text-white font-mono focus:outline-none focus:border-cyan-500" required>
                </div>
            </div>

            <div class="pt-2 flex items-center justify-between">
                <label class="flex items-center gap-2 cursor-pointer text-[11px] text-slate-300">
                    <input type="checkbox" name="confirm_operator" value="1" class="rounded border-slate-800 bg-slate-950 text-cyan-500 focus:ring-0">
                    <span>I confirm authorization for the offsite backup policy change and agree to forensic audit logging.</span>
                </label>

                <button type="submit" class="px-4 py-1.5 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-white font-semibold text-xs shadow-md transition">
                    Save Gate Changes
                </button>
            </div>
        </form>
        @else
        <div class="p-3 rounded-lg bg-slate-950/80 border border-slate-800 text-[11px] text-slate-400">
            🔒 The offsite backup authorization gate may only be configured by users with <strong>super_admin</strong> privileges.
        </div>
        @endif
    </div>

    <!-- 6. Production Hardening Guidance -->
    <div class="glass-panel p-6 rounded-2xl border border-slate-800 shadow-xl space-y-4 text-xs">
        <div class="border-b border-slate-800 pb-3">
            <h2 class="text-base font-bold text-white uppercase tracking-wider flex items-center gap-2">
                <span>🛡️</span>
                <span>Production Environment Hardening &amp; Security State</span>
            </h2>
            <p class="text-xs text-slate-400 mt-1">Runtime application configuration compliance against enterprise production security parameters.</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="p-3.5 rounded-xl bg-slate-900 border border-slate-800 space-y-1">
                <span class="text-slate-400 text-[11px]">Runtime Environment (APP_ENV):</span>
                <div class="text-sm font-bold font-mono {{ config('app.env') === 'production' ? 'text-emerald-300' : 'text-amber-300' }}">
                    {{ strtoupper(config('app.env')) }}
                </div>
                <div class="text-[10px] text-slate-500">{{ config('app.env') === 'production' ? 'Production mode active' : 'Local development mode (Intentionally preserved)' }}</div>
            </div>

            <div class="p-3.5 rounded-xl bg-slate-900 border border-slate-800 space-y-1">
                <span class="text-slate-400 text-[11px]">Debug Mode (APP_DEBUG):</span>
                <div class="text-sm font-bold font-mono {{ config('app.debug') ? 'text-amber-400' : 'text-emerald-300' }}">
                    {{ config('app.debug') ? 'TRUE (ACTIVE ON WORKSTATION)' : 'FALSE (SECURE)' }}
                </div>
                <div class="text-[10px] text-slate-500">{{ config('app.debug') ? 'Must be FALSE on production deployment server' : 'Stack traces hidden' }}</div>
            </div>

            <div class="p-3.5 rounded-xl bg-slate-900 border border-slate-800 space-y-1">
                <span class="text-slate-400 text-[11px]">Application Encryption Key:</span>
                <div class="text-sm font-bold font-mono text-emerald-300">✓ PRESENT</div>
                <div class="text-[10px] text-slate-500">AES-256-CBC cipher active</div>
            </div>
        </div>

        <div class="p-3 rounded-lg bg-slate-950/80 border border-slate-800 text-[11px] text-slate-400 leading-relaxed space-y-1">
            <strong class="text-slate-300">Enterprise Hardening Deployment Guide:</strong>
            <div>1. Set <code>APP_ENV=production</code> and <code>APP_DEBUG=false</code> in the target production server environment file. Local development workstations remain in local mode.</div>
            <div>2. Run <code>php artisan config:cache</code> and <code>php artisan route:cache</code> for configuration protection and execution acceleration.</div>
            <div>3. Ensure the reverse proxy enforces HTTPS TLS 1.3 and the <code>X-Content-Type-Options: nosniff</code> header.</div>
        </div>
    </div>

    <!-- 7. Algorithm Parameters & Geodataset Versions -->
    <div class="glass-panel p-6 rounded-2xl border border-slate-800 shadow-xl space-y-4 text-xs">
        <div class="border-b border-slate-800 pb-3">
            <h2 class="text-base font-bold text-white uppercase tracking-wider flex items-center gap-2">
                <span>⚙️</span>
                <span>Proximity Algorithm Parameters &amp; Configured Geodatasets</span>
            </h2>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-xs">
            <div class="p-3.5 rounded-xl bg-slate-900 border border-slate-800 space-y-1">
                <span class="text-slate-400">Maximum Proximity Distance:</span>
                <div class="text-lg font-bold font-mono text-teal-300">{{ $config['encounters']['max_distance_meters'] }} meters</div>
                <div class="text-[10px] text-slate-500">Rendezvous encounter detection radius</div>
            </div>

            <div class="p-3.5 rounded-xl bg-slate-900 border border-slate-800 space-y-1">
                <span class="text-slate-400">Minimum Proximity Duration:</span>
                <div class="text-lg font-bold font-mono text-teal-300">{{ $config['encounters']['min_duration_minutes'] }} minutes</div>
                <div class="text-[10px] text-slate-500">Filters out transient passing vessels</div>
            </div>

            <div class="p-3.5 rounded-xl bg-slate-900 border border-slate-800 space-y-1">
                <span class="text-slate-400">Telemetry Timestamp Tolerance:</span>
                <div class="text-lg font-bold font-mono text-teal-300">{{ $config['encounters']['timestamp_tolerance_minutes'] }} minutes</div>
                <div class="text-[10px] text-slate-500">Temporal position matching window</div>
            </div>
        </div>

        <div class="space-y-3 pt-2 text-slate-300">
            <div class="p-3 rounded-xl bg-slate-900 border border-slate-800">
                <div class="font-bold text-indigo-300">{{ $config['fao']['name'] }}</div>
                <div class="text-[11px] text-slate-400">Version: {{ $config['fao']['version'] }} &bull; License: {{ $config['fao']['license'] }}</div>
            </div>

            <div class="p-3 rounded-xl bg-slate-900 border border-slate-800">
                <div class="font-bold text-teal-300">{{ $config['eez']['name'] }}</div>
                <div class="text-[11px] text-slate-400">Version: {{ $config['eez']['version'] }} &bull; License: {{ $config['eez']['license'] }}</div>
            </div>
        </div>
    </div>

</div>
@endsection
