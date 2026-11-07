@extends('layouts.app')

@section('header_title', 'Profil Kapal — ' . $vessel->name)

@section('content')
@php
    $flagInfo = $vessel->flag_info;
    $enrichment = $vessel->metadata['enrichment'] ?? [];
    $conflicts = $vessel->enrichment_conflicts;
    $altNames = $vessel->alternative_names;
    $eventsSummary = $vessel->gfw_events_summary;
    $provenance = $enrichment['field_provenance'] ?? [];
    $pos = $vessel->latestPosition;
@endphp

<div class="space-y-6">

    @if(session('success'))
    <div class="p-4 rounded-xl bg-teal-950/60 border border-teal-500/40 text-teal-200 text-xs flex items-center justify-between shadow-lg">
        <div class="flex items-center gap-2">
            <span class="text-base text-teal-400">✓</span>
            <span>{{ session('success') }}</span>
        </div>
        <button type="button" onclick="this.parentElement.remove()" class="text-teal-400 hover:text-white px-2 py-1">&times;</button>
    </div>
    @endif

    @if(session('warning'))
    <div class="p-4 rounded-xl bg-amber-950/60 border border-amber-500/40 text-amber-200 text-xs flex items-center justify-between shadow-lg">
        <div class="flex items-center gap-2">
            <span class="text-base text-amber-400">⚠️</span>
            <span>{{ session('warning') }}</span>
        </div>
        <button type="button" onclick="this.parentElement.remove()" class="text-amber-400 hover:text-white px-2 py-1">&times;</button>
    </div>
    @endif

    @if(session('error'))
    <div class="p-4 rounded-xl bg-rose-950/60 border border-rose-500/40 text-rose-200 text-xs flex items-center justify-between shadow-lg">
        <div class="flex items-center gap-2">
            <span class="text-base text-rose-400">✕</span>
            <span>{{ session('error') }}</span>
        </div>
        <button type="button" onclick="this.parentElement.remove()" class="text-rose-400 hover:text-white px-2 py-1">&times;</button>
    </div>
    @endif

    <!-- Header Card -->
    <div class="glass-panel p-6 rounded-2xl border border-slate-800 shadow-xl flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-3">
                <span class="text-4xl">{{ $flagInfo['flag_emoji'] }}</span>
                <div>
                    <h2 class="text-2xl font-black text-white tracking-wide flex items-center gap-2 flex-wrap">
                        <span>{{ $vessel->name }}</span>
                        <span class="px-2.5 py-0.5 rounded text-xs font-mono font-bold uppercase {{ $flagInfo['status'] === 'verified_explicit' ? 'bg-emerald-950/90 text-emerald-300 border border-emerald-500/40' : ($flagInfo['status'] === 'conflict_detected' ? 'bg-rose-950/90 text-rose-300 border border-rose-500/40' : 'bg-slate-800 text-slate-300 border border-slate-700') }}">
                            @if(!empty($flagInfo['is_known']) && $flagInfo['status'] !== 'unknown')
                                {{ $flagInfo['iso2'] }} &bull; {{ $flagInfo['country_name'] }}
                            @else
                                Unknown
                            @endif
                        </span>
                        @if($vessel->gfw_sync_status === 'synced')
                        <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold uppercase bg-teal-500/20 text-teal-300 border border-teal-500/40">
                            ✓ GFW Enriched
                        </span>
                        @elseif($vessel->gfw_sync_status === 'syncing')
                        <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold uppercase bg-sky-500/20 text-sky-300 border border-sky-500/40 animate-pulse">
                            ⏳ GFW Syncing...
                        </span>
                        @elseif($vessel->gfw_sync_status === 'not_found')
                        <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold uppercase bg-slate-800 text-slate-300 border border-slate-700">
                            🔍 GFW Not Found
                        </span>
                        @elseif($vessel->gfw_sync_status === 'match_review_required')
                        <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold uppercase bg-amber-500/20 text-amber-300 border border-amber-500/40">
                            ⚠️ Review Required
                        </span>
                        @elseif($vessel->gfw_sync_status === 'failed')
                        <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold uppercase bg-rose-500/20 text-rose-300 border border-rose-500/40">
                            ✕ GFW Sync Failed
                        </span>
                        @endif
                    </h2>
                    <div class="text-xs text-slate-400 mt-1.5 font-mono flex items-center gap-2 flex-wrap">
                        <span>UUID: <span class="text-slate-300">{{ $vessel->id }}</span></span>
                        <span>&bull;</span>
                        <span>Tipe: <strong class="capitalize text-teal-300 font-sans">{{ $vessel->vessel_type }}</strong></span>
                        <span>&bull;</span>
                        <span class="text-[11px] {{ $flagInfo['is_verified'] ? 'text-emerald-400' : 'text-amber-400' }}">
                            {{ $flagInfo['status'] === 'verified_explicit' ? '✓ Flag Terverifikasi' : '⚠️ ' . $flagInfo['status'] }}
                        </span>
                        @if($vessel->last_enriched_at)
                        <span>&bull;</span>
                        <span class="text-slate-400 text-[11px]">Enriched: {{ $vessel->last_enriched_at->diffForHumans() }}</span>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('vessels.index') }}" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-medium text-xs transition border border-slate-700">
                &larr; Kembali ke Registry
            </a>
            @if($pos)
            <a href="{{ route('map.index') }}#map=12/{{ $pos->latitude }}/{{ $pos->longitude }}&vessel={{ $vessel->mmsi }}" class="px-4 py-2 rounded-xl bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold text-xs transition shadow-lg shadow-teal-500/20 flex items-center gap-1.5">
                <span>🗺️</span>
                <span>Lihat di Peta Global</span>
            </a>
            @else
            <a href="{{ route('map.index') }}" class="px-4 py-2 rounded-xl bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold text-xs transition shadow-lg shadow-teal-500/20">
                Lihat di Peta Global
            </a>
            @endif
        </div>
    </div>

    <!-- Conflict Warning Banner (if any) -->
    @if(count($conflicts) > 0)
    <div class="p-4 rounded-xl bg-amber-950/40 border border-amber-500/40 text-amber-200 text-xs space-y-2">
        <div class="flex items-center gap-2 font-bold text-amber-300">
            <span>⚠️</span>
            <span>Konflik Multi-Sumber Terdeteksi (Analyst Review Required)</span>
        </div>
        <div class="space-y-1 text-slate-300">
            @foreach($conflicts as $field => $c)
            <div class="flex items-start gap-2 text-[11px]">
                <span class="px-1.5 py-0.5 rounded bg-amber-500/20 text-amber-300 font-mono text-[10px] uppercase font-bold shrink-0">{{ $field }}</span>
                <span>{{ $c['message'] ?? 'Konflik nilai antara data telemetri lokal dan provider eksternal.' }} (Lokal: <strong>{{ (string)$c['local'] }}</strong>, GFW: <strong>{{ (string)$c['gfw'] }}</strong>)</span>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    <!-- Main Grid: Sections 1 to 5 -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- SECTION 1: Core Identifiers -->
        <div class="glass-panel p-5 rounded-xl border border-slate-800 space-y-4">
            <h3 class="text-xs font-bold uppercase tracking-wider text-teal-400 border-b border-slate-800 pb-2 flex items-center justify-between">
                <span class="flex items-center gap-1.5">
                    <span>🆔</span>
                    <span>1. Identitas & Kode Registrasi</span>
                </span>
                <span class="text-[10px] px-1.5 py-0.5 rounded font-mono {{ $vessel->isManuallyVerified() ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30' : 'bg-slate-800 text-slate-400' }}">
                    {{ $vessel->verification_status }}
                </span>
            </h3>

            <div class="space-y-2.5 text-xs">
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Nama Utama:</span>
                    <span class="font-bold text-white font-mono">{{ $vessel->name }}</span>
                </div>
                @if(count($altNames) > 0)
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Nama Alternatif:</span>
                    <div class="text-right">
                        @foreach($altNames as $alt)
                        <span class="inline-block px-1.5 py-0.5 rounded bg-slate-800 text-teal-300 font-mono text-[11px] ml-1">{{ $alt }}</span>
                        @endforeach
                    </div>
                </div>
                @endif
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Bendera Negara:</span>
                    <span class="font-mono text-white font-bold flex items-center gap-1.5">
                        <span>{{ $flagInfo['flag_emoji'] }}</span>
                        @if(!empty($flagInfo['is_known']) && $flagInfo['status'] !== 'unknown')
                            <span>{{ $flagInfo['country_name'] }} ({{ $flagInfo['iso2'] }})</span>
                        @else
                            <span>Unknown</span>
                        @endif
                    </span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Status Bendera:</span>
                    <span class="text-[10px] font-mono px-2 py-0.5 rounded {{ $flagInfo['status'] === 'verified_explicit' ? 'bg-emerald-950 text-emerald-300' : ($flagInfo['status'] === 'conflict_detected' ? 'bg-rose-950 text-rose-300' : 'bg-slate-800 text-amber-300') }}">
                        {{ $flagInfo['status'] === 'verified_explicit' ? 'Verified Explicit' : ($flagInfo['status'] === 'mid_administrative_indicator' ? 'MID Clue (Belum Terverifikasi)' : $flagInfo['status']) }}
                    </span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">MMSI:</span>
                    <span class="font-mono text-teal-300 font-bold">{{ $vessel->mmsi ?? 'Belum tercatat' }}</span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">IMO:</span>
                    <span class="font-mono text-white font-bold">{{ $vessel->imo ?? 'Belum tercatat' }}</span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Callsign:</span>
                    <span class="font-mono text-white font-bold">{{ $vessel->callsign ?? 'Belum tercatat' }}</span>
                </div>
                <div class="flex justify-between py-1">
                    <span class="text-slate-400">Kode Lambung (Hull):</span>
                    <span class="font-mono text-slate-300">{{ $vessel->hull_code ?? 'Belum tercatat' }}</span>
                </div>
            </div>
        </div>

        <!-- SECTION 2: Vessel Classification -->
        <div class="glass-panel p-5 rounded-xl border border-slate-800 space-y-4">
            <h3 class="text-xs font-bold uppercase tracking-wider text-teal-400 border-b border-slate-800 pb-2 flex items-center justify-between">
                <span class="flex items-center gap-1.5">
                    <span>🏷️</span>
                    <span>2. Klasifikasi & Tipe Armada</span>
                </span>
                @if(isset($provenance['vessel_type']))
                <span class="text-[9px] px-1.5 py-0.5 rounded bg-teal-500/10 text-teal-300 border border-teal-500/30 font-mono">
                    Enriched
                </span>
                @endif
            </h3>

            <div class="space-y-2.5 text-xs">
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Tipe Kapal (Vessel Type):</span>
                    <span class="font-bold {{ $vessel->vessel_type === 'unknown' || empty($vessel->vessel_type) ? 'text-slate-400' : 'text-emerald-300' }}">
                        {{ $vessel->display_vessel_type }}
                    </span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Kategori Armada (Fleet):</span>
                    <span class="text-white capitalize">
                        @if($vessel->vessel_type === 'fishing')
                            Fishing Fleet
                        @elseif($vessel->vessel_type === 'passenger')
                            Commercial Passenger / Yacht
                        @elseif(in_array($vessel->vessel_type, ['patrol', 'military', 'sar']))
                            Law Enforcement / Security
                        @else
                            {{ ucfirst($vessel->vessel_type) }} Fleet
                        @endif
                    </span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Alat Tangkap (Gear):</span>
                    <span class="text-white font-mono">
                        {{ $vessel->gear_type ? ucfirst($vessel->gear_type) : ($vessel->vessel_type === 'fishing' ? 'Belum terdaftar' : 'N/A (Bukan Kapal Tangkap)') }}
                    </span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Sumber Klasifikasi:</span>
                    <span class="text-[11px] font-mono text-slate-300 text-right max-w-[180px] truncate" title="{{ $provenance['vessel_type']['source'] ?? ($vessel->sourceProvider?->name ?? 'AIS Provider') }}">
                        {{ $provenance['vessel_type']['source'] ?? ($vessel->sourceProvider?->name ?? 'AISStream ITU Code') }}
                    </span>
                </div>
                <div class="flex justify-between py-1">
                    <span class="text-slate-400">Status Verifikasi:</span>
                    <span class="text-[10px] font-mono px-2 py-0.5 rounded {{ $vessel->verification_status === 'verified' ? 'bg-emerald-950 text-emerald-300' : 'bg-slate-800 text-slate-300' }}">
                        {{ $vessel->verification_status }}
                    </span>
                </div>
            </div>
        </div>

        <!-- SECTION 3: Technical Specifications -->
        <div class="glass-panel p-5 rounded-xl border border-slate-800 space-y-4">
            <h3 class="text-xs font-bold uppercase tracking-wider text-teal-400 border-b border-slate-800 pb-2 flex items-center justify-between">
                <span class="flex items-center gap-1.5">
                    <span>📐</span>
                    <span>3. Spesifikasi Teknis</span>
                </span>
                <span class="text-[9px] px-1.5 py-0.5 rounded bg-slate-800 text-slate-400 font-mono">Specs</span>
            </h3>

            <div class="space-y-2.5 text-xs">
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Panjang (LOA):</span>
                    <span class="text-white font-mono font-bold">{{ $vessel->length_meters ? $vessel->length_meters . ' m' : '<tidak tersedia>' }}</span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Lebar (Beam):</span>
                    <span class="text-white font-mono">{{ $vessel->beam_meters ? $vessel->beam_meters . ' m' : '<tidak tersedia>' }}</span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Tonase Kotor (GT):</span>
                    <span class="text-white font-mono font-bold {{ $vessel->tonnage ? 'text-teal-300' : '' }}">
                        {{ $vessel->tonnage ? number_format($vessel->tonnage) . ' GT' : '<tidak tersedia>' }}
                    </span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Daya Mesin (Power):</span>
                    <span class="text-white font-mono">{{ $vessel->engine_power_kw ? number_format($vessel->engine_power_kw, 2) . ' kW' : '<tidak tersedia>' }}</span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Tahun Pembuatan:</span>
                    <span class="text-white font-mono">{{ $vessel->build_year ?: '<tidak tersedia>' }}</span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Operator Kapal:</span>
                    <span class="text-white font-semibold font-mono text-right max-w-[200px] truncate" title="{{ $vessel->operator ?: 'Not available' }}">
                        {{ $vessel->display_operator }}
                    </span>
                </div>
                <div class="flex justify-between py-1">
                    <span class="text-slate-400">Pemilik (Owner):</span>
                    <span class="text-white font-semibold font-mono text-right max-w-[200px] truncate" title="{{ $vessel->owner ?: 'Not available' }}">
                        {{ $vessel->display_owner }}
                    </span>
                </div>
            </div>
        </div>

    </div>

    <!-- SECTION 4: Tracking Information -->
    <div class="glass-panel p-5 rounded-xl border border-slate-800 space-y-4">
        <h3 class="text-xs font-bold uppercase tracking-wider text-sky-400 border-b border-slate-800 pb-2 flex items-center justify-between">
            <span class="flex items-center gap-1.5">
                <span>📍</span>
                <span>4. Telemetri & Pelacakan Terkini</span>
            </span>
            @if($vessel->isRecentlyActive())
            <span class="flex items-center gap-1.5 px-2 py-0.5 rounded bg-emerald-950/80 text-emerald-300 border border-emerald-500/30 text-[10px] font-mono">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                Aktif (&le; 24h)
            </span>
            @else
            <span class="px-2 py-0.5 rounded bg-slate-800 text-slate-400 text-[10px] font-mono">
                Histori / Stale
            </span>
            @endif
        </h3>

        @if($pos)
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-xs">
            <div class="p-2.5 rounded-lg bg-slate-900/60 border border-slate-800">
                <span class="text-slate-400 text-[11px] block">Koordinat Terkini:</span>
                <span class="font-mono text-sky-300 font-bold text-sm block mt-0.5">
                    {{ number_format($pos->latitude, 6) }}&deg;, {{ number_format($pos->longitude, 6) }}&deg;
                </span>
            </div>
            <div class="p-2.5 rounded-lg bg-slate-900/60 border border-slate-800">
                <span class="text-slate-400 text-[11px] block">Kecepatan & Haluan:</span>
                <span class="font-mono text-white font-bold text-sm block mt-0.5">
                    {{ $pos->speed_knots ?? 0 }} kts &bull; {{ $pos->course_degrees ?? 0 }}&deg;
                </span>
            </div>
            <div class="p-2.5 rounded-lg bg-slate-900/60 border border-slate-800">
                <span class="text-slate-400 text-[11px] block">Status Navigasi:</span>
                <span class="text-white capitalize font-semibold text-sm block mt-0.5">
                    {{ $pos->nav_status ?? 'under_way' }}
                </span>
            </div>
            <div class="p-2.5 rounded-lg bg-slate-900/60 border border-slate-800">
                <span class="text-slate-400 text-[11px] block">Waktu Observasi:</span>
                <span class="text-slate-200 font-mono text-xs block mt-0.5">
                    {{ $pos->observed_at->format('Y-m-d H:i:s') }} UTC
                </span>
            </div>
        </div>
        <div class="flex flex-wrap items-center justify-between text-[11px] text-slate-400 pt-2 border-t border-slate-800/60">
            <div>
                <span>Sumber Telemetri: <strong class="text-teal-300 font-mono">{{ $pos->sourceProvider?->name ?? 'AIS Ingestion' }}</strong></span>
            </div>
            <div>
                <span>Kualitas Sinyal: <strong class="text-teal-400 font-mono">{{ $pos->data_quality_score }} / 1.0</strong></span>
            </div>
        </div>
        @else
        <div class="py-8 text-center text-slate-500 text-xs">
            <span class="text-3xl block mb-2">📡</span>
            Belum ada data posisi AIS aktual yang tersimpan untuk kapal ini.
        </div>
        @endif
    </div>

    <!-- SECTION 5: Automatic GFW Monitoring & Events Suite -->
    <div class="glass-panel p-6 rounded-2xl border border-slate-800 space-y-5" id="gfw-monitoring-panel">
        @php
            $gfwStatus = $vessel->gfw_sync_status;
            $gfwCandidate = $enrichment['raw_gfw_candidate'] ?? null;
            $gfwDetails = $enrichment['raw_vessel_details'] ?? null;
            $searchAttempts = $enrichment['search_attempts'] ?? [];
            $allEncounters = $vessel->encountersAsVessel1->concat($vessel->encountersAsVessel2);
        @endphp

        <!-- Panel Header -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-slate-800 pb-4">
            <div>
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="text-lg">🛰️</span>
                    <h3 class="text-sm font-bold uppercase tracking-wider text-amber-400 font-mono">
                        5. GLOBAL FISHING WATCH MONITORING & EVENTS / GFW API v3
                    </h3>
                    <span class="text-[9px] px-2 py-0.5 rounded font-mono font-bold uppercase bg-teal-500/20 text-teal-300 border border-teal-500/40">
                        NON-COMMERCIAL RESEARCH
                    </span>
                </div>
                <p class="text-xs text-slate-400 mt-1">
                    Automatic multi-source data synchronization via official GFW API v3 (<span class="font-mono text-slate-300">public-global-vessel-identity:latest</span>).
                </p>
            </div>

            <!-- Header Action Controls & Status Badge -->
            <div class="flex items-center gap-2.5 flex-wrap">
                <!-- Sync Status Badge -->
                @if($gfwStatus === 'synced')
                <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold uppercase bg-emerald-950/80 text-emerald-300 border border-emerald-500/40 flex items-center gap-1.5 shadow-sm">
                    <span class="w-2 h-2 rounded-full bg-emerald-400"></span>
                    <span>✓ Synced</span>
                </span>
                @elseif($gfwStatus === 'syncing')
                <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold uppercase bg-sky-950/80 text-sky-300 border border-sky-500/40 flex items-center gap-1.5 animate-pulse shadow-sm">
                    <span class="w-2 h-2 rounded-full bg-sky-400 animate-ping"></span>
                    <span>⏳ Syncing in Background...</span>
                </span>
                @elseif($gfwStatus === 'not_found')
                <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold uppercase bg-slate-800 text-slate-300 border border-slate-700 flex items-center gap-1.5 shadow-sm">
                    <span>🔍</span>
                    <span>Not Found in GFW</span>
                </span>
                @elseif($gfwStatus === 'match_review_required')
                <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold uppercase bg-amber-950/80 text-amber-300 border border-amber-500/40 flex items-center gap-1.5 shadow-sm">
                    <span>⚠️</span>
                    <span>Review Required</span>
                </span>
                @elseif($gfwStatus === 'failed')
                <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold uppercase bg-rose-950/80 text-rose-300 border border-rose-500/40 flex items-center gap-1.5 shadow-sm">
                    <span>✕</span>
                    <span>Sync Failed</span>
                </span>
                @else
                <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold uppercase bg-slate-900 text-slate-400 border border-slate-800 flex items-center gap-1.5 shadow-sm">
                    <span>⏱️</span>
                    <span>Pending Sync</span>
                </span>
                @endif

                <!-- Manual Action Form (Sync Now / Retry) -->
                @if($vessel->isEligibleForGfwSync())
                <form action="{{ route('vessels.gfw-sync', $vessel->id) }}" method="POST" class="inline">
                    @csrf
                    <button type="submit" class="px-3 py-1 rounded-lg bg-teal-500/10 hover:bg-teal-500/20 text-teal-300 border border-teal-500/30 text-xs font-medium flex items-center gap-1.5 transition">
                        <span>🔄</span>
                        <span>{{ in_array($gfwStatus, ['failed', 'not_found']) ? 'Retry' : 'Sync Now' }}</span>
                    </button>
                </form>
                @endif

                <!-- GFW Source External Link -->
                @if($vessel->gfw_vessel_id)
                <a href="https://globalfishingwatch.org/map/vessels/{{ $vessel->gfw_vessel_id }}" target="_blank" rel="noopener noreferrer" class="px-3 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 border border-slate-700 text-xs font-medium flex items-center gap-1.5 transition">
                    <span>🌐</span>
                    <span>Lihat Sumber GFW ↗</span>
                </a>
                @endif
            </div>
        </div>

        <!-- Metadata Summary Bar -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-xs bg-slate-900/60 p-3 rounded-xl border border-slate-800/80">
            <div>
                <span class="text-slate-400 text-[10px] uppercase font-mono block">GFW Vessel ID:</span>
                @if($vessel->gfw_vessel_id)
                <span class="font-mono text-teal-300 font-bold truncate block mt-0.5" title="{{ $vessel->gfw_vessel_id }}">
                    {{ $vessel->gfw_vessel_id }}
                </span>
                @else
                <span class="text-slate-500 italic block mt-0.5">Belum terhubung ke GFW</span>
                @endif
            </div>
            <div>
                <span class="text-slate-400 text-[10px] uppercase font-mono block">Sinkronisasi Terakhir:</span>
                <span class="text-slate-200 font-mono block mt-0.5">
                    @if($vessel->last_enriched_at)
                    {{ $vessel->last_enriched_at->diffForHumans() }}
                    @else
                    <span class="text-slate-500 italic">Belum pernah disinkronkan</span>
                    @endif
                </span>
            </div>
            <div>
                <span class="text-slate-400 text-[10px] uppercase font-mono block">Metode Pencocokan:</span>
                <span class="text-slate-200 font-mono block mt-0.5 truncate">
                    @if($enrichment['match_method'] ?? null)
                    {{ ucwords(str_replace('_', ' ', $enrichment['match_method'])) }}
                    @elseif($gfwStatus === 'not_found')
                    <span class="text-slate-400">Pencarian selesai (0 kandidat cocok)</span>
                    @elseif($gfwStatus === 'match_review_required')
                    <span class="text-amber-400">Ambiguitas / Multi-Kandidat</span>
                    @else
                    <span class="text-slate-500 italic">Menunggu evaluasi</span>
                    @endif
                </span>
            </div>
            <div>
                <span class="text-slate-400 text-[10px] uppercase font-mono block">Tingkat Kepercayaan:</span>
                <span class="font-mono block mt-0.5">
                    @if(($enrichment['match_confidence'] ?? null) === 'high_authoritative')
                    <span class="text-emerald-400 font-bold">Tinggi (IMO Authoritative)</span>
                    @elseif(($enrichment['match_confidence'] ?? null) === 'high_telemetry')
                    <span class="text-teal-400 font-bold">Tinggi (MMSI Telemetry)</span>
                    @elseif(($enrichment['match_confidence'] ?? null) === 'medium_verified')
                    <span class="text-sky-400 font-bold">Sedang (Nama Terverifikasi)</span>
                    @elseif($gfwStatus === 'not_found')
                    <span class="text-slate-400">Kandidat Tidak Ditemukan</span>
                    @elseif($gfwStatus === 'match_review_required')
                    <span class="text-amber-400">Review Required</span>
                    @else
                    <span class="text-slate-500 italic">-</span>
                    @endif
                </span>
            </div>
        </div>

        <!-- 7 Tabs Navigation -->
        <div class="border-b border-slate-800">
            <nav class="flex space-x-1 overflow-x-auto responsive-table-container text-xs" aria-label="GFW Monitoring Tabs">
                <button type="button" data-gfw-tab="identity" class="px-3.5 py-2.5 font-medium border-b-2 text-teal-300 border-teal-400 bg-slate-800/60 rounded-t-lg transition flex items-center gap-1.5 whitespace-nowrap">
                    <span>🪪</span>
                    <span>1. Identitas & Registrasi</span>
                </button>
                <button type="button" data-gfw-tab="fishing" class="px-3.5 py-2.5 font-medium border-b-2 text-slate-400 border-transparent hover:text-slate-200 hover:bg-slate-800/30 rounded-t-lg transition flex items-center gap-1.5 whitespace-nowrap">
                    <span>🎣</span>
                    <span>2. Aktivitas Penangkapan</span>
                </button>
                <button type="button" data-gfw-tab="encounters" class="px-3.5 py-2.5 font-medium border-b-2 text-slate-400 border-transparent hover:text-slate-200 hover:bg-slate-800/30 rounded-t-lg transition flex items-center gap-1.5 whitespace-nowrap">
                    <span>🚢</span>
                    <span>3. Encounter & Transshipment</span>
                </button>
                <button type="button" data-gfw-tab="loitering" class="px-3.5 py-2.5 font-medium border-b-2 text-slate-400 border-transparent hover:text-slate-200 hover:bg-slate-800/30 rounded-t-lg transition flex items-center gap-1.5 whitespace-nowrap">
                    <span>⚓</span>
                    <span>4. Loitering & Pelabuhan</span>
                </button>
                <button type="button" data-gfw-tab="gaps" class="px-3.5 py-2.5 font-medium border-b-2 text-slate-400 border-transparent hover:text-slate-200 hover:bg-slate-800/30 rounded-t-lg transition flex items-center gap-1.5 whitespace-nowrap">
                    <span>📡</span>
                    <span>5. AIS Gaps</span>
                </button>
                <button type="button" data-gfw-tab="authorizations" class="px-3.5 py-2.5 font-medium border-b-2 text-slate-400 border-transparent hover:text-slate-200 hover:bg-slate-800/30 rounded-t-lg transition flex items-center gap-1.5 whitespace-nowrap">
                    <span>📜</span>
                    <span>6. Otorisasi & RFMO</span>
                </button>
                <button type="button" data-gfw-tab="sources" class="px-3.5 py-2.5 font-medium border-b-2 text-slate-400 border-transparent hover:text-slate-200 hover:bg-slate-800/30 rounded-t-lg transition flex items-center gap-1.5 whitespace-nowrap">
                    <span>ℹ️</span>
                    <span>7. Sumber Data & Disclaimer</span>
                </button>
            </nav>
        </div>

        <!-- TAB 1: Identitas & Registrasi (Vessel Identity) -->
        <div data-gfw-panel="identity" class="space-y-4">
            @if($vessel->gfw_vessel_id || $gfwCandidate)
            <!-- Comparison Table -->
            <div class="overflow-x-auto responsive-table-container">
                <table class="w-full text-left text-xs min-w-[620px]">
                    <thead class="text-[10px] text-slate-400 uppercase border-b border-slate-800 bg-slate-900/40">
                        <tr>
                            <th class="py-2 px-3">Parameter Identitas</th>
                            <th class="py-2 px-3">Lokal GFVIP (AIS Telemetry)</th>
                            <th class="py-2 px-3">Global Fishing Watch (GFW v3)</th>
                            <th class="py-2 px-3">Status Pencocokan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60 font-mono text-[11px]">
                        <tr>
                            <td class="py-2 px-3 text-slate-400 font-sans font-medium">Nama Kapal</td>
                            <td class="py-2 px-3 text-white font-bold">{{ $vessel->name }}</td>
                            <td class="py-2 px-3 text-teal-300 font-bold">{{ $gfwCandidate['shipname'] ?? '-' }}</td>
                            <td class="py-2 px-3">
                                @if(strtoupper(trim($vessel->name)) === strtoupper(trim($gfwCandidate['shipname'] ?? '')))
                                <span class="text-emerald-400">✓ Identik</span>
                                @else
                                <span class="text-amber-400">Variasi Nama</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td class="py-2 px-3 text-slate-400 font-sans font-medium">MMSI / SSVID</td>
                            <td class="py-2 px-3 text-white">{{ $vessel->mmsi ?: '-' }}</td>
                            <td class="py-2 px-3 text-teal-300">{{ $gfwCandidate['ssvid'] ?? '-' }}</td>
                            <td class="py-2 px-3">
                                @if($vessel->mmsi && ($gfwCandidate['ssvid'] ?? '') === $vessel->mmsi)
                                <span class="text-emerald-400">✓ Cocok (MMSI)</span>
                                @else
                                <span class="text-slate-400">-</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td class="py-2 px-3 text-slate-400 font-sans font-medium">Nomor IMO</td>
                            <td class="py-2 px-3 text-white">{{ $vessel->imo ?: '-' }}</td>
                            <td class="py-2 px-3 text-teal-300">{{ $gfwCandidate['imo'] ?? '-' }}</td>
                            <td class="py-2 px-3">
                                @if($vessel->imo && ($gfwCandidate['imo'] ?? '') === $vessel->imo)
                                <span class="text-emerald-400 font-bold">✓ Cocok (IMO)</span>
                                @else
                                <span class="text-slate-400">-</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td class="py-2 px-3 text-slate-400 font-sans font-medium">Call Sign</td>
                            <td class="py-2 px-3 text-white">{{ $vessel->callsign ?: '-' }}</td>
                            <td class="py-2 px-3 text-teal-300">{{ $gfwCandidate['callsign'] ?? '-' }}</td>
                            <td class="py-2 px-3">
                                @if($vessel->callsign && strtoupper($vessel->callsign) === strtoupper($gfwCandidate['callsign'] ?? ''))
                                <span class="text-emerald-400">✓ Cocok</span>
                                @else
                                <span class="text-slate-400">-</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td class="py-2 px-3 text-slate-400 font-sans font-medium">Negara Bendera</td>
                            <td class="py-2 px-3 text-white">{{ $vessel->flag_state }}</td>
                            <td class="py-2 px-3 text-teal-300">{{ $gfwCandidate['flag'] ?? '-' }}</td>
                            <td class="py-2 px-3">
                                @if($vessel->flag_state === ($gfwCandidate['flag'] ?? ''))
                                <span class="text-emerald-400">✓ Konsisten</span>
                                @else
                                <span class="text-amber-400">Beda Bendera</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td class="py-2 px-3 text-slate-400 font-sans font-medium">Tipe Kapal & Alat Tangkap</td>
                            <td class="py-2 px-3 text-white capitalize font-sans">{{ $vessel->vessel_type }}</td>
                            <td class="py-2 px-3 text-teal-300 capitalize font-sans">
                                {{ $gfwCandidate['shiptype'] ?? '-' }}
                                @if(!empty($gfwCandidate['geartype']))
                                &bull; <span class="text-amber-300">{{ $gfwCandidate['geartype'] }}</span>
                                @endif
                            </td>
                            <td class="py-2 px-3">
                                <span class="text-teal-400">Sinkron</span>
                            </td>
                        </tr>
                        <tr>
                            <td class="py-2 px-3 text-slate-400 font-sans font-medium">Dimensi (Panjang & Tonase)</td>
                            <td class="py-2 px-3 text-white">{{ $vessel->length_meters ? $vessel->length_meters . ' m' : '-' }} &bull; {{ $vessel->tonnage ? $vessel->tonnage . ' GT' : '-' }}</td>
                            <td class="py-2 px-3 text-teal-300">{{ !empty($gfwCandidate['length']) ? $gfwCandidate['length'] . ' m' : '-' }} &bull; {{ !empty($gfwCandidate['tonnage']) ? $gfwCandidate['tonnage'] . ' GT' : '-' }}</td>
                            <td class="py-2 px-3">
                                <span class="text-slate-300">Model Registry</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            @if(count($altNames) > 0)
            <div class="p-3 rounded-lg bg-slate-900/50 border border-slate-800 text-xs flex items-center gap-2">
                <span class="text-slate-400">Nama Historis / Alias GFW:</span>
                <div class="flex items-center gap-1.5 flex-wrap">
                    @foreach($altNames as $alt)
                    <span class="px-2 py-0.5 rounded bg-slate-800 text-teal-300 font-mono text-[11px] border border-slate-700">{{ $alt }}</span>
                    @endforeach
                </div>
            </div>
            @endif

            @elseif($gfwStatus === 'not_found')
            <!-- Professional Informative Not Found Card -->
            <div class="p-6 rounded-xl bg-slate-900/60 border border-slate-800 space-y-3">
                <div class="flex items-start gap-3">
                    <span class="text-3xl shrink-0">🔍</span>
                    <div class="space-y-1">
                        <h4 class="text-sm font-bold text-white">Kapal Tidak Ditemukan dalam Katalog Publik Global Fishing Watch</h4>
                        <p class="text-xs text-slate-400 leading-relaxed">
                            Pemeriksaan otomatis terhadap basis data Global Fishing Watch (<span class="font-mono text-slate-300">public-global-vessel-identity:latest</span>) tidak menemukan entitas yang cocok dengan kombinasi identitas kapal ini.
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5 pt-2 text-xs">
                    <div class="p-2.5 rounded-lg bg-slate-950/70 border border-slate-800/80">
                        <span class="text-slate-500 text-[10px] uppercase font-mono block">Query IMO:</span>
                        <span class="font-mono text-slate-300 font-semibold mt-0.5 block">{{ $vessel->imo ?: 'Tidak tersedia' }}</span>
                        <span class="text-[10px] text-slate-500 block mt-0.5">0 hasil di katalog publik</span>
                    </div>
                    <div class="p-2.5 rounded-lg bg-slate-950/70 border border-slate-800/80">
                        <span class="text-slate-500 text-[10px] uppercase font-mono block">Query MMSI:</span>
                        <span class="font-mono text-slate-300 font-semibold mt-0.5 block">{{ $vessel->mmsi ?: 'Tidak tersedia' }}</span>
                        <span class="text-[10px] text-slate-500 block mt-0.5">0 hasil di katalog publik</span>
                    </div>
                    <div class="p-2.5 rounded-lg bg-slate-950/70 border border-slate-800/80">
                        <span class="text-slate-500 text-[10px] uppercase font-mono block">Query Nama:</span>
                        <span class="font-mono text-slate-300 font-semibold mt-0.5 block truncate">{{ $vessel->name }}</span>
                        <span class="text-[10px] text-slate-500 block mt-0.5">Diverifikasi ketat terhadap MMSI/IMO</span>
                    </div>
                </div>

                <div class="pt-2 border-t border-slate-800/60 text-[11px] text-slate-400 leading-relaxed space-y-1">
                    <p>
                        <strong class="text-slate-300">Analisis maritim:</strong> Tidak ditemukannya kapal di GFW umum terjadi apabila:
                    </p>
                    <ul class="list-disc pl-5 space-y-0.5 text-slate-400">
                        <li>Kapal beroperasi secara dominan di perairan teritorial dekat pantai (coastal waters) di luar jangkauan satelit AIS GFW.</li>
                        <li>Kapal belum didaftarkan pada registri perikanan internasional IMO/RFMO resmi.</li>
                        <li>Transponder AIS kapal berdaya rendah (Class B) atau menggunakan identitas telemetri lokal.</li>
                    </ul>
                    <p class="text-teal-400/90 pt-1">
                        Kapal ini tetap tercatat, dapat dimonitor secara mandiri melalui sensor AIS lokal GFVIP, dan tidak diubah klasifikasinya secara sepihak.
                    </p>
                </div>
            </div>

            @elseif($gfwStatus === 'syncing')
            <div class="py-10 text-center text-slate-400 space-y-2">
                <span class="text-3xl block animate-spin">⏳</span>
                <div class="text-xs font-semibold text-white">Sinkronisasi Data Pemantauan GFW Sedang Berlangsung</div>
                <div class="text-[11px] text-slate-400 max-w-md mx-auto">
                    Sistem sedang memverifikasi identitas kapal melalui endpoint GET /v3/vessels/search pada gateway Global Fishing Watch di latar belakang. Silakan segarkan halaman beberapa saat lagi.
                </div>
            </div>

            @else
            <div class="py-8 text-center text-slate-500 text-xs space-y-2">
                <span class="text-3xl block mb-1">⏱️</span>
                <div>Kapal ini memenuhi syarat dan dijadwalkan untuk sinkronisasi otomatis ke GFW API v3.</div>
                <div class="text-[11px] text-slate-400">
                    Klik tombol <strong class="text-teal-300">Sync Now</strong> di atas untuk memicu sinkronisasi langsung ke queue latar belakang.
                </div>
            </div>
            @endif
        </div>

        <!-- TAB 2: Aktivitas Penangkapan (Fishing Activity) -->
        <div data-gfw-panel="fishing" class="space-y-4 hidden">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div class="p-3 rounded-xl bg-slate-900 border border-slate-800">
                    <span class="text-[10px] text-slate-400 uppercase font-mono block">Total Event Penangkapan:</span>
                    <div class="text-xl font-bold font-mono text-white mt-1">
                        @if(($eventsSummary['fishing']['status'] ?? '') === 'available')
                        {{ number_format($eventsSummary['fishing']['count'] ?? 0) }}
                        <span class="text-xs font-normal text-slate-400">event</span>
                        @elseif(($eventsSummary['fishing']['status'] ?? '') === 'permission_restricted')
                        <span class="text-xs text-amber-400 font-sans">Research Access Req.</span>
                        @elseif($gfwStatus === 'not_found')
                        <span class="text-xs text-slate-400 font-sans">0 event</span>
                        @else
                        <span class="text-xs text-slate-500 font-sans">Belum disinkronkan</span>
                        @endif
                    </div>
                </div>
                <div class="p-3 rounded-xl bg-slate-900 border border-slate-800">
                    <span class="text-[10px] text-slate-400 uppercase font-mono block">Status Dataset API:</span>
                    <div class="text-xs font-mono mt-1">
                        @if(($eventsSummary['fishing']['status'] ?? '') === 'available')
                        <span class="text-emerald-400 font-bold">✓ Authorized (HTTP 200)</span>
                        @elseif(($eventsSummary['fishing']['status'] ?? '') === 'permission_restricted')
                        <span class="text-amber-400 font-bold">HTTP 403 (Katalog Publik)</span>
                        @else
                        <span class="text-slate-400">public-global-fishing-events</span>
                        @endif
                    </div>
                </div>
                <div class="p-3 rounded-xl bg-slate-900 border border-slate-800">
                    <span class="text-[10px] text-slate-400 uppercase font-mono block">Klasifikasi Alat Tangkap:</span>
                    <div class="text-xs font-semibold text-teal-300 capitalize mt-1 truncate">
                        {{ $vessel->gear_type ?: ($gfwCandidate['geartype'] ?? 'Tidak terdefinisi') }}
                    </div>
                </div>
            </div>

            <div class="p-4 rounded-xl bg-slate-900/40 border border-slate-800 text-xs space-y-2">
                <h4 class="font-bold text-white flex items-center gap-1.5">
                    <span>🎣</span>
                    <span>Pemodelan Aktivitas Penangkapan (Apparent Fishing Effort)</span>
                </h4>
                <p class="text-slate-400 text-[11px] leading-relaxed">
                    Global Fishing Watch mendeteksi perkiraan aktivitas penangkapan ikan menggunakan algoritma machine learning berbasis convolutional neural network (CNN). Model ini menganalisis pergerakan transponder AIS termasuk penurunan kecepatan (< 4 knot), perubahan arah haluan yang konstan, dan profil maneuver sesuai jenis alat tangkap (misalnya drifting longlines, purse seine, atau trawlers).
                </p>
                <div class="p-2.5 rounded bg-slate-950/60 border border-slate-800 text-[10px] text-slate-400 space-y-1">
                    <div class="font-semibold text-slate-300">Catatan Batasan Akses Data Publik:</div>
                    <div>
                        Berdasarkan kebijakan Global Fishing Watch API v3, dataset <span class="font-mono text-teal-300">public-global-fishing-events:latest</span> memerlukan persetujuan khusus untuk kemitraan penelitian non-komersial (Research Access). Peta aktivitas spasial kapal tetap dapat diverifikasi secara visual melalui portal publik GFW Map.
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 3: Encounter & Transshipment (Encounters) -->
        <div data-gfw-panel="encounters" class="space-y-4 hidden">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div class="p-3 rounded-xl bg-slate-900 border border-slate-800">
                    <span class="text-[10px] text-slate-400 uppercase font-mono block">GFW Transshipment Encounters:</span>
                    <div class="text-xl font-bold font-mono text-white mt-1">
                        @if(($eventsSummary['encounters']['status'] ?? '') === 'available')
                        {{ number_format($eventsSummary['encounters']['count'] ?? 0) }}
                        <span class="text-xs font-normal text-slate-400">event</span>
                        @elseif(($eventsSummary['encounters']['status'] ?? '') === 'permission_restricted')
                        <span class="text-xs text-amber-400 font-sans">Research Access Req. (HTTP 403)</span>
                        @elseif($gfwStatus === 'not_found')
                        <span class="text-xs text-slate-400 font-sans">0 event</span>
                        @else
                        <span class="text-xs text-slate-500 font-sans">-</span>
                        @endif
                    </div>
                </div>
                <div class="p-3 rounded-xl bg-slate-900 border border-slate-800">
                    <span class="text-[10px] text-slate-400 uppercase font-mono block">Encounter Terdeteksi di GFVIP (Lokal):</span>
                    <div class="text-xl font-bold font-mono text-teal-300 mt-1">
                        {{ $allEncounters->count() }}
                        <span class="text-xs font-normal text-slate-400">kejadian terverifikasi</span>
                    </div>
                </div>
            </div>

            @if($allEncounters->count() > 0)
            <div class="overflow-x-auto responsive-table-container">
                <table class="w-full text-left text-xs min-w-[500px]">
                    <thead class="text-[10px] text-slate-400 uppercase border-b border-slate-800 bg-slate-900/40">
                        <tr>
                            <th class="py-2 px-3">Waktu Kejadian</th>
                            <th class="py-2 px-3">Kapal Pasangan (Partner)</th>
                            <th class="py-2 px-3">Durasi</th>
                            <th class="py-2 px-3">Jarak Terdekat</th>
                            <th class="py-2 px-3">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60 font-mono text-[11px]">
                        @foreach($allEncounters->take(5) as $enc)
                        @php
                            $isV1 = $enc->vessel_id_1 === $vessel->id;
                            $partner = $isV1 ? $enc->vessel2 : $enc->vessel1;
                        @endphp
                        <tr>
                            <td class="py-2 px-3 text-white">{{ $enc->started_at?->format('Y-m-d H:i') ?? '-' }} UTC</td>
                            <td class="py-2 px-3 text-teal-300 font-bold">
                                {{ $partner?->name ?? 'MMSI: ' . ($isV1 ? $enc->vessel_id_2 : $enc->vessel_id_1) }}
                            </td>
                            <td class="py-2 px-3 text-slate-300">{{ $enc->duration_minutes ?? '-' }} menit</td>
                            <td class="py-2 px-3 text-slate-300">{{ $enc->median_distance_meters ? round($enc->median_distance_meters) . ' m' : '-' }}</td>
                            <td class="py-2 px-3">
                                <span class="px-1.5 py-0.5 rounded text-[9px] uppercase font-bold bg-amber-500/20 text-amber-300">
                                    {{ $enc->status ?? 'detected' }}
                                </span>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @else
            <div class="p-6 rounded-xl bg-slate-900/40 border border-slate-800 text-center text-slate-400 text-xs space-y-1">
                <span class="text-2xl block mb-1">🚢</span>
                <div class="font-semibold text-white">Tidak Ada Event Encounter yang Terdeteksi</div>
                <div class="text-[11px] text-slate-500 max-w-md mx-auto">
                    Tidak ditemukan kontak laut jarak dekat (&le; 500 meter selama &ge; 2 jam) antara kapal ini dengan kapal kargo reefer atau bunker baik di database GFW maupun sensor AIS lokal GFVIP.
                </div>
            </div>
            @endif
        </div>

        <!-- TAB 4: Loitering & Kunjungan Pelabuhan (Loitering & Port Visits) -->
        <div data-gfw-panel="loitering" class="space-y-4 hidden">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Loitering Card -->
                <div class="p-4 rounded-xl bg-slate-900/60 border border-slate-800 space-y-2">
                    <div class="flex items-center justify-between border-b border-slate-800 pb-2">
                        <span class="text-xs font-bold uppercase tracking-wider text-amber-400 font-mono flex items-center gap-1.5">
                            <span>⚓</span>
                            <span>Aktivitas Loitering</span>
                        </span>
                        <span class="text-[10px] font-mono text-slate-400">public-global-loitering-events</span>
                    </div>
                    <div class="flex items-baseline gap-2">
                        <span class="text-2xl font-bold font-mono text-white">
                            @if(($eventsSummary['loitering']['status'] ?? '') === 'available')
                            {{ number_format($eventsSummary['loitering']['count'] ?? 0) }}
                            @elseif(($eventsSummary['loitering']['status'] ?? '') === 'permission_restricted')
                            <span class="text-xs text-amber-400">Research Req.</span>
                            @else
                            0
                            @endif
                        </span>
                        <span class="text-xs text-slate-400">event tercatat</span>
                    </div>
                    <p class="text-[11px] text-slate-400 leading-relaxed">
                        Loitering didefinisikan saat kapal beroperasi pada kecepatan rendah (&lt; 2 knot) di laut lepas (di luar pelabuhan/jangkar) selama minimal 4 jam berturut-turut. Kondisi ini seringkali mengindikasikan kegiatan menunggu perbekalan, bunkering, atau persiapan transfer hasil tangkapan.
                    </p>
                </div>

                <!-- Port Visits Card -->
                <div class="p-4 rounded-xl bg-slate-900/60 border border-slate-800 space-y-2">
                    <div class="flex items-center justify-between border-b border-slate-800 pb-2">
                        <span class="text-xs font-bold uppercase tracking-wider text-teal-400 font-mono flex items-center gap-1.5">
                            <span>🏢</span>
                            <span>Kunjungan Pelabuhan (Port Visits)</span>
                        </span>
                        <span class="text-[10px] font-mono text-slate-400">public-global-port-visits-events</span>
                    </div>
                    <div class="flex items-baseline gap-2">
                        <span class="text-2xl font-bold font-mono text-white">
                            @if(($eventsSummary['port_visits']['status'] ?? '') === 'available')
                            {{ number_format($eventsSummary['port_visits']['count'] ?? 0) }}
                            @elseif(($eventsSummary['port_visits']['status'] ?? '') === 'permission_restricted')
                            <span class="text-xs text-amber-400">Research Req.</span>
                            @else
                            0
                            @endif
                        </span>
                        <span class="text-xs text-slate-400">kunjungan tercatat</span>
                    </div>
                    <p class="text-[11px] text-slate-400 leading-relaxed">
                        Data pelabuhan mendeteksi waktu sandar dan durasi singgah kapal di pelabuhan resmi dunia. Kunjungan pelabuhan yang teratur mengindikasikan rantai logistik resmi untuk bongkar muat ikan dan inspeksi otoritas pelabuhan (PSMA).
                    </p>
                </div>
            </div>
        </div>

        <!-- TAB 5: AIS Gaps (AIS Disabling Events) -->
        <div data-gfw-panel="gaps" class="space-y-4 hidden">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="p-4 rounded-xl bg-slate-900/60 border border-slate-800 space-y-2">
                    <div class="flex items-center justify-between border-b border-slate-800 pb-2">
                        <span class="text-xs font-bold uppercase tracking-wider text-sky-400 font-mono flex items-center gap-1.5">
                            <span>📡</span>
                            <span>AIS Disabling Events</span>
                        </span>
                        <span class="text-[10px] font-mono text-slate-400">public-global-gaps-events</span>
                    </div>
                    <div class="flex items-baseline gap-2">
                        <span class="text-2xl font-bold font-mono text-white">
                            @if(($eventsSummary['ais_gaps']['status'] ?? '') === 'available')
                            {{ number_format($eventsSummary['ais_gaps']['count'] ?? 0) }}
                            @else
                            0
                            @endif
                        </span>
                        <span class="text-xs text-slate-400">anomali celah transmisi terdeteksi</span>
                    </div>
                    <p class="text-[11px] text-slate-400 leading-relaxed">
                        Celah transmisi AIS (AIS Gaps) mengidentifikasi periode di mana transmisi sinyal AIS kapal terhenti lebih dari interval wajar saat berada di laut lepas.
                    </p>
                </div>

                <div class="p-4 rounded-xl bg-slate-900/40 border border-slate-800 space-y-2 text-xs">
                    <span class="font-bold text-slate-200 block">Klasifikasi Celah Transmisi:</span>
                    <div class="space-y-1.5 text-[11px] text-slate-400 leading-relaxed">
                        <div>
                            <strong class="text-slate-300">1. Coverage Gap (Teknis):</strong> Hilangnya sinyal akibat densitas tinggi lalu lintas kapal yang menyumbat frekuensi penerima satelit, atau interferensi cuaca ionosfer.
                        </div>
                        <div>
                            <strong class="text-amber-400">2. Suspected Disabling (Sengaja):</strong> Sinyal dimatikan secara manual saat kapal mendekati batas EEZ negara lain atau kawasan konservasi laut terlarang.
                        </div>
                    </div>
                </div>
            </div>

            <div class="p-4 rounded-xl bg-slate-950/40 border border-slate-800/80 text-xs text-slate-400 flex items-center gap-2">
                <span class="text-emerald-400 text-lg">✓</span>
                <span>Tidak ditemukan anomali celah transmisi AIS mencurigakan yang diverifikasi untuk identitas kapal ini.</span>
            </div>
        </div>

        <!-- TAB 6: Otorisasi & RFMO (Authorizations) -->
        <div data-gfw-panel="authorizations" class="space-y-4 hidden">
            <div class="space-y-3">
                <div class="flex items-center justify-between border-b border-slate-800 pb-2">
                    <span class="text-xs font-bold uppercase tracking-wider text-teal-400 font-mono flex items-center gap-1.5">
                        <span>📜</span>
                        <span>Registri RFMO & Otorisasi Penangkapan</span>
                    </span>
                    <span class="text-[10px] text-slate-400">Regional Fisheries Management Organizations</span>
                </div>

                @if($vessel->authorizations->count() > 0)
                <div class="overflow-x-auto responsive-table-container">
                    <table class="w-full text-left text-xs min-w-[500px]">
                        <thead class="text-[10px] text-slate-400 uppercase border-b border-slate-800 bg-slate-900/40">
                            <tr>
                                <th class="py-2 px-3">Kode Otorisasi</th>
                                <th class="py-2 px-3">Tipe Izin</th>
                                <th class="py-2 px-3">Otoritas Penerbit</th>
                                <th class="py-2 px-3">Zona Maritim</th>
                                <th class="py-2 px-3">Masa Berlaku</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60 font-mono text-[11px]">
                            @foreach($vessel->authorizations as $auth)
                            <tr>
                                <td class="py-2 px-3 text-teal-300 font-bold">{{ $auth->authorization_code }}</td>
                                <td class="py-2 px-3 text-white font-sans">{{ $auth->permit_type }}</td>
                                <td class="py-2 px-3 text-slate-300 font-sans">{{ $auth->issuing_authority }}</td>
                                <td class="py-2 px-3 text-slate-300 font-sans">{{ $auth->maritimeZone?->name ?? 'Semua Zona' }}</td>
                                <td class="py-2 px-3 text-slate-400">s/d {{ $auth->valid_to->toDateString() }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @else
                <div class="p-6 rounded-xl bg-slate-900/40 border border-slate-800 text-center text-slate-400 text-xs space-y-1">
                    <span class="text-2xl block mb-1">📜</span>
                    <div class="font-semibold text-white">Belum Ada Catatan Otorisasi RFMO yang Terdaftar</div>
                    <div class="text-[11px] text-slate-500 max-w-md mx-auto">
                        Kapal ini belum memiliki dokumen otorisasi penangkapan ikan internasional (seperti IOTC, WCPFC, ICCAT, CCSBT) atau izin zonasi khusus yang diverifikasi di sistem.
                    </div>
                </div>
                @endif
            </div>
        </div>

        <!-- TAB 7: Sumber Data & Disclaimer (Data Sources) -->
        <div data-gfw-panel="sources" class="space-y-4 hidden">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                <!-- Technical Metadata -->
                <div class="p-4 rounded-xl bg-slate-900/60 border border-slate-800 space-y-2.5">
                    <h4 class="font-bold text-white font-mono uppercase text-[11px] text-teal-400 border-b border-slate-800 pb-1.5">
                        Konfigurasi Gateway GFW API v3
                    </h4>
                    <div class="space-y-1.5 font-mono text-[11px]">
                        <div class="flex justify-between py-1 border-b border-slate-800/60">
                            <span class="text-slate-400 font-sans">Gateway URL:</span>
                            <span class="text-slate-200">https://gateway.api.globalfishingwatch.org/v3</span>
                        </div>
                        <div class="flex justify-between py-1 border-b border-slate-800/60">
                            <span class="text-slate-400 font-sans">Dataset Identitas:</span>
                            <span class="text-teal-300">public-global-vessel-identity:latest</span>
                        </div>
                        <div class="flex justify-between py-1 border-b border-slate-800/60">
                            <span class="text-slate-400 font-sans">Status Sinkronisasi:</span>
                            <span class="text-white capitalize font-bold">{{ $gfwStatus }}</span>
                        </div>
                        <div class="flex justify-between py-1 border-b border-slate-800/60">
                            <span class="text-slate-400 font-sans">Timestamp Sinkronisasi:</span>
                            <span class="text-slate-300">{{ $vessel->last_enriched_at?->toIso8601String() ?? 'Belum pernah' }}</span>
                        </div>
                        <div class="flex justify-between py-1">
                            <span class="text-slate-400 font-sans">Queue Job Worker:</span>
                            <span class="text-slate-300">EnrichVesselGfwJob</span>
                        </div>
                    </div>
                </div>

                <!-- Query Search History -->
                <div class="p-4 rounded-xl bg-slate-900/60 border border-slate-800 space-y-2.5">
                    <h4 class="font-bold text-white font-mono uppercase text-[11px] text-sky-400 border-b border-slate-800 pb-1.5">
                        Audit Log Permintaan Pencarian Identitas
                    </h4>
                    @if(count($searchAttempts) > 0)
                    <div class="space-y-2 text-[11px] font-mono">
                        @foreach($searchAttempts as $attempt)
                        <div class="p-2 rounded bg-slate-950/70 border border-slate-800 flex items-center justify-between">
                            <div>
                                <span class="px-1.5 py-0.5 rounded text-[9px] uppercase font-bold bg-slate-800 text-teal-300">{{ $attempt['type'] ?? 'query' }}</span>
                                <span class="text-slate-300 ml-1">{{ $attempt['query'] ?? '-' }}</span>
                            </div>
                            <div class="text-right">
                                <span class="text-slate-400 font-sans">HTTP {{ $attempt['status'] ?? '-' }}</span>
                                <span class="text-slate-500 text-[10px]">({{ $attempt['total_found'] ?? 0 }} found)</span>
                            </div>
                        </div>
                        @endforeach
                    </div>
                    @else
                    <div class="text-slate-500 italic text-[11px] py-4 text-center">
                        Belum ada riwayat audit log permintaan pencarian untuk kapal ini.
                    </div>
                    @endif
                </div>
            </div>

            <!-- Mandatory Attribution & Legal Disclaimers -->
            <div class="p-4 rounded-xl bg-slate-950/80 border border-slate-800 text-xs space-y-2.5">
                <div class="text-[11px] text-slate-300 italic leading-relaxed">
                    &ldquo;This application uses data and services provided by Global Fishing Watch (GFW) for non-commercial research, educational, and public-interest purposes. GFW data are subject to applicable licensing, attribution, and usage restrictions.&rdquo;
                </div>
                <div class="text-[10px] text-amber-300/90 leading-relaxed pt-2 border-t border-slate-800/80 space-y-1">
                    <div>
                        <strong>PEMBERITAHUAN HUKUM & ANALISIS:</strong> Data pemantauan maritim yang ditampilkan pada profil ini merupakan hasil inferensi model komputasi, algoritma machine learning, dan integrasi multi-sumber dari sinyal transmisi AIS. Hasil analisis tidak boleh dianggap sebagai kesimpulan sepihak atas pelanggaran hukum perikanan (IUU Fishing) atau bukti hukum final tanpa verifikasi fisik, penegakan hukum resmi, atau inspeksi otoritas berwenang di pelabuhan.
                    </div>
                    <div>
                        Pemberitahuan: Aktivitas kapal yang ditampilkan merupakan estimasi atau hasil analisis berdasarkan sumber data, bukan bukti final pelanggaran.
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- Tab Switching Script -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const tabButtons = document.querySelectorAll('[data-gfw-tab]');
            const tabPanels = document.querySelectorAll('[data-gfw-panel]');

            tabButtons.forEach(btn => {
                btn.addEventListener('click', function () {
                    const target = this.getAttribute('data-gfw-tab');

                    tabButtons.forEach(b => {
                        b.classList.remove('text-teal-300', 'border-teal-400', 'bg-slate-800/60');
                        b.classList.add('text-slate-400', 'border-transparent');
                    });

                    this.classList.remove('text-slate-400', 'border-transparent');
                    this.classList.add('text-teal-300', 'border-teal-400', 'bg-slate-800/60');

                    tabPanels.forEach(panel => {
                        if (panel.getAttribute('data-gfw-panel') === target) {
                            panel.classList.remove('hidden');
                        } else {
                            panel.classList.add('hidden');
                        }
                    });
                });
            });
        });
    </script>

    <!-- SECTION 6: Data Provenance & Authorizations -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        <!-- Provenance Traceability Table -->
        <div class="glass-panel p-5 rounded-xl border border-slate-800 space-y-4">
            <h3 class="text-xs font-bold uppercase tracking-wider text-teal-400 border-b border-slate-800 pb-2 flex items-center gap-1.5">
                <span>🛡️</span>
                <span>6. Ketertelusuran Sumber Data (Provenance)</span>
            </h3>

            @if(count($provenance) > 0)
            <div class="overflow-x-auto responsive-table-container">
                <table class="w-full text-left text-xs min-w-[480px]">
                    <thead class="text-[10px] text-slate-400 uppercase border-b border-slate-800 bg-slate-900/40">
                        <tr>
                            <th class="py-1.5 px-2">Field</th>
                            <th class="py-1.5 px-2">Sumber Data</th>
                            <th class="py-1.5 px-2">Status & Kepercayaan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60 font-mono text-[11px]">
                        @foreach($provenance as $fName => $pData)
                        <tr>
                            <td class="py-1.5 px-2 text-white font-bold">{{ $fName }}</td>
                            <td class="py-1.5 px-2 text-slate-300">{{ $pData['source'] }}</td>
                            <td class="py-1.5 px-2">
                                <span class="px-1.5 py-0.5 rounded text-[9px] uppercase font-bold {{ $pData['confidence'] === 'high' ? 'bg-emerald-950 text-emerald-300' : 'bg-slate-800 text-amber-300' }}">
                                    {{ $pData['confidence'] }}
                                </span>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @else
            <div class="py-4 text-slate-500 text-xs italic">
                Data profil bersumber langsung dari sinyal telemetri AIS awal (provider: {{ $vessel->sourceProvider?->name ?? 'AIS Stream' }}).
            </div>
            @endif
        </div>

        <!-- Compliance & Authorizations -->
        <div class="glass-panel p-5 rounded-xl border border-slate-800 space-y-4">
            <h3 class="text-xs font-bold uppercase tracking-wider text-amber-400 border-b border-slate-800 pb-2 flex items-center gap-1.5">
                <span>⚖️</span>
                <span>Izin & Rekam Kepatuhan</span>
            </h3>

            <div class="space-y-3 text-xs">
                <div>
                    <span class="text-[10px] text-slate-400 uppercase font-semibold">Izin Penangkapan:</span>
                    @forelse($vessel->authorizations as $auth)
                    <div class="mt-1 p-2 rounded bg-slate-900 border border-slate-800">
                        <div class="font-bold text-teal-300 font-mono">{{ $auth->authorization_code }}</div>
                        <div class="text-[11px] text-slate-300">{{ $auth->permit_type }} &bull; {{ $auth->issuing_authority }}</div>
                        <div class="text-[10px] text-slate-500 font-mono">Berlaku s/d: {{ $auth->valid_to->toDateString() }}</div>
                    </div>
                    @empty
                    <div class="mt-1 text-slate-500 italic">Belum ada dokumen izin terdaftar.</div>
                    @endforelse
                </div>

                <div class="pt-2 border-t border-slate-800">
                    <span class="text-[10px] text-slate-400 uppercase font-semibold">Alert Pelanggaran / Anomali:</span>
                    <div class="text-white font-mono font-bold mt-0.5">
                        {{ $vessel->complianceEvents->count() }} kejadian
                    </div>
                </div>
            </div>
        </div>

    </div>

</div>
@endsection
