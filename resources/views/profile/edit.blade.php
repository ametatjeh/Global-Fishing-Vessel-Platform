@extends('layouts.app')

@section('header_title', 'User Settings — Profil & Identitas Pengguna')

@section('content')
<div class="max-w-4xl space-y-6">

    <!-- Header Section -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-teal-500/20 text-teal-300 border border-teal-500/30 uppercase">
                    Manajemen Akun
                </span>
                <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-slate-800 text-slate-300 border border-slate-700 uppercase">
                    Role: {{ strtoupper($user->role ?? 'ANALYST') }}
                </span>
            </div>
            <h2 class="text-xl sm:text-2xl font-bold text-white tracking-wide uppercase mt-1">
                Pengaturan Profil Pengguna
            </h2>
            <p class="text-xs text-slate-400 mt-0.5">
                Kelola nama tampilan, foto profil kustom, dan status kredensial Single Sign-On (Google SSO).
            </p>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('dashboard') }}" class="px-3.5 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 text-slate-300 hover:text-white border border-slate-700 font-medium text-xs transition flex items-center gap-1.5">
                <span>←</span>
                <span>Dashboard</span>
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

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

        <!-- Column 1: Avatar Management -->
        <div class="glass-panel p-5 rounded-2xl border border-slate-800 space-y-5 shadow-xl flex flex-col items-center text-center">
            <h3 class="text-xs font-bold uppercase tracking-wider text-teal-300 border-b border-slate-800 w-full pb-2">
                Foto Profil
            </h3>

            <!-- Avatar Container -->
            <div class="relative group">
                <div class="w-32 h-32 rounded-full overflow-hidden border-2 border-teal-400/80 shadow-lg shadow-teal-500/10 bg-slate-950 flex items-center justify-center">
                    <img id="avatarPreview" src="{{ $user->avatar_url }}" alt="{{ $user->name }}" class="w-full h-full object-cover">
                </div>
                <div class="absolute inset-0 rounded-full bg-slate-950/60 opacity-0 group-hover:opacity-100 transition flex items-center justify-center cursor-pointer" onclick="document.getElementById('avatarInput').click();">
                    <span class="text-xs text-teal-300 font-medium">Ubah Foto</span>
                </div>
            </div>

            <div class="space-y-1">
                <div class="text-sm font-bold text-white">{{ $user->name }}</div>
                <div class="text-[11px] text-slate-400 font-mono">{{ $user->email }}</div>
                @if($user->avatar_path)
                    <div class="inline-flex items-center gap-1 text-[10px] text-teal-400 font-mono bg-teal-500/10 px-2 py-0.5 rounded-full border border-teal-500/20">
                        <span>✓</span> Foto Kustom Aktif
                    </div>
                @else
                    <div class="inline-flex items-center gap-1 text-[10px] text-slate-400 font-mono bg-slate-800 px-2 py-0.5 rounded-full">
                        <span>👤</span> Avatar Default Sistem
                    </div>
                @endif
            </div>

            <!-- Upload Avatar Form -->
            <form id="avatarForm" action="{{ route('profile.avatar.update') }}" method="POST" enctype="multipart/form-data" class="w-full space-y-3 pt-2 border-t border-slate-800/80 text-xs">
                @csrf
                <input type="file" name="avatar" id="avatarInput" accept="image/jpeg,image/png,image/webp" class="hidden" onchange="handleAvatarSelected(event)">

                <div class="flex flex-col gap-2">
                    <button type="button" onclick="document.getElementById('avatarInput').click();" class="w-full py-2 px-3 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 font-medium text-xs border border-slate-700 transition flex items-center justify-center gap-1.5 cursor-pointer">
                        <span>📁</span>
                        <span>Pilih Berkas Foto</span>
                    </button>

                    <button type="submit" id="btnSaveAvatar" disabled class="w-full py-2 px-3 rounded-xl bg-teal-500 hover:bg-teal-400 disabled:bg-slate-800 disabled:text-slate-500 disabled:cursor-not-allowed text-slate-950 font-bold text-xs uppercase tracking-wider transition shadow-md shadow-teal-500/20 cursor-pointer">
                        Simpan Foto Baru
                    </button>
                </div>

                <p class="text-[10px] text-slate-400 leading-tight">
                    Format: JPEG, PNG, WebP &bull; Maks. 2 MB.<br>Verifikasi integritas MIME dilakukan di server.
                </p>
            </form>

            @if($user->avatar_path)
            <form action="{{ route('profile.avatar.destroy') }}" method="POST" class="w-full pt-1" onsubmit="return confirm('Apakah Anda yakin ingin menghapus foto profil kustom dan kembali ke avatar default?');">
                @csrf
                @method('DELETE')
                <button type="submit" class="w-full py-1.5 px-3 rounded-xl bg-rose-500/10 hover:bg-rose-500/20 text-rose-300 font-semibold text-[11px] border border-rose-500/30 transition flex items-center justify-center gap-1 cursor-pointer">
                    <span>🗑️</span>
                    <span>Hapus Foto Profil</span>
                </button>
            </form>
            @endif
        </div>

        <!-- Column 2 & 3: Profile Details & SSO Status -->
        <div class="md:col-span-2 space-y-6">

            <!-- Card 1: Edit Profile Details -->
            <div class="glass-panel p-5 rounded-2xl border border-slate-800 space-y-5 shadow-xl">
                <div class="flex items-center justify-between border-b border-slate-800 pb-2">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-teal-300 flex items-center gap-2">
                        <span>👤</span>
                        <span>Informasi Profil Akun</span>
                    </h3>
                    <span class="text-[10px] text-slate-400 font-mono">ID Pengguna: #{{ $user->id }}</span>
                </div>

                <form action="{{ route('profile.update') }}" method="POST" class="space-y-4 text-xs">
                    @csrf
                    @method('PATCH')

                    <div>
                        <label for="name" class="block font-semibold text-slate-300 mb-1">
                            Nama Lengkap / Panggilan Operasional <span class="text-rose-400">*</span>
                        </label>
                        <input type="text" id="name" name="name" value="{{ old('name', $user->name) }}" required
                            class="w-full px-3.5 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-slate-100 placeholder-slate-500 focus:outline-none focus:border-teal-400 focus:ring-1 focus:ring-teal-400 transition text-xs font-medium">
                        <p class="text-[10px] text-slate-400 mt-1">Nama ini ditampilkan di bilah navigasi, audit log forensik, dan laporan investigasi.</p>
                    </div>

                    <div>
                        <label class="block font-semibold text-slate-300 mb-1">Alamat Email Dinas</label>
                        <div class="flex items-center gap-2">
                            <input type="email" value="{{ $user->email }}" readonly disabled
                                class="w-full px-3.5 py-2.5 rounded-xl bg-slate-950/80 border border-slate-800 text-slate-400 font-mono text-xs cursor-not-allowed">
                            @if($user->email_verified_at)
                                <span class="shrink-0 px-2.5 py-2 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-[10px] font-mono font-semibold flex items-center gap-1">
                                    <span>✓</span> Terverifikasi
                                </span>
                            @endif
                        </div>
                        <p class="text-[10px] text-slate-400 mt-1">Email dinas terdaftar sebagai kunci identifikasi unik sistem.</p>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-1">
                        <div>
                            <label class="block font-semibold text-slate-300 mb-1">Hak Akses / Peran Sistem</label>
                            <input type="text" value="{{ strtoupper($user->role ?? 'ANALYST') }}" readonly disabled
                                class="w-full px-3.5 py-2 rounded-xl bg-slate-950/80 border border-slate-800 text-teal-400 font-mono text-xs font-bold uppercase cursor-not-allowed">
                        </div>
                        <div>
                            <label class="block font-semibold text-slate-300 mb-1">Organisasi / Divisi</label>
                            <input type="text" value="{{ $user->organization ?? 'Maritime Operations' }}" readonly disabled
                                class="w-full px-3.5 py-2 rounded-xl bg-slate-950/80 border border-slate-800 text-slate-300 text-xs cursor-not-allowed">
                        </div>
                    </div>

                    <div class="pt-2 flex justify-end">
                        <button type="submit" class="py-2.5 px-5 rounded-xl bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold text-xs uppercase tracking-wider transition shadow-lg shadow-teal-500/20 cursor-pointer">
                            Simpan Perubahan
                        </button>
                    </div>
                </form>
            </div>

            <!-- Card 2: Single Sign-On (Google SSO) Status -->
            <div class="glass-panel p-5 rounded-2xl border border-slate-800 space-y-4 shadow-xl">
                <div class="flex items-center justify-between border-b border-slate-800 pb-2">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-indigo-300 flex items-center gap-2">
                        <span>🔐</span>
                        <span>Integrasi Single Sign-On (Google SSO)</span>
                    </h3>
                    <span class="text-[10px] font-mono text-slate-400">OAuth 2.0</span>
                </div>

                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-3.5 rounded-xl bg-slate-900 border border-slate-800 text-xs">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-xl bg-white/10 flex items-center justify-center p-2 shrink-0 border border-slate-700">
                            <svg class="w-full h-full" viewBox="0 0 24 24">
                                <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                                <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                                <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/>
                                <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/>
                            </svg>
                        </div>
                        <div>
                            <div class="font-bold text-white">Akun Google Workspace / Gmail</div>
                            <div class="text-[11px] text-slate-400">
                                @if($user->google_id)
                                    ID Tertaut: <code class="text-teal-300 font-mono">{{ substr($user->google_id, 0, 8) }}••••••••</code>
                                @else
                                    Akun Google belum ditautkan ke profil ini.
                                @endif
                            </div>
                        </div>
                    </div>

                    <div>
                        @if($user->google_id)
                            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs font-semibold">
                                <span class="w-2 h-2 rounded-full bg-emerald-400"></span>
                                Terhubung
                            </span>
                        @else
                            <a href="{{ route('auth.google.redirect') }}" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 hover:text-white border border-slate-700 transition text-xs font-medium">
                                <span>Hubungkan Google</span>
                                <span>→</span>
                            </a>
                        @endif
                    </div>
                </div>

                <div class="text-[10px] text-slate-400 leading-relaxed">
                    Catatan Keamanan: Menautkan Google SSO memungkinkan autentikasi cepat tanpa memasukkan kata sandi lokal. Kredensial kata sandi lokal Anda tetap tersimpan aman dan tidak ditimpa.
                </div>
            </div>

        </div>

    </div>

</div>

@push('scripts')
<script>
function handleAvatarSelected(event) {
    const input = event.target;
    const btnSave = document.getElementById('btnSaveAvatar');
    const preview = document.getElementById('avatarPreview');

    if (!input.files || !input.files[0]) {
        btnSave.disabled = true;
        return;
    }

    const file = input.files[0];

    // Client-side validation: max 2 MB
    if (file.size > 2 * 1024 * 1024) {
        alert('Ukuran berkas melebihi 2 MB. Silakan pilih foto dengan ukuran lebih kecil.');
        input.value = '';
        btnSave.disabled = true;
        return;
    }

    // Client-side MIME validation
    const allowed = ['image/jpeg', 'image/png', 'image/webp'];
    if (!allowed.includes(file.type)) {
        alert('Format file tidak didukung. Gunakan format JPEG, PNG, atau WebP.');
        input.value = '';
        btnSave.disabled = true;
        return;
    }

    // Instant local preview
    const reader = new FileReader();
    reader.onload = function(e) {
        preview.src = e.target.result;
        btnSave.disabled = false;
    };
    reader.readAsDataURL(file);
}
</script>
@endpush
@endsection

