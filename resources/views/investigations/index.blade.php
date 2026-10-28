@extends('layouts.app')

@section('header_title', 'Investigations — Berkas Kasus Intelijen Maritim')

@section('content')
<div class="space-y-6">

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold text-white tracking-wide uppercase">Investigations Dossier</h2>
            <p class="text-xs text-slate-400 mt-1">
                Penyusunan berkas bukti kasus transshipment ilegal, operasi kapal tanpa izin, manipulasi AIS, dan anomali citra satelit SAR.
            </p>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('investigations.create') }}" class="px-4 py-2 rounded-xl bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold text-xs transition shadow-lg shadow-teal-500/20">
                + Buka Berkas Kasus Baru
            </a>
        </div>
    </div>

    <!-- Investigations Table -->
    <div class="glass-panel rounded-2xl border border-slate-800 overflow-hidden shadow-xl">
        <div class="overflow-x-auto text-xs">
            <table class="w-full text-left">
                <thead class="bg-slate-900/90 text-slate-400 uppercase tracking-wider text-[10px] border-b border-slate-800">
                    <tr>
                        <th class="py-3 px-4">Nomor Berkas</th>
                        <th class="py-3 px-4">Judul Kasus</th>
                        <th class="py-3 px-4">Target Kapal</th>
                        <th class="py-3 px-4">Prioritas</th>
                        <th class="py-3 px-4">Status</th>
                        <th class="py-3 px-4">Analis Bertanggung Jawab</th>
                        <th class="py-3 px-4">Tanggal Buka</th>
                        <th class="py-3 px-4 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60">
                    @forelse($investigations as $inv)
                    <tr class="hover:bg-slate-900/40 transition">
                        <td class="py-3 px-4 font-mono font-bold text-indigo-300">{{ $inv->case_number }}</td>
                        <td class="py-3 px-4 font-bold text-white">{{ $inv->title }}</td>
                        <td class="py-3 px-4 text-teal-300 font-semibold">{{ $inv->targetVessel?->name ?? 'Armada Belum Teridentifikasi' }}</td>
                        <td class="py-3 px-4">
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase font-mono
                                {{ $inv->priority === 'urgent' ? 'bg-rose-500/20 text-rose-300 border border-rose-500/40' : 'bg-slate-800 text-slate-300 border border-slate-700' }}">
                                {{ $inv->priority }}
                            </span>
                        </td>
                        <td class="py-3 px-4">
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase font-mono bg-slate-800 text-slate-300 border border-slate-700">
                                {{ $inv->status }}
                            </span>
                        </td>
                        <td class="py-3 px-4 text-slate-300">{{ $inv->assignedTo?->name ?? 'Belum ditugaskan' }}</td>
                        <td class="py-3 px-4 text-slate-400 font-mono text-[11px]">{{ $inv->created_at->format('Y-m-d') }}</td>
                        <td class="py-3 px-4 text-right">
                            <a href="{{ route('investigations.show', $inv->id) }}" class="px-2.5 py-1 rounded bg-slate-800 hover:bg-slate-700 text-teal-300 font-semibold transition border border-slate-700">
                                Dossier
                            </a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="8" class="py-12 text-center text-slate-500">
                            <span class="text-4xl block mb-2">📁</span>
                            Belum ada berkas investigasi kasus aktif saat ini.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection
