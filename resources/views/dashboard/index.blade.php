@extends('layouts.app')

@section('header_title', 'Overview Dashboard — Intelijen Maritim Global')

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
                <p class="text-xs sm:text-sm text-slate-300 mt-1 max-w-2xl leading-relaxed">
                    Sistem pemantauan armada perikanan global dengan tiga pilar operasional: <strong>Live Vessel Tracking</strong>, <strong>Fishing Compliance</strong>, dan <strong>Dark Vessel Detection</strong> berbasis fusi telemetri AIS, citra radar satelit SAR, serta batas yurisdiksi FAO dan EEZ.
                </p>
            </div>

            <div class="flex items-center gap-3 shrink-0">
                <a href="{{ route('map.index') }}" class="px-4 py-2.5 rounded-xl bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold text-xs uppercase tracking-wider transition shadow-lg shadow-teal-500/20 flex items-center gap-2">
                    <span>🗺️</span>
                    <span>Buka Peta Global</span>
                </a>
            </div>
        </div>
    </div>

    <!-- Authentic Key Metrics (Non-Fictitious) -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4">
        <!-- Metric 1: Total Vessels -->
        <div class="glass-panel p-4 rounded-xl border border-slate-800 space-y-2">
            <div class="flex items-center justify-between text-slate-400 text-xs">
                <span class="font-semibold uppercase tracking-wider text-[10px]">Total Terdaftar</span>
                <span>🚢</span>
            </div>
            <div class="text-2xl font-black text-white font-mono">{{ number_format($totalVessels) }}</div>
            <div class="text-[10px] text-slate-400">Armada pada registry sistem</div>
        </div>

        <!-- Metric 2: Active Positions (24h) -->
        <div class="glass-panel p-4 rounded-xl border border-slate-800 space-y-2">
            <div class="flex items-center justify-between text-slate-400 text-xs">
                <span class="font-semibold uppercase tracking-wider text-[10px]">Posisi Aktif 24 Jam</span>
                <span>📡</span>
            </div>
            <div class="text-2xl font-black text-teal-300 font-mono">{{ number_format($activeVesselsCount) }}</div>
            <div class="text-[10px] text-slate-400">Kapal dengan broadcast terkini</div>
        </div>

        <!-- Metric 3: Encounters Needing Review -->
        <div class="glass-panel p-4 rounded-xl border border-slate-800 space-y-2">
            <div class="flex items-center justify-between text-slate-400 text-xs">
                <span class="font-semibold uppercase tracking-wider text-[10px]">Encounter Review</span>
                <span>🤝</span>
            </div>
            <div class="text-2xl font-black text-amber-300 font-mono">{{ number_format($encountersNeedingReview) }}</div>
            <div class="text-[10px] text-slate-400">Perlu telaah analis intelijen</div>
        </div>

        <!-- Metric 4: Dark Vessel Candidates -->
        <div class="glass-panel p-4 rounded-xl border border-slate-800 space-y-2">
            <div class="flex items-center justify-between text-slate-400 text-xs">
                <span class="font-semibold uppercase tracking-wider text-[10px]">Kandidat Dark Vessel</span>
                <span>🛰️</span>
            </div>
            <div class="text-2xl font-black text-rose-400 font-mono">{{ number_format($darkVesselCandidates) }}</div>
            <div class="text-[10px] text-slate-400">Deteksi SAR tanpa AIS aktif</div>
        </div>

        <!-- Metric 5: Open Compliance Alerts -->
        <div class="glass-panel p-4 rounded-xl border border-slate-800 space-y-2">
            <div class="flex items-center justify-between text-slate-400 text-xs">
                <span class="font-semibold uppercase tracking-wider text-[10px]">Compliance Alerts</span>
                <span>⚖️</span>
            </div>
            <div class="text-2xl font-black text-orange-400 font-mono">{{ number_format($openComplianceAlerts) }}</div>
            <div class="text-[10px] text-slate-400">Indikasi pelanggaran terbuka</div>
        </div>

        <!-- Metric 6: Open Investigations -->
        <div class="glass-panel p-4 rounded-xl border border-slate-800 space-y-2">
            <div class="flex items-center justify-between text-slate-400 text-xs">
                <span class="font-semibold uppercase tracking-wider text-[10px]">Berkas Investigasi</span>
                <span>📁</span>
            </div>
            <div class="text-2xl font-black text-indigo-300 font-mono">{{ number_format($openInvestigations) }}</div>
            <div class="text-[10px] text-slate-400">Dossier dalam penanganan</div>
        </div>
    </div>

    <!-- Data Freshness & Data Providers Status -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Telemetry Freshness & Audit Provenance -->
        <div class="glass-panel p-5 rounded-xl border border-slate-800 space-y-4">
            <div class="flex items-center justify-between border-b border-slate-800 pb-2">
                <h3 class="text-xs font-bold uppercase tracking-wider text-white flex items-center gap-2">
                    <span>⏱️</span>
                    <span>Integritas & Kesegaran Data</span>
                </h3>
                <span class="text-[10px] text-slate-400 font-mono">Real-time Tracker</span>
            </div>

            <div class="space-y-3 text-xs">
                <div class="flex justify-between items-center p-2.5 rounded-lg bg-slate-900/80 border border-slate-800">
                    <span class="text-slate-400">Telemetri Terakhir Diterima:</span>
                    <span class="text-teal-300 font-mono font-semibold">{{ $latestTelemetryAge }}</span>
                </div>

                <div class="flex justify-between items-center p-2.5 rounded-lg bg-slate-900/80 border border-slate-800">
                    <span class="text-slate-400">Kebijakan Fusi Data:</span>
                    <span class="text-slate-200 font-medium">Multi-source Evidence</span>
                </div>

                <div class="p-3 rounded-lg bg-slate-900/90 border border-slate-800 text-[11px] text-slate-400 space-y-1.5 leading-relaxed">
                    <div class="font-semibold text-slate-300 flex items-center gap-1">
                        <span>🛡️</span> Prinsip Anti-Data Dummy
                    </div>
                    <p>
                        Sistem tidak menyimulasikan pergerakan kapal palsu atau angka metrik artifisial. Jika sumber data eksternal belum dikonfigurasi, sistem menampilkan status transparan <code>Not Configured</code> atau nilai 0.
                    </p>
                </div>
            </div>
        </div>

        <!-- Providers Health Matrix -->
        <div class="lg:col-span-2 glass-panel p-5 rounded-xl border border-slate-800 space-y-4">
            <div class="flex items-center justify-between border-b border-slate-800 pb-2">
                <h3 class="text-xs font-bold uppercase tracking-wider text-white flex items-center gap-2">
                    <span>🔌</span>
                    <span>Status Penyedia Data & Lisensi</span>
                </h3>
                <a href="{{ route('providers.index') }}" class="text-[11px] text-teal-400 hover:text-teal-300 font-semibold underline">
                    Kelola Provider &rarr;
                </a>
            </div>

            <div class="overflow-x-auto text-xs responsive-table-container">
                <table class="w-full text-left min-w-[540px]">
                    <thead>
                        <tr class="text-[10px] text-slate-400 uppercase tracking-wider border-b border-slate-800">
                            <th class="pb-2">Penyedia Data</th>
                            <th class="pb-2">Tipe Ingest</th>
                            <th class="pb-2">Status Operasional</th>
                            <th class="pb-2">Sinkronisasi</th>
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
                                {{ $provider->last_sync_at ? $provider->last_sync_at->diffForHumans() : 'Belum pernah' }}
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="4" class="py-4 text-center text-slate-500">Tidak ada provider terdaftar.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Recent Feeds: Encounters & Compliance Alerts -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        <!-- Recent Encounters -->
        <div class="glass-panel p-5 rounded-xl border border-slate-800 space-y-4">
            <div class="flex items-center justify-between border-b border-slate-800 pb-2">
                <h3 class="text-xs font-bold uppercase tracking-wider text-amber-300 flex items-center gap-2">
                    <span>🤝</span>
                    <span>Proximity Encounters Terkini</span>
                </h3>
                <a href="{{ route('encounters.index') }}" class="text-[11px] text-cyan-400 hover:text-cyan-300 font-semibold underline">
                    Semua Encounters &rarr;
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
                            {{ $enc->vessel1?->name ?? 'Kapal A' }} &bull; {{ $enc->vessel2?->name ?? 'Kapal B' }}
                        </div>
                        <div class="text-[10px] text-slate-500 mt-0.5 font-mono">
                            Jarak: {{ $enc->min_distance_meters }}m &bull; Durasi: {{ $enc->duration_minutes }}m &bull; {{ $enc->start_time->diffForHumans() }}
                        </div>
                    </div>
                    <div class="text-right shrink-0">
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase font-mono
                            {{ $enc->status === 'verified' ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30' : ($enc->status === 'rejected' ? 'bg-rose-500/20 text-rose-300 border border-rose-500/30' : 'bg-amber-500/20 text-amber-300 border border-amber-500/30') }}">
                            {{ $enc->status }}
                        </span>
                        <div class="mt-1">
                            <a href="{{ route('encounters.show', $enc->id) }}" class="text-[11px] text-cyan-400 hover:underline">Detail</a>
                        </div>
                    </div>
                </div>
                @empty
                <div class="py-8 text-center text-slate-500">
                    <span class="text-3xl block mb-1">🌊</span>
                    Belum ada rekaman encounter yang terdeteksi dari data AIS saat ini.
                </div>
                @endforelse
            </div>
        </div>

        <!-- Recent Compliance Alerts -->
        <div class="glass-panel p-5 rounded-xl border border-slate-800 space-y-4">
            <div class="flex items-center justify-between border-b border-slate-800 pb-2">
                <h3 class="text-xs font-bold uppercase tracking-wider text-rose-400 flex items-center gap-2">
                    <span>⚖️</span>
                    <span>Compliance & Rule Alerts</span>
                </h3>
                <a href="{{ route('compliance.index') }}" class="text-[11px] text-cyan-400 hover:text-cyan-300 font-semibold underline">
                    Semua Alerts &rarr;
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
                            {{ $alert->rule?->rule_name ?? 'Rule Violation' }} &bull; <strong class="text-white">{{ $alert->vessel?->name ?? 'Kapal' }}</strong>
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
                            <a href="{{ route('compliance.show', $alert->id) }}" class="text-[11px] text-cyan-400 hover:underline">Periksa</a>
                        </div>
                    </div>
                </div>
                @empty
                <div class="py-8 text-center text-slate-500">
                    <span class="text-3xl block mb-1">✅</span>
                    Tidak ada peringatan kepatuhan yang berstatus open pada sistem saat ini.
                </div>
                @endforelse
            </div>
        </div>

    </div>

</div>
@endsection
