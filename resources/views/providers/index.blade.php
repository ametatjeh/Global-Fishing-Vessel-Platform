@extends('layouts.app')

@section('header_title', 'Data Sources & Providers — Konfigurasi, Health Check & Lisensi')

@section('content')
<div class="space-y-6">

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold text-white tracking-wide uppercase">Data Sources & Provider Catalog</h2>
            <p class="text-xs text-slate-400 mt-1">
                Matriks integrasi provider eksternal: Global Fishing Watch, Copernicus Sentinel-1, AIS Live Telemetry, FAO, dan Marine Regions EEZ.
            </p>
        </div>
    </div>

    <!-- Providers Table -->
    <div class="glass-panel rounded-2xl border border-slate-800 overflow-hidden shadow-xl p-5 space-y-4">
        <h3 class="text-xs font-bold uppercase tracking-wider text-teal-300 border-b border-slate-800 pb-2">
            Status Operasional & Hak Penggunaan Provider
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
                        @if($provider->health_status === 'receiving_data')
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/40">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span> Receiving Live Data
                            </span>
                        @elseif($provider->health_status === 'authenticated')
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold bg-sky-500/20 text-sky-300 border border-sky-500/40">
                                <span class="w-1.5 h-1.5 rounded-full bg-sky-400"></span> Authenticated
                            </span>
                        @elseif($provider->health_status === 'reachable')
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold bg-amber-500/20 text-amber-300 border border-amber-500/40">
                                <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span> Reachable (Idle)
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
                            <button type="submit" class="px-3 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-teal-300 border border-slate-700 font-semibold transition" title="Jalankan Health Check">
                                Test Health
                            </button>
                        </form>
                    </div>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-[11px]">
                    <div>
                        <span class="text-slate-400">Lisensi Data:</span>
                        <div class="font-semibold text-slate-200">{{ $provider->license_type ?? 'Proprietary' }}</div>
                    </div>
                    <div>
                        <span class="text-slate-400">Hak Komersial:</span>
                        <div>
                            @if($provider->commercial_use_allowed)
                                <span class="text-teal-400 font-bold">Diizinkan</span>
                            @else
                                <span class="text-rose-400 font-bold">Non-Komersial Saja</span>
                            @endif
                        </div>
                    </div>
                    <div>
                        <span class="text-slate-400">Hak Retensi & Redistribusi:</span>
                        <div class="text-slate-300">
                            {{ $provider->retention_allowed ? 'Retensi: Ya' : 'Retensi: Tidak' }} &bull;
                            {{ $provider->redistribution_allowed ? 'Redistribusi: Ya' : 'Redistribusi: Terbatas' }}
                        </div>
                    </div>
                    <div>
                        <span class="text-slate-400">Sinkronisasi Terakhir:</span>
                        <div class="font-mono text-slate-300">
                            {{ $provider->last_sync_at ? $provider->last_sync_at->diffForHumans() : 'Belum pernah' }}
                        </div>
                    </div>
                </div>

                @if($provider->last_error_message)
                <div class="p-2.5 rounded-lg bg-rose-950/40 border border-rose-500/30 text-rose-300 text-[11px]">
                    <strong>Catatan Diagnostik:</strong> {{ $provider->last_error_message }}
                </div>
                @endif
            </div>
            @empty
            <div class="text-center py-6 text-slate-500">Tidak ada provider terdaftar.</div>
            @endforelse
        </div>
    </div>

    <!-- Ingestion Runs Audit History -->
    <div class="glass-panel rounded-2xl border border-slate-800 overflow-hidden shadow-xl p-5 space-y-4">
        <h3 class="text-xs font-bold uppercase tracking-wider text-white border-b border-slate-800 pb-2">
            Riwayat Eksekusi Sinkronisasi & Ingestion Runs
        </h3>

        <div class="overflow-x-auto text-xs">
            <table class="w-full text-left">
                <thead class="text-slate-400 uppercase text-[10px] border-b border-slate-800">
                    <tr>
                        <th class="py-2.5">Provider</th>
                        <th class="py-2.5">Tipe Pekerjaan</th>
                        <th class="py-2.5">Status</th>
                        <th class="py-2.5">Fetched / Inserted / Skipped</th>
                        <th class="py-2.5">Mulai Ingestion</th>
                        <th class="py-2.5">Selesai</th>
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
                        <td colspan="6" class="py-6 text-center text-slate-500">Belum ada riwayat proses sinkronisasi yang dijalankan.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection
