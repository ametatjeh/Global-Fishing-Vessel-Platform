<!DOCTYPE html>
<html lang="id" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? config('app.name', 'Global Fishing Vessel Tracking') }}</title>

    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body class="bg-slate-950 text-slate-100 font-sans antialiased selection:bg-teal-500 selection:text-slate-950 min-h-screen flex">

    <!-- Sidebar Navigation -->
    <aside class="w-64 bg-slate-900 border-r border-slate-800 flex flex-col shrink-0 fixed inset-y-0 z-30">
        <!-- Brand Header -->
        <div class="h-16 px-4 flex items-center justify-between border-b border-slate-800 bg-slate-900/90 backdrop-blur">
            <a href="{{ route('dashboard') }}" class="flex items-center gap-2.5">
                <span class="text-2xl">🛰️</span>
                <div class="leading-tight">
                    <div class="text-xs font-bold text-teal-400 tracking-wider uppercase font-mono">GFVT OPS</div>
                    <div class="text-[13px] font-bold text-white tracking-tight truncate max-w-[150px]">Vessel Tracking</div>
                </div>
            </a>
            <span class="text-[10px] px-1.5 py-0.5 rounded bg-teal-500/10 text-teal-300 border border-teal-500/30 font-mono">v1.0</span>
        </div>

        <!-- Navigation Menu -->
        <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-1 text-xs">
            <div class="px-2 pb-1 text-[10px] uppercase font-bold text-slate-400 tracking-wider">Operasi & Pemetaan</div>

            <a href="{{ route('dashboard') }}" class="flex items-center gap-2.5 px-3 py-2 rounded-lg font-medium transition {{ request()->routeIs('dashboard') || request()->routeIs('home') ? 'bg-teal-500/20 text-teal-300 border border-teal-500/40 shadow-xs' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                <span>📊</span>
                <span>Overview Dashboard</span>
            </a>

            <a href="{{ route('map.index') }}" class="flex items-center gap-2.5 px-3 py-2 rounded-lg font-medium transition {{ request()->routeIs('map.*') ? 'bg-teal-500/20 text-teal-300 border border-teal-500/40 shadow-xs' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                <span>🗺️</span>
                <span>Global Vessel Map</span>
            </a>

            <a href="{{ route('tracking.index') }}" class="flex items-center gap-2.5 px-3 py-2 rounded-lg font-medium transition {{ request()->routeIs('tracking.*') ? 'bg-teal-500/20 text-teal-300 border border-teal-500/40 shadow-xs' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                <span>📡</span>
                <span>Live Vessel Tracking</span>
            </a>

            <div class="pt-4 px-2 pb-1 text-[10px] uppercase font-bold text-slate-400 tracking-wider">Intelijen & Registry</div>

            <a href="{{ route('vessels.index') }}" class="flex items-center gap-2.5 px-3 py-2 rounded-lg font-medium transition {{ request()->routeIs('vessels.*') ? 'bg-teal-500/20 text-teal-300 border border-teal-500/40 shadow-xs' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                <span>🚢</span>
                <span>Vessel Registry</span>
            </a>

            <a href="{{ route('fishing-grounds.index') }}" class="flex items-center gap-2.5 px-3 py-2 rounded-lg font-medium transition {{ request()->routeIs('fishing-grounds.*') ? 'bg-teal-500/20 text-teal-300 border border-teal-500/40 shadow-xs' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                <span>🧭</span>
                <span>Fishing Ground (FAO/EEZ)</span>
            </a>

            <a href="{{ route('encounters.index') }}" class="flex items-center justify-between px-3 py-2 rounded-lg font-medium transition {{ request()->routeIs('encounters.*') ? 'bg-amber-500/20 text-amber-300 border border-amber-500/40 shadow-xs' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                <div class="flex items-center gap-2.5">
                    <span>🤝</span>
                    <span>Vessel Encounters</span>
                </div>
            </a>

            <a href="{{ route('dark-vessels.index') }}" class="flex items-center justify-between px-3 py-2 rounded-lg font-medium transition {{ request()->routeIs('dark-vessels.*') || request()->routeIs('sar.*') ? 'bg-amber-500/20 text-amber-300 border border-amber-500/40 shadow-xs' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                <div class="flex items-center gap-2.5">
                    <span>🛰️</span>
                    <span>Analyst Review (SAR)</span>
                </div>
            </a>

            <a href="{{ route('compliance.index') }}" class="flex items-center justify-between px-3 py-2 rounded-lg font-medium transition {{ request()->routeIs('compliance.*') ? 'bg-teal-500/20 text-teal-300 border border-teal-500/40 shadow-xs' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                <div class="flex items-center gap-2.5">
                    <span>⚖️</span>
                    <span>Fishing Compliance</span>
                </div>
            </a>

            <a href="{{ route('investigations.index') }}" class="flex items-center gap-2.5 px-3 py-2 rounded-lg font-medium transition {{ request()->routeIs('investigations.*') ? 'bg-teal-500/20 text-teal-300 border border-teal-500/40 shadow-xs' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                <span>📁</span>
                <span>Investigations</span>
            </a>

            <div class="pt-4 px-2 pb-1 text-[10px] uppercase font-bold text-slate-400 tracking-wider">Integrasi & Pengaturan</div>

            <a href="{{ route('profile.edit') }}" class="flex items-center gap-2.5 px-3 py-2 rounded-lg font-medium transition {{ request()->routeIs('profile.*') ? 'bg-teal-500/20 text-teal-300 border border-teal-500/40 shadow-xs' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                <span>👤</span>
                <span>User Settings</span>
            </a>

            <a href="{{ route('providers.index') }}" class="flex items-center gap-2.5 px-3 py-2 rounded-lg font-medium transition {{ request()->routeIs('providers.*') ? 'bg-teal-500/20 text-teal-300 border border-teal-500/40 shadow-xs' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                <span>🔌</span>
                <span>Data Sources & Providers</span>
            </a>

            <a href="{{ route('settings.index') }}" class="flex items-center gap-2.5 px-3 py-2 rounded-lg font-medium transition {{ request()->routeIs('settings.*') ? 'bg-teal-500/20 text-teal-300 border border-teal-500/40 shadow-xs' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                <span>⚙️</span>
                <span>System Settings</span>
            </a>

            <a href="{{ route('audit-logs.index') }}" class="flex items-center gap-2.5 px-3 py-2 rounded-lg font-medium transition {{ request()->routeIs('audit-logs.*') ? 'bg-teal-500/20 text-teal-300 border border-teal-500/40 shadow-xs' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                <span>📜</span>
                <span>Activity Logs</span>
            </a>

            @if(auth()->user()?->isSuperAdmin())
            <div class="pt-4 px-2 pb-1 text-[10px] uppercase font-bold text-amber-400 tracking-wider flex items-center justify-between">
                <span>Administrasi Root</span>
                <span class="px-1.5 py-0.5 rounded text-[8px] bg-amber-500/20 text-amber-300 font-mono border border-amber-500/30">SUPER ADMIN</span>
            </div>

            <a href="{{ route('admin.users.index') }}" class="flex items-center gap-2.5 px-3 py-2 rounded-lg font-medium transition {{ request()->routeIs('admin.users.*') ? 'bg-amber-500/20 text-amber-300 border border-amber-500/40 shadow-xs' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                <span>🛡️</span>
                <span>Manajemen Akun</span>
            </a>
            @endif
        </nav>

        <!-- User Footer & Logout -->
        <div class="p-3 border-t border-slate-800 bg-slate-900/60">
            @auth
            <div class="flex items-center justify-between gap-2">
                <a href="{{ route('profile.edit') }}" class="flex items-center gap-2.5 min-w-0 hover:opacity-85 transition group flex-1" title="Buka User Settings">
                    <img src="{{ auth()->user()->avatar_url }}" alt="{{ auth()->user()->name }}" class="w-8 h-8 rounded-full object-cover border border-slate-700 group-hover:border-teal-400 transition shrink-0">
                    <div class="truncate">
                        <div class="text-xs font-bold text-white truncate group-hover:text-teal-300 transition">{{ auth()->user()->name }}</div>
                        <div class="text-[10px] text-teal-400 uppercase font-mono">{{ auth()->user()->role ?? 'Analyst' }}</div>
                    </div>
                </a>
                <div class="flex items-center gap-1 shrink-0">
                    <a href="{{ route('profile.edit') }}" class="p-1.5 rounded-lg text-slate-400 hover:text-teal-300 hover:bg-teal-500/10 transition" title="User Settings">
                        <span>⚙️</span>
                    </a>
                    <form action="{{ route('logout') }}" method="POST">
                        @csrf
                        <button type="submit" class="p-1.5 rounded-lg text-slate-400 hover:text-rose-400 hover:bg-rose-500/10 transition" title="Keluar">
                            <span>🚪</span>
                        </button>
                    </form>
                </div>
            </div>
            @endauth
        </div>
    </aside>

    <!-- Main Content Area -->
    <div class="flex-1 pl-64 flex flex-col min-h-screen">
        <!-- Top Operational Bar -->
        <header class="h-16 bg-slate-900/80 border-b border-slate-800 sticky top-0 z-20 backdrop-blur px-6 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <h1 class="text-sm font-bold text-white tracking-wide uppercase flex items-center gap-2">
                    @yield('header_title', 'Maritime Intelligence Platform')
                </h1>
            </div>

            <!-- Health & Provider Status Badges -->
            <div class="flex items-center gap-3 text-xs">
                <div class="hidden md:flex items-center gap-2 px-2.5 py-1 rounded-full bg-slate-800/80 border border-slate-700/60 text-[11px]">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                    <span class="text-slate-300">Spatial Engine: <strong>Active</strong></span>
                </div>

                <a href="{{ route('map.index') }}" class="px-3 py-1.5 rounded-lg bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold text-xs transition shadow-sm flex items-center gap-1.5">
                    <span>🗺️</span>
                    <span>Peta Global</span>
                </a>

                @auth
                <a href="{{ route('profile.edit') }}" class="flex items-center gap-2 p-1 pl-1.5 pr-3 rounded-full bg-slate-800/80 hover:bg-slate-800 border border-slate-700/60 hover:border-teal-400/50 transition text-xs text-slate-300 hover:text-white" title="User Settings">
                    <img src="{{ auth()->user()->avatar_url }}" alt="{{ auth()->user()->name }}" class="w-6 h-6 rounded-full object-cover border border-slate-600">
                    <span class="font-medium text-[11px] truncate max-w-[100px] hidden sm:inline">{{ auth()->user()->name }}</span>
                </a>
                @endauth
            </div>
        </header>

        <!-- Flash Notifications -->
        <div class="px-6 pt-4">
            @if(session('status'))
            <div class="p-3 mb-4 rounded-xl bg-teal-500/15 border border-teal-500/30 text-teal-200 text-xs flex items-center gap-2">
                <span>✓</span>
                <span>{{ session('status') }}</span>
            </div>
            @endif

            @if(session('warning'))
            <div class="p-3 mb-4 rounded-xl bg-amber-500/15 border border-amber-500/30 text-amber-200 text-xs flex items-center gap-2">
                <span>⚠️</span>
                <span>{{ session('warning') }}</span>
            </div>
            @endif

            @if(session('error'))
            <div class="p-3 mb-4 rounded-xl bg-rose-500/15 border border-rose-500/30 text-rose-200 text-xs flex items-center gap-2">
                <span>✗</span>
                <span>{{ session('error') }}</span>
            </div>
            @endif
        </div>

        <!-- Page Content -->
        <main class="flex-1 p-6">
            @yield('content')
        </main>

        <!-- Global Disclaimer Footer -->
        <footer class="px-6 py-4 border-t border-slate-800 text-[11px] text-slate-400 flex flex-col sm:flex-row items-center justify-between gap-2">
            <div>
                &copy; {{ date('Y') }} {{ config('app.name', 'Global Fishing Vessel Tracking') }}. All rights reserved.
            </div>
            <div class="text-[10px] text-slate-400 max-w-xl text-center sm:text-right">
                Pemberitahuan: Proximity encounter, batas statistik FAO, dan deteksi SAR merupakan indikasi observasional intelijen maritim dan bukan bukti definitif pelanggaran hukum tanpa pembuktian multi-sumber.
            </div>
        </footer>
    </div>

    @stack('scripts')
</body>
</html>
