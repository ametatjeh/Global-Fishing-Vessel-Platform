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

    <!-- Header Card -->
    <div class="glass-panel p-6 rounded-2xl border border-slate-800 shadow-xl flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-3">
                <span class="text-4xl">{{ $flagInfo['flag_emoji'] }}</span>
                <div>
                    <h2 class="text-2xl font-black text-white tracking-wide flex items-center gap-2 flex-wrap">
                        <span>{{ $vessel->name }}</span>
                        <span class="px-2.5 py-0.5 rounded text-xs font-mono font-bold uppercase {{ $flagInfo['status'] === 'verified_explicit' ? 'bg-emerald-950/90 text-emerald-300 border border-emerald-500/40' : ($flagInfo['status'] === 'conflict_detected' ? 'bg-rose-950/90 text-rose-300 border border-rose-500/40' : 'bg-slate-800 text-slate-300 border border-slate-700') }}">
                            {{ $flagInfo['iso2'] }} &bull; {{ $flagInfo['country_name'] }}
                        </span>
                        @if($vessel->enrichment_status === 'enriched')
                        <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold uppercase bg-teal-500/20 text-teal-300 border border-teal-500/40">
                            ✓ GFW Enriched
                        </span>
                        @elseif($vessel->enrichment_status === 'conflict_detected')
                        <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold uppercase bg-amber-500/20 text-amber-300 border border-amber-500/40">
                            ⚠️ Conflicts Recorded
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
                        <span>{{ $flagInfo['country_name'] }} ({{ $flagInfo['iso2'] }})</span>
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
                    <span class="font-bold capitalize {{ $vessel->vessel_type === 'unknown' ? 'text-slate-400' : 'text-emerald-300' }}">
                        {{ $vessel->vessel_type }}
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
                    <span class="text-white font-semibold font-mono text-right max-w-[180px] truncate" title="{{ $vessel->operator ?: 'Belum terverifikasi' }}">
                        {{ $vessel->operator ?: '<tidak tersedia>' }}
                    </span>
                </div>
                <div class="flex justify-between py-1">
                    <span class="text-slate-400">Pemilik (Owner):</span>
                    <span class="text-white font-semibold font-mono text-right max-w-[180px] truncate" title="{{ $vessel->owner ?: ($vessel->operator ?: 'Belum terverifikasi') }}">
                        {{ $vessel->owner ?: ($vessel->operator ?: '<tidak tersedia>') }}
                    </span>
                </div>
            </div>
        </div>

    </div>

    <!-- Secondary Grid: Sections 4 & 5 -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

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
            <div class="space-y-2.5 text-xs">
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Koordinat Terkini:</span>
                    <span class="font-mono text-sky-300 font-bold">
                        {{ number_format($pos->latitude, 6) }}&deg;, {{ number_format($pos->longitude, 6) }}&deg;
                    </span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Kecepatan (SOG):</span>
                    <span class="font-mono text-white">{{ $pos->speed_knots ?? 0 }} knots</span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Haluan (COG):</span>
                    <span class="font-mono text-white">{{ $pos->course_degrees ?? 0 }}&deg;</span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">True Heading (HDG):</span>
                    <span class="font-mono text-white">{{ $pos->heading_degrees !== null ? $pos->heading_degrees . '&deg;' : '-' }}</span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Status Navigasi:</span>
                    <span class="text-white capitalize">{{ $pos->nav_status ?? 'under_way' }}</span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Waktu Observasi:</span>
                    <span class="text-slate-200 font-mono">{{ $pos->observed_at->format('Y-m-d H:i:s') }} UTC</span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Sumber Telemetri:</span>
                    <span class="font-mono text-teal-300">{{ $pos->sourceProvider?->name ?? 'AIS Ingestion' }}</span>
                </div>
                <div class="flex justify-between py-1">
                    <span class="text-slate-400">Skor Kualitas Sinyal:</span>
                    <span class="font-mono text-teal-400 font-bold">{{ $pos->data_quality_score }} / 1.0</span>
                </div>
            </div>
            @else
            <div class="py-12 text-center text-slate-500 text-xs">
                <span class="text-3xl block mb-2">📡</span>
                Belum ada data posisi AIS aktual yang tersimpan untuk kapal ini.
            </div>
            @endif
        </div>

        <!-- SECTION 5: GFW Intelligence & Events -->
        <div class="glass-panel p-5 rounded-xl border border-slate-800 space-y-4">
            <h3 class="text-xs font-bold uppercase tracking-wider text-amber-400 border-b border-slate-800 pb-2 flex items-center justify-between">
                <span class="flex items-center gap-1.5">
                    <span>🛰️</span>
                    <span>5. Intelijen Global Fishing Watch (GFW v3)</span>
                </span>
                <span class="text-[9px] px-2 py-0.5 rounded font-mono font-bold uppercase bg-teal-500/20 text-teal-300 border border-teal-500/40">
                    NON-COMMERCIAL RESEARCH
                </span>
            </h3>

            <div class="space-y-3 text-xs">
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">GFW Vessel ID:</span>
                    @if($vessel->gfw_vessel_id)
                    <span class="font-mono text-teal-300 font-bold text-[11px] truncate max-w-[200px]" title="{{ $vessel->gfw_vessel_id }}">
                        {{ $vessel->gfw_vessel_id }}
                    </span>
                    @else
                    <span class="text-slate-500 italic">Belum terhubung ke GFW</span>
                    @endif
                </div>

                @if($eventsSummary)
                <!-- Event Categories -->
                <div class="grid grid-cols-2 gap-2.5 pt-1">
                    <!-- Port visits -->
                    <div class="p-2.5 rounded-lg bg-slate-900 border border-slate-800">
                        <div class="text-[10px] text-slate-400 uppercase font-semibold">Kunjungan Pelabuhan</div>
                        @if(($eventsSummary['port_visits']['status'] ?? '') === 'available')
                        <div class="text-lg font-bold text-white font-mono mt-0.5">
                            {{ number_format($eventsSummary['port_visits']['count'] ?? 0) }}
                            <span class="text-[10px] text-slate-400 font-normal">event</span>
                        </div>
                        <div class="text-[9px] text-emerald-400 font-mono">✓ Authorized (HTTP 200)</div>
                        @elseif(($eventsSummary['port_visits']['status'] ?? '') === 'permission_restricted')
                        <div class="text-[11px] text-amber-400 mt-1">⚠️ Izin Diperlukan (HTTP 403)</div>
                        @else
                        <div class="text-[11px] text-slate-500 mt-1">Tidak tersedia</div>
                        @endif
                    </div>

                    <!-- Loitering -->
                    <div class="p-2.5 rounded-lg bg-slate-900 border border-slate-800">
                        <div class="text-[10px] text-slate-400 uppercase font-semibold">Aktivitas Loitering</div>
                        @if(($eventsSummary['loitering']['status'] ?? '') === 'available')
                        <div class="text-lg font-bold text-white font-mono mt-0.5">
                            {{ number_format($eventsSummary['loitering']['count'] ?? 0) }}
                            <span class="text-[10px] text-slate-400 font-normal">event</span>
                        </div>
                        <div class="text-[9px] text-emerald-400 font-mono">✓ Authorized (HTTP 200)</div>
                        @elseif(($eventsSummary['loitering']['status'] ?? '') === 'permission_restricted')
                        <div class="text-[11px] text-amber-400 mt-1">⚠️ Izin Diperlukan (HTTP 403)</div>
                        @else
                        <div class="text-[11px] text-slate-500 mt-1">Tidak tersedia</div>
                        @endif
                    </div>

                    <!-- Encounters -->
                    <div class="p-2.5 rounded-lg bg-slate-900 border border-slate-800">
                        <div class="text-[10px] text-slate-400 uppercase font-semibold">Encounter di Laut</div>
                        @if(($eventsSummary['encounters']['status'] ?? '') === 'available')
                        <div class="text-lg font-bold text-white font-mono mt-0.5">
                            {{ number_format($eventsSummary['encounters']['count'] ?? 0) }}
                            <span class="text-[10px] text-slate-400 font-normal">event</span>
                        </div>
                        <div class="text-[9px] text-emerald-400 font-mono">✓ Authorized (HTTP 200)</div>
                        @elseif(($eventsSummary['encounters']['status'] ?? '') === 'permission_restricted')
                        <div class="text-[11px] text-amber-400 mt-1 font-mono">Research Access Req.</div>
                        <div class="text-[9px] text-slate-500">HTTP 403 (Katalog Publik)</div>
                        @else
                        <div class="text-[11px] text-slate-500 mt-1">Tidak tersedia</div>
                        @endif
                    </div>

                    <!-- Fishing Activity -->
                    <div class="p-2.5 rounded-lg bg-slate-900 border border-slate-800">
                        <div class="text-[10px] text-slate-400 uppercase font-semibold">Aktivitas Penangkapan</div>
                        @if(($eventsSummary['fishing']['status'] ?? '') === 'available')
                        <div class="text-lg font-bold text-white font-mono mt-0.5">
                            {{ number_format($eventsSummary['fishing']['count'] ?? 0) }}
                            <span class="text-[10px] text-slate-400 font-normal">event</span>
                        </div>
                        <div class="text-[9px] text-emerald-400 font-mono">✓ Authorized (HTTP 200)</div>
                        @elseif(($eventsSummary['fishing']['status'] ?? '') === 'permission_restricted')
                        <div class="text-[11px] text-amber-400 mt-1 font-mono">Research Access Req.</div>
                        <div class="text-[9px] text-slate-500">HTTP 403 (Katalog Publik)</div>
                        @else
                        <div class="text-[11px] text-slate-500 mt-1">Tidak tersedia</div>
                        @endif
                    </div>
                </div>

                <div class="pt-2 border-t border-slate-800 text-[10px] text-slate-400 space-y-1">
                    <div class="flex items-center justify-between">
                        <span>Sumber Data: <a href="https://globalfishingwatch.org" target="_blank" rel="noopener noreferrer" class="text-teal-400 hover:underline font-bold">Global Fishing Watch (GFW)</a></span>
                        <span>Dataset: <span class="font-mono text-slate-300">public-global-vessel-identity</span></span>
                    </div>
                    <div class="text-[9px] text-slate-400 italic leading-relaxed">
                        &ldquo;This application uses data and services provided by Global Fishing Watch (GFW) for non-commercial research, educational, and public-interest purposes. GFW data are subject to applicable licensing, attribution, and usage restrictions.&rdquo;
                    </div>
                    <div class="text-[9px] text-amber-300/90">
                        Pemberitahuan: Aktivitas kapal yang ditampilkan merupakan estimasi atau hasil analisis berdasarkan sumber data, bukan bukti final pelanggaran.
                    </div>
                </div>
                @else
                <div class="py-8 text-center text-slate-500 text-xs">
                    <span class="text-2xl block mb-1">🛰️</span>
                    Data intelijen GFW belum disinkronkan untuk kapal ini.
                    <div class="mt-2 text-[10px] text-slate-400 font-mono">
                        Jalankan: php artisan gfw:vessels:enrich --vessel={{ $vessel->id }}
                    </div>
                </div>
                @endif
            </div>
        </div>

    </div>

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
