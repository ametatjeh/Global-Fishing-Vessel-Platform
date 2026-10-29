@extends('layouts.app')

@section('header_title', 'Edit Akun Pengguna — Root Control')

@section('content')
<div class="max-w-3xl space-y-6">

    <!-- Header Section -->
    <div class="flex items-center justify-between">
        <div>
            <div class="flex items-center gap-2">
                <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-amber-500/20 text-amber-300 border border-amber-500/30 uppercase">
                    Root Administration
                </span>
                <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-slate-800 text-slate-300 border border-slate-700">
                    ID #{{ $targetUser->id }}
                </span>
            </div>
            <h2 class="text-xl sm:text-2xl font-bold text-white tracking-wide uppercase mt-1">
                Edit Akun: {{ $targetUser->name }}
            </h2>
            <p class="text-xs text-slate-400 mt-0.5">
                Perbarui data profil dinas, peran hak akses, dan status operasional akun.
            </p>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('admin.users.show', $targetUser->id) }}" class="px-3.5 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 text-slate-300 hover:text-white border border-slate-700 font-medium text-xs transition flex items-center gap-1.5">
                <span>👁️</span>
                <span>Lihat Detail</span>
            </a>
            <a href="{{ route('admin.users.index') }}" class="px-3.5 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 text-slate-300 hover:text-white border border-slate-700 font-medium text-xs transition flex items-center gap-1.5">
                <span>←</span>
                <span>Daftar Akun</span>
            </a>
        </div>
    </div>

    @if($errors->any())
    <div class="p-4 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-300 text-xs space-y-1">
        <strong class="font-bold flex items-center gap-1.5">
            <span>⚠️</span> Terdapat kendala pada input:
        </strong>
        <ul class="list-disc pl-5 space-y-0.5">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    <!-- Edit User Form -->
    <div class="glass-panel p-6 rounded-2xl border border-slate-800 shadow-xl space-y-6">
        <form action="{{ route('admin.users.update', $targetUser->id) }}" method="POST" class="space-y-5 text-xs">
            @csrf
            @method('PATCH')

            <!-- Basic Information -->
            <div class="space-y-4">
                <h3 class="text-xs font-bold uppercase tracking-wider text-teal-300 border-b border-slate-800 pb-2">
                    Informasi Identitas &amp; Dinas
                </h3>

                <div>
                    <label for="name" class="block font-semibold text-slate-300 mb-1">
                        Nama Lengkap Pengguna <span class="text-rose-400">*</span>
                    </label>
                    <input type="text" id="name" name="name" value="{{ old('name', $targetUser->name) }}" required minlength="2"
                        class="w-full px-3.5 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-slate-100 placeholder-slate-500 focus:outline-none focus:border-teal-400 focus:ring-1 focus:ring-teal-400 transition text-xs font-medium">
                </div>

                <div>
                    <label for="email" class="block font-semibold text-slate-300 mb-1">
                        Alamat Email Dinas <span class="text-rose-400">*</span>
                    </label>
                    <input type="email" id="email" name="email" value="{{ old('email', $targetUser->email) }}" required
                        class="w-full px-3.5 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-slate-100 placeholder-slate-500 focus:outline-none focus:border-teal-400 focus:ring-1 focus:ring-teal-400 transition text-xs font-mono">
                </div>

                <div>
                    <label for="organization" class="block font-semibold text-slate-300 mb-1">
                        Organisasi / Satuan Operasi
                    </label>
                    <input type="text" id="organization" name="organization" value="{{ old('organization', $targetUser->organization) }}"
                        class="w-full px-3.5 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-slate-100 placeholder-slate-500 focus:outline-none focus:border-teal-400 focus:ring-1 focus:ring-teal-400 transition text-xs font-medium">
                </div>
            </div>

            <!-- Role & Permissions -->
            <div class="space-y-4 pt-2">
                <h3 class="text-xs font-bold uppercase tracking-wider text-amber-300 border-b border-slate-800 pb-2">
                    Penetapan Peran (Role) &amp; Status Operasi
                </h3>

                <div>
                    <label for="role" class="block font-semibold text-slate-300 mb-1">
                        Peran Sistem (Tingkat Otorisasi) <span class="text-rose-400">*</span>
                    </label>
                    <select id="role" name="role" required
                        class="w-full px-3.5 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-slate-100 focus:outline-none focus:border-teal-400 focus:ring-1 focus:ring-teal-400 transition text-xs font-medium">
                        @foreach($roles as $roleKey => $roleLabel)
                            <option value="{{ $roleKey }}" {{ old('role', $targetUser->role) === $roleKey ? 'selected' : '' }}>
                                {{ $roleLabel }}
                            </option>
                        @endforeach
                    </select>

                    @if($targetUser->id === auth()->id())
                        <p class="text-[10px] text-amber-400 mt-1">
                            ℹ️ Ini adalah akun Anda sendiri. Anda tidak dapat menurunkan peran Super Admin untuk akun Anda sendiri.
                        </p>
                    @endif
                </div>

                <div class="pt-2">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="is_active" value="1" {{ old('is_active', $targetUser->is_active ? '1' : '0') == '1' ? 'checked' : '' }}
                            class="w-4 h-4 rounded bg-slate-900 border-slate-700 text-teal-500 focus:ring-0 focus:ring-offset-0">
                        <span class="font-semibold text-slate-200">Akun Aktif (Dapat Masuk ke Sistem)</span>
                    </label>
                    <p class="text-[10px] text-slate-400 pl-6 mt-0.5">
                        Menonaktifkan akun akan memutus sesi aktif pengguna dan menolak login selanjutnya.
                    </p>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="pt-4 border-t border-slate-800 flex items-center justify-end gap-3">
                <a href="{{ route('admin.users.index') }}" class="py-2.5 px-4 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-medium text-xs transition border border-slate-700">
                    Batal
                </a>
                <button type="submit" class="py-2.5 px-6 rounded-xl bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold text-xs uppercase tracking-wider transition shadow-lg shadow-teal-500/20 cursor-pointer">
                    Simpan Perubahan Akun
                </button>
            </div>
        </form>
    </div>

</div>
@endsection

