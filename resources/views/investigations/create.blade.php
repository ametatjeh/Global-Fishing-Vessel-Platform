@extends('layouts.app')

@section('header_title', 'Buka Berkas Investigasi Riset Baru (Private Research)')

@section('content')
<div class="max-w-3xl mx-auto space-y-6">

    <div class="glass-panel p-6 rounded-2xl border border-slate-800 shadow-xl space-y-4">
        <div class="border-b border-slate-800 pb-3 flex items-start justify-between">
            <div>
                <div class="flex items-center gap-2">
                    <h2 class="text-base font-bold text-white uppercase tracking-wider flex items-center gap-2">
                        <span>📁</span>
                        <span>Inisiasi Kasus Investigasi Intelijen Maritim</span>
                    </h2>
                    <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-rose-500/20 text-rose-300 border border-rose-500/40">
                        RESTRICTED — RESEARCH ONLY
                    </span>
                </div>
                <p class="text-xs text-slate-400 mt-1">
                    Buat dossier investigasi tertutup untuk riset mendalam kapal target: perubahan identitas, jejaring korporasi, pelanggaran ZEE, dan kepatuhan otorisasi penangkapan.
                </p>
            </div>
        </div>

        <form action="{{ route('investigations.store') }}" method="POST" class="space-y-4 text-xs">
            @csrf

            <div>
                <label for="title" class="block font-semibold text-slate-300 mb-1">Judul / Subjek Investigasi:</label>
                <input type="text" name="title" id="title" required placeholder="Contoh: Penelusuran Flag Hopping & Kepemilikan Manfaat Kapal Ikan Asing di WPPNRI 718"
                    class="w-full px-3.5 py-2 rounded-xl bg-slate-900 border border-slate-700 text-slate-100 placeholder-slate-500 focus:outline-none focus:border-teal-400">
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="confidentiality_level" class="block font-semibold text-slate-300 mb-1">Tingkat Kerahasiaan:</label>
                    <select name="confidentiality_level" id="confidentiality_level" class="w-full px-3.5 py-2 rounded-xl bg-slate-900 border border-slate-700 text-slate-100 focus:outline-none focus:border-teal-400">
                        <option value="private" selected>Private — Tim Peneliti Internal</option>
                        <option value="restricted">Restricted — Peneliti & Lembaga Mitra</option>
                        <option value="strictly_confidential">Strictly Confidential — Pimpinan & Peneliti Ditugaskan Saja</option>
                    </select>
                </div>

                <div>
                    <label for="organization" class="block font-semibold text-slate-300 mb-1">Lembaga / Organisasi Riset:</label>
                    <input type="text" name="organization" id="organization" value="{{ auth()->user()->organization }}" placeholder="Contoh: Pusat Riset Kelautan / NGO Konservasi"
                        class="w-full px-3.5 py-2 rounded-xl bg-slate-900 border border-slate-700 text-slate-100 placeholder-slate-500 focus:outline-none focus:border-teal-400">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
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
                        <option value="">-- Belum Dipilih / Target Belum Terdaftar --</option>
                        @foreach($vessels as $v)
                            <option value="{{ $v->id }}" {{ request('target_vessel_id') == $v->id ? 'selected' : '' }}>
                                {{ $v->name }} (MMSI: {{ $v->mmsi ?? '-' }} | Bendera: {{ $v->flag_state ?? 'UN' }})
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="lead_user_id" class="block font-semibold text-slate-300 mb-1">Lead Peneliti (Pimpinan Riset):</label>
                    <select name="lead_user_id" id="lead_user_id" class="w-full px-3.5 py-2 rounded-xl bg-slate-900 border border-slate-700 text-slate-100 focus:outline-none focus:border-teal-400">
                        <option value="">-- Tidak Dipilih (Default Diri Sendiri) --</option>
                        @foreach($analysts as $analyst)
                            <option value="{{ $analyst->id }}" {{ auth()->id() == $analyst->id ? 'selected' : '' }}>
                                {{ $analyst->name }} ({{ $analyst->role }} - {{ $analyst->organization ?? 'Internal' }})
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="assigned_to_user_id" class="block font-semibold text-slate-300 mb-1">Tugaskan Analis Pelaksana:</label>
                    <select name="assigned_to_user_id" id="assigned_to_user_id" class="w-full px-3.5 py-2 rounded-xl bg-slate-900 border border-slate-700 text-slate-100 focus:outline-none focus:border-teal-400">
                        @foreach($analysts as $analyst)
                            <option value="{{ $analyst->id }}" {{ auth()->id() == $analyst->id ? 'selected' : '' }}>
                                {{ $analyst->name }} ({{ $analyst->role }})
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div>
                <label class="block font-semibold text-slate-300 mb-2">Fokus Investigasi Utama:</label>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 p-3 rounded-xl bg-slate-900/80 border border-slate-800">
                    <label class="flex items-center gap-2 text-slate-300 cursor-pointer">
                        <input type="checkbox" name="focus_areas[]" value="vessel_identity_and_flag_hopping" checked class="rounded border-slate-700 text-teal-500 focus:ring-0">
                        <span>A. Pergantian Identitas & Bendera (Flag Hopping)</span>
                    </label>
                    <label class="flex items-center gap-2 text-slate-300 cursor-pointer">
                        <input type="checkbox" name="focus_areas[]" value="corporate_network_and_beneficial_ownership" checked class="rounded border-slate-700 text-teal-500 focus:ring-0">
                        <span>B. Jejaring Korporasi & Beneficial Ownership</span>
                    </label>
                    <label class="flex items-center gap-2 text-slate-300 cursor-pointer">
                        <input type="checkbox" name="focus_areas[]" value="eez_boundary_compliance" checked class="rounded border-slate-700 text-teal-500 focus:ring-0">
                        <span>C. Lintasan Geospasial & Dugaan Pelanggaran ZEE</span>
                    </label>
                    <label class="flex items-center gap-2 text-slate-300 cursor-pointer">
                        <input type="checkbox" name="focus_areas[]" value="iuu_fishing_and_vessel_authorization" checked class="rounded border-slate-700 text-teal-500 focus:ring-0">
                        <span>D. IUU Fishing & Status Otorisasi/Izin</span>
                    </label>
                </div>
            </div>

            <div>
                <label for="summary" class="block font-semibold text-slate-300 mb-1">Uraian Kasus Awal / Hipotesis Riset:</label>
                <textarea name="summary" id="summary" rows="4" required placeholder="Deskripsikan latar belakang indikasi, rentang waktu, data AIS atau satelit pendukung..."
                    class="w-full px-3.5 py-2 rounded-xl bg-slate-900 border border-slate-700 text-slate-100 placeholder-slate-500 focus:outline-none focus:border-teal-400"></textarea>
            </div>

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-800">
                <a href="{{ route('investigations.index') }}" class="px-4 py-2 rounded-xl bg-slate-800 text-slate-300 hover:bg-slate-700 font-semibold transition">
                    Batal
                </a>
                <button type="submit" class="px-5 py-2 rounded-xl bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold transition shadow-md shadow-teal-500/20">
                    Inisiasi Dossier Investigasi
                </button>
            </div>
        </form>
    </div>

</div>
@endsection
