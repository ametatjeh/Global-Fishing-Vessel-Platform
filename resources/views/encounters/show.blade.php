@extends('layouts.app')

@section('header_title', 'Detail Encounter — ' . $encounter->encounter_code)

@section('content')
<div class="space-y-6">

    <div class="glass-panel p-6 rounded-2xl border border-slate-800 shadow-xl flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-3">
                <span class="text-3xl">🤝</span>
                <div>
                    <h2 class="text-2xl font-black text-white tracking-wide flex items-center gap-2">
                        <span>{{ $encounter->encounter_code }}</span>
                        <span class="px-2.5 py-0.5 rounded text-xs font-mono uppercase bg-slate-800 text-amber-300 border border-slate-700">
                            {{ $encounter->category }}
                        </span>
                    </h2>
                    <div class="text-xs text-slate-400 mt-1 font-mono">
                        Metode: {{ $encounter->detection_method }} &bull; Versi Algoritma: {{ $encounter->algorithm_version }}
                    </div>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('encounters.index') }}" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-medium text-xs transition border border-slate-700">
                &larr; Kembali ke Daftar
            </a>
            <a href="{{ route('map.index') }}" class="px-4 py-2 rounded-xl bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold text-xs transition shadow-lg shadow-teal-500/20">
                Lihat di Peta
            </a>
        </div>
    </div>

    <!-- Legal Disclaimer Notice -->
    <div class="p-4 rounded-xl bg-amber-950/40 border border-amber-500/30 text-amber-200 text-xs flex items-start gap-2.5">
        <span class="text-base shrink-0">⚠️</span>
        <div>
            <div class="font-bold text-amber-300">Pemberitahuan Disposisi Intelijen:</div>
            <div class="text-[11px] text-slate-300 leading-relaxed mt-0.5">
                {{ $encounter->disclaimer }}
            </div>
        </div>
    </div>

    <!-- Pair Comparison Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

        <!-- Vessel 1 Card -->
        <div class="glass-panel p-5 rounded-xl border border-slate-800 space-y-3">
            <h3 class="text-xs font-bold uppercase tracking-wider text-teal-400 border-b border-slate-800 pb-2 flex items-center justify-between">
                <span>🚢 Kapal Terlibat 1</span>
                <span class="font-mono text-[10px] uppercase text-slate-400">{{ $encounter->vessel1?->flag_state ?? 'UN' }}</span>
            </h3>

            <div class="space-y-2 text-xs">
                <div class="text-sm font-bold text-white">{{ $encounter->vessel1?->name }}</div>
                <div class="grid grid-cols-2 gap-2 text-slate-400 text-[11px]">
                    <div>MMSI: <span class="text-teal-300 font-mono">{{ $encounter->vessel1?->mmsi ?? '-' }}</span></div>
                    <div>IMO: <span class="text-slate-200 font-mono">{{ $encounter->vessel1?->imo ?? '-' }}</span></div>
                    <div>Tipe: <span class="text-slate-200 capitalize">{{ $encounter->vessel1?->vessel_type }}</span></div>
                    <div>Callsign: <span class="text-slate-200 font-mono">{{ $encounter->vessel1?->callsign ?? '-' }}</span></div>
                </div>
                <div class="pt-2 text-right">
                    <a href="{{ route('vessels.show', $encounter->vessel1_id) }}" class="text-cyan-400 hover:underline text-[11px]">Buka Profil Kapal &rarr;</a>
                </div>
            </div>
        </div>

        <!-- Vessel 2 Card -->
        <div class="glass-panel p-5 rounded-xl border border-slate-800 space-y-3">
            <h3 class="text-xs font-bold uppercase tracking-wider text-teal-400 border-b border-slate-800 pb-2 flex items-center justify-between">
                <span>🚢 Kapal Terlibat 2</span>
                <span class="font-mono text-[10px] uppercase text-slate-400">{{ $encounter->vessel2?->flag_state ?? 'UN' }}</span>
            </h3>

            <div class="space-y-2 text-xs">
                <div class="text-sm font-bold text-white">{{ $encounter->vessel2?->name }}</div>
                <div class="grid grid-cols-2 gap-2 text-slate-400 text-[11px]">
                    <div>MMSI: <span class="text-teal-300 font-mono">{{ $encounter->vessel2?->mmsi ?? '-' }}</span></div>
                    <div>IMO: <span class="text-slate-200 font-mono">{{ $encounter->vessel2?->imo ?? '-' }}</span></div>
                    <div>Tipe: <span class="text-slate-200 capitalize">{{ $encounter->vessel2?->vessel_type }}</span></div>
                    <div>Callsign: <span class="text-slate-200 font-mono">{{ $encounter->vessel2?->callsign ?? '-' }}</span></div>
                </div>
                <div class="pt-2 text-right">
                    <a href="{{ route('vessels.show', $encounter->vessel2_id) }}" class="text-cyan-400 hover:underline text-[11px]">Buka Profil Kapal &rarr;</a>
                </div>
            </div>
        </div>

    </div>

    <!-- Parameters & Analyst Verification Workflow -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        <!-- Metrics & Temporal Window -->
        <div class="glass-panel p-5 rounded-xl border border-slate-800 space-y-3 text-xs">
            <h3 class="font-bold uppercase tracking-wider text-white border-b border-slate-800 pb-2">
                Parameter & Geometri Kejadian
            </h3>

            <div class="space-y-2">
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Jarak Minimum (Proximity):</span>
                    <span class="font-mono font-bold text-amber-300">{{ $encounter->min_distance_meters }} meter</span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Jarak Median:</span>
                    <span class="font-mono text-slate-200">{{ $encounter->median_distance_meters ? $encounter->median_distance_meters . ' meter' : 'N/A' }}</span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Durasi Kedekatan:</span>
                    <span class="font-mono font-bold text-white">{{ $encounter->duration_minutes }} menit</span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Rentang Waktu:</span>
                    <span class="font-mono text-slate-300">{{ $encounter->start_time->format('Y-m-d H:i') }} - {{ $encounter->end_time->format('H:i') }} UTC</span>
                </div>
                <div class="flex justify-between py-1">
                    <span class="text-slate-400">Titik Pusat Koordinat:</span>
                    <span class="font-mono text-teal-300">{{ $encounter->latitude }}&deg;, {{ $encounter->longitude }}&deg;</span>
                </div>
            </div>
        </div>

        <!-- Verification Form -->
        <div class="glass-panel p-5 rounded-xl border border-slate-800 space-y-3 text-xs">
            <h3 class="font-bold uppercase tracking-wider text-white border-b border-slate-800 pb-2">
                Disposisi Status Analis
            </h3>

            <form action="{{ route('encounters.update-status', $encounter->id) }}" method="POST" class="space-y-3">
                @csrf

                <div>
                    <label for="status" class="block font-semibold text-slate-300 mb-1">Status Verifikasi:</label>
                    <select name="status" id="status" class="w-full px-3 py-2 rounded-xl bg-slate-900 border border-slate-700 text-slate-200 focus:outline-none focus:border-teal-400">
                        <option value="candidate" {{ $encounter->status === 'candidate' ? 'selected' : '' }}>Candidate (Kandidat Awal)</option>
                        <option value="needs_review" {{ $encounter->status === 'needs_review' ? 'selected' : '' }}>Needs Review (Perlu Telaah Mendalam)</option>
                        <option value="verified" {{ $encounter->status === 'verified' ? 'selected' : '' }}>Verified (Terverifikasi oleh Analis)</option>
                        <option value="rejected" {{ $encounter->status === 'rejected' ? 'selected' : '' }}>Rejected (Bukan Pelanggaran / False Positive)</option>
                    </select>
                </div>

                <div>
                    <label for="analyst_notes" class="block font-semibold text-slate-300 mb-1">Catatan Analis Intelijen:</label>
                    <textarea name="analyst_notes" id="analyst_notes" rows="3" class="w-full px-3 py-2 rounded-xl bg-slate-900 border border-slate-700 text-slate-200 placeholder-slate-500 focus:outline-none focus:border-teal-400 text-xs">{{ old('analyst_notes', $encounter->analyst_notes) }}</textarea>
                </div>

                <button type="submit" class="w-full py-2.5 rounded-xl bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold transition shadow-md shadow-teal-500/20">
                    Simpan Disposisi Analis
                </button>
            </form>
        </div>

    </div>

</div>
@endsection
