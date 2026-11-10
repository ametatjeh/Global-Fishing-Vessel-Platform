<!DOCTYPE html>
<html lang="en" class="dark">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login — Global Fishing Vessel Tracking</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        #particles-js {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 1;
            pointer-events: auto;
        }

        #particles-js canvas {
            display: block;
            vertical-align: bottom;
            width: 100% !important;
            height: 100% !important;
        }

        /* Disable particles canvas on mobile screens (< 768px) */
        @media (max-width: 767px) {
            #particles-js {
                display: none !important;
            }
        }
    </style>
</head>

<body class="bg-slate-950 text-slate-100 font-sans antialiased min-h-screen flex items-center justify-center p-4 relative overflow-x-hidden selection:bg-teal-500 selection:text-slate-950">

    <!-- Ambient Dark Maritime Gradient -->
    <div class="fixed inset-0 bg-gradient-to-tr from-slate-950 via-slate-900/60 to-slate-950 pointer-events-none" style="z-index: 0;"></div>

    <!-- Interactive Particle Background Canvas (Hidden on Mobile < 768px) -->
    <div id="particles-js" class="hidden md:block" aria-hidden="true"></div>

    <div class="w-full max-w-md relative" style="z-index: 10;">
        <!-- Brand Header -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-teal-500/10 border border-teal-500/30 text-3xl mb-3 shadow-lg shadow-teal-500/10">
                🛰️
            </div>
            <h1 class="text-xl font-black text-white tracking-wide uppercase">
                Global Fishing Vessel Tracking
            </h1>
            <p class="text-xs text-slate-400 mt-1">
                Global Vessel Tracking &bull; Maritime Monitoring, AIS Intelligence, Fisheries Compliance &amp; Dark Vessel Detection
            </p>
        </div>

        <!-- Login Card -->
        <div class="glass-panel p-6 sm:p-8 rounded-2xl border border-slate-800 shadow-2xl relative">
            <h2 class="text-sm font-bold text-slate-200 uppercase tracking-wider mb-4 border-b border-slate-800 pb-2">
                Operational Authentication
            </h2>

            @if(session('error'))
            <div class="p-3 mb-4 rounded-xl bg-rose-500/15 border border-rose-500/30 text-rose-300 text-xs flex items-center gap-2">
                <span>✗</span>
                <span>{{ session('error') }}</span>
            </div>
            @endif

            @if(session('status'))
            <div class="p-3 mb-4 rounded-xl bg-teal-500/15 border border-teal-500/30 text-teal-300 text-xs flex items-center gap-2">
                <span>✓</span>
                <span>{{ session('status') }}</span>
            </div>
            @endif

            @if($errors->any())
            <div class="p-3 mb-4 rounded-xl bg-rose-500/15 border border-rose-500/30 text-rose-300 text-xs space-y-1">
                @foreach($errors->all() as $err)
                <div>• {{ $err }}</div>
                @endforeach
            </div>
            @endif

            <form action="{{ route('login.post') }}" method="POST" class="space-y-4 text-xs">
                @csrf

                <div>
                    <label for="email" class="block font-semibold text-slate-300 mb-1">Email</label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus
                        class="w-full px-3.5 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-slate-100 placeholder-slate-500 focus:outline-none focus:border-teal-400 focus:ring-1 focus:ring-teal-400 transition"
                        placeholder="user@nagakecil.site">
                </div>

                <div>
                    <label for="password" class="block font-semibold text-slate-300 mb-1">Password</label>
                    <input type="password" id="password" name="password" required
                        class="w-full px-3.5 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-slate-100 placeholder-slate-500 focus:outline-none focus:border-teal-400 focus:ring-1 focus:ring-teal-400 transition font-mono"
                        placeholder="••••••••">
                </div>

                <div class="flex items-center justify-between text-[11px] text-slate-400 pt-1">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="remember" class="rounded bg-slate-900 border-slate-700 text-teal-500 focus:ring-teal-400">
                        <span>Remember session</span>
                    </label>
                </div>

                <button type="submit" class="w-full py-2.5 px-4 rounded-xl bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold text-xs uppercase tracking-wider transition shadow-lg shadow-teal-500/20 cursor-pointer mt-2">
                    Sign In
                </button>
            </form>

            <div class="relative my-5">
                <div class="absolute inset-0 flex items-center">
                    <div class="w-full border-t border-slate-800"></div>
                </div>
                <div class="relative flex justify-center text-[10px] uppercase font-mono">
                    <span class="bg-slate-900 px-2.5 text-slate-400">Or Single Sign-On</span>
                </div>
            </div>

            <a href="{{ route('auth.google.redirect') }}" class="w-full py-2.5 px-4 rounded-xl bg-slate-900 hover:bg-slate-800 border border-slate-700 hover:border-slate-600 text-slate-200 hover:text-white font-medium text-xs transition shadow flex items-center justify-center gap-2.5">
                <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24">
                    <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" />
                    <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" />
                    <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z" />
                    <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z" />
                </svg>
                <span>Sign in with Google</span>
            </a>

            <div class="mt-6 pt-4 border-t border-slate-800/80 text-center text-[11px] text-slate-500">
                Authorized personnel only. All access and activity is logged and monitored.
            </div>
        </div>
    </div>

    <!-- Particles.js Engine & Config Loader (Disabled on Mobile) -->
    <script src="{{ asset('particles.min.js') }}"></script>
    <script>
        (function() {
            var configUrl = "{{ asset('particlesjs-config.json') }}";
            var particlesLoaded = false;

            function isMobileScreen() {
                return window.innerWidth < 768 || (window.matchMedia && window.matchMedia('(max-width: 767px)').matches);
            }

            function startParticles() {
                if (isMobileScreen()) {
                    return;
                }

                if (typeof window.particlesJS !== 'undefined' && !particlesLoaded) {
                    particlesLoaded = true;
                    window.particlesJS.load('particles-js', configUrl, function() {
                        console.log('particles.js loaded via particlesjs-config.json (desktop only)');
                    });
                }
            }

            function destroyParticles() {
                if (window.pJSDom && window.pJSDom.length > 0) {
                    window.pJSDom.forEach(function(item) {
                        if (item && item.pJS && item.pJS.fn && item.pJS.fn.vendors && item.pJS.fn.vendors.destroypJS) {
                            item.pJS.fn.vendors.destroypJS();
                        }
                    });
                    window.pJSDom = [];
                }
                particlesLoaded = false;
            }

            // Only initialize on desktop/tablet (>= 768px)
            if (!isMobileScreen()) {
                if (typeof window.particlesJS !== 'undefined') {
                    startParticles();
                } else {
                    // CDN fallback if local asset is unreachable
                    var script = document.createElement('script');
                    script.src = 'https://cdn.jsdelivr.net/npm/particles.js@2.0.0/particles.min.js';
                    script.onload = startParticles;
                    script.onerror = function() {
                        console.warn('Gagal memuat script particles.js');
                    };
                    document.head.appendChild(script);
                }
            }

            // Dynamically manage lifecycle if screen orientation or viewport changes
            window.addEventListener('resize', function() {
                if (isMobileScreen()) {
                    if (particlesLoaded) {
                        destroyParticles();
                    }
                } else {
                    if (!particlesLoaded) {
                        startParticles();
                    }
                }
            });
        })();
    </script>
</body>

</html>