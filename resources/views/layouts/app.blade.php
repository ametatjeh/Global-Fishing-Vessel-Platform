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

    <!-- Zero-FOUC Sidebar State Initialization -->
    <script>
        (function() {
            try {
                if (localStorage.getItem('gfvip_sidebar_minimized') === 'true') {
                    document.documentElement.classList.add('sidebar-collapsed');
                }
            } catch (e) {}
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body class="bg-slate-950 text-slate-100 font-sans antialiased selection:bg-teal-500 selection:text-slate-950 min-h-screen flex flex-col overflow-x-hidden">

    <!-- Mobile Drawer Backdrop Overlay -->
    <div id="sidebar-overlay" class="fixed inset-0 bg-slate-950/80 backdrop-blur-xs z-40 transition-opacity duration-300 hidden opacity-0 lg:hidden" aria-hidden="true"></div>

    <!-- Sidebar Navigation (Desktop Collapsible & Mobile Drawer) -->
    <aside id="app-sidebar"
        class="fixed inset-y-0 left-0 z-50 flex flex-col bg-slate-900 border-r border-slate-800 transition-all duration-300 ease-in-out -translate-x-full lg:translate-x-0 w-64 shadow-2xl lg:shadow-none">

        <!-- Brand Header -->
        <div class="h-16 px-4 flex items-center justify-between border-b border-slate-800 bg-slate-900/90 backdrop-blur shrink-0 sidebar-brand-header">
            <a href="{{ route('dashboard') }}" class="flex items-center gap-2.5 min-w-0" title="GFVT OPS - Global Fishing Vessel Tracking">
                <span class="text-2xl shrink-0">🛰️</span>
                <div class="leading-tight sidebar-brand-text truncate">
                    <div class="text-xs font-bold text-teal-400 tracking-wider uppercase font-mono">GFVT OPS</div>
                    <div class="text-[13px] font-bold text-white tracking-tight truncate max-w-[130px]">Vessel Tracking</div>
                </div>
            </a>

            <div class="flex items-center gap-1">
                <!-- Desktop Minimize/Expand Toggle Button -->
                <button id="sidebar-desktop-toggle" type="button"
                    class="hidden lg:flex items-center justify-center p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition border border-transparent hover:border-slate-700 cursor-pointer"
                    title="Kecilkan / Perluas Sidebar (Desktop)">
                    <svg id="sidebar-toggle-icon" class="w-4 h-4 transition-transform duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7" />
                    </svg>
                </button>

                <!-- Mobile Drawer Close Button -->
                <button id="sidebar-mobile-close" type="button"
                    class="lg:hidden p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition cursor-pointer"
                    aria-label="Tutup Menu Navigasi">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>

        <!-- Navigation Menu -->
        <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-1 text-xs">
            <div class="sidebar-section-title px-2 pb-1 text-[10px] uppercase font-bold text-slate-400 tracking-wider">
                Operasi &amp; Pemetaan
            </div>

            <a href="{{ route('dashboard') }}" data-tooltip="Overview Dashboard"
                class="sidebar-item flex items-center gap-2.5 px-3 py-2 rounded-lg font-medium transition {{ request()->routeIs('dashboard') || request()->routeIs('home') ? 'bg-teal-500/20 text-teal-300 border border-teal-500/40 shadow-xs' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                <span class="sidebar-icon">📊</span>
                <span class="sidebar-text truncate">Overview Dashboard</span>
            </a>

            <a href="{{ route('map.index') }}" data-tooltip="Global Vessel Map"
                class="sidebar-item flex items-center gap-2.5 px-3 py-2 rounded-lg font-medium transition {{ request()->routeIs('map.*') ? 'bg-teal-500/20 text-teal-300 border border-teal-500/40 shadow-xs' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                <span class="sidebar-icon">🗺️</span>
                <span class="sidebar-text truncate">Global Vessel Map</span>
            </a>

            <a href="{{ route('tracking.index') }}" data-tooltip="Live Vessel Tracking"
                class="sidebar-item flex items-center gap-2.5 px-3 py-2 rounded-lg font-medium transition {{ request()->routeIs('tracking.*') ? 'bg-teal-500/20 text-teal-300 border border-teal-500/40 shadow-xs' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                <span class="sidebar-icon">📡</span>
                <span class="sidebar-text truncate">Live Vessel Tracking</span>
            </a>

            <div class="sidebar-section-title pt-4 px-2 pb-1 text-[10px] uppercase font-bold text-slate-400 tracking-wider">
                Intelijen &amp; Registry
            </div>

            <a href="{{ route('vessels.index') }}" data-tooltip="Vessel Registry"
                class="sidebar-item flex items-center gap-2.5 px-3 py-2 rounded-lg font-medium transition {{ request()->routeIs('vessels.*') ? 'bg-teal-500/20 text-teal-300 border border-teal-500/40 shadow-xs' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                <span class="sidebar-icon">🚢</span>
                <span class="sidebar-text truncate">Vessel Registry</span>
            </a>

            <a href="{{ route('encounters.index') }}" data-tooltip="Vessel Encounters"
                class="sidebar-item flex items-center justify-between px-3 py-2 rounded-lg font-medium transition {{ request()->routeIs('encounters.*') ? 'bg-amber-500/20 text-amber-300 border border-amber-500/40 shadow-xs' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                <div class="flex items-center gap-2.5 min-w-0">
                    <span class="sidebar-icon">🤝</span>
                    <span class="sidebar-text truncate">Vessel Encounters</span>
                </div>
            </a>

            <a href="{{ route('dark-vessels.index') }}" data-tooltip="Analyst Review (SAR)"
                class="sidebar-item flex items-center justify-between px-3 py-2 rounded-lg font-medium transition {{ request()->routeIs('dark-vessels.*') || request()->routeIs('sar.*') ? 'bg-amber-500/20 text-amber-300 border border-amber-500/40 shadow-xs' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                <div class="flex items-center gap-2.5 min-w-0">
                    <span class="sidebar-icon">🛰️</span>
                    <span class="sidebar-text truncate">Analyst Review (SAR)</span>
                </div>
            </a>

            <a href="{{ route('compliance.index') }}" data-tooltip="Fishing Compliance"
                class="sidebar-item flex items-center justify-between px-3 py-2 rounded-lg font-medium transition {{ request()->routeIs('compliance.*') ? 'bg-teal-500/20 text-teal-300 border border-teal-500/40 shadow-xs' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                <div class="flex items-center gap-2.5 min-w-0">
                    <span class="sidebar-icon">⚖️</span>
                    <span class="sidebar-text truncate">Fishing Compliance</span>
                </div>
            </a>

            <a href="{{ route('investigations.index') }}" data-tooltip="Investigations Dossier"
                class="sidebar-item flex items-center gap-2.5 px-3 py-2 rounded-lg font-medium transition {{ request()->routeIs('investigations.*') ? 'bg-teal-500/20 text-teal-300 border border-teal-500/40 shadow-xs' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                <span class="sidebar-icon">📁</span>
                <span class="sidebar-text truncate">Investigations</span>
            </a>

            <div class="sidebar-section-title pt-4 px-2 pb-1 text-[10px] uppercase font-bold text-slate-400 tracking-wider">
                Integrasi &amp; Pengaturan
            </div>

            <a href="{{ route('profile.edit') }}" data-tooltip="User Settings"
                class="sidebar-item flex items-center gap-2.5 px-3 py-2 rounded-lg font-medium transition {{ request()->routeIs('profile.*') ? 'bg-teal-500/20 text-teal-300 border border-teal-500/40 shadow-xs' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                <span class="sidebar-icon">👤</span>
                <span class="sidebar-text truncate">User Settings</span>
            </a>

            <a href="{{ route('providers.index') }}" data-tooltip="Data Sources & Providers"
                class="sidebar-item flex items-center gap-2.5 px-3 py-2 rounded-lg font-medium transition {{ request()->routeIs('providers.*') ? 'bg-teal-500/20 text-teal-300 border border-teal-500/40 shadow-xs' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                <span class="sidebar-icon">🔌</span>
                <span class="sidebar-text truncate">Data Sources &amp; Providers</span>
            </a>

            <a href="{{ route('settings.index') }}" data-tooltip="System Settings"
                class="sidebar-item flex items-center gap-2.5 px-3 py-2 rounded-lg font-medium transition {{ request()->routeIs('settings.*') ? 'bg-teal-500/20 text-teal-300 border border-teal-500/40 shadow-xs' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                <span class="sidebar-icon">⚙️</span>
                <span class="sidebar-text truncate">System Settings</span>
            </a>

            <a href="{{ route('audit-logs.index') }}" data-tooltip="Activity Logs"
                class="sidebar-item flex items-center gap-2.5 px-3 py-2 rounded-lg font-medium transition {{ request()->routeIs('audit-logs.*') ? 'bg-teal-500/20 text-teal-300 border border-teal-500/40 shadow-xs' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                <span class="sidebar-icon">📜</span>
                <span class="sidebar-text truncate">Activity Logs</span>
            </a>

            @if(auth()->user()?->isSuperAdmin())
            <div class="sidebar-section-title pt-4 px-2 pb-1 text-[10px] uppercase font-bold text-amber-400 tracking-wider flex items-center justify-between">
                <span>Administrasi Root</span>
                <span class="sidebar-badge px-1.5 py-0.5 rounded text-[8px] bg-amber-500/20 text-amber-300 font-mono border border-amber-500/30">SUPER ADMIN</span>
            </div>

            <a href="{{ route('admin.users.index') }}" data-tooltip="Manajemen Akun"
                class="sidebar-item flex items-center gap-2.5 px-3 py-2 rounded-lg font-medium transition {{ request()->routeIs('admin.users.*') ? 'bg-amber-500/20 text-amber-300 border border-amber-500/40 shadow-xs' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                <span class="sidebar-icon">🛡️</span>
                <span class="sidebar-text truncate">Manajemen Akun</span>
            </a>
            @endif
        </nav>

        <!-- User Footer & Logout -->
        <div class="p-3 border-t border-slate-800 bg-slate-900/60 shrink-0 sidebar-footer">
            @auth
            <div class="flex items-center justify-between gap-2">
                <a href="{{ route('profile.edit') }}" class="flex items-center gap-2.5 min-w-0 hover:opacity-85 transition group flex-1" title="Buka User Settings ({{ auth()->user()->name }})">
                    <img src="{{ auth()->user()->avatar_url }}" alt="{{ auth()->user()->name }}" class="w-8 h-8 rounded-full object-cover border border-slate-700 group-hover:border-teal-400 transition shrink-0">
                    <div class="truncate sidebar-user-details">
                        <div class="text-xs font-bold text-white truncate group-hover:text-teal-300 transition">{{ auth()->user()->name }}</div>
                        <div class="text-[10px] text-teal-400 uppercase font-mono">{{ auth()->user()->role ?? 'Analyst' }}</div>
                    </div>
                </a>
                <div class="flex items-center gap-1 shrink-0 sidebar-user-actions">
                    <a href="{{ route('profile.edit') }}" class="p-1.5 rounded-lg text-slate-400 hover:text-teal-300 hover:bg-teal-500/10 transition" title="User Settings">
                        <span>⚙️</span>
                    </a>
                    <form action="{{ route('logout') }}" method="POST">
                        @csrf
                        <button type="submit" class="p-1.5 rounded-lg text-slate-400 hover:text-rose-400 hover:bg-rose-500/10 transition cursor-pointer" title="Keluar">
                            <span>🚪</span>
                        </button>
                    </form>
                </div>
            </div>
            @endauth
        </div>
    </aside>

    <!-- Main Content Area -->
    <div id="main-content" class="flex-1 flex flex-col min-h-screen transition-all duration-300 ease-in-out pl-0 lg:pl-64 max-w-full overflow-x-hidden">
        <!-- Top Operational Bar -->
        <header class="h-16 bg-slate-900/80 border-b border-slate-800 sticky top-0 z-20 backdrop-blur px-4 sm:px-6 flex items-center justify-between gap-2 sm:gap-4">
            <div class="flex items-center gap-2.5 sm:gap-3 min-w-0">
                <!-- Mobile Hamburger Toggle Button -->
                <button id="sidebar-mobile-toggle" type="button"
                    class="lg:hidden p-2 rounded-lg text-slate-300 hover:text-white hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-teal-500/50 transition cursor-pointer shrink-0"
                    aria-label="Buka Menu Navigasi" title="Buka Menu Navigasi">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>

                <h1 class="text-xs sm:text-sm font-bold text-white tracking-wide uppercase truncate flex items-center gap-2">
                    @yield('header_title', 'Maritime Intelligence Platform')
                </h1>
            </div>

            <!-- Health & Provider Status Badges -->
            <div class="flex items-center gap-2 sm:gap-3 text-xs shrink-0">
                <div class="hidden md:flex items-center gap-2 px-2.5 py-1 rounded-full bg-slate-800/80 border border-slate-700/60 text-[11px]">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                    <span class="text-slate-300">Spatial Engine: <strong>Active</strong></span>
                </div>

                <a href="{{ route('map.index') }}" class="px-2.5 sm:px-3 py-1.5 rounded-lg bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold text-xs transition shadow-sm flex items-center gap-1.5 shrink-0">
                    <span>🗺️</span>
                    <span class="hidden sm:inline">Peta Global</span>
                </a>

                @auth
                <a href="{{ route('profile.edit') }}" class="flex items-center gap-2 p-1 pl-1.5 pr-2 sm:pr-3 rounded-full bg-slate-800/80 hover:bg-slate-800 border border-slate-700/60 hover:border-teal-400/50 transition text-xs text-slate-300 hover:text-white shrink-0" title="User Settings">
                    <img src="{{ auth()->user()->avatar_url }}" alt="{{ auth()->user()->name }}" class="w-6 h-6 rounded-full object-cover border border-slate-600">
                    <span class="font-medium text-[11px] truncate max-w-[90px] hidden sm:inline">{{ auth()->user()->name }}</span>
                </a>
                @endauth
            </div>
        </header>

        <!-- Flash Notifications -->
        <div class="px-4 sm:px-6 pt-4">
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
        <main class="flex-1 p-4 sm:p-6">
            @yield('content')
        </main>

        <!-- Single-Line Compact Footer -->
        <footer class="h-9 min-h-[36px] max-h-[40px] px-4 sm:px-6 border-t border-slate-800/80 bg-slate-950/80 text-[11px] max-sm:text-[10px] text-slate-400 flex items-center justify-between gap-3 mt-auto shrink-0 select-none">
            <div class="truncate text-slate-400 font-normal">
                <span class="font-bold text-slate-300">GFVIP</span> &middot; <span>Global Fishing Vessel Intelligence Platform</span>
            </div>
            <div class="shrink-0 text-slate-400 text-right whitespace-nowrap">
                <span>Research Platform</span> &middot; <span>&copy; {{ date('Y') }}</span>
            </div>
        </footer>
    </div>

    <!-- Floating Tooltip Container for Minimized Sidebar -->
    <div id="sidebar-floating-tooltip" class="fixed hidden px-2.5 py-1 rounded-md bg-slate-800 text-teal-300 border border-slate-700 text-xs font-semibold shadow-2xl pointer-events-none z-[100] whitespace-nowrap transition-opacity duration-150"></div>

    <!-- Sidebar State & Interaction Script -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const sidebar = document.getElementById('app-sidebar');
            const overlay = document.getElementById('sidebar-overlay');
            const desktopToggle = document.getElementById('sidebar-desktop-toggle');
            const mobileToggle = document.getElementById('sidebar-mobile-toggle');
            const mobileClose = document.getElementById('sidebar-mobile-close');
            const tooltip = document.getElementById('sidebar-floating-tooltip');

            // --- Desktop Minimize / Expand Toggle ---
            if (desktopToggle) {
                desktopToggle.addEventListener('click', function () {
                    const isCollapsed = document.documentElement.classList.toggle('sidebar-collapsed');
                    try {
                        localStorage.setItem('gfvip_sidebar_minimized', isCollapsed ? 'true' : 'false');
                    } catch (e) {}

                    if (tooltip) {
                        tooltip.classList.add('hidden');
                    }

                    // Trigger window resize event so MapLibre / charts adapt immediately
                    window.dispatchEvent(new Event('resize'));
                });
            }

            // --- Mobile Drawer Handlers ---
            function openMobileDrawer() {
                if (!sidebar || !overlay) return;
                sidebar.classList.remove('-translate-x-full');
                sidebar.classList.add('translate-x-0');
                overlay.classList.remove('hidden');
                setTimeout(() => {
                    overlay.classList.remove('opacity-0');
                    overlay.classList.add('opacity-100');
                }, 10);
                document.body.classList.add('overflow-hidden');
            }

            function closeMobileDrawer() {
                if (!sidebar || !overlay) return;
                sidebar.classList.add('-translate-x-full');
                sidebar.classList.remove('translate-x-0');
                overlay.classList.remove('opacity-100');
                overlay.classList.add('opacity-0');
                setTimeout(() => {
                    overlay.classList.add('hidden');
                }, 300);
                document.body.classList.remove('overflow-hidden');
            }

            if (mobileToggle) {
                mobileToggle.addEventListener('click', openMobileDrawer);
            }
            if (mobileClose) {
                mobileClose.addEventListener('click', closeMobileDrawer);
            }
            if (overlay) {
                overlay.addEventListener('click', closeMobileDrawer);
            }

            // Close drawer on Escape key press
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && window.innerWidth < 1024) {
                    closeMobileDrawer();
                }
            });

            // Automatically close drawer when clicking any navigation link on mobile
            const navLinks = sidebar ? sidebar.querySelectorAll('a.sidebar-item') : [];
            navLinks.forEach(function (link) {
                link.addEventListener('click', function () {
                    if (window.innerWidth < 1024) {
                        closeMobileDrawer();
                    }
                });

                // Desktop Tooltip Hover on Minimized Sidebar
                link.addEventListener('mouseenter', function () {
                    if (window.innerWidth >= 1024 && document.documentElement.classList.contains('sidebar-collapsed') && tooltip) {
                        const tipText = link.getAttribute('data-tooltip');
                        if (!tipText) return;

                        tooltip.textContent = tipText;
                        const rect = link.getBoundingClientRect();
                        tooltip.style.top = (rect.top + (rect.height / 2) - 12) + 'px';
                        tooltip.style.left = (rect.right + 12) + 'px';
                        tooltip.classList.remove('hidden');
                    }
                });

                link.addEventListener('mouseleave', function () {
                    if (tooltip) {
                        tooltip.classList.add('hidden');
                    }
                });
            });

            // Hide tooltip if window resizes to mobile
            window.addEventListener('resize', function () {
                if (tooltip && window.innerWidth < 1024) {
                    tooltip.classList.add('hidden');
                }
            });
        });
    </script>

    @stack('scripts')
</body>
</html>
