@extends('layouts.app')

@section('header_title', 'Profil Kapal — ' . $vessel->name)

@section('content')
<div class="space-y-6">

    <!-- Header Card -->
    <div class="glass-panel p-6 rounded-2xl border border-slate-800 shadow-xl flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            @php $flagInfo = $vessel->flag_info; @endphp
            <div class="flex items-center gap-3">
                <span class="text-4xl">{{ $flagInfo['flag_emoji'] }}</span>
                <div>
                    <h2 class="text-2xl font-black text-white tracking-wide flex items-center gap-2">
                        <span>{{ $vessel->name }}</span>
                        <span class="px-2.5 py-0.5 rounded text-xs font-mono font-bold uppercase {{ $flagInfo['status'] === 'verified_explicit' ? 'bg-emerald-950/90 text-emerald-300 border border-emerald-500/40' : ($flagInfo['status'] === 'conflict_detected' ? 'bg-rose-950/90 text-rose-300 border border-rose-500/40' : 'bg-slate-800 text-slate-300 border border-slate-700') }}">
                            {{ $flagInfo['iso2'] }} &bull; {{ $flagInfo['country_name'] }}
                        </span>
                    </h2>
                    <div class="text-xs text-slate-400 mt-1 font-mono flex items-center gap-2">
                        <span>UUID: {{ $vessel->id }}</span>
                        <span>&bull;</span>
                        <span>Tipe: <strong class="capitalize text-slate-300 font-sans">{{ $vessel->vessel_type }}</strong></span>
                        <span>&bull;</span>
                        <span class="text-[11px] {{ $flagInfo['is_verified'] ? 'text-emerald-400' : 'text-amber-400' }}">
                            {{ $flagInfo['status'] === 'verified_explicit' ? '✓ Flag Terverifikasi' : '⚠️ ' . $flagInfo['status'] }}
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('vessels.index') }}" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-medium text-xs transition border border-slate-700">
                &larr; Kembali ke Registry
            </a>
            <a href="{{ route('map.index') }}" class="px-4 py-2 rounded-xl bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold text-xs transition shadow-lg shadow-teal-500/20">
                Lihat di Peta Global
            </a>
        </div>
    </div>

    <!-- Identity & Specifications Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Column 1: Core Identifiers -->
        <div class="glass-panel p-5 rounded-xl border border-slate-800 space-y-4">
            <h3 class="text-xs font-bold uppercase tracking-wider text-teal-400 border-b border-slate-800 pb-2 flex items-center gap-1.5">
                <span>🆔</span>
                <span>Identitas & Kode Registrasi</span>
            </h3>

            <div class="space-y-2.5 text-xs">
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Bendera Negara:</span>
                    <span class="font-mono text-white font-bold flex items-center gap-1.5">
                        <span>{{ $flagInfo['flag_emoji'] }}</span>
                        <span>{{ $flagInfo['country_name'] }} ({{ $flagInfo['iso2'] }})</span>
                    </span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Status Bendera:</span>
                    <span class="text-[11px] font-mono px-2 py-0.5 rounded {{ $flagInfo['status'] === 'verified_explicit' ? 'bg-emerald-950 text-emerald-300' : ($flagInfo['status'] === 'conflict_detected' ? 'bg-rose-950 text-rose-300' : 'bg-slate-800 text-amber-300') }}">
                        {{ $flagInfo['status'] === 'verified_explicit' ? 'Verified Explicit' : ($flagInfo['status'] === 'mid_administrative_indicator' ? 'MID Clue (Belum Terverifikasi)' : $flagInfo['status']) }}
                    </span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Sumber Bendera:</span>
                    <span class="text-[10px] text-slate-300 font-mono text-right max-w-[180px] truncate" title="{{ $flagInfo['source'] }}">
                        {{ $flagInfo['source'] }}
                    </span>
                </div>
                @if($flagInfo['has_conflict'])
                <div class="p-2 rounded bg-rose-950/40 border border-rose-500/30 text-rose-300 text-[10px]">
                    <strong>Konflik Flag State:</strong> {{ $flagInfo['conflict_note'] }}
                </div>
                @endif
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
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Kode Lambung (Hull):</span>
                    <span class="font-mono text-white font-bold">{{ $vessel->hull_code ?? 'Belum tercatat' }}</span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Panjang (LOA):</span>
                    <span class="text-white">{{ $vessel->length_meters ? $vessel->length_meters . ' m' : '-' }}</span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Tonase Kotor (GT):</span>
                    <span class="text-white">{{ $vessel->tonnage ? number_format($vessel->tonnage) . ' GT' : '-' }}</span>
                </div>
                <div class="flex justify-between py-1">
                    <span class="text-slate-400">Operator / Pemilik:</span>
                    <span class="text-white font-semibold">{{ $vessel->operator ?: ($vessel->owner ?: 'Belum terverifikasi') }}</span>
                </div>
            </div>
        </div>

        <!-- Column 2: Latest Telemetry & Positioning -->
        <div class="glass-panel p-5 rounded-xl border border-slate-800 space-y-4">
            <h3 class="text-xs font-bold uppercase tracking-wider text-sky-400 border-b border-slate-800 pb-2 flex items-center gap-1.5">
                <span>📍</span>
                <span>Telemetri Posisi Terkini</span>
            </h3>

            @if($vessel->latestPosition)
            <div class="space-y-2.5 text-xs">
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Koordinat:</span>
                    <span class="font-mono text-sky-300 font-bold">
                        {{ $vessel->latestPosition->latitude }}&deg;, {{ $vessel->latestPosition->longitude }}&deg;
                    </span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Kecepatan (SOG):</span>
                    <span class="font-mono text-white">{{ $vessel->latestPosition->speed_knots ?? 0 }} knots</span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Haluan (COG):</span>
                    <span class="font-mono text-white">{{ $vessel->latestPosition->course_degrees ?? 0 }}&deg;</span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Status Navigasi:</span>
                    <span class="text-white capitalize">{{ $vessel->latestPosition->nav_status ?? 'under_way' }}</span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-800/60">
                    <span class="text-slate-400">Waktu Observasi:</span>
                    <span class="text-slate-200 font-mono">{{ $vessel->latestPosition->observed_at->format('Y-m-d H:i:s') }} UTC</span>
                </div>
                <div class="flex justify-between py-1">
                    <span class="text-slate-400">Skor Kualitas Sinyal:</span>
                    <span class="font-mono text-teal-400 font-bold">{{ $vessel->latestPosition->data_quality_score }} / 1.0</span>
                </div>
            </div>
            @else
            <div class="py-12 text-center text-slate-500 text-xs">
                <span class="text-3xl block mb-2">📡</span>
                Belum ada data posisi AIS aktual yang tersimpan untuk kapal ini.
            </div>
            @endif
        </div>

        <!-- Column 3: Compliance & Authorizations -->
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
