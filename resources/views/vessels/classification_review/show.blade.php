@extends('layouts.app')

@section('header_title', 'Review Klasifikasi Kapal: ' . ($vessel->name ?? 'MMSI ' . $vessel->mmsi))

@section('content')
<div class="space-y-6">

    <!-- Header & Navigation -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2.5 flex-wrap">
                <a href="{{ route('vessels.index') }}" class="text-xs text-slate-400 hover:text-teal-300 transition">Vessels</a>
                <span class="text-slate-600">/</span>
                <a href="{{ route('vessels.classification-review.index') }}" class="text-xs text-slate-400 hover:text-indigo-300 transition">Classification Review</a>
                <span class="text-slate-600">/</span>
                <h2 class="text-xl font-bold text-white tracking-wide uppercase">{{ $vessel->name ?? 'UNKNOWN' }}</h2>
                <span class="px-2 py-0.5 rounded text-[10px] font-mono bg-slate-800 text-slate-300 border border-slate-700">
                    MMSI: {{ $vessel->mmsi }}
                </span>
            </div>
            <p class="text-xs text-slate-400 mt-1">
                Analisis bukti telemetri statis AIS, verifikasi manual, resolusi status disputed, dan pencatatan audit log keputusan analis.
            </p>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('vessels.show', $vessel->id) }}" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white border border-slate-700 text-xs font-medium transition shadow-sm">
                <span>🚢</span> Profil Kapal Penuh
            </a>
            <a href="{{ route('vessels.classification-review.index') }}" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-indigo-950/60 hover:bg-indigo-900 text-indigo-300 border border-indigo-700/60 text-xs font-medium transition shadow-sm">
                <span>←</span> Daftar Review
            </a>
        </div>
    </div>

    <!-- Alert / Conflict Warning Banner -->
    @php
        $isDisputed = $vessel->isClassificationDisputed();
        $isHeld = $vessel->isUnderProtectiveHold();
        $isVerifiedFish = $vessel->isVerifiedFishing();
        $isHistorical = $vessel->isHistoricalProtected();
    @endphp

    @if($isDisputed)
    <div class="glass-panel p-4 rounded-2xl border border-rose-500/40 bg-rose-500/10 shadow-lg">
        <div class="flex items-start gap-3">
            <span class="text-2xl">⚠️</span>
            <div class="space-y-1">
                <h3 class="text-sm font-bold text-rose-300 uppercase tracking-wide">
                    Konflik Klasifikasi Terdeteksi (Disputed Classification)
                </h3>
                <p class="text-xs text-rose-200/90 leading-relaxed">
                    Kapal terdaftar atau berlabel <span class="font-bold underline">Fishing</span>, namun telemetri objektif AIS melaporkan ShipType <span class="font-mono font-bold">{{ $vessel->ais_ship_type_code }}</span> ({{ \App\Services\Maritime\VesselClassificationService::mapAisCodeToTaxonomy($vessel->ais_ship_type_code) }}).
                    Sesuai aturan keamanan retensi, kapal ini <strong>secara otomatis dikarantina dalam Protective Hold</strong> dan <strong>dilarang</strong> memperoleh retensi permanen fishing sampai diselesaikan oleh analis.
                </p>
            </div>
        </div>
    </div>
    @elseif($isHistorical)
    <div class="glass-panel p-4 rounded-2xl border border-purple-500/40 bg-purple-500/10 shadow-lg">
        <div class="flex items-start gap-3">
            <span class="text-2xl">📜</span>
            <div class="space-y-1">
                <h3 class="text-sm font-bold text-purple-300 uppercase tracking-wide">
                    Kapal Historis Dilindungi (Historical Protected MMSI)
                </h3>
                <p class="text-xs text-purple-200/90 leading-relaxed">
                    Kapal ini termasuk dalam lima kapal historis terproteksi (MMSI: {{ $vessel->mmsi }}). Seluruh data posisi ditahan dalam Protective Hold permanen dan dilarang diubah atau dihapus secara otomatis.
                </p>
            </div>
        </div>
    </div>
    @endif

    <!-- Success & Error Alerts -->
    @if(session('success'))
    <div class="p-4 rounded-2xl border border-emerald-500/40 bg-emerald-500/15 text-emerald-300 text-xs flex items-center gap-2">
        <span>✓</span> {{ session('success') }}
    </div>
    @endif

    @if($errors->any())
    <div class="p-4 rounded-2xl border border-rose-500/40 bg-rose-500/15 text-rose-300 text-xs space-y-1">
        <div class="font-bold">Gagal memproses keputusan analis:</div>
        <ul class="list-disc list-inside">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    <!-- Two-Column Layout: Evidence & Identity vs Analyst Decision Desk -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

        <!-- Left Column: Vessel Identity & Evidence (7 Cols) -->
        <div class="lg:col-span-7 space-y-6">

            <!-- Card 1: Identity & Classification Baseline -->
            <div class="glass-panel p-5 rounded-2xl border border-slate-800 shadow-xl space-y-4">
                <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400 flex items-center justify-between">
                    <span>Identitas Kapal & Status Klasifikasi</span>
                    <span class="text-[10px] font-mono text-slate-500">ID: {{ $vessel->id }}</span>
                </h3>

                <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 text-xs">
                    <div class="space-y-1">
                        <div class="text-[10px] text-slate-400 uppercase font-semibold">Nama Kapal</div>
                        <div class="font-bold text-white text-sm">{{ $vessel->name ?? 'UNKNOWN' }}</div>
                    </div>

                    <div class="space-y-1">
                        <div class="text-[10px] text-slate-400 uppercase font-semibold">MMSI</div>
                        <div class="font-mono text-slate-200 font-bold">{{ $vessel->mmsi ?? '—' }}</div>
                    </div>

                    <div class="space-y-1">
                        <div class="text-[10px] text-slate-400 uppercase font-semibold">IMO</div>
                        <div class="font-mono text-slate-200">{{ $vessel->display_imo }}</div>
                    </div>

                    <div class="space-y-1">
                        <div class="text-[10px] text-slate-400 uppercase font-semibold">Callsign</div>
                        <div class="font-mono text-slate-200">{{ $vessel->callsign ?? '—' }}</div>
                    </div>

                    <div class="space-y-1">
                        <div class="text-[10px] text-slate-400 uppercase font-semibold">Bendera (Flag)</div>
                        <div class="text-slate-200">{{ $vessel->display_flag_country ?: 'Unknown' }}</div>
                    </div>

                    <div class="space-y-1">
                        <div class="text-[10px] text-slate-400 uppercase font-semibold">Dimensi (P x L)</div>
                        <div class="text-slate-200">
                            {{ $vessel->length_meters ? $vessel->length_meters . 'm' : '—' }} ×
                            {{ $vessel->beam_meters ? $vessel->beam_meters . 'm' : '—' }}
                        </div>
                    </div>
                </div>

                <hr class="border-slate-800">

                <!-- Classification Attributes Grid -->
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 text-xs">
                    <div class="space-y-1">
                        <div class="text-[10px] text-slate-400 uppercase font-semibold">Current Vessel Type</div>
                        <div>
                            <span class="px-2 py-0.5 rounded text-[11px] font-bold uppercase
                                {{ strtolower($vessel->vessel_type) === 'fishing' ? 'bg-teal-500/20 text-teal-300 border border-teal-500/40' : 'bg-slate-800 text-slate-300 border border-slate-700' }}">
                                {{ $vessel->display_vessel_type }}
                            </span>
                        </div>
                    </div>

                    <div class="space-y-1">
                        <div class="text-[10px] text-slate-400 uppercase font-semibold">AIS ShipType Code</div>
                        <div class="font-mono text-slate-200">
                            @if($vessel->ais_ship_type_code !== null)
                                <span class="px-1.5 py-0.5 rounded bg-slate-800 font-bold text-white border border-slate-700">
                                    {{ $vessel->ais_ship_type_code }}
                                </span>
                                <span class="text-[11px] text-slate-400 ml-1">
                                    ({{ \App\Services\Maritime\VesselClassificationService::mapAisCodeToTaxonomy($vessel->ais_ship_type_code) }})
                                </span>
                            @else
                                <span class="text-slate-500 italic">None (Belum pernah menerima Type 5/19/24B)</span>
                            @endif
                        </div>
                    </div>

                    <div class="space-y-1">
                        <div class="text-[10px] text-slate-400 uppercase font-semibold">Classification Source</div>
                        <div class="font-mono text-[11px] text-slate-300">
                            {{ $vessel->classification_source ?? 'Belum terdefinisi' }}
                        </div>
                    </div>

                    <div class="space-y-1">
                        <div class="text-[10px] text-slate-400 uppercase font-semibold">Verification Status</div>
                        <div>
                            @if($vessel->verification_status === 'verified')
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-emerald-500/20 text-emerald-300 border border-emerald-500/40">
                                    ✓ Verified
                                </span>
                            @elseif($vessel->verification_status === 'reclassified')
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-indigo-500/20 text-indigo-300 border border-indigo-500/40">
                                    ⇄ Reclassified
                                </span>
                            @elseif($vessel->verification_status === 'unverified_historical')
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-purple-500/20 text-purple-300 border border-purple-500/40">
                                    📜 Historical
                                </span>
                            @elseif($vessel->verification_status === 'under_review')
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-amber-500/20 text-amber-300 border border-amber-500/40">
                                    ⏳ Under Review
                                </span>
                            @elseif($vessel->verification_status === 'rejected')
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-rose-500/20 text-rose-300 border border-rose-500/40">
                                    ✕ Rejected
                                </span>
                            @else
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-slate-800 text-slate-400 border border-slate-700">
                                    {{ $vessel->verification_status ?? 'unverified' }}
                                </span>
                            @endif
                        </div>
                    </div>

                    <div class="space-y-1">
                        <div class="text-[10px] text-slate-400 uppercase font-semibold">Classified At</div>
                        <div class="text-slate-300">
                            {{ $vessel->classified_at ? $vessel->classified_at->format('Y-m-d H:i:s') : '—' }}
                        </div>
                    </div>

                    <div class="space-y-1">
                        <div class="text-[10px] text-slate-400 uppercase font-semibold">Kebijakan Retensi Posisi</div>
                        <div>
                            @if($isVerifiedFish)
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/40">
                                    PERMANEN (100% AMAN)
                                </span>
                            @elseif($isHeld)
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-500/20 text-amber-300 border border-amber-500/40">
                                    🛡️ PROTECTIVE HOLD
                                </span>
                            @else
                                <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-slate-800 text-slate-400 border border-slate-700">
                                    Standar (Berdasarkan Tipe)
                                </span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <!-- Card 2: Bukti Yang Tersedia (Available Evidence) -->
            <div class="glass-panel p-5 rounded-2xl border border-slate-800 shadow-xl space-y-4">
                <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400 flex items-center justify-between">
                    <span>Bukti Telemetri & Metadata Tersedia</span>
                    <span class="text-[10px] text-slate-500">Pemeriksaan Bukti Objektif</span>
                </h3>

                <div class="space-y-3 text-xs">
                    <!-- AIS Static Telemetry Evidence -->
                    <div class="p-3 rounded-xl bg-slate-900/80 border border-slate-800 space-y-1.5">
                        <div class="flex items-center justify-between">
                            <span class="font-semibold text-slate-200 flex items-center gap-1.5">
                                <span>📡</span> Telemetri Statis AIS (Type 5 / Type 19 / Type 24B)
                            </span>
                            @if($vessel->ais_ship_type_code !== null)
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-teal-500/20 text-teal-300 border border-teal-500/30">
                                    Diterima
                                </span>
                            @else
                                <span class="px-2 py-0.5 rounded text-[10px] text-slate-400 bg-slate-800">
                                    Evidence unavailable
                                </span>
                            @endif
                        </div>
                        <div class="text-slate-400 text-[11px] leading-relaxed">
                            @if($vessel->ais_ship_type_code !== null)
                                Nilai kode AIS tersimpan: <strong class="text-white">{{ $vessel->ais_ship_type_code }}</strong>
                                (Kategori ITU: {{ \App\Services\Maritime\VesselClassificationService::mapAisCodeToTaxonomy($vessel->ais_ship_type_code) }}).
                                @if($vessel->ais_ship_type_code === 30)
                                    <span class="text-emerald-400 font-semibold">Bukti sah kapal penangkap ikan (Fishing).</span>
                                @else
                                    <span class="text-rose-400 font-semibold">Bukti telemetri non-fishing (tidak boleh diabaikan).</span>
                                @endif
                            @else
                                Tidak ada rekaman frame statis AIS tipe 5, 19, atau 24B untuk MMSI ini di database.
                            @endif
                        </div>
                    </div>

                    <!-- Provider Metadata Evidence -->
                    <div class="p-3 rounded-xl bg-slate-900/80 border border-slate-800 space-y-1.5">
                        <div class="flex items-center justify-between">
                            <span class="font-semibold text-slate-200 flex items-center gap-1.5">
                                <span>🔌</span> Sumber Provider & Feed Telemetri
                            </span>
                            <span class="text-slate-400 font-mono text-[10px]">
                                {{ $vessel->sourceProvider->name ?? 'Internal / AISStream' }}
                            </span>
                        </div>
                        <div class="text-slate-400 text-[11px]">
                            Pertama kali terdeteksi: {{ $vessel->first_seen_at ? $vessel->first_seen_at->format('Y-m-d H:i') : '—' }} |
                            Terakhir terlihat: {{ $vessel->last_seen_at ? $vessel->last_seen_at->format('Y-m-d H:i') : '—' }}
                        </div>
                    </div>

                    <!-- GFW Intelligence / Enrichment Evidence -->
                    @if(!empty($vessel->metadata['enrichment']))
                    <div class="p-3 rounded-xl bg-slate-900/80 border border-slate-800 space-y-1.5">
                        <div class="flex items-center justify-between">
                            <span class="font-semibold text-slate-200 flex items-center gap-1.5">
                                <span>🌐</span> Intelijen Global Fishing Watch (GFW v3)
                            </span>
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-indigo-500/20 text-indigo-300">
                                {{ $vessel->enrichment_status }}
                            </span>
                        </div>
                        <div class="text-slate-400 text-[11px]">
                            GFW Vessel ID: {{ $vessel->gfw_vessel_id ?? 'None' }} |
                            Gear Type: {{ $vessel->gear_type ?? 'Unknown' }}
                        </div>
                    </div>
                    @endif
                </div>
            </div>

            <!-- Card 3: Riwayat Keputusan Analis & Audit Log -->
            <div class="glass-panel p-5 rounded-2xl border border-slate-800 shadow-xl space-y-4">
                <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400 flex items-center justify-between">
                    <span>Riwayat Keputusan Analis & Audit Log (Append-Only)</span>
                    <span class="text-[10px] text-slate-500">{{ $auditLogs->count() }} rekaman</span>
                </h3>

                <div class="space-y-3">
                    @forelse($auditLogs as $log)
                    @php
                        $det = $log->details ?? [];
                    @endphp
                    <div class="p-3 rounded-xl bg-slate-900/80 border border-slate-800/80 text-xs space-y-1">
                        <div class="flex items-center justify-between text-[11px]">
                            <span class="font-bold text-indigo-300">
                                ⚖️ {{ ucwords(str_replace('_', ' ', $det['action'] ?? $log->action)) }}
                            </span>
                            <span class="text-slate-500 font-mono text-[10px]">
                                {{ $log->created_at ? $log->created_at->format('Y-m-d H:i:s') : '—' }}
                            </span>
                        </div>
                        <div class="text-slate-300 text-[11px]">
                            <span class="text-slate-400">Analis:</span> {{ $det['actor_name'] ?? $log->user?->name ?? 'System' }} |
                            <span class="text-slate-400">Transisi:</span>
                            {{ $det['previous_vessel_type'] ?? '—' }} ({{ $det['previous_verification_status'] ?? '—' }}) →
                            <strong class="text-white">{{ $det['new_vessel_type'] ?? '—' }} ({{ $det['new_verification_status'] ?? '—' }})</strong>
                        </div>
                        @if(!empty($det['reason']))
                        <div class="text-slate-300 text-[11px] bg-slate-950/60 p-2 rounded-lg mt-1">
                            <span class="text-slate-400 font-semibold">Alasan:</span> {{ $det['reason'] }}
                        </div>
                        @endif
                        @if(!empty($det['evidence']))
                        <div class="text-slate-400 text-[10px] mt-0.5">
                            <span class="font-semibold text-slate-400">Bukti:</span> {{ $det['evidence'] }}
                        </div>
                        @endif
                    </div>
                    @empty
                    <div class="text-xs text-slate-500 py-3 text-center">
                        Belum ada riwayat review analis untuk kapal ini.
                    </div>
                    @endforelse
                </div>
            </div>

        </div>

        <!-- Right Column: Analyst Decision Desk Form (5 Cols) -->
        <div class="lg:col-span-5 space-y-6">

            <div class="glass-panel p-5 rounded-2xl border border-indigo-500/30 bg-indigo-950/10 shadow-xl space-y-4 sticky top-6">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-bold text-white uppercase tracking-wider flex items-center gap-2">
                        <span>⚖️</span> Keputusan Analis
                    </h3>
                    <span class="px-2 py-0.5 rounded text-[10px] font-mono bg-indigo-500/20 text-indigo-300 font-semibold border border-indigo-500/30">
                        Otorisasi: {{ auth()->user()?->role }}
                    </span>
                </div>

                <p class="text-xs text-slate-400 leading-relaxed">
                    Setiap keputusan memerlukan penjelasan alasan yang bermakna dan bukti pendukung. Tindakan diverifikasi secara atomik dan dicatat permanen dalam audit log.
                </p>

                <form action="{{ route('vessels.classification-review.decision', $vessel->id) }}" method="POST" id="decisionForm" class="space-y-4 text-xs">
                    @csrf

                    <!-- 1. Decision Action -->
                    <div class="space-y-1.5">
                        <label class="block text-slate-300 font-semibold uppercase tracking-wider text-[10px]">
                            Pilih Tindakan Verifikasi <span class="text-rose-400">*</span>
                        </label>
                        <select name="action" id="actionSelect" required
                            class="w-full px-3 py-2 rounded-xl bg-slate-900 border border-slate-700 text-slate-200 focus:outline-none focus:border-indigo-400 font-sans transition">
                            <option value="">-- Pilih Tindakan --</option>
                            <option value="verify_fishing" {{ old('action') === 'verify_fishing' ? 'selected' : '' }}
                                {{ ($vessel->ais_ship_type_code !== null && (int)$vessel->ais_ship_type_code > 0 && (int)$vessel->ais_ship_type_code !== 30) ? 'disabled' : '' }}>
                                ✓ Verify Fishing (Permanen 100% Aman)
                                @if($vessel->ais_ship_type_code !== null && (int)$vessel->ais_ship_type_code > 0 && (int)$vessel->ais_ship_type_code !== 30)
                                    — [DILARANG: AIS Non-Fishing]
                                @endif
                            </option>
                            <option value="verify_non_fishing" {{ old('action') === 'verify_non_fishing' ? 'selected' : '' }}>
                                ✓ Verify Non-Fishing (Tetapkan Cargo/Tanker/dll)
                            </option>
                            <option value="reclassify" {{ old('action') === 'reclassify' ? 'selected' : '' }}>
                                ⇄ Reclassify (Perbaiki Klasifikasi Sebelumnya)
                            </option>
                            <option value="reject" {{ old('action') === 'reject' ? 'selected' : '' }}>
                                ✕ Reject / Bukti Tidak Memadai (Protective Hold)
                            </option>
                        </select>
                        @if($vessel->ais_ship_type_code !== null && (int)$vessel->ais_ship_type_code > 0 && (int)$vessel->ais_ship_type_code !== 30)
                            <p class="text-[10px] text-rose-400 italic">
                                * Pilihan "Verify Fishing" dinonaktifkan karena kapal membawa bukti objektif telemetri AIS ShipType non-fishing ({{ $vessel->ais_ship_type_code }}).
                            </p>
                        @endif
                    </div>

                    <!-- 2. Target Vessel Type (Conditional for verify_non_fishing or reclassify) -->
                    <div class="space-y-1.5" id="targetTypeGroup">
                        <label class="block text-slate-300 font-semibold uppercase tracking-wider text-[10px]">
                            Tipe Kapal Target <span class="text-rose-400">*</span>
                        </label>
                        <select name="target_vessel_type" id="targetTypeSelect"
                            class="w-full px-3 py-2 rounded-xl bg-slate-900 border border-slate-700 text-slate-200 focus:outline-none focus:border-indigo-400 font-sans transition">
                            <option value="">-- Pilih Tipe Kapal --</option>
                            <option value="cargo" {{ old('target_vessel_type') === 'cargo' ? 'selected' : '' }}>Cargo / Kapal Kargo</option>
                            <option value="tanker" {{ old('target_vessel_type') === 'tanker' ? 'selected' : '' }}>Tanker / Kapal Tangki</option>
                            <option value="passenger" {{ old('target_vessel_type') === 'passenger' ? 'selected' : '' }}>Passenger / Penumpang</option>
                            <option value="tug" {{ old('target_vessel_type') === 'tug' ? 'selected' : '' }}>Tug / Kapal Tunda</option>
                            <option value="pleasure" {{ old('target_vessel_type') === 'pleasure' ? 'selected' : '' }}>Pleasure Craft / Rekreasi</option>
                            <option value="other" {{ old('target_vessel_type') === 'other' ? 'selected' : '' }}>Other / Lainnya</option>
                            <option value="fishing" {{ old('target_vessel_type') === 'fishing' ? 'selected' : '' }}
                                {{ ($vessel->ais_ship_type_code !== null && (int)$vessel->ais_ship_type_code > 0 && (int)$vessel->ais_ship_type_code !== 30) ? 'disabled' : '' }}>
                                Fishing (Hanya jika AIS code 30 atau null)
                            </option>
                        </select>
                    </div>

                    <!-- 3. Mandatory Reason -->
                    <div class="space-y-1.5">
                        <label class="block text-slate-300 font-semibold uppercase tracking-wider text-[10px]">
                            Alasan Keputusan Analis <span class="text-rose-400">* (Wajib, min 5 karakter)</span>
                        </label>
                        <textarea name="reason" rows="3" required placeholder="Jelaskan alasan verifikasi atau resolusi konflik secara profesional..."
                            class="w-full px-3 py-2 rounded-xl bg-slate-900 border border-slate-700 text-slate-200 placeholder-slate-500 focus:outline-none focus:border-indigo-400 font-sans transition">{{ old('reason') }}</textarea>
                    </div>

                    <!-- 4. Mandatory Evidence -->
                    <div class="space-y-1.5" id="evidenceGroup">
                        <label class="block text-slate-300 font-semibold uppercase tracking-wider text-[10px]">
                            Bukti Pendukung <span class="text-rose-400">*</span>
                        </label>
                        <textarea name="evidence" rows="3" placeholder="Sebutkan bukti dokumen, registri bendera, AIS frame Type 5/24B, atau basis data pelabuhan..."
                            class="w-full px-3 py-2 rounded-xl bg-slate-900 border border-slate-700 text-slate-200 placeholder-slate-500 focus:outline-none focus:border-indigo-400 font-sans transition">{{ old('evidence') }}</textarea>
                    </div>

                    <!-- Submit Button -->
                    <div class="pt-2">
                        <button type="submit" class="w-full py-2.5 px-4 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white font-bold text-xs uppercase tracking-wider transition shadow-md flex items-center justify-center gap-2">
                            <span>⚖️</span> Simpan Keputusan & Catat Audit Log
                        </button>
                    </div>
                </form>
            </div>

        </div>

    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const actionSelect = document.getElementById('actionSelect');
    const targetTypeGroup = document.getElementById('targetTypeGroup');
    const targetTypeSelect = document.getElementById('targetTypeSelect');

    function syncFormState() {
        const val = actionSelect.value;
        if (val === 'verify_non_fishing' || val === 'reclassify') {
            targetTypeGroup.style.display = 'block';
            targetTypeSelect.required = true;
        } else {
            targetTypeGroup.style.display = 'none';
            targetTypeSelect.required = false;
        }
    }

    actionSelect.addEventListener('change', syncFormState);
    syncFormState();
});
</script>
@endsection

