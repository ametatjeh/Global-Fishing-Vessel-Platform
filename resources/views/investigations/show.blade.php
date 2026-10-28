@extends('layouts.app')

@section('header_title', 'Berkas Investigasi — ' . $investigation->case_number)

@section('content')
<div class="space-y-6">

    <div class="glass-panel p-6 rounded-2xl border border-slate-800 shadow-xl flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-3">
                <span class="text-3xl">📁</span>
                <div>
                    <h2 class="text-2xl font-black text-white tracking-wide flex items-center gap-2">
                        <span>{{ $investigation->case_number }}</span>
                        <span class="px-2.5 py-0.5 rounded text-xs font-mono uppercase bg-slate-800 text-teal-300 border border-slate-700">
                            {{ $investigation->status }}
                        </span>
                    </h2>
                    <div class="text-sm font-semibold text-slate-300 mt-0.5">{{ $investigation->title }}</div>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('investigations.index') }}" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-medium text-xs transition border border-slate-700">
                &larr; Kembali ke Berkas
            </a>
            <a href="{{ route('map.index') }}" class="px-4 py-2 rounded-xl bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold text-xs transition shadow-lg shadow-teal-500/20">
                Lihat di Peta
            </a>
        </div>
    </div>

    <!-- Case Dossier Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <div class="lg:col-span-2 space-y-6">
            <div class="glass-panel p-5 rounded-xl border border-slate-800 space-y-3 text-xs">
                <h3 class="font-bold uppercase tracking-wider text-teal-300 border-b border-slate-800 pb-2">
                    Uraian Fakta & Ringkasan Kejadian
                </h3>
                <div class="text-slate-200 leading-relaxed p-3 rounded-lg bg-slate-900 border border-slate-800">
                    {{ $investigation->summary }}
                </div>
            </div>

            @if($investigation->targetVessel)
            <div class="glass-panel p-5 rounded-xl border border-slate-800 space-y-3 text-xs">
                <h3 class="font-bold uppercase tracking-wider text-sky-400 border-b border-slate-800 pb-2 flex items-center justify-between">
                    <span>Target Kapal Utama Teridentifikasi</span>
                    <a href="{{ route('vessels.show', $investigation->targetVessel->id) }}" class="text-cyan-400 hover:underline">Buka Profil Kapal &rarr;</a>
                </h3>
                <div class="grid grid-cols-2 gap-3 text-slate-300">
                    <div>Nama: <strong class="text-white">{{ $investigation->targetVessel->name }}</strong></div>
                    <div>Bendera: <span class="font-mono text-teal-300 uppercase">{{ $investigation->targetVessel->flag_state ?? 'UN' }}</span></div>
                    <div>MMSI: <span class="font-mono text-white">{{ $investigation->targetVessel->mmsi ?? '-' }}</span></div>
                    <div>Tipe Armada: <span class="capitalize text-white">{{ $investigation->targetVessel->vessel_type }}</span></div>
                </div>
            </div>
            @endif
        </div>

        <div class="glass-panel p-5 rounded-xl border border-slate-800 space-y-4 text-xs">
            <h3 class="font-bold uppercase tracking-wider text-white border-b border-slate-800 pb-2">
                Metadata & Penugasan Kasus
            </h3>

            <div class="space-y-2.5">
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Prioritas:</span>
                    <span class="font-bold uppercase font-mono text-rose-300">{{ $investigation->priority }}</span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Status Kasus:</span>
                    <span class="font-bold uppercase font-mono text-teal-300">{{ $investigation->status }}</span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Ditugaskan Kepada:</span>
                    <span class="font-semibold text-white">{{ $investigation->assignedTo?->name ?? 'Belum Ditugaskan' }}</span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Dibuat Oleh:</span>
                    <span class="text-slate-200">{{ $investigation->createdBy?->name ?? 'System Admin' }}</span>
                </div>
                <div class="flex justify-between py-1">
                    <span class="text-slate-400">Tanggal Inisiasi:</span>
                    <span class="font-mono text-slate-300">{{ $investigation->created_at->format('Y-m-d H:i') }} UTC</span>
                </div>
            </div>
        </div>

    </div>

</div>
@endsection
