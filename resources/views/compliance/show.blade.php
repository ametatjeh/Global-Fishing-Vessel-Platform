@extends('layouts.app')

@section('header_title', 'Peringatan Kepatuhan — ' . $event->event_code)

@section('content')
<div class="space-y-6">

    <div class="glass-panel p-6 rounded-2xl border border-slate-800 shadow-xl flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-3">
                <span class="text-3xl text-rose-400">⚠️</span>
                <div>
                    <h2 class="text-2xl font-black text-white tracking-wide flex items-center gap-2">
                        <span>{{ $event->event_code }}</span>
                        <span class="px-2.5 py-0.5 rounded text-xs font-mono uppercase bg-slate-800 text-rose-300 border border-slate-700">
                            {{ $event->severity }}
                        </span>
                    </h2>
                    <div class="text-xs text-slate-400 mt-1 font-mono">
                        Aturan: {{ $event->rule?->rule_name }} &bull; Waktu: {{ $event->event_time->format('Y-m-d H:i:s') }} UTC
                    </div>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('compliance.index') }}" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-medium text-xs transition border border-slate-700">
                &larr; Kembali ke Compliance
            </a>
            <a href="{{ route('investigations.create', ['target_vessel_id' => $event->vessel_id]) }}" class="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white font-bold text-xs transition shadow-md">
                Buka Kasus Investigasi
            </a>
        </div>
    </div>

    <!-- Evidence Details Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

        <div class="glass-panel p-5 rounded-xl border border-slate-800 space-y-3 text-xs">
            <h3 class="font-bold uppercase tracking-wider text-teal-400 border-b border-slate-800 pb-2">
                Informasi Pelanggaran & Bukti Pendukung
            </h3>

            <div class="space-y-2">
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Kapal Terduga:</span>
                    <span class="font-bold text-white">{{ $event->vessel?->name ?? 'Kapal' }} (MMSI: {{ $event->vessel?->mmsi ?? '-' }})</span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Dasar Hukum:</span>
                    <span class="font-mono text-slate-300">{{ $event->rule?->legal_source ?? '-' }}</span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Koordinat:</span>
                    <span class="font-mono text-teal-300">{{ $event->latitude }}&deg;, {{ $event->longitude }}&deg;</span>
                </div>
                <div class="pt-2">
                    <span class="text-slate-400 block mb-1">Ringkasan Bukti (Evidence Summary):</span>
                    <div class="p-3 rounded-lg bg-slate-900 border border-slate-800 text-[11px] text-slate-200 leading-relaxed">
                        {{ $event->evidence_summary }}
                    </div>
                </div>
            </div>
        </div>

        <div class="glass-panel p-5 rounded-xl border border-slate-800 space-y-3 text-xs">
            <h3 class="font-bold uppercase tracking-wider text-rose-400 border-b border-slate-800 pb-2">
                Disposisi Penelaahan Petugas
            </h3>

            <form action="{{ route('compliance.update-status', $event->id) }}" method="POST" class="space-y-3">
                @csrf

                <div>
                    <label for="status" class="block font-semibold text-slate-300 mb-1">Status Peringatan:</label>
                    <select name="status" id="status" class="w-full px-3 py-2 rounded-xl bg-slate-900 border border-slate-700 text-slate-200 focus:outline-none focus:border-teal-400">
                        <option value="open" {{ $event->status === 'open' ? 'selected' : '' }}>Open (Menunggu Tindak Lanjut)</option>
                        <option value="under_review" {{ $event->status === 'under_review' ? 'selected' : '' }}>Under Review (Sedang Ditelusuri)</option>
                        <option value="resolved" {{ $event->status === 'resolved' ? 'selected' : '' }}>Resolved (Selesai Dikonfirmasi / Ditindak)</option>
                        <option value="dismissed" {{ $event->status === 'dismissed' ? 'selected' : '' }}>Dismissed (Ditutup / False Alarm)</option>
                    </select>
                </div>

                <div>
                    <label for="review_notes" class="block font-semibold text-slate-300 mb-1">Catatan Verifikasi Analis:</label>
                    <textarea name="review_notes" id="review_notes" rows="3" class="w-full px-3 py-2 rounded-xl bg-slate-900 border border-slate-700 text-slate-200 placeholder-slate-500 focus:outline-none focus:border-teal-400 text-xs">{{ old('review_notes', $event->review_notes) }}</textarea>
                </div>

                <button type="submit" class="w-full py-2.5 rounded-xl bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold transition shadow-md shadow-teal-500/20">
                    Simpan Telaah Kepatuhan
                </button>
            </form>
        </div>

    </div>

</div>
@endsection
