@extends('layouts.app')

@section('header_title', 'Tambah Pengguna Baru — Root Control')

@section('content')
<div class="max-w-3xl space-y-6">

    <!-- Header Section -->
    <div class="flex items-center justify-between">
        <div>
            <div class="flex items-center gap-2">
                <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-amber-500/20 text-amber-300 border border-amber-500/30 uppercase">
                    Root Administration
                </span>
            </div>
            <h2 class="text-xl sm:text-2xl font-bold text-white tracking-wide uppercase mt-1">
                Tambah Akun Pengguna Baru
            </h2>
            <p class="text-xs text-slate-400 mt-0.5">
                Daftarkan akun operator/analyst baru dengan penetapan hak akses operasional yang ketat.
            </p>
        </div>

        <a href="{{ route('admin.users.index') }}" class="px-3.5 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 text-slate-300 hover:text-white border border-slate-700 font-medium text-xs transition flex items-center gap-1.5">
            <span>←</span>
            <span>Kembali ke Daftar</span>
        </a>
    </div>

    @if($errors->any())
    <div class="p-4 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-300 text-xs space-y-1">
        <strong class="font-bold flex items-center gap-1.5">
            <span>⚠️</span> Periksa kembali isian formulir:
        </strong>
        <ul class="list-disc pl-5 space-y-0.5">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    <!-- Create User Form -->
    <div class="glass-panel p-6 rounded-2xl border border-slate-800 shadow-xl space-y-6">
        <form action="{{ route('admin.users.store') }}" method="POST" class="space-y-5 text-xs">
            @csrf

            <!-- Basic Information -->
            <div class="space-y-4">
                <h3 class="text-xs font-bold uppercase tracking-wider text-teal-300 border-b border-slate-800 pb-2">
                    Informasi Identitas &amp; Dinas
                </h3>

                <div>
                    <label for="name" class="block font-semibold text-slate-300 mb-1">
                        Nama Lengkap Pengguna <span class="text-rose-400">*</span>
                    </label>
                    <input type="text" id="name" name="name" value="{{ old('name') }}" required minlength="2" placeholder="Contoh: Capt. Hendra Gunawan"
                        class="w-full px-3.5 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-slate-100 placeholder-slate-500 focus:outline-none focus:border-teal-400 focus:ring-1 focus:ring-teal-400 transition text-xs font-medium">
                </div>

                <div>
                    <label for="email" class="block font-semibold text-slate-300 mb-1">
                        Alamat Email Dinas (Login Unik) <span class="text-rose-400">*</span>
                    </label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}" required placeholder="operator@maritime-intel.org"
                        class="w-full px-3.5 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-slate-100 placeholder-slate-500 focus:outline-none focus:border-teal-400 focus:ring-1 focus:ring-teal-400 transition text-xs font-mono">
                    <p class="text-[10px] text-slate-400 mt-1">Email harus unik dan belum terdaftar dalam sistem.</p>
                </div>

                <div>
                    <label for="organization" class="block font-semibold text-slate-300 mb-1">
                        Organisasi / Satuan Operasi
                    </label>
                    <input type="text" id="organization" name="organization" value="{{ old('organization', 'Maritime Operations Center') }}" placeholder="Contoh: Satuan Pengawasan SDKP"
                        class="w-full px-3.5 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-slate-100 placeholder-slate-500 focus:outline-none focus:border-teal-400 focus:ring-1 focus:ring-teal-400 transition text-xs font-medium">
                </div>
            </div>

            <!-- Role & Permissions -->
            <div class="space-y-4 pt-2">
                <h3 class="text-xs font-bold uppercase tracking-wider text-amber-300 border-b border-slate-800 pb-2">
                    Penetapan Peran (Role) &amp; Hak Akses
                </h3>

                <div>
                    <label for="role" class="block font-semibold text-slate-300 mb-1">
                        Pilih Tingkat Hak Akses <span class="text-rose-400">*</span>
                    </label>
                    <select id="role" name="role" required
                        class="w-full px-3.5 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-slate-100 focus:outline-none focus:border-teal-400 focus:ring-1 focus:ring-teal-400 transition text-xs font-medium">
                        @foreach($roles as $roleKey => $roleLabel)
                            <option value="{{ $roleKey }}" {{ old('role') === $roleKey ? 'selected' : ($roleKey === 'analyst' ? 'selected' : '') }}>
                                {{ $roleLabel }}
                            </option>
                        @endforeach
                    </select>

                    <div class="mt-3 p-3 rounded-xl bg-slate-900 border border-slate-800 text-[11px] text-slate-400 space-y-1">
                        <div class="font-bold text-slate-300 mb-1">Panduan Otorisasi Sistem:</div>
                        <ul class="list-disc pl-4 space-y-0.5">
                            <li><strong class="text-amber-300">Super Admin:</strong> Hak akses penuh root, manajemen akun, offsite backup, dan seluruh modul.</li>
                            <li><strong class="text-blue-300">Admin:</strong> Kelola provider health, system settings, activity log, dan operasional.</li>
                            <li><strong class="text-teal-300">Analyst:</strong> Evaluasi SAR/Dark Vessels, buat investigasi, update compliance status.</li>
                            <li><strong class="text-slate-300">Viewer:</strong> Akses hanya-baca ke peta global, live tracking, registry, dan statistik.</li>
                        </ul>
                    </div>
                </div>

                <div class="pt-2">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="is_active" value="1" {{ old('is_active', '1') == '1' ? 'checked' : '' }}
                            class="w-4 h-4 rounded bg-slate-900 border-slate-700 text-teal-500 focus:ring-0 focus:ring-offset-0">
                        <span class="font-semibold text-slate-200">Aktifkan akun ini segera setelah dibuat</span>
                    </label>
                    <p class="text-[10px] text-slate-400 pl-6 mt-0.5">Jika tidak dicentang, akun akan berstatus nonaktif dan tidak dapat masuk.</p>
                </div>
            </div>

            <!-- Initial Password -->
            <div class="space-y-4 pt-2">
                <h3 class="text-xs font-bold uppercase tracking-wider text-teal-300 border-b border-slate-800 pb-2">
                    Kredensial Kata Sandi Awal
                </h3>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="password" class="block font-semibold text-slate-300 mb-1">
                            Kata Sandi Awal <span class="text-rose-400">*</span>
                        </label>
                        <input type="password" id="password" name="password" required minlength="8"
                            class="w-full px-3.5 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-slate-100 placeholder-slate-500 focus:outline-none focus:border-teal-400 focus:ring-1 focus:ring-teal-400 transition text-xs font-mono">
                        <p class="text-[10px] text-slate-400 mt-1">Minimal 8 karakter.</p>
                    </div>

                    <div>
                        <label for="password_confirmation" class="block font-semibold text-slate-300 mb-1">
                            Konfirmasi Kata Sandi <span class="text-rose-400">*</span>
                        </label>
                        <input type="password" id="password_confirmation" name="password_confirmation" required minlength="8"
                            class="w-full px-3.5 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-slate-100 placeholder-slate-500 focus:outline-none focus:border-teal-400 focus:ring-1 focus:ring-teal-400 transition text-xs font-mono">
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="pt-4 border-t border-slate-800 flex items-center justify-end gap-3">
                <a href="{{ route('admin.users.index') }}" class="py-2.5 px-4 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-medium text-xs transition border border-slate-700">
                    Batal
                </a>
                <button type="submit" class="py-2.5 px-6 rounded-xl bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold text-xs uppercase tracking-wider transition shadow-lg shadow-teal-500/20 cursor-pointer">
                    Simpan Akun Pengguna
                </button>
            </div>
        </form>
    </div>

</div>
@endsection

