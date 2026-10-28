@extends('layouts.app')

@section('header_title', 'Buka Berkas Investigasi Baru')

@section('content')
<div class="max-w-2xl mx-auto space-y-6">

    <div class="glass-panel p-6 rounded-2xl border border-slate-800 shadow-xl space-y-4">
        <div class="border-b border-slate-800 pb-3">
            <h2 class="text-base font-bold text-white uppercase tracking-wider flex items-center gap-2">
                <span>📁</span>
                <span>Inisiasi Kasus Investigasi Intelijen Maritim</span>
            </h2>
            <p class="text-xs text-slate-400 mt-1">Buat dossier investigasi formal untuk penelusuran anomali kapal, rendezvous, atau deteksi radar.</p>
        </div>

        <form action="{{ route('investigations.store') }}" method="POST" class="space-y-4 text-xs">
            @csrf

            <div>
                <label for="title" class="block font-semibold text-slate-300 mb-1">Judul / Subjek Investigasi:</label>
                <input type="text" name="title" id="title" required placeholder="Contoh: Investigasi Dugaan Transshipment Ilegal Laut Lepas"
                    class="w-full px-3.5 py-2 rounded-xl bg-slate-900 border border-slate-700 text-slate-100 placeholder-slate-500 focus:outline-none focus:border-teal-400">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="priority" class="block font-semibold text-slate-300 mb-1">Tingkat Prioritas:</label>
                    <select name="priority" id="priority" class="w-full px-3.5 py-2 rounded-xl bg-slate-900 border border-slate-700 text-slate-100 focus:outline-none focus:border-teal-400">
                        <option value="low">Rendah (Low)</option>
                        <option value="medium" selected>Sedang (Medium)</option>
                        <option value="high">Tinggi (High)</option>
                        <option value="urgent">Mendesak (Urgent)</option>
                    </select>
                </div>

                <div>
                    <label for="target_vessel_id" class="block font-semibold text-slate-300 mb-1">Target Kapal Utama:</label>
                    <select name="target_vessel_id" id="target_vessel_id" class="w-full px-3.5 py-2 rounded-xl bg-slate-900 border border-slate-700 text-slate-100 focus:outline-none focus:border-teal-400">
                        <option value="">-- Belum Dipilih / Tidak Diketahui --</option>
                        @foreach($vessels as $v)
                            <option value="{{ $v->id }}" {{ request('target_vessel_id') == $v->id ? 'selected' : '' }}>
                                {{ $v->name }} (MMSI: {{ $v->mmsi ?? '-' }})
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div>
                <label for="assigned_to_user_id" class="block font-semibold text-slate-300 mb-1">Tugaskan Analis:</label>
                <select name="assigned_to_user_id" id="assigned_to_user_id" class="w-full px-3.5 py-2 rounded-xl bg-slate-900 border border-slate-700 text-slate-100 focus:outline-none focus:border-teal-400">
                    @foreach($analysts as $analyst)
                        <option value="{{ $analyst->id }}">{{ $analyst->name }} ({{ $analyst->role }})</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="summary" class="block font-semibold text-slate-300 mb-1">Uraian Awal / Fakta Intelijen:</label>
                <textarea name="summary" id="summary" rows="4" required placeholder="Deskripsikan latar belakang indikasi, rentang waktu, data AIS atau satelit pendukung..."
                    class="w-full px-3.5 py-2 rounded-xl bg-slate-900 border border-slate-700 text-slate-100 placeholder-slate-500 focus:outline-none focus:border-teal-400"></textarea>
            </div>

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-800">
                <a href="{{ route('investigations.index') }}" class="px-4 py-2 rounded-xl bg-slate-800 text-slate-300 hover:bg-slate-700 font-semibold transition">
                    Batal
                </a>
                <button type="submit" class="px-5 py-2 rounded-xl bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold transition shadow-md shadow-teal-500/20">
                    Buka Berkas Investigasi
                </button>
            </div>
        </form>
    </div>

</div>
@endsection
