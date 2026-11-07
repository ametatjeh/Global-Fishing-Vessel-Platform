@extends('layouts.app')

@section('header_title', 'Berkas Investigasi — ' . $investigation->case_number . ' (Private Research)')

@section('content')
<div class="space-y-6">

    <!-- Header & Watermark Banner -->
    <div class="glass-panel p-6 rounded-2xl border border-slate-800 shadow-xl flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-3">
                <span class="text-3xl">📁</span>
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-2xl font-black text-white tracking-wide">
                            {{ $investigation->case_number }}
                        </h2>
                        <span class="px-2.5 py-0.5 rounded text-xs font-mono uppercase bg-slate-800 text-teal-300 border border-slate-700">
                            {{ $investigation->status }}
                        </span>
                        <span class="px-2.5 py-0.5 rounded text-xs font-mono font-bold uppercase
                            {{ $investigation->confidentiality_level === 'strictly_confidential' ? 'bg-rose-500/20 text-rose-300 border border-rose-500/40' : 'bg-amber-500/20 text-amber-300 border border-amber-500/40' }}">
                            {{ $investigation->confidentiality_level ?? 'private' }}
                        </span>
                    </div>
                    <div class="text-sm font-semibold text-slate-300 mt-1">{{ $investigation->title }}</div>
                </div>
            </div>
            <div class="mt-2 flex items-center gap-2 text-[11px] text-rose-300/80 font-mono">
                <span>🔒 KLASIFIKASI KERAHASIAAN:</span>
                <span class="font-bold underline">PRIVATE — RESEARCH TEAM ONLY</span>
                <span>(Dilarang menyebarluaskan data atau koordinat tanpa otorisasi pimpinan riset)</span>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('investigations.index') }}" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-medium text-xs transition border border-slate-700">
                &larr; Berkas
            </a>

            @if($canExport)
            <a href="{{ route('investigations.export', $investigation->id) }}" class="px-3 py-2 rounded-xl bg-indigo-600/80 hover:bg-indigo-500 text-white font-bold text-xs transition border border-indigo-500/40 shadow-lg shadow-indigo-500/20 flex items-center gap-1.5">
                <span>⬇</span>
                <span>Ekspor Dossier (JSON)</span>
            </a>
            @endif

            <a href="{{ route('map.index') }}" class="px-4 py-2 rounded-xl bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold text-xs transition shadow-lg shadow-teal-500/20">
                Lihat di Peta
            </a>
        </div>
    </div>

    @if(session('status'))
    <div class="p-4 rounded-xl bg-teal-500/10 border border-teal-500/30 text-teal-300 text-xs font-medium">
        {{ session('status') }}
    </div>
    @endif

    <!-- Case Dossier Overview Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Left/Main Column: Overview & Four Research Focus Areas -->
        <div class="lg:col-span-2 space-y-6">

            <!-- Summary Card -->
            <div class="glass-panel p-5 rounded-xl border border-slate-800 space-y-3 text-xs">
                <h3 class="font-bold uppercase tracking-wider text-teal-300 border-b border-slate-800 pb-2">
                    Uraian Fakta & Hipotesis Awal Riset
                </h3>
                <div class="text-slate-200 leading-relaxed p-3.5 rounded-lg bg-slate-900 border border-slate-800">
                    {{ $investigation->summary }}
                </div>
            </div>

            <!-- Target Vessel Profile Card -->
            @if($investigation->targetVessel)
            <div class="glass-panel p-5 rounded-xl border border-slate-800 space-y-3 text-xs">
                <div class="flex items-center justify-between border-b border-slate-800 pb-2">
                    <h3 class="font-bold uppercase tracking-wider text-sky-400">
                        Target Armada Utama Teridentifikasi
                    </h3>
                    <a href="{{ route('vessels.show', $investigation->targetVessel->id) }}" class="text-cyan-400 hover:underline">
                        Buka Profil Kapal Lengkap &rarr;
                    </a>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-slate-300">
                    <div class="p-2.5 rounded-lg bg-slate-900/60 border border-slate-800">
                        <div class="text-[10px] text-slate-400 uppercase">Nama Kapal</div>
                        <div class="font-bold text-white text-sm">{{ $investigation->targetVessel->name }}</div>
                    </div>
                    <div class="p-2.5 rounded-lg bg-slate-900/60 border border-slate-800">
                        <div class="text-[10px] text-slate-400 uppercase">Bendera Negara</div>
                        <div class="font-mono text-teal-300 uppercase font-bold text-sm">{{ $investigation->targetVessel->flag_state ?? 'UN' }}</div>
                    </div>
                    <div class="p-2.5 rounded-lg bg-slate-900/60 border border-slate-800">
                        <div class="text-[10px] text-slate-400 uppercase">MMSI / IMO</div>
                        <div class="font-mono text-white text-xs">{{ $investigation->targetVessel->mmsi ?? '-' }} / {{ $investigation->targetVessel->imo ?? '-' }}</div>
                    </div>
                    <div class="p-2.5 rounded-lg bg-slate-900/60 border border-slate-800">
                        <div class="text-[10px] text-slate-400 uppercase">Tipe Kapal</div>
                        <div class="capitalize text-slate-200 text-xs font-semibold">{{ $investigation->targetVessel->vessel_type }}</div>
                    </div>
                </div>
            </div>
            @endif

            <!-- FOCUS A: Pergantian Identitas dan Bendera Kapal (Flag Hopping) -->
            <div class="glass-panel p-5 rounded-xl border border-slate-800 space-y-4 text-xs">
                <div class="flex items-center justify-between border-b border-slate-800 pb-2">
                    <div class="flex items-center gap-2">
                        <span class="w-6 h-6 rounded-lg bg-indigo-500/20 text-indigo-400 font-bold flex items-center justify-center text-xs">A</span>
                        <h3 class="font-bold uppercase tracking-wider text-indigo-300">
                            Pergantian Identitas dan Bendera Kapal (Flag Hopping)
                        </h3>
                    </div>
                    @if($identityAnalysis && $identityAnalysis['flag_hopping_detected'])
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase font-mono bg-rose-500/20 text-rose-300 border border-rose-500/40">
                            ⚠ Indikasi Flag Hopping Terdeteksi
                        </span>
                    @else
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase font-mono bg-emerald-500/20 text-emerald-300 border border-emerald-500/40">
                            Identitas Bendera Stabil
                        </span>
                    @endif
                </div>

                @if($identityAnalysis)
                <div class="space-y-3">
                    <div class="p-3 rounded-lg bg-slate-900/80 border border-slate-800 flex items-center justify-between text-slate-300">
                        <div>
                            <span class="text-slate-400">Bendera Terpantau:</span>
                            <span class="font-mono font-bold text-white uppercase ml-1">
                                {{ implode(', ', $identityAnalysis['observed_flags'] ?: ['Tidak Tercatat']) }}
                            </span>
                        </div>
                        <div class="text-[11px] text-slate-400">
                            Total Rekaman Perubahan: <strong class="text-white">{{ count($identityAnalysis['identity_timeline']) }}</strong>
                        </div>
                    </div>

                    @if(!empty($identityAnalysis['identity_timeline']))
                    <div class="overflow-x-auto rounded-lg border border-slate-800">
                        <table class="w-full text-left text-slate-300">
                            <thead class="bg-slate-900/90 text-slate-400 uppercase text-[10px] border-b border-slate-800">
                                <tr>
                                    <th class="py-2 px-3">Tipe</th>
                                    <th class="py-2 px-3">Nilai Rekaman</th>
                                    <th class="py-2 px-3">Periode Berlaku</th>
                                    <th class="py-2 px-3">Status</th>
                                    <th class="py-2 px-3">Sumber Provenance</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800/60 font-mono text-[11px]">
                                @foreach($identityAnalysis['identity_timeline'] as $item)
                                <tr>
                                    <td class="py-2 px-3 uppercase text-indigo-300">{{ $item['type'] }}</td>
                                    <td class="py-2 px-3 font-bold text-white">{{ $item['value'] }}</td>
                                    <td class="py-2 px-3 text-slate-400 text-[10px]">
                                        {{ $item['valid_from'] ? substr($item['valid_from'], 0, 10) : 'Awal Catatan' }} &rarr; {{ $item['valid_to'] ? substr($item['valid_to'], 0, 10) : 'Sekarang' }}
                                    </td>
                                    <td class="py-2 px-3">
                                        @if($item['is_current'])
                                            <span class="text-emerald-400 font-bold">Aktif</span>
                                        @else
                                            <span class="text-slate-500">Historis</span>
                                        @endif
                                    </td>
                                    <td class="py-2 px-3 text-slate-400 text-[10px] font-sans">{{ $item['provenance'] }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @else
                    <div class="p-3 text-center text-slate-500 bg-slate-900/50 rounded-lg">
                        Tidak ada riwayat pergantian identitas sebelumnya yang tercatat untuk kapal ini.
                    </div>
                    @endif

                    <div class="text-[11px] text-slate-400 italic">
                        Catatan Provenance: {{ $identityAnalysis['provenance_notes'] }}
                    </div>
                </div>
                @else
                <div class="text-slate-500 italic">Data analisis identitas memerlukan penetapan kapal target.</div>
                @endif
            </div>

            <!-- FOCUS B: Jaringan Korporasi dan Kepemilikan Manfaat (Beneficial Ownership) -->
            <div class="glass-panel p-5 rounded-xl border border-slate-800 space-y-4 text-xs">
                <div class="flex items-center justify-between border-b border-slate-800 pb-2">
                    <div class="flex items-center gap-2">
                        <span class="w-6 h-6 rounded-lg bg-purple-500/20 text-purple-400 font-bold flex items-center justify-center text-xs">B</span>
                        <h3 class="font-bold uppercase tracking-wider text-purple-300">
                            Jejaring Korporasi dan Kepemilikan Manfaat (Beneficial Ownership)
                        </h3>
                    </div>
                    @if($corporateAnalysis)
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase font-mono
                        {{ $corporateAnalysis['confidence_level'] === 'verified_legal_docs' ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/40' : 'bg-slate-800 text-slate-400 border border-slate-700' }}">
                        Tingkat Validasi: {{ $corporateAnalysis['confidence_level'] }}
                    </span>
                    @endif
                </div>

                @if($corporateAnalysis)
                <div class="space-y-3">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div class="p-3 rounded-lg bg-slate-900/80 border border-slate-800">
                            <div class="text-[10px] text-slate-400 uppercase">Pemilik Terdaftar (Registered Owner)</div>
                            <div class="font-bold text-white text-xs mt-0.5">{{ $corporateAnalysis['registered_owner'] }}</div>
                        </div>
                        <div class="p-3 rounded-lg bg-slate-900/80 border border-slate-800">
                            <div class="text-[10px] text-slate-400 uppercase">Operator Armada</div>
                            <div class="font-bold text-white text-xs mt-0.5">{{ $corporateAnalysis['operator'] }}</div>
                        </div>
                        <div class="p-3 rounded-lg bg-slate-900/80 border border-slate-800">
                            <div class="text-[10px] text-slate-400 uppercase">Beneficial Owner (Pemilik Manfaat)</div>
                            <div class="font-bold text-purple-300 text-xs mt-0.5">{{ $corporateAnalysis['beneficial_owner'] }}</div>
                        </div>
                    </div>

                    @if(!empty($corporateAnalysis['related_sister_vessels']))
                    <div class="mt-3">
                        <div class="text-xs font-semibold text-slate-300 mb-2">
                            Armada Sejawat Terkorelasi (Sister Fleet Correlation): {{ $corporateAnalysis['related_fleet_count'] }} Kapal Teridentifikasi
                        </div>
                        <div class="overflow-x-auto rounded-lg border border-slate-800">
                            <table class="w-full text-left text-slate-300">
                                <thead class="bg-slate-900/90 text-slate-400 uppercase text-[10px] border-b border-slate-800">
                                    <tr>
                                        <th class="py-2 px-3">Nama Kapal</th>
                                        <th class="py-2 px-3">MMSI</th>
                                        <th class="py-2 px-3">Bendera</th>
                                        <th class="py-2 px-3">Tipe</th>
                                        <th class="py-2 px-3">Operator Bersama</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-800/60 text-[11px]">
                                    @foreach($corporateAnalysis['related_sister_vessels'] as $sister)
                                    <tr>
                                        <td class="py-2 px-3 font-bold text-white">{{ $sister['name'] }}</td>
                                        <td class="py-2 px-3 font-mono text-slate-300">{{ $sister['mmsi'] ?? '-' }}</td>
                                        <td class="py-2 px-3 font-mono uppercase text-teal-300">{{ $sister['flag_state'] ?? '-' }}</td>
                                        <td class="py-2 px-3 capitalize text-slate-400">{{ $sister['vessel_type'] ?? '-' }}</td>
                                        <td class="py-2 px-3 text-slate-300">{{ $sister['operator'] ?? $sister['owner'] }}</td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                    @endif

                    <div class="p-3 rounded-lg bg-amber-500/10 border border-amber-500/20 text-amber-300/90 text-[11px] leading-relaxed">
                        <strong>Metodologi Kerahasiaan Kepemilikan:</strong> {{ $corporateAnalysis['investigative_disclaimer'] }}
                    </div>
                </div>
                @else
                <div class="text-slate-500 italic">Data analisis korporasi memerlukan penetapan kapal target.</div>
                @endif
            </div>

            <!-- FOCUS C: Analisis Lintasan & Dugaan Pelanggaran Batas ZEE -->
            <div class="glass-panel p-5 rounded-xl border border-slate-800 space-y-4 text-xs">
                <div class="flex items-center justify-between border-b border-slate-800 pb-2">
                    <div class="flex items-center gap-2">
                        <span class="w-6 h-6 rounded-lg bg-teal-500/20 text-teal-400 font-bold flex items-center justify-center text-xs">C</span>
                        <h3 class="font-bold uppercase tracking-wider text-teal-300">
                            Analisis Lintasan Geospasial & Dugaan Pelanggaran Batas Wilayah ZEE
                        </h3>
                    </div>
                    @if($eezAnalysis)
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase font-mono
                        {{ $eezAnalysis['anomalies_detected_count'] > 0 ? 'bg-amber-500/20 text-amber-300 border border-amber-500/40' : 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/40' }}">
                        Anomali Spasial: {{ $eezAnalysis['anomalies_detected_count'] }}
                    </span>
                    @endif
                </div>

                @if($eezAnalysis)
                <div class="space-y-3">
                    @if($eezAnalysis['latest_position'])
                    <div class="p-3 rounded-lg bg-slate-900/80 border border-slate-800 grid grid-cols-2 sm:grid-cols-4 gap-2 text-slate-300">
                        <div>
                            <span class="text-slate-400 text-[10px] uppercase block">Lintang (Lat):</span>
                            <span class="font-mono text-white font-bold">{{ $eezAnalysis['latest_position']['latitude'] }}</span>
                        </div>
                        <div>
                            <span class="text-slate-400 text-[10px] uppercase block">Bujur (Lon):</span>
                            <span class="font-mono text-white font-bold">{{ $eezAnalysis['latest_position']['longitude'] }}</span>
                        </div>
                        <div>
                            <span class="text-slate-400 text-[10px] uppercase block">Kecepatan:</span>
                            <span class="font-mono text-teal-300">{{ $eezAnalysis['latest_position']['speed_knots'] ?? 0 }} kts</span>
                        </div>
                        <div>
                            <span class="text-slate-400 text-[10px] uppercase block">Waktu Terakhir:</span>
                            <span class="font-mono text-slate-400 text-[10px]">{{ substr($eezAnalysis['latest_position']['observed_at'] ?? '', 0, 16) }} UTC</span>
                        </div>
                    </div>
                    @endif

                    @if(!empty($eezAnalysis['boundary_events']))
                    <div class="overflow-x-auto rounded-lg border border-slate-800">
                        <table class="w-full text-left text-slate-300">
                            <thead class="bg-slate-900/90 text-slate-400 uppercase text-[10px] border-b border-slate-800">
                                <tr>
                                    <th class="py-2 px-3">Aturan / Rule</th>
                                    <th class="py-2 px-3">Klasifikasi Formil</th>
                                    <th class="py-2 px-3">Koordinat</th>
                                    <th class="py-2 px-3">Waktu Terdeteksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800/60 text-[11px]">
                                @foreach($eezAnalysis['boundary_events'] as $evt)
                                <tr>
                                    <td class="py-2 px-3 font-semibold text-white">{{ $evt['rule'] }}</td>
                                    <td class="py-2 px-3">
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase font-mono
                                            {{ $evt['status'] === 'confirmed' ? 'bg-rose-500/20 text-rose-300' : 'bg-amber-500/20 text-amber-300' }}">
                                            {{ $evt['classification'] }}
                                        </span>
                                    </td>
                                    <td class="py-2 px-3 font-mono text-slate-300 text-[10px]">
                                        {{ $evt['coordinates']['latitude'] }}, {{ $evt['coordinates']['longitude'] }}
                                    </td>
                                    <td class="py-2 px-3 text-slate-400 text-[10px] font-mono">{{ substr($evt['event_time'] ?? '', 0, 16) }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @else
                    <div class="p-3 text-center text-slate-500 bg-slate-900/50 rounded-lg">
                        Tidak ada anomali atau peristiwa pelanggaran batas wilayah ZEE yang terekam pada basis data kepatuhan.
                    </div>
                    @endif

                    <div class="text-[11px] text-slate-400 italic">
                        Prinsip Audit Geospasial: {{ $eezAnalysis['methodology_note'] }}
                    </div>
                </div>
                @else
                <div class="text-slate-500 italic">Data analisis ZEE memerlukan penetapan kapal target.</div>
                @endif
            </div>

            <!-- FOCUS D: IUU Fishing & Status Otorisasi/Izin Penangkapan -->
            <div class="glass-panel p-5 rounded-xl border border-slate-800 space-y-4 text-xs">
                <div class="flex items-center justify-between border-b border-slate-800 pb-2">
                    <div class="flex items-center gap-2">
                        <span class="w-6 h-6 rounded-lg bg-rose-500/20 text-rose-400 font-bold flex items-center justify-center text-xs">D</span>
                        <h3 class="font-bold uppercase tracking-wider text-rose-300">
                            IUU Fishing & Status Otorisasi Penangkapan Ikan
                        </h3>
                    </div>
                    @if($iuuAnalysis)
                        @php
                            $cat = $iuuAnalysis['verdict_category'];
                            $catStyle = match($cat) {
                                'verified_violation' => 'bg-rose-500/20 text-rose-300 border-rose-500/40',
                                'suspected_violation' => 'bg-amber-500/20 text-amber-300 border-amber-500/40',
                                'potential_risk' => 'bg-yellow-500/20 text-yellow-300 border-yellow-500/40',
                                'no_evidence_found' => 'bg-emerald-500/20 text-emerald-300 border-emerald-500/40',
                                default => 'bg-slate-800 text-slate-400 border-slate-700',
                            };
                        @endphp
                        <span class="px-2.5 py-0.5 rounded text-[10px] font-bold uppercase font-mono border {{ $catStyle }}">
                            Klasifikasi 5-Tingkat: {{ str_replace('_', ' ', $cat) }}
                        </span>
                    @endif
                </div>

                @if($iuuAnalysis)
                <div class="space-y-3">
                    <div class="p-3.5 rounded-lg bg-slate-900/80 border border-slate-800 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                        <div>
                            <div class="text-[10px] text-slate-400 uppercase">Status Daftar Hitam IUU Fishing:</div>
                            <div class="text-sm font-black font-mono mt-0.5
                                {{ $iuuAnalysis['iuu_blacklist_status'] === 'BLACKLISTED' ? 'text-rose-400' : 'text-emerald-400' }}">
                                {{ $iuuAnalysis['iuu_blacklist_status'] }}
                            </div>
                        </div>
                        <div class="sm:text-right">
                            <div class="text-[10px] text-slate-400 uppercase">Uraian Rasional Bukti:</div>
                            <div class="text-xs text-slate-200 font-semibold">{{ $iuuAnalysis['verdict_rationale'] }}</div>
                        </div>
                    </div>

                    @if(!empty($iuuAnalysis['authorizations']))
                    <div>
                        <div class="text-xs font-semibold text-slate-300 mb-2">
                            Riwayat Otorisasi & Surat Izin Penangkapan Ikan (SIPI): {{ $iuuAnalysis['authorizations_count'] }} Dokumen
                        </div>
                        <div class="overflow-x-auto rounded-lg border border-slate-800">
                            <table class="w-full text-left text-slate-300">
                                <thead class="bg-slate-900/90 text-slate-400 uppercase text-[10px] border-b border-slate-800">
                                    <tr>
                                        <th class="py-2 px-3">Kode Otorisasi</th>
                                        <th class="py-2 px-3">Tipe Izin</th>
                                        <th class="py-2 px-3">Otoritas Penerbit</th>
                                        <th class="py-2 px-3">Alat Tangkap</th>
                                        <th class="py-2 px-3">Masa Berlaku</th>
                                        <th class="py-2 px-3">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-800/60 font-mono text-[11px]">
                                    @foreach($iuuAnalysis['authorizations'] as $auth)
                                    <tr>
                                        <td class="py-2 px-3 font-bold text-white">{{ $auth['code'] }}</td>
                                        <td class="py-2 px-3 text-slate-300">{{ $auth['permit_type'] }}</td>
                                        <td class="py-2 px-3 text-slate-400 font-sans text-[10px]">{{ $auth['issuing_authority'] }}</td>
                                        <td class="py-2 px-3 text-slate-300 font-sans text-[10px]">{{ $auth['gear'] ?? '-' }}</td>
                                        <td class="py-2 px-3 text-slate-400 text-[10px]">
                                            {{ $auth['valid_from'] }} &rarr; {{ $auth['valid_to'] }}
                                        </td>
                                        <td class="py-2 px-3">
                                            @if($auth['is_current'])
                                                <span class="text-emerald-400 font-bold uppercase">Berlaku</span>
                                            @else
                                                <span class="text-rose-400 font-bold uppercase">Expired</span>
                                            @endif
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                    @endif

                    <div class="text-[11px] text-slate-400 italic">
                        Standar Verifikasi: {{ $iuuAnalysis['audit_standard'] }}
                    </div>
                </div>
                @else
                <div class="text-slate-500 italic">Data analisis IUU memerlukan penetapan kapal target.</div>
                @endif
            </div>

            <!-- Matriks Bukti Tambahan (Evidence Matrix) -->
            @if(!empty($investigation->evidence_matrix))
            <div class="glass-panel p-5 rounded-xl border border-slate-800 space-y-3 text-xs">
                <h3 class="font-bold uppercase tracking-wider text-slate-200 border-b border-slate-800 pb-2">
                    Matriks Bukti Forensik Tambahan (Evidence Matrix)
                </h3>
                <div class="space-y-2">
                    @foreach($investigation->evidence_matrix as $ev)
                    <div class="p-3 rounded-lg bg-slate-900/60 border border-slate-800 flex items-start justify-between gap-3">
                        <div>
                            <div class="font-bold text-white">{{ $ev['title'] ?? 'Bukti Forensik' }}</div>
                            <div class="text-slate-400 text-[11px] mt-0.5">{{ $ev['notes'] ?? '-' }}</div>
                        </div>
                        <div class="text-right text-[10px] text-slate-500 font-mono">
                            <div>Dicatat oleh: {{ $ev['recorded_by'] ?? '-' }}</div>
                            <div>{{ substr($ev['recorded_at'] ?? '', 0, 16) }}</div>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif

        </div>

        <!-- Right Column: Case Metadata & Action Update Form -->
        <div class="space-y-6">

            <!-- Case Metadata & Assignment -->
            <div class="glass-panel p-5 rounded-xl border border-slate-800 space-y-4 text-xs">
                <h3 class="font-bold uppercase tracking-wider text-white border-b border-slate-800 pb-2">
                    Metadata & Otorisasi Kasus
                </h3>

                <div class="space-y-2.5">
                    <div class="flex justify-between py-1 border-b border-slate-800/60">
                        <span class="text-slate-400">Klasifikasi Kerahasiaan:</span>
                        <span class="font-bold uppercase font-mono text-rose-300">{{ $investigation->confidentiality_level ?? 'private' }}</span>
                    </div>
                    <div class="flex justify-between py-1 border-b border-slate-800/60">
                        <span class="text-slate-400">Institusi Riset:</span>
                        <span class="font-semibold text-slate-200">{{ $investigation->organization ?? 'Tim Internal GFVIP' }}</span>
                    </div>
                    <div class="flex justify-between py-1 border-b border-slate-800/60">
                        <span class="text-slate-400">Prioritas:</span>
                        <span class="font-bold uppercase font-mono text-amber-300">{{ $investigation->priority }}</span>
                    </div>
                    <div class="flex justify-between py-1 border-b border-slate-800/60">
                        <span class="text-slate-400">Status Berkas:</span>
                        <span class="font-bold uppercase font-mono text-teal-300">{{ $investigation->status }}</span>
                    </div>
                    <div class="flex justify-between py-1 border-b border-slate-800/60">
                        <span class="text-slate-400">Kesimpulan Riset:</span>
                        <span class="font-bold uppercase font-mono text-indigo-300">{{ str_replace('_', ' ', $investigation->verdict_status ?? 'under_review') }}</span>
                    </div>
                    <div class="flex justify-between py-1 border-b border-slate-800/60">
                        <span class="text-slate-400">Lead Peneliti:</span>
                        <span class="font-semibold text-white">{{ $investigation->leadResearcher?->name ?? 'Belum Ditunjuk' }}</span>
                    </div>
                    <div class="flex justify-between py-1 border-b border-slate-800/60">
                        <span class="text-slate-400">Analis Pelaksana:</span>
                        <span class="font-semibold text-white">{{ $investigation->assignedTo?->name ?? 'Belum Ditugaskan' }}</span>
                    </div>
                    <div class="flex justify-between py-1 border-b border-slate-800/60">
                        <span class="text-slate-400">Inisiator Berkas:</span>
                        <span class="text-slate-300">{{ $investigation->createdBy?->name ?? 'System' }}</span>
                    </div>
                    <div class="flex justify-between py-1">
                        <span class="text-slate-400">Waktu Inisiasi:</span>
                        <span class="font-mono text-slate-400 text-[10px]">{{ $investigation->created_at->format('Y-m-d H:i') }} UTC</span>
                    </div>
                </div>
            </div>

            <!-- Current Findings & Recommendations Display -->
            <div class="glass-panel p-5 rounded-xl border border-slate-800 space-y-3 text-xs">
                <h3 class="font-bold uppercase tracking-wider text-amber-300 border-b border-slate-800 pb-2">
                    Temuan Akhir & Rekomendasi Riset
                </h3>
                <div class="space-y-2">
                    <div>
                        <span class="text-[10px] uppercase text-slate-400 font-semibold block mb-0.5">Temuan Analis:</span>
                        <div class="p-2.5 rounded-lg bg-slate-900 border border-slate-800 text-slate-300 leading-relaxed">
                            {{ $investigation->findings ?: 'Belum ada temuan tertulis yang disimpulkan.' }}
                        </div>
                    </div>
                    <div>
                        <span class="text-[10px] uppercase text-slate-400 font-semibold block mb-0.5">Rekomendasi Tindakan:</span>
                        <div class="p-2.5 rounded-lg bg-slate-900 border border-slate-800 text-slate-300 leading-relaxed">
                            {{ $investigation->recommendation ?: 'Belum ada rekomendasi yang diformulasikan.' }}
                        </div>
                    </div>
                </div>
            </div>

            <!-- Update Findings & Verdict Form (Authorized Managers Only) -->
            @if($canManage)
            <div class="glass-panel p-5 rounded-xl border border-teal-500/30 bg-teal-950/10 space-y-4 text-xs">
                <h3 class="font-bold uppercase tracking-wider text-teal-300 border-b border-teal-500/20 pb-2">
                    Perbarui Kesimpulan & Temuan Kasus
                </h3>

                <form action="{{ route('investigations.findings.update', $investigation->id) }}" method="POST" class="space-y-3">
                    @csrf
                    @method('PATCH')

                    <div>
                        <label for="verdict_status" class="block font-semibold text-slate-300 mb-1">Status Kesimpulan Hukum/Riset:</label>
                        <select name="verdict_status" id="verdict_status" class="w-full px-3 py-1.5 rounded-lg bg-slate-900 border border-slate-700 text-slate-200">
                            <option value="under_review" {{ $investigation->verdict_status === 'under_review' ? 'selected' : '' }}>Under Review (Sedang Ditelusuri)</option>
                            <option value="confirmed" {{ $investigation->verdict_status === 'confirmed' ? 'selected' : '' }}>Confirmed (Terbukti Pelanggaran)</option>
                            <option value="suspected" {{ $investigation->verdict_status === 'suspected' ? 'selected' : '' }}>Suspected (Dugaan Kuat)</option>
                            <option value="potential" {{ $investigation->verdict_status === 'potential' ? 'selected' : '' }}>Potential (Potensi Risiko)</option>
                            <option value="inconclusive" {{ $investigation->verdict_status === 'inconclusive' ? 'selected' : '' }}>Inconclusive (Bukti Tidak Cukup)</option>
                        </select>
                    </div>

                    <div>
                        <label for="status" class="block font-semibold text-slate-300 mb-1">Status Dossier:</label>
                        <select name="status" id="status" class="w-full px-3 py-1.5 rounded-lg bg-slate-900 border border-slate-700 text-slate-200">
                            <option value="open" {{ $investigation->status === 'open' ? 'selected' : '' }}>Open</option>
                            <option value="in_progress" {{ $investigation->status === 'in_progress' ? 'selected' : '' }}>In Progress</option>
                            <option value="closed" {{ $investigation->status === 'closed' ? 'selected' : '' }}>Closed</option>
                            <option value="archived" {{ $investigation->status === 'archived' ? 'selected' : '' }}>Archived</option>
                        </select>
                    </div>

                    <div>
                        <label for="findings" class="block font-semibold text-slate-300 mb-1">Ringkasan Temuan:</label>
                        <textarea name="findings" id="findings" rows="3" class="w-full px-3 py-1.5 rounded-lg bg-slate-900 border border-slate-700 text-slate-200 placeholder-slate-500" placeholder="Uraikan temuan analisis berdasarkan bukti telemetri atau satelit...">{{ $investigation->findings }}</textarea>
                    </div>

                    <div>
                        <label for="recommendation" class="block font-semibold text-slate-300 mb-1">Rekomendasi Tindakan Lanjutan:</label>
                        <textarea name="recommendation" id="recommendation" rows="2" class="w-full px-3 py-1.5 rounded-lg bg-slate-900 border border-slate-700 text-slate-200 placeholder-slate-500" placeholder="Rekomendasi pemantauan patroli, notifikasi satgas, atau tindak lanjut hukum...">{{ $investigation->recommendation }}</textarea>
                    </div>

                    <div class="pt-2 border-t border-slate-800">
                        <label class="block font-semibold text-slate-300 mb-1">Tambah Bukti Forensik Baru (Opsional):</label>
                        <input type="text" name="new_evidence_title" placeholder="Nama / Judul Dokumen Bukti" class="w-full px-3 py-1.5 rounded-lg bg-slate-900 border border-slate-700 text-slate-200 text-xs mb-1.5">
                        <textarea name="new_evidence_notes" rows="2" placeholder="Catatan bukti forensik (referensi citra SAR, koordinat AIS, dll)..." class="w-full px-3 py-1.5 rounded-lg bg-slate-900 border border-slate-700 text-slate-200 text-xs"></textarea>
                    </div>

                    <div class="pt-2">
                        <button type="submit" class="w-full py-2 rounded-xl bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold transition shadow-md shadow-teal-500/20">
                            Simpan Pembaruan Analisis
                        </button>
                    </div>
                </form>
            </div>
            @endif

        </div>

    </div>

</div>
@endsection
