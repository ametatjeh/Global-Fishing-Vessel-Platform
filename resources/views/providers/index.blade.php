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
                        @if($provider->health_status === 'receiving_data' || $provider->health_status === 'streaming')
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/40">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span> Streaming Live Data
                            </span>
                        @elseif($provider->health_status === 'authenticated')
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold bg-sky-500/20 text-sky-300 border border-sky-500/40">
                                <span class="w-1.5 h-1.5 rounded-full bg-sky-400"></span> Authenticated
                            </span>
                        @elseif($provider->health_status === 'research_access_required')
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold bg-amber-500/20 text-amber-300 border border-amber-500/40" title="Token valid di gateway GFW, namun endpoint /events memerlukan izin Research Access">
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
                <div class="p-2.5 rounded-lg {{ $provider->health_status === 'research_access_required' ? 'bg-amber-950/40 border border-amber-500/30 text-amber-300' : 'bg-rose-950/40 border border-rose-500/30 text-rose-300' }} text-[11px]">
                    <strong>Catatan Diagnostik:</strong> {{ $provider->last_error_message }}
                </div>
                @endif

                @if($provider->slug === 'global-fishing-watch' && $provider->health_status === 'research_access_required')
                <div class="p-3.5 rounded-xl bg-amber-950/40 border border-amber-500/40 text-amber-200 text-xs space-y-2">
                    <div class="flex items-center gap-2 font-bold text-amber-300">
                        <svg class="w-4 h-4 text-amber-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                        </svg>
                        <span>Panduan Akses GFW: Permohonan Research Access Diperlukan</span>
                    </div>
                    <p class="text-[11px] text-amber-300/90 leading-relaxed">
                        Token API GFW Anda valid dan terotentikasi pada gateway publik (katalog <code>/datasets</code> berhasil diakses), namun endpoint <code>/events</code> (deteksi encounter & loitering kapal perikanan) memerlukan hak akses tingkat <strong>Research Access</strong> dari Global Fishing Watch.
                    </p>
                    <div class="flex flex-wrap items-center gap-3 pt-1">
                        <a href="https://globalfishingwatch.org/our-apis/tokens" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-amber-600 hover:bg-amber-500 text-white font-semibold text-[11px] transition shadow">
                            <span>Ajukan Izin Research Access di GFW Portal</span>
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                        </a>
                        <span class="text-[10px] text-amber-400/80">
                            * Modul AIS Live Stream, Garis Batas ZEE Marine Regions, FAO Major Areas, dan Citra SAR Copernicus tetap beroperasi normal secara independen.
                        </span>
                    </div>
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
