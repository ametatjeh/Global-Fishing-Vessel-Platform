@extends('layouts.app')

@section('header_title', 'Detail Akun Pengguna — Root Control')

@section('content')
<div class="space-y-6 max-w-5xl">

    <!-- Header Section -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
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
                {{ $targetUser->name }}
            </h2>
            <p class="text-xs text-slate-400 mt-0.5">
                Audit akun, detail hak akses, pengelolaan kredensial, dan rekam jejak aktivitas forensik.
            </p>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('admin.users.edit', $targetUser->id) }}" class="px-3.5 py-2 rounded-xl bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold text-xs uppercase tracking-wider transition flex items-center gap-1.5 cursor-pointer">
                <span>✏️</span>
                <span>Edit Akun</span>
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
            <span>⚠️</span> Terdapat kendala:
        </strong>
        <ul class="list-disc pl-5 space-y-0.5">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

        <!-- Column 1: Identity & Profile Card -->
        <div class="glass-panel p-5 rounded-2xl border border-slate-800 space-y-5 shadow-xl flex flex-col items-center text-center">
            <div class="w-28 h-28 rounded-full overflow-hidden border-2 border-teal-400/80 shadow-lg shadow-teal-500/10 bg-slate-950 flex items-center justify-center">
                <img src="{{ $targetUser->avatar_url }}" alt="{{ $targetUser->name }}" class="w-full h-full object-cover">
            </div>

            <div class="space-y-1.5 w-full">
                <h3 class="text-base font-bold text-white">{{ $targetUser->name }}</h3>
                <div class="text-xs text-slate-400 font-mono">{{ $targetUser->email }}</div>

                <div class="pt-1 flex flex-wrap justify-center gap-1.5">
                    @if($targetUser->role === 'super_admin')
                        <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-amber-500/20 text-amber-300 border border-amber-500/30 uppercase">
                            SUPER ADMIN
                        </span>
                    @elseif($targetUser->role === 'admin')
                        <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-blue-500/20 text-blue-300 border border-blue-500/30 uppercase">
                            ADMIN
                        </span>
                    @elseif($targetUser->role === 'analyst')
                        <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-teal-500/20 text-teal-300 border border-teal-500/30 uppercase">
                            ANALYST
                        </span>
                    @else
                        <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-slate-800 text-slate-300 border border-slate-700 uppercase">
                            VIEWER
                        </span>
                    @endif

                    @if($targetUser->is_active)
                        <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                            AKTIF
                        </span>
                    @else
                        <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-rose-500/10 text-rose-400 border border-rose-500/20">
                            NONAKTIF
                        </span>
                    @endif
                </div>
            </div>

            <div class="w-full border-t border-slate-800 pt-4 text-left text-xs space-y-2.5">
                <div>
                    <div class="text-[10px] uppercase font-bold text-slate-500 font-mono">Organisasi / Divisi</div>
                    <div class="text-slate-200 font-medium">{{ $targetUser->organization ?? '-' }}</div>
                </div>

                <div>
                    <div class="text-[10px] uppercase font-bold text-slate-500 font-mono">Autentikasi Google SSO</div>
                    <div class="text-slate-200">
                        @if($targetUser->google_id)
                            <span class="text-emerald-400 font-mono text-[11px]">✓ Terhubung ({{ substr($targetUser->google_id, 0, 8) }}••••)</span>
                        @else
                            <span class="text-slate-400 text-[11px]">Belum ditautkan</span>
                        @endif
                    </div>
                </div>

                <div>
                    <div class="text-[10px] uppercase font-bold text-slate-500 font-mono">Kata Sandi Lokal</div>
                    <div class="text-slate-200">
                        @if($targetUser->hasSetPassword())
                            <span class="text-teal-400 font-mono text-[11px]">✓ Kredensial aktif</span>
                        @else
                            <span class="text-amber-300 font-mono text-[11px]">Belum ditetapkan</span>
                        @endif
                    </div>
                </div>

                <div>
                    <div class="text-[10px] uppercase font-bold text-slate-500 font-mono">Terakhir Masuk (Last Login)</div>
                    <div class="text-slate-200 font-mono text-[11px]">
                        @if($targetUser->last_login_at)
                            <span class="text-teal-300">{{ $targetUser->last_login_at->format('d M Y, H:i') }}</span>
                            <span class="text-slate-500">({{ $targetUser->last_login_at->diffForHumans() }})</span>
                        @else
                            <span class="text-slate-500 italic">Belum pernah login</span>
                        @endif
                    </div>
                </div>

                <div>
                    <div class="text-[10px] uppercase font-bold text-slate-500 font-mono">Tanggal Terdaftar</div>
                    <div class="text-slate-200 font-mono text-[11px]">
                        {{ $targetUser->created_at ? $targetUser->created_at->format('d M Y, H:i') : '-' }}
                    </div>
                </div>
            </div>

            <!-- Toggle Status Form -->
            @if($targetUser->id !== auth()->id())
            <div class="w-full border-t border-slate-800 pt-3">
                <form action="{{ route('admin.users.toggle-status', $targetUser->id) }}" method="POST" onsubmit="return confirm('Apakah Anda yakin ingin mengubah status aktif pengguna ini?');">
                    @csrf
                    <button type="submit" class="w-full py-2 px-3 rounded-xl {{ $targetUser->is_active ? 'bg-rose-500/10 hover:bg-rose-500/20 text-rose-300 border border-rose-500/30' : 'bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-300 border border-emerald-500/30' }} font-bold text-xs transition cursor-pointer">
                        {{ $targetUser->is_active ? 'Nonaktifkan Akun Ini' : 'Aktifkan Akun Ini' }}
                    </button>
                </form>
            </div>
            @endif
        </div>

        <!-- Column 2 & 3: Reset Password & Activity Logs -->
        <div class="md:col-span-2 space-y-6">

            <!-- Card: Reset Password by Super Admin -->
            <div class="glass-panel p-5 rounded-2xl border border-slate-800 space-y-4 shadow-xl">
                <div class="flex items-center justify-between border-b border-slate-800 pb-2">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-amber-300 flex items-center gap-2">
                        <span>🔑</span>
                        <span>Reset Kata Sandi Akun (Super Admin Override)</span>
                    </h3>
                    <span class="text-[10px] font-mono text-slate-400">Root Credential Reset</span>
                </div>

                <p class="text-[11px] text-slate-400 leading-relaxed">
                    Sebagai Super Admin, Anda dapat mereset kata sandi akun ini secara langsung. Kata sandi baru akan di-hash secara aman dan langsung dapat digunakan oleh pengguna bersangkutan.
                </p>

                <form action="{{ route('admin.users.reset-password', $targetUser->id) }}" method="POST" class="space-y-4 text-xs" onsubmit="return confirm('Konfirmasi reset kata sandi untuk akun {{ $targetUser->name }}?');">
                    @csrf

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="reset_password" class="block font-semibold text-slate-300 mb-1">
                                Kata Sandi Baru <span class="text-rose-400">*</span>
                            </label>
                            <input type="password" id="reset_password" name="password" required minlength="8"
                                class="w-full px-3.5 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-slate-100 placeholder-slate-500 focus:outline-none focus:border-amber-400 focus:ring-1 focus:ring-amber-400 transition text-xs font-mono">
                            <p class="text-[10px] text-slate-400 mt-1">Minimal 8 karakter.</p>
                        </div>

                        <div>
                            <label for="reset_password_confirmation" class="block font-semibold text-slate-300 mb-1">
                                Konfirmasi Kata Sandi Baru <span class="text-rose-400">*</span>
                            </label>
                            <input type="password" id="reset_password_confirmation" name="password_confirmation" required minlength="8"
                                class="w-full px-3.5 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-slate-100 placeholder-slate-500 focus:outline-none focus:border-amber-400 focus:ring-1 focus:ring-amber-400 transition text-xs font-mono">
                        </div>
                    </div>

                    <div class="flex justify-end pt-1">
                        <button type="submit" class="py-2.5 px-5 rounded-xl bg-amber-500 hover:bg-amber-400 text-slate-950 font-bold text-xs uppercase tracking-wider transition shadow-lg shadow-amber-500/20 cursor-pointer">
                            Eksekusi Reset Kata Sandi
                        </button>
                    </div>
                </form>
            </div>

            <!-- Card: Recent Audit Logs for User -->
            <div class="glass-panel p-5 rounded-2xl border border-slate-800 space-y-4 shadow-xl">
                <div class="flex items-center justify-between border-b border-slate-800 pb-2">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-teal-300 flex items-center gap-2">
                        <span>📜</span>
                        <span>Rekam Jejak Aktivitas Forensik (Audit Log)</span>
                    </h3>
                    <span class="text-[10px] font-mono text-slate-400">15 Entri Terakhir</span>
                </div>

                <div class="space-y-2 text-xs">
                    @forelse($recentLogs as $log)
                    <div class="p-3 rounded-xl bg-slate-900/60 border border-slate-800/80 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                        <div class="space-y-0.5">
                            <div class="flex items-center gap-2">
                                <span class="px-1.5 py-0.5 rounded text-[10px] font-mono font-bold bg-slate-800 text-teal-300 border border-slate-700 uppercase">
                                    {{ $log->action }}
                                </span>
                                <span class="text-slate-300 font-medium text-[11px]">
                                    {{ $log->entity_type }} #{{ $log->entity_id }}
                                </span>
                            </div>
                            @if($log->details)
                                <div class="text-[10px] text-slate-400 font-mono truncate max-w-md">
                                    {{ json_encode($log->details) }}
                                </div>
                            @endif
                        </div>

                        <div class="text-[10px] text-slate-500 font-mono shrink-0">
                            {{ $log->created_at ? $log->created_at->diffForHumans() : '-' }}
                        </div>
                    </div>
                    @empty
                    <div class="py-6 text-center text-slate-400 text-xs">
                        Belum ada catatan aktivitas audit log untuk akun ini.
                    </div>
                    @endforelse
                </div>
            </div>

        </div>

    </div>

</div>
@endsection

