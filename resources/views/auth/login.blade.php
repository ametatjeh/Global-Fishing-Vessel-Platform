<!DOCTYPE html>
<html lang="id" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login — Global Fishing Vessel Intelligence Platform</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-950 text-slate-100 font-sans antialiased min-h-screen flex items-center justify-center p-4">

    <div class="w-full max-w-md">
        <!-- Brand Header -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-teal-500/10 border border-teal-500/30 text-3xl mb-3 shadow-lg shadow-teal-500/10">
                🛰️
            </div>
            <h1 class="text-xl font-black text-white tracking-wide uppercase">
                Global Fishing Vessel Intelligence
            </h1>
            <p class="text-xs text-slate-400 mt-1">
                Platform Intelijen Maritim: Tracking, Compliance & Dark Vessel Detection
            </p>
        </div>

        <!-- Login Card -->
        <div class="glass-panel p-6 sm:p-8 rounded-2xl border border-slate-800 shadow-2xl relative">
            <h2 class="text-sm font-bold text-slate-200 uppercase tracking-wider mb-4 border-b border-slate-800 pb-2">
                Otentikasi Operasional
            </h2>

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
                    <label for="email" class="block font-semibold text-slate-300 mb-1">Email Dinas / Analis</label>
                    <input type="email" id="email" name="email" value="{{ old('email', 'admin@maritime-intel.org') }}" required autofocus
                        class="w-full px-3.5 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-slate-100 placeholder-slate-500 focus:outline-none focus:border-teal-400 focus:ring-1 focus:ring-teal-400 transition">
                </div>

                <div>
                    <label for="password" class="block font-semibold text-slate-300 mb-1">Kata Sandi</label>
                    <input type="password" id="password" name="password" value="Password123!" required
                        class="w-full px-3.5 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-slate-100 placeholder-slate-500 focus:outline-none focus:border-teal-400 focus:ring-1 focus:ring-teal-400 transition font-mono">
                </div>

                <div class="flex items-center justify-between text-[11px] text-slate-400 pt-1">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="remember" class="rounded bg-slate-900 border-slate-700 text-teal-500 focus:ring-teal-400">
                        <span>Ingat sesi ini</span>
                    </label>
                    <span class="text-teal-400 font-mono">Akun Default: admin@maritime-intel.org</span>
                </div>

                <button type="submit" class="w-full py-2.5 px-4 rounded-xl bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold text-xs uppercase tracking-wider transition shadow-lg shadow-teal-500/20 cursor-pointer mt-2">
                    Masuk ke Sistem Intelijen
                </button>
            </form>

            <div class="mt-6 pt-4 border-t border-slate-800/80 text-center text-[11px] text-slate-500">
                Akses dibatasi hanya untuk personel berwenang. Segala aktivitas dicatat dalam sistem audit trail terenkripsi.
            </div>
        </div>
    </div>

</body>
</html>
