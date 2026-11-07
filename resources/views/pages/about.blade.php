@extends('layouts.app')

@section('header_title', 'About & Legal Attributions — Global Fishing Watch Non-Commercial Compliance')

@section('content')
<div class="max-w-4xl mx-auto space-y-6">

    <!-- Header Section -->
    <div class="glass-panel p-6 rounded-2xl border border-slate-800 shadow-xl space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-800 pb-3">
            <div>
                <div class="flex items-center gap-2">
                    <span class="px-2.5 py-0.5 rounded text-xs font-mono font-bold tracking-wider uppercase bg-teal-500/20 text-teal-300 border border-teal-500/40">
                        NON-COMMERCIAL RESEARCH
                    </span>
                    <span class="px-2 py-0.5 rounded text-[10px] font-mono uppercase bg-slate-800 text-slate-300 border border-slate-700">
                        Platform Intelijen Maritim
                    </span>
                </div>
                <h2 class="text-2xl font-black text-white tracking-wide uppercase mt-1">
                    Tentang Global Fishing Vessel Tracking
                </h2>
            </div>
            <div class="text-right text-[11px] text-slate-400 font-mono">
                <div>Pembaruan Terakhir:</div>
                <div class="text-slate-200 font-bold">{{ now()->format('Y-m-d H:i') }} UTC</div>
            </div>
        </div>

        <p class="text-xs text-slate-300 leading-relaxed">
            Platform <strong>Global Fishing Vessel Tracking (GFVIP)</strong> dirancang secara khusus untuk mendukung penelitian, pemantauan aktivitas kapal perikanan, transparansi informasi maritim, analisis potensi <em>Illegal, Unreported, and Unregulated</em> (IUU) Fishing, serta kepentingan pendidikan dan advokasi publik non-komersial.
        </p>
    </div>

    <!-- MANDATORY GLOBAL FISHING WATCH ATTRIBUTION CARD -->
    <div class="glass-panel p-6 rounded-2xl border border-teal-500/30 bg-teal-950/10 shadow-xl space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-teal-500/20 pb-3">
            <div class="flex items-center gap-2.5">
                <span class="text-2xl">🌐</span>
                <div>
                    <h3 class="text-sm font-bold uppercase tracking-wider text-teal-300">
                        Global Fishing Watch Data Attribution
                    </h3>
                    <div class="text-[11px] text-slate-400">
                        Sumber Data: <a href="https://globalfishingwatch.org" target="_blank" rel="noopener noreferrer" class="text-teal-400 hover:underline font-semibold">Global Fishing Watch (GFW)</a>
                    </div>
                </div>
            </div>
            <span class="px-2.5 py-1 rounded-full text-[10px] font-mono font-bold uppercase bg-teal-500/20 text-teal-300 border border-teal-500/40 w-fit">
                NON-COMMERCIAL RESEARCH
            </span>
        </div>

        <!-- Official Mandatory Attribution Statement -->
        <blockquote class="p-4 rounded-xl bg-slate-900/90 border border-slate-800 text-xs text-slate-200 italic leading-relaxed">
            &ldquo;This application uses data and services provided by Global Fishing Watch (GFW) for non-commercial research, educational, and public-interest purposes. GFW data are subject to applicable licensing, attribution, and usage restrictions.&rdquo;
        </blockquote>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs text-slate-300">
            <div class="p-3 rounded-xl bg-slate-900 border border-slate-800 space-y-1">
                <span class="text-[10px] uppercase font-bold text-slate-400 block">Ketentuan Lisensi GFW:</span>
                <div>Lisensi: <strong class="text-teal-300">{{ $gfwConfig['license_type'] ?? 'GFW Research & Non-Commercial Terms' }}</strong></div>
                <div>Status Penggunaan: <strong class="text-emerald-400">Non-Komersial & Bebas Biaya (100% Free)</strong></div>
            </div>

            <div class="p-3 rounded-xl bg-slate-900 border border-slate-800 space-y-1">
                <span class="text-[10px] uppercase font-bold text-slate-400 block">Tautan Resmi Provider:</span>
                <div>Portal Utama: <a href="https://globalfishingwatch.org" target="_blank" rel="noopener noreferrer" class="text-teal-400 hover:underline font-mono">globalfishingwatch.org</a></div>
                <div>API & Token Portal: <a href="https://globalfishingwatch.org/our-apis/tokens" target="_blank" rel="noopener noreferrer" class="text-teal-400 hover:underline font-mono">our-apis/tokens</a></div>
            </div>
        </div>

        <!-- Mandatory Disclaimer -->
        <div class="p-3.5 rounded-xl bg-amber-500/10 border border-amber-500/30 text-amber-200 text-xs leading-relaxed flex items-start gap-2.5">
            <span class="text-amber-400 text-base">⚠️</span>
            <div>
                <strong class="font-bold text-amber-300">Pemberitahuan Estimasi Intelijen Maritim:</strong>
                <p class="mt-0.5">
                    Aktivitas kapal yang ditampilkan merupakan estimasi atau hasil analisis berdasarkan sumber data, bukan bukti final pelanggaran. Setiap tindak lanjut penegakan hukum memerlukan pembuktian materiil formil sesuai yurisdiksi otoritas maritim yang berwenang.
                </p>
            </div>
        </div>
    </div>

    <!-- NON-COMMERCIAL & PUBLIC ACCESS PRINCIPLES -->
    <div class="glass-panel p-6 rounded-2xl border border-slate-800 shadow-xl space-y-4">
        <h3 class="text-xs font-bold uppercase tracking-wider text-white border-b border-slate-800 pb-2 flex items-center gap-2">
            <span>🛡️</span>
            <span>Komitmen Model Akses Publik Bebas Biaya & Tanpa Paywall</span>
        </h3>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
            <div class="p-3.5 rounded-xl bg-slate-900 border border-slate-800 space-y-1.5">
                <div class="text-teal-400 font-bold flex items-center gap-1.5">
                    <span>✓</span> Bebas Biaya 100%
                </div>
                <p class="text-slate-400 text-[11px] leading-relaxed">
                    Semua fitur platform yang menggunakan data GFW tersedia gratis bagi seluruh peneliti, akademisi, dan analis maritim yang terdaftar.
                </p>
            </div>

            <div class="p-3.5 rounded-xl bg-slate-900 border border-slate-800 space-y-1.5">
                <div class="text-teal-400 font-bold flex items-center gap-1.5">
                    <span>✓</span> Tanpa Langganan Premium
                </div>
                <p class="text-slate-400 text-[11px] leading-relaxed">
                    Tidak ada paywall, paket berbayar, atau fitur berbayar berjenjang. Platform tidak menjual atau memonetisasi data mentah maupun hasil analisis GFW.
                </p>
            </div>

            <div class="p-3.5 rounded-xl bg-slate-900 border border-slate-800 space-y-1.5">
                <div class="text-teal-400 font-bold flex items-center gap-1.5">
                    <span>✓</span> Pemisahan Data Komersial
                </div>
                <p class="text-slate-400 text-[11px] leading-relaxed">
                    Data GFW tidak pernah digunakan untuk mendukung aktivitas atau transaksi komersial pihak ketiga dalam bentuk apa pun.
                </p>
            </div>
        </div>
    </div>

    <!-- RESEARCH ACCESS POLICY & INTEGRATION TRANSPARENCY -->
    <div class="glass-panel p-6 rounded-2xl border border-slate-800 shadow-xl space-y-4 text-xs">
        <h3 class="text-xs font-bold uppercase tracking-wider text-white border-b border-slate-800 pb-2 flex items-center gap-2">
            <span>🔬</span>
            <span>Transparansi Penanganan Research Access & Error 403</span>
        </h3>

        <div class="space-y-3 text-slate-300 leading-relaxed">
            <p>
                Sistem GFVIP mematuhi struktur izin dataset API GFW v3 secara ketat:
            </p>
            <ul class="list-disc pl-5 space-y-1 text-slate-400">
                <li><strong class="text-slate-200">Katalog & Pencarian Kapal (Identity):</strong> Diakses melalui endpoint publik yang diotorisasi oleh API token.</li>
                <li><strong class="text-slate-200">Dataset Events API (Encounters, Loitering, Fishing, Port Visits):</strong> Memerlukan otorisasi eksplisit <strong>Research Access</strong> dari Global Fishing Watch.</li>
            </ul>

            <div class="p-3.5 rounded-xl bg-slate-900 border border-slate-800 space-y-1 text-[11px]">
                <strong class="text-teal-300">Penanganan Kode HTTP 403 Forbidden:</strong>
                <p class="text-slate-400">
                    Jika gateway GFW merespons dengan HTTP 403 pada endpoint /events, sistem secara otomatis menetapkan status <code>RESEARCH_ACCESS_REQUIRED</code>. Sistem <strong>dilarang keras</strong> membuat data simulasi/dummy untuk menutupi ketiadaan akses, dan tidak akan mengubah status menjadi terhubung (connected) tanpa verifikasi aktual.
                </p>
            </div>

            <div class="p-3.5 rounded-xl bg-slate-900 border border-slate-800 text-[11px] text-slate-400">
                <strong class="text-white block mb-1">Independensi Sumber Data Lain:</strong>
                Ketiadaan akses pada dataset GFW Events tidak mempengaruhi fungsionalitas modul lain. Integrasi telemetri langsung <strong>AIS Stream</strong>, citra satelit radar <strong>Copernicus Sentinel-1 SAR</strong>, area statistik <strong>FAO Fisheries</strong>, dan batas yurisdiksi <strong>Marine Regions EEZ</strong> tetap beroperasi secara penuh dan independen.
            </div>
        </div>
    </div>

</div>
@endsection

