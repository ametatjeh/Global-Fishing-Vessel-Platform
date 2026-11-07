@extends('layouts.app')

@section('header_title', 'Vessel Encounters — Deteksi Kedekatan Pasangan Kapal')

@section('content')
<div class="space-y-6">

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <h2 class="text-xl font-bold text-white tracking-wide uppercase">Vessel Encounter Events</h2>
                <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold tracking-wider uppercase bg-teal-500/20 text-teal-300 border border-teal-500/40">
                    NON-COMMERCIAL RESEARCH
                </span>
            </div>
            <p class="text-xs text-slate-400 mt-1">
                Deteksi kedekatan temporal-spasial antara pasangan kapal (indikasi rendezvous penangkapan, support, bunker, atau carrier).
            </p>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('map.index') }}" class="px-4 py-2 rounded-xl bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold text-xs transition shadow-lg shadow-teal-500/20">
                Peta Layer Encounter
            </a>
        </div>
    </div>

    <!-- Data Attribution & Non-Commercial Disclaimer -->
    <div class="glass-panel p-4 rounded-xl border border-slate-800 text-xs text-slate-300 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div class="space-y-0.5">
            <div class="text-[11px] text-slate-400">
                Sumber Data: <a href="https://globalfishingwatch.org" target="_blank" rel="noopener noreferrer" class="text-teal-400 hover:underline font-semibold">Global Fishing Watch (GFW)</a> &amp; Observasi Native AIS
            </div>
            <div class="text-[11px] text-amber-300/90 italic">
                Pemberitahuan: Aktivitas kapal yang ditampilkan merupakan estimasi atau hasil analisis berdasarkan sumber data, bukan bukti final pelanggaran.
            </div>
        </div>
        <div class="text-[10px] text-slate-500 font-mono shrink-0">
            Pembaruan: {{ now()->format('Y-m-d H:i') }} UTC
        </div>
    </div>

    <!-- Status Badges Summary -->
    <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
        <a href="{{ route('encounters.index') }}" class="glass-panel p-3 rounded-xl border border-slate-800 text-center hover:border-slate-700 transition">
            <div class="text-[10px] uppercase font-bold text-slate-400">Total</div>
            <div class="text-xl font-black text-white font-mono mt-0.5">{{ number_format($stats['total']) }}</div>
        </a>
        <a href="{{ route('encounters.index', ['status' => 'candidate']) }}" class="glass-panel p-3 rounded-xl border border-slate-800 text-center hover:border-amber-500/50 transition">
            <div class="text-[10px] uppercase font-bold text-amber-400">Candidate</div>
            <div class="text-xl font-black text-amber-300 font-mono mt-0.5">{{ number_format($stats['candidate']) }}</div>
        </a>
        <a href="{{ route('encounters.index', ['status' => 'needs_review']) }}" class="glass-panel p-3 rounded-xl border border-slate-800 text-center hover:border-sky-500/50 transition">
            <div class="text-[10px] uppercase font-bold text-sky-400">Needs Review</div>
            <div class="text-xl font-black text-sky-300 font-mono mt-0.5">{{ number_format($stats['needs_review']) }}</div>
        </a>
        <a href="{{ route('encounters.index', ['status' => 'verified']) }}" class="glass-panel p-3 rounded-xl border border-slate-800 text-center hover:border-emerald-500/50 transition">
            <div class="text-[10px] uppercase font-bold text-emerald-400">Verified</div>
            <div class="text-xl font-black text-emerald-300 font-mono mt-0.5">{{ number_format($stats['verified']) }}</div>
        </a>
        <a href="{{ route('encounters.index', ['status' => 'rejected']) }}" class="glass-panel p-3 rounded-xl border border-slate-800 text-center hover:border-rose-500/50 transition">
            <div class="text-[10px] uppercase font-bold text-rose-400">Rejected</div>
            <div class="text-xl font-black text-rose-300 font-mono mt-0.5">{{ number_format($stats['rejected']) }}</div>
        </a>
    </div>

    <!-- Encounters Table -->
    <div class="glass-panel rounded-2xl border border-slate-800 overflow-hidden shadow-xl">
        <div class="overflow-x-auto text-xs responsive-table-container">
            <table class="w-full text-left min-w-[800px]">
                <thead class="bg-slate-900/90 text-slate-400 uppercase tracking-wider text-[10px] border-b border-slate-800">
                    <tr>
                        <th class="py-3 px-4">Kode Kejadian</th>
                        <th class="py-3 px-4">Pasangan Kapal</th>
                        <th class="py-3 px-4">Kategori Encounter</th>
                        <th class="py-3 px-4">Metode Deteksi</th>
                        <th class="py-3 px-4">Jarak Minimum</th>
                        <th class="py-3 px-4">Durasi</th>
                        <th class="py-3 px-4">Status Review</th>
                        <th class="py-3 px-4 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60">
                    @forelse($encounters as $enc)
                    <tr class="hover:bg-slate-900/40 transition">
                        <td class="py-3 px-4">
                            <span class="font-bold text-amber-300 font-mono">{{ $enc->encounter_code }}</span>
                            <div class="text-[10px] text-slate-500 font-mono">{{ $enc->start_time->format('Y-m-d H:i') }} UTC</div>
                        </td>
                        <td class="py-3 px-4">
                            <div class="font-semibold text-white">{{ $enc->vessel1?->name ?? 'Kapal 1' }}</div>
                            <div class="font-semibold text-slate-300 mt-0.5">&bull; {{ $enc->vessel2?->name ?? 'Kapal 2' }}</div>
                        </td>
                        <td class="py-3 px-4">
                            <span class="px-2 py-0.5 rounded text-[10px] font-mono uppercase bg-slate-800 border border-slate-700 text-slate-300">
                                {{ $enc->category }}
                            </span>
                        </td>
                        <td class="py-3 px-4 text-slate-400 font-mono text-[11px]">
                            {{ $enc->detection_method === 'native_ais' ? 'Algoritma Native' : 'GFW Events v3' }}
                        </td>
                        <td class="py-3 px-4 font-mono text-amber-300">
                            {{ $enc->min_distance_meters }} m
                        </td>
                        <td class="py-3 px-4 font-mono text-slate-300">
                            {{ $enc->duration_minutes }} menit
                        </td>
                        <td class="py-3 px-4">
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase font-mono
                                {{ $enc->status === 'verified' ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/40' : ($enc->status === 'rejected' ? 'bg-rose-500/20 text-rose-300 border border-rose-500/40' : 'bg-amber-500/20 text-amber-300 border border-amber-500/40') }}">
                                {{ $enc->status }}
                            </span>
                        </td>
                        <td class="py-3 px-4 text-right">
                            <a href="{{ route('encounters.show', $enc->id) }}" class="px-2.5 py-1 rounded bg-slate-800 hover:bg-slate-700 text-teal-300 border border-slate-700 font-semibold transition">
                                Berkas
                            </a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="8" class="py-12 text-center text-slate-500">
                            <span class="text-4xl block mb-2">🤝</span>
                            Belum ada kejadian encounter yang terdeteksi dari data AIS saat ini.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($encounters->hasPages())
        <div class="p-4 border-t border-slate-800 bg-slate-900/40">
            {{ $encounters->links() }}
        </div>
        @endif
    </div>

</div>
@endsection
