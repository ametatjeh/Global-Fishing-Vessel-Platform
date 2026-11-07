@extends('layouts.app')

@section('header_title', 'AIS Data Retention Policy — System Settings & Storage Management')

@section('content')
<div class="max-w-6xl space-y-6">

    <!-- Top Breadcrumbs & Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-slate-800 pb-4">
        <div>
            <div class="flex items-center gap-2 text-xs font-mono text-slate-400 mb-1">
                <a href="{{ route('settings.index') }}" class="hover:text-teal-400 transition flex items-center gap-1">
                    <span>⚙️</span>
                    <span>System Settings</span>
                </a>
                <span>/</span>
                <span class="text-teal-400 font-semibold">Data Retention</span>
            </div>
            <h1 class="text-xl font-bold text-white tracking-wide flex items-center gap-2.5">
                <span>🛡️</span>
                <span>AIS Data Retention Policy</span>
            </h1>
            <p class="text-xs text-slate-400 mt-1">
                Manajemen kebijakan retensi telemetri AIS historis, jadwal pembersihan terkontrol, dan perlindungan permanen kapal penangkap ikan (Fishing).
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold bg-emerald-500/10 text-emerald-400 border border-emerald-500/30 flex items-center gap-1.5 shadow-xs">
                <span>🔒</span>
                <span>FISHING: 100% IMMUTABLE</span>
            </span>

            @if($settings->auto_cleanup_enabled)
            <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold bg-teal-500/10 text-teal-300 border border-teal-500/30 flex items-center gap-1.5 shadow-xs">
                <span class="w-2 h-2 rounded-full bg-teal-400 animate-pulse"></span>
                <span>CLEANUP: ACTIVE ({{ strtoupper($settings->cleanup_schedule) }})</span>
            </span>
            @else
            <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold bg-slate-800 text-slate-400 border border-slate-700 flex items-center gap-1.5 shadow-xs">
                <span class="w-2 h-2 rounded-full bg-slate-500"></span>
                <span>CLEANUP: DISABLED</span>
            </span>
            @endif

            <a href="{{ route('settings.index') }}" class="px-3 py-1 rounded-lg text-xs font-medium bg-slate-800 hover:bg-slate-700 text-slate-300 border border-slate-700 transition">
                ← Kembali ke System Settings
            </a>
        </div>
    </div>

    <!-- Alert / Status Notifications -->
    @if(session('status'))
    <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 text-xs flex items-center gap-2 shadow-xs">
        <span class="text-base">✓</span>
        <span>{{ session('status') }}</span>
    </div>
    @endif

    @if(session('warning'))
    <div class="p-4 rounded-xl bg-amber-500/10 border border-amber-500/30 text-amber-300 text-xs flex items-center gap-2 shadow-xs">
        <span class="text-base">⚠️</span>
        <span>{{ session('warning') }}</span>
    </div>
    @endif

    @if($errors->any())
    <div class="p-4 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-300 text-xs space-y-1 shadow-xs">
        <strong class="font-bold">Gagal memperbarui kebijakan retensi:</strong>
        <ul class="list-disc pl-5 space-y-0.5">
            @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    <!-- Storage Statistics Dashboard -->
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-3">
        <!-- 1. Total Positions -->
        <div class="glass-panel p-4 rounded-xl border border-slate-800 shadow-sm flex flex-col justify-between">
            <div class="text-[10px] font-mono uppercase text-slate-400 tracking-wider">Total Posisi AIS</div>
            <div class="text-2xl font-black text-white font-mono my-1">{{ number_format($stats['total_positions']) }}</div>
            <div class="text-[10px] text-slate-400 truncate">{{ $stats['positions_table_size'] }} (Tabel)</div>
        </div>

        <!-- 2. Fishing Positions (Permanent) -->
        <div class="glass-panel p-4 rounded-xl border border-emerald-500/40 bg-emerald-950/10 shadow-sm flex flex-col justify-between">
            <div class="flex items-center justify-between">
                <span class="text-[10px] font-mono uppercase text-emerald-400 font-bold tracking-wider">Fishing</span>
                <span class="text-[9px] px-1.5 py-0.5 rounded bg-emerald-500/20 text-emerald-300 font-mono">PERMANEN</span>
            </div>
            <div class="text-2xl font-black text-emerald-300 font-mono my-1">{{ number_format($stats['fishing_positions']) }}</div>
            <div class="text-[10px] text-emerald-400/80">{{ $stats['fishing_percentage'] }}% dari total posisi</div>
        </div>

        <!-- 3. Non-Fishing Positions -->
        <div class="glass-panel p-4 rounded-xl border border-slate-800 shadow-sm flex flex-col justify-between">
            <div class="text-[10px] font-mono uppercase text-slate-400 tracking-wider">Non-Fishing</div>
            <div class="text-2xl font-black text-amber-300 font-mono my-1">{{ number_format($stats['non_fishing_positions']) }}</div>
            <div class="text-[10px] text-slate-400">{{ $stats['non_fishing_percentage'] }}% tunduk retensi</div>
        </div>

        <!-- 4. Unknown Positions -->
        <div class="glass-panel p-4 rounded-xl border border-slate-800 shadow-sm flex flex-col justify-between">
            <div class="text-[10px] font-mono uppercase text-slate-400 tracking-wider">Unknown / Terbuka</div>
            <div class="text-2xl font-black text-slate-300 font-mono my-1">{{ number_format($stats['unknown_positions']) }}</div>
            <div class="text-[10px] text-slate-400">{{ $stats['unknown_percentage'] }}% belum verifikasi</div>
        </div>

        <!-- 5. Database Size -->
        <div class="glass-panel p-4 rounded-xl border border-slate-800 shadow-sm flex flex-col justify-between">
            <div class="text-[10px] font-mono uppercase text-slate-400 tracking-wider">Ukuran Database</div>
            <div class="text-2xl font-black text-teal-300 font-mono my-1">{{ $stats['database_size'] }}</div>
            <div class="text-[10px] text-slate-400 truncate">PostgreSQL DB Total</div>
        </div>

        <!-- 6. Candidate Purge Records -->
        <div class="glass-panel p-4 rounded-xl border border-slate-800 shadow-sm flex flex-col justify-between">
            <div class="text-[10px] font-mono uppercase text-slate-400 tracking-wider">Kandidat Purge</div>
            <div class="text-2xl font-black font-mono my-1 {{ $stats['candidate_purge_count'] > 0 ? 'text-rose-400' : 'text-slate-400' }}">
                {{ number_format($stats['candidate_purge_count']) }}
            </div>
            <div class="text-[10px] text-slate-400 truncate">Est. dibebaskan: {{ $stats['candidate_estimated_freed'] }}</div>
        </div>
    </div>

    <!-- Telemetry Timestamp Span & Growth Projection -->
    <div class="glass-panel p-4 rounded-xl border border-slate-800 text-xs flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-slate-300 bg-slate-900/40">
        <div class="flex items-center gap-4 flex-wrap">
            <div>
                <span class="text-slate-500 uppercase font-mono text-[10px] block">Record Terlama:</span>
                <span class="font-mono text-slate-200">{{ $stats['oldest_record'] ?? 'Tidak ada data' }}</span>
            </div>
            <div class="border-l border-slate-800 pl-4">
                <span class="text-slate-500 uppercase font-mono text-[10px] block">Record Terbaru:</span>
                <span class="font-mono text-slate-200">{{ $stats['newest_record'] ?? 'Tidak ada data' }}</span>
            </div>
            <div class="border-l border-slate-800 pl-4">
                <span class="text-slate-500 uppercase font-mono text-[10px] block">Rerata Ukuran Row Posisi:</span>
                <span class="font-mono text-slate-200">{{ $stats['avg_row_bytes'] }} Bytes</span>
            </div>
        </div>
        <div class="flex items-center gap-2 font-mono text-teal-400 bg-teal-950/20 border border-teal-800/40 px-3 py-1.5 rounded-lg shrink-0">
            <span>📈 Proyeksi Pertumbuhan Bulanan:</span>
            <span class="font-bold">{{ $stats['projected_monthly_growth'] }}</span>
        </div>
    </div>

    <!-- Dry-Run Simulation Results (if session exists) -->
    @if(!empty($simulation))
    <div class="glass-panel p-6 rounded-2xl border border-cyan-500/50 bg-cyan-950/20 shadow-xl space-y-4">
        <div class="flex items-center justify-between border-b border-cyan-800/40 pb-3">
            <h2 class="text-sm font-bold text-white uppercase tracking-wider flex items-center gap-2">
                <span>🧪</span>
                <span>Hasil Simulasi Pembersihan Retensi (Dry-Run Mode)</span>
            </h2>
            <span class="px-2.5 py-0.5 rounded text-[10px] font-mono font-bold bg-cyan-500/20 text-cyan-300 border border-cyan-500/40">
                DRY-RUN SIMULATION (DATABASE TIDAK DIUBAH)
            </span>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
            <div class="p-3 rounded-xl bg-slate-900/60 border border-slate-800">
                <div class="text-[10px] text-slate-400 uppercase font-mono">Posisi Fishing Dihapus</div>
                <div class="text-lg font-black text-emerald-400 font-mono mt-0.5">0 Record</div>
                <div class="text-[10px] text-emerald-400/80">100% Terlindungi Mutlak</div>
            </div>
            <div class="p-3 rounded-xl bg-slate-900/60 border border-slate-800">
                <div class="text-[10px] text-slate-400 uppercase font-mono">Kapal Bukti Terlindungi</div>
                <div class="text-lg font-black text-teal-300 font-mono mt-0.5">{{ $simulation['protected_evidence_vessels'] }} Kapal</div>
                <div class="text-[10px] text-slate-400">SAR / Encounter / Investigasi</div>
            </div>
            <div class="p-3 rounded-xl bg-slate-900/60 border border-slate-800">
                <div class="text-[10px] text-slate-400 uppercase font-mono">Kandidat Posisi Terpangkas</div>
                <div class="text-lg font-black text-amber-300 font-mono mt-0.5">{{ number_format($simulation['total_candidate_positions']) }}</div>
                <div class="text-[10px] text-slate-400">Non-Fishing Lewat Retensi</div>
            </div>
            <div class="p-3 rounded-xl bg-slate-900/60 border border-slate-800">
                <div class="text-[10px] text-slate-400 uppercase font-mono">Estimasi Disk Dibebaskan</div>
                <div class="text-lg font-black text-cyan-300 font-mono mt-0.5">{{ $simulation['estimated_freed_size'] }}</div>
                <div class="text-[10px] text-slate-400">Ruang disk yang dihemat</div>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs border-collapse">
                <thead>
                    <tr class="border-b border-cyan-800/40 text-cyan-200 uppercase font-mono text-[10px]">
                        <th class="py-2 px-3">Tipe Kapal</th>
                        <th class="py-2 px-3">Kebijakan Retensi</th>
                        <th class="py-2 px-3">Batas Waktu (Cutoff Date)</th>
                        <th class="py-2 px-3">Kandidat Terhapus</th>
                        <th class="py-2 px-3">Status Pengamanan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800 font-mono">
                    @foreach($simulation['breakdown'] as $type => $row)
                    <tr class="hover:bg-slate-800/40 transition">
                        <td class="py-2 px-3 font-sans font-semibold text-white">{{ $row['label'] }}</td>
                        <td class="py-2 px-3 text-slate-300">
                            {{ $row['retention_days'] ? $row['retention_days'] . ' Hari' : 'Permanen' }}
                        </td>
                        <td class="py-2 px-3 text-slate-400">{{ $row['cutoff_date'] ?: '—' }}</td>
                        <td class="py-2 px-3 font-bold {{ $row['candidates'] > 0 ? 'text-amber-400' : 'text-slate-400' }}">
                            {{ number_format($row['candidates']) }}
                        </td>
                        <td class="py-2 px-3">
                            @if($row['status'] === 'PROTECTED_PERMANENT')
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">
                                🛡️ TERLINDUNGI PERMANEN
                            </span>
                            @else
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-slate-800 text-slate-300 border border-slate-700">
                                RETENSI KONFIGURASI
                            </span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    <!-- Main Retention Form -->
    <form method="POST" action="{{ route('settings.retention.update') }}" class="space-y-6">
        @csrf

        <!-- SECTION A: FISHING (Kapal Penangkap Ikan - Permanen & Immutable) -->
        <div class="glass-panel p-6 rounded-2xl border-2 border-emerald-500/50 bg-gradient-to-br from-emerald-950/20 to-slate-900/60 shadow-xl space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-emerald-900/50 pb-3">
                <div class="flex items-center gap-2.5">
                    <span class="text-xl">🎣</span>
                    <div>
                        <h2 class="text-base font-bold text-white uppercase tracking-wider flex items-center gap-2">
                            <span>Bagian A: Kapal Penangkap Ikan (Fishing)</span>
                            <span class="px-2 py-0.5 rounded text-[10px] font-mono bg-emerald-500/20 text-emerald-300 border border-emerald-500/40">
                                PERMANENT MANDATE
                            </span>
                        </h2>
                        <p class="text-xs text-slate-400">Seluruh telemetri AIS kapal penangkap ikan dilindungi undang-undang platform GFVIP secara permanen.</p>
                    </div>
                </div>

                <div class="flex items-center gap-2 shrink-0">
                    <span class="px-3 py-1 rounded-lg text-xs font-mono font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/40 flex items-center gap-1.5 shadow-xs">
                        <span>🛡️</span>
                        <span>PERMANENT RETENTION / TERLINDUNGI PERMANEN</span>
                    </span>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-xs">
                <div class="space-y-1.5 md:col-span-2">
                    <label class="block font-semibold text-slate-200">Durasi Retensi Telemetri Posisi:</label>
                    <div class="relative">
                        <input type="text" value="Permanen (Tidak Terbatas / Forever) — Kebijakan Tetap (Immutable)" disabled
                            class="w-full px-3 py-2.5 rounded-xl bg-emerald-950/30 border border-emerald-800/60 text-emerald-300 font-mono text-xs cursor-not-allowed">
                        <span class="absolute right-3 top-2.5 text-xs text-emerald-400">🔒 Kunci Sistem</span>
                    </div>
                    <p class="text-[11px] text-slate-400 leading-relaxed mt-1">
                        Sesuai spesifikasi arsitektur GFVIP, data kapal penangkap ikan <strong>TIDAK DAPAT</strong> diberi batas kedaluwarsa atau terkena scheduler pembersihan otomatis. Seluruh titik koordinat, kecepatan, arah, dan riwayat jejak tersimpan tanpa batas waktu.
                    </p>
                </div>

                <div class="p-3.5 rounded-xl bg-slate-900/60 border border-slate-800 space-y-2">
                    <div class="text-[10px] text-slate-400 uppercase font-mono">Status Kapal Fishing Saat Ini</div>
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-slate-400">Total Posisi Tersimpan:</span>
                        <span class="font-bold text-white font-mono">{{ number_format($stats['fishing_positions']) }}</span>
                    </div>
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-slate-400">Pangsa Ruang AIS:</span>
                        <span class="font-bold text-emerald-400 font-mono">{{ $stats['fishing_percentage'] }}%</span>
                    </div>
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-slate-400">Status Penghapusan:</span>
                        <span class="font-bold text-emerald-400 font-mono">0% Dihapus (Kekebalan Penuh)</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- SECTION B: OTHER VESSEL TYPES (Non-Fishing) -->
        <div class="glass-panel p-6 rounded-2xl border border-slate-800 shadow-xl space-y-4">
            <div class="border-b border-slate-800 pb-3">
                <h2 class="text-base font-bold text-white uppercase tracking-wider flex items-center gap-2">
                    <span>🚢</span>
                    <span>Bagian B: Tipe Kapal Non-Fishing (Other Vessel Types)</span>
                </h2>
                <p class="text-xs text-slate-400 mt-0.5">
                    Atur batas retensi telemetri posisi AIS untuk armada kapal niaga dan non-fishing. Data yang melewati batas durasi ini dapat dibersihkan untuk efisiensi kapasitas disk.
                </p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                @php
                    $nonFishingTypes = [
                        'cargo' => ['icon' => '📦', 'preset' => [7, 30, 90, 180, 365]],
                        'tanker' => ['icon' => '🛢️', 'preset' => [7, 30, 90, 180, 365]],
                        'passenger' => ['icon' => '🛳️', 'preset' => [7, 30, 60, 90, 180]],
                        'tug' => ['icon' => '⚓', 'preset' => [7, 30, 60, 90, 180]],
                        'pleasure' => ['icon' => '🛥️', 'preset' => [7, 30, 60, 90, 180]],
                        'other' => ['icon' => '🚢', 'preset' => [7, 30, 90, 180, 365]],
                    ];
                @endphp

                @foreach($nonFishingTypes as $type => $meta)
                    @php
                        $policy = $policies->firstWhere('vessel_type', $type);
                        $breakdown = $stats['breakdown'][$type] ?? null;
                        $currentDays = $policy ? $policy->retention_days : 90;
                        $isEnabled = $policy ? $policy->is_enabled : true;
                    @endphp

                    <div class="p-4 rounded-xl bg-slate-900/60 border border-slate-800 hover:border-slate-700 transition space-y-3">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="text-base">{{ $meta['icon'] }}</span>
                                <span class="font-bold text-white text-xs">{{ $policy?->label ?? ucfirst($type) }}</span>
                            </div>
                            <span class="text-[10px] font-mono px-1.5 py-0.5 rounded bg-slate-800 text-slate-300 border border-slate-700">
                                {{ $breakdown['count'] ?? 0 }} Posisi
                            </span>
                        </div>

                        <div class="space-y-1.5">
                            <div class="flex items-center justify-between text-[11px]">
                                <span class="text-slate-400">Durasi Retensi:</span>
                                <span class="font-mono text-teal-400 font-bold" id="display_{{ $type }}">{{ $currentDays }} Hari</span>
                            </div>

                            <!-- Preset select / custom input -->
                            <div class="flex items-center gap-2">
                                <select onchange="updateRetentionDays('{{ $type }}', this.value)"
                                    class="w-full px-2.5 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs font-mono focus:border-teal-400 focus:outline-hidden">
                                    @foreach($meta['preset'] as $presetDays)
                                    <option value="{{ $presetDays }}" {{ $currentDays == $presetDays ? 'selected' : '' }}>
                                        {{ $presetDays }} Hari
                                    </option>
                                    @endforeach
                                    <option value="custom" {{ !in_array($currentDays, $meta['preset']) ? 'selected' : '' }}>Custom...</option>
                                </select>

                                <input type="number" name="policies[{{ $type }}][retention_days]" id="input_{{ $type }}"
                                    value="{{ $currentDays }}" min="1" max="3650"
                                    class="w-24 px-2 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs font-mono text-center focus:border-teal-400 focus:outline-hidden"
                                    title="Durasi dalam satuan hari">
                            </div>
                        </div>

                        <div class="flex items-center justify-between pt-2 border-t border-slate-800/80 text-[11px]">
                            <label class="flex items-center gap-1.5 cursor-pointer text-slate-400 hover:text-white transition">
                                <input type="checkbox" name="policies[{{ $type }}][is_enabled]" value="1" {{ $isEnabled ? 'checked' : '' }}
                                    class="rounded border-slate-700 bg-slate-900 text-teal-500 focus:ring-0">
                                <span>Aktifkan Retensi</span>
                            </label>
                            <span class="text-[10px] text-slate-500 font-mono">{{ $breakdown['estimated_size'] ?? '0 B' }}</span>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <!-- SECTION C: UNKNOWN / UNCLASSIFIED VESSELS -->
        @php
            $unknownPolicy = $policies->firstWhere('vessel_type', 'unknown');
            $unknownDays = $unknownPolicy ? $unknownPolicy->retention_days : 30;
            $unknownBreakdown = $stats['breakdown']['unknown'] ?? null;
        @endphp
        <div class="glass-panel p-6 rounded-2xl border border-amber-500/40 bg-gradient-to-br from-amber-950/10 to-slate-900/60 shadow-xl space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-amber-900/40 pb-3">
                <div class="flex items-center gap-2.5">
                    <span class="text-xl">❓</span>
                    <div>
                        <h2 class="text-base font-bold text-white uppercase tracking-wider flex items-center gap-2">
                            <span>Bagian C: Kapal Belum Terklasifikasi (Unknown / Unclassified)</span>
                            <span class="px-2 py-0.5 rounded text-[10px] font-mono bg-amber-500/20 text-amber-300 border border-amber-500/40">
                                SEPARATE RETENTION
                            </span>
                        </h2>
                        <p class="text-xs text-slate-400">Pengaturan retensi khusus untuk kapal yang belum memiliki klasifikasi AIS ShipType resmi.</p>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold bg-slate-900 border border-slate-700 text-slate-300">
                        {{ $unknownBreakdown['count'] ?? 0 }} Posisi ({{ $unknownBreakdown['estimated_size'] ?? '0 B' }})
                    </span>
                </div>
            </div>

            <div class="p-4 rounded-xl bg-amber-950/20 border border-amber-800/40 text-xs text-amber-200/90 space-y-2">
                <div class="font-bold flex items-center gap-1.5 text-amber-300">
                    <span>⚠️</span>
                    <span>ATURAN KEAMANAN KLASIFIKASI & RETENSI:</span>
                </div>
                <p class="leading-relaxed text-[11px]">
                    1. Kapal berstatus <strong>Unknown</strong> <em>TIDAK BOLEH</em> otomatis dianggap sebagai kapal Fishing.<br>
                    2. Durasi retensi terpisah diterapkan sebagai masa tenggang (grace period) bagi analis untuk mengidentifikasi kapal.<br>
                    3. <strong>Perlindungan Otomatis:</strong> Apabila suatu kapal terverifikasi berubah klasifikasi menjadi <strong>Fishing</strong>, seluruh riwayat posisi AIS yang masih tersisa untuk kapal tersebut akan <strong>seketika itu juga terlindungi permanen</strong> secara otomatis.
                </p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                <div class="space-y-1.5">
                    <label class="block font-semibold text-slate-200">Durasi Retensi Kapal Unknown (Hari):</label>
                    <div class="flex items-center gap-2">
                        <select onchange="updateRetentionDays('unknown', this.value)"
                            class="w-full px-3 py-2 rounded-xl bg-slate-950 border border-slate-700 text-slate-200 text-xs font-mono focus:border-teal-400 focus:outline-hidden">
                            <option value="7" {{ $unknownDays == 7 ? 'selected' : '' }}>7 Hari (Paling Ketat)</option>
                            <option value="14" {{ $unknownDays == 14 ? 'selected' : '' }}>14 Hari</option>
                            <option value="30" {{ $unknownDays == 30 ? 'selected' : '' }}>30 Hari (Default Direkomendasikan)</option>
                            <option value="60" {{ $unknownDays == 60 ? 'selected' : '' }}>60 Hari</option>
                            <option value="90" {{ $unknownDays == 90 ? 'selected' : '' }}>90 Hari</option>
                            <option value="custom" {{ !in_array($unknownDays, [7, 14, 30, 60, 90]) ? 'selected' : '' }}>Custom...</option>
                        </select>

                        <input type="number" name="policies[unknown][retention_days]" id="input_unknown"
                            value="{{ $unknownDays }}" min="1" max="3650"
                            class="w-28 px-3 py-2 rounded-xl bg-slate-950 border border-slate-700 text-slate-200 text-xs font-mono text-center focus:border-teal-400 focus:outline-hidden">
                    </div>
                </div>

                <div class="flex items-center justify-between p-3.5 rounded-xl bg-slate-900/60 border border-slate-800">
                    <div>
                        <div class="font-semibold text-slate-200">Status Retensi Unknown</div>
                        <div class="text-[11px] text-slate-400">Aktifkan penghapusan posisi unknown yang kedaluwarsa</div>
                    </div>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="policies[unknown][is_enabled]" value="1" {{ ($unknownPolicy?->is_enabled ?? true) ? 'checked' : '' }}
                            class="rounded border-slate-700 bg-slate-900 text-teal-500 focus:ring-0">
                        <span class="text-xs text-slate-300">Aktif</span>
                    </label>
                </div>
            </div>
        </div>

        <!-- SECTION D: AUTOMATIC CLEANUP & SCHEDULING -->
        <div class="glass-panel p-6 rounded-2xl border border-slate-800 shadow-xl space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-800 pb-3">
                <div>
                    <h2 class="text-base font-bold text-white uppercase tracking-wider flex items-center gap-2">
                        <span>⏰</span>
                        <span>Bagian D: Pembersihan Otomatis (Automatic Cleanup Scheduler)</span>
                    </h2>
                    <p class="text-xs text-slate-400 mt-0.5">Konfigurasi scheduler latar belakang untuk memangkas telemetri non-fishing yang melampaui masa retensi.</p>
                </div>

                <div>
                    @if($settings->auto_cleanup_enabled)
                    <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold bg-teal-500/20 text-teal-300 border border-teal-500/40">
                        STATUS: AKTIF (RUNNING)
                    </span>
                    @else
                    <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold bg-rose-500/10 text-rose-300 border border-rose-500/30">
                        STATUS: NONAKTIF (DISABLED)
                    </span>
                    @endif
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-xs">
                <!-- Toggle Cleanup -->
                <div class="p-4 rounded-xl bg-slate-900/60 border border-slate-800 space-y-2">
                    <label class="font-semibold text-white block">Aktivasi Automatic Cleanup</label>
                    <p class="text-[11px] text-slate-400">Pembersihan berkala di latar belakang sesuai jadwal.</p>
                    <div class="pt-2">
                        <label class="inline-flex items-center gap-2.5 cursor-pointer">
                            <input type="checkbox" name="settings[auto_cleanup_enabled]" value="1" {{ $settings->auto_cleanup_enabled ? 'checked' : '' }}
                                class="w-4 h-4 rounded border-slate-700 bg-slate-950 text-teal-500 focus:ring-0">
                            <span class="text-xs font-bold text-slate-200">Aktifkan Scheduler Pembersihan</span>
                        </label>
                    </div>
                </div>

                <!-- Schedule Frequency -->
                <div class="p-4 rounded-xl bg-slate-900/60 border border-slate-800 space-y-2">
                    <label class="font-semibold text-white block">Frekuensi Jadwal</label>
                    <p class="text-[11px] text-slate-400">Kapan scheduler harus dijalankan.</p>
                    <select name="settings[cleanup_schedule]"
                        class="w-full px-3 py-2 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs font-mono focus:border-teal-400 focus:outline-hidden">
                        <option value="daily" {{ $settings->cleanup_schedule === 'daily' ? 'selected' : '' }}>Harian (Daily)</option>
                        <option value="weekly" {{ $settings->cleanup_schedule === 'weekly' ? 'selected' : '' }}>Mingguan (Weekly - Setiap Minggu)</option>
                        <option value="monthly" {{ $settings->cleanup_schedule === 'monthly' ? 'selected' : '' }}>Bulanan (Monthly - Tgl 1)</option>
                    </select>
                </div>

                <!-- Schedule Time -->
                <div class="p-4 rounded-xl bg-slate-900/60 border border-slate-800 space-y-2">
                    <label class="font-semibold text-white block">Waktu Eksekusi (UTC / Jam Server)</label>
                    <p class="text-[11px] text-slate-400">Jam pembersihan (format HH:MM 24 jam).</p>
                    <input type="text" name="settings[cleanup_time]" value="{{ $settings->cleanup_time ?: '03:00' }}"
                        placeholder="03:00"
                        class="w-full px-3 py-2 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs font-mono focus:border-teal-400 focus:outline-hidden">
                </div>
            </div>

            <!-- Execution Status Metadata -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 p-4 rounded-xl bg-slate-950/60 border border-slate-800/80 text-xs">
                <div>
                    <span class="text-slate-500 uppercase font-mono text-[10px] block">Waktu Pembersihan Terakhir:</span>
                    <span class="font-mono text-slate-200 font-semibold">
                        {{ $settings->last_cleanup_at ? $settings->last_cleanup_at->format('Y-m-d H:i:s T') : 'Belum Pernah Dijalankan' }}
                    </span>
                    @if($settings->last_cleanup_records_purged > 0)
                    <span class="text-[10px] text-teal-400 block mt-0.5">({{ $settings->last_cleanup_records_purged }} posisi dibersihkan)</span>
                    @endif
                </div>

                <div>
                    <span class="text-slate-500 uppercase font-mono text-[10px] block">Jadwal Eksekusi Berikutnya:</span>
                    <span class="font-mono text-teal-300 font-semibold">
                        {{ $nextRun ? $nextRun->format('Y-m-d H:i:s T') : 'Nonaktif (Pembersihan Otomatis Mati)' }}
                    </span>
                </div>

                <div>
                    <span class="text-slate-500 uppercase font-mono text-[10px] block">Kandidat Posisi Kedaluwarsa Saat Ini:</span>
                    <span class="font-mono font-bold {{ $stats['candidate_purge_count'] > 0 ? 'text-amber-400' : 'text-slate-300' }}">
                        {{ number_format($stats['candidate_purge_count']) }} Record ({{ $stats['candidate_estimated_freed'] }})
                    </span>
                </div>
            </div>
        </div>

        <!-- Submit Bar -->
        <div class="flex items-center justify-between gap-4 pt-2">
            <div class="text-xs text-slate-400 flex items-center gap-1.5">
                <span>🛡️</span>
                <span>Perubahan konfigurasi akan diaudit dan dicatat dalam Activity Logs platform.</span>
            </div>

            <button type="submit"
                class="px-6 py-2.5 rounded-xl bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold text-xs transition shadow-lg shadow-teal-500/20 flex items-center gap-2 cursor-pointer">
                <span>💾</span>
                <span>Simpan Kebijakan Retensi AIS</span>
            </button>
        </div>
    </form>

    <!-- SECTION E: SIMULATION & MANUAL EXECUTION CONTROL -->
    <div class="glass-panel p-6 rounded-2xl border border-slate-800 shadow-xl space-y-4">
        <div class="border-b border-slate-800 pb-3">
            <h2 class="text-base font-bold text-white uppercase tracking-wider flex items-center gap-2">
                <span>⚡</span>
                <span>Pusat Kendali Pengujian & Pembersihan Manual</span>
            </h2>
            <p class="text-xs text-slate-400 mt-0.5">Uji coba simulasi dry-run sebelum pembersihan aktual, atau jalankan pembersihan ad-hoc dengan otorisasi administrator.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <!-- 1. Dry Run Simulation Button -->
            <div class="p-4 rounded-xl bg-slate-900/60 border border-slate-800 space-y-3 flex flex-col justify-between">
                <div>
                    <div class="flex items-center gap-2 text-white font-bold text-xs">
                        <span>🧪</span>
                        <span>Simulasi Pembersihan (Dry-Run Test)</span>
                    </div>
                    <p class="text-[11px] text-slate-400 mt-1 leading-relaxed">
                        Menghitung seluruh posisi yang memenuhi syarat purge berdasarkan kebijakan retensi aktif tanpa menghapus satupun data dari database. Memverifikasi perlindungan 100% kapal Fishing dan bukti operasional.
                    </p>
                </div>

                <form method="POST" action="{{ route('settings.retention.simulate') }}">
                    @csrf
                    <button type="submit"
                        class="w-full px-4 py-2 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-white font-bold text-xs transition flex items-center justify-center gap-2 shadow-xs cursor-pointer">
                        <span>🧪</span>
                        <span>Jalankan Simulasi Dry-Run Sekarang</span>
                    </button>
                </form>
            </div>

            <!-- 2. Manual Purge (Super Admin Only) -->
            <div class="p-4 rounded-xl bg-slate-900/60 border border-slate-800 space-y-3 flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2 text-white font-bold text-xs">
                            <span>🧹</span>
                            <span>Pembersihan Manual Langsung (Manual Ad-Hoc Purge)</span>
                        </div>
                        <span class="text-[9px] px-1.5 py-0.5 rounded bg-rose-500/20 text-rose-300 font-mono">SUPER ADMIN</span>
                    </div>
                    <p class="text-[11px] text-slate-400 mt-1 leading-relaxed">
                        Mengeksekusi pembersihan posisi non-fishing kedaluwarsa secara langsung saat ini juga dalam batch terkontrol. Kapal Fishing dan bukti SAR/Encounter tetap 100% terlindungi.
                    </p>
                </div>

                @if(auth()->user()?->isSuperAdmin())
                <form method="POST" action="{{ route('settings.retention.purge') }}" onsubmit="return confirm('Apakah Anda yakin ingin mengeksekusi pembersihan posisi AIS non-fishing yang kedaluwarsa? Data Fishing dan bukti SAR/Encounter akan tetap terlindungi 100%.');">
                    @csrf
                    <div class="space-y-2">
                        <label class="flex items-center gap-2 cursor-pointer text-slate-300 text-[11px]">
                            <input type="checkbox" name="confirm_purge" value="1" required
                                class="rounded border-slate-700 bg-slate-950 text-rose-500 focus:ring-0">
                            <span>Konfirmasi pembersihan manual telemetri non-fishing</span>
                        </label>

                        <button type="submit"
                            class="w-full px-4 py-2 rounded-lg bg-rose-600 hover:bg-rose-500 text-white font-bold text-xs transition flex items-center justify-center gap-2 shadow-xs cursor-pointer">
                            <span>⚠️</span>
                            <span>Eksekusi Pembersihan Manual</span>
                        </button>
                    </div>
                </form>
                @else
                <div class="p-2.5 rounded-lg bg-slate-950 border border-slate-800 text-[11px] text-slate-500 text-center">
                    Hanya Super Admin yang berwenang mengeksekusi pembersihan manual langsung.
                </div>
                @endif
            </div>
        </div>
    </div>

    <!-- SECTION F: AUDIT LOGS -->
    <div class="glass-panel p-6 rounded-2xl border border-slate-800 shadow-xl space-y-3">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <h2 class="text-sm font-bold text-white uppercase tracking-wider flex items-center gap-2">
                <span>📜</span>
                <span>Audit Trail Perubahan Kebijakan Retensi AIS</span>
            </h2>
            <a href="{{ route('audit-logs.index') }}" class="text-xs text-teal-400 hover:text-teal-300 transition">
                Lihat Semua Activity Logs →
            </a>
        </div>

        @if($recentLogs->isEmpty())
        <div class="text-center py-6 text-xs text-slate-500">
            Belum ada catatan perubahan kebijakan retensi yang terekam.
        </div>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs border-collapse">
                <thead>
                    <tr class="border-b border-slate-800 text-slate-400 uppercase font-mono text-[10px]">
                        <th class="py-2 px-3">Waktu</th>
                        <th class="py-2 px-3">Operator / Pengguna</th>
                        <th class="py-2 px-3">Tindakan</th>
                        <th class="py-2 px-3">IP Address</th>
                        <th class="py-2 px-3">Detail Perubahan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60 font-mono">
                    @foreach($recentLogs as $log)
                    <tr class="hover:bg-slate-800/30 transition">
                        <td class="py-2.5 px-3 text-slate-400 whitespace-nowrap">{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
                        <td class="py-2.5 px-3 font-sans font-semibold text-white">
                            {{ $log->user?->name ?? 'System' }}
                            <span class="text-[10px] text-slate-400 font-mono block">({{ $log->user?->email ?? 'Automated Worker' }})</span>
                        </td>
                        <td class="py-2.5 px-3">
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase
                                {{ $log->action === 'delete' ? 'bg-rose-500/20 text-rose-300 border border-rose-500/30' : 'bg-teal-500/20 text-teal-300 border border-teal-500/30' }}">
                                {{ $log->action }}
                            </span>
                        </td>
                        <td class="py-2.5 px-3 text-slate-400">{{ $log->ip_address ?? '—' }}</td>
                        <td class="py-2.5 px-3 text-slate-300 font-sans text-[11px]">
                            @if(isset($log->details['action_label']))
                                {{ $log->details['action_label'] }}
                            @elseif(isset($log->details['total_purged']))
                                Pembersihan telemetri: {{ $log->details['total_purged'] }} record dihapus.
                            @else
                                {{ json_encode($log->details) }}
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

</div>

<script>
function updateRetentionDays(type, value) {
    const input = document.getElementById('input_' + type);
    const display = document.getElementById('display_' + type);
    if (!input) return;

    if (value !== 'custom') {
        input.value = value;
        if (display) display.textContent = value + ' Hari';
    } else {
        input.focus();
    }
}
</script>
@endsection
