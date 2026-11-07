@extends('layouts.app')

@section('header_title', 'Live Maritime Tracking — Real-Time AIS Telemetry Platform')

@section('content')
<div class="space-y-6">

    <!-- Header Section -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-sky-500/20 text-sky-300 border border-sky-500/30 uppercase">
                    Live Telemetri AIS
                </span>
                <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-teal-500/20 text-teal-300 border border-teal-500/30 uppercase">
                    MapLibre GL JS
                </span>
                <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-slate-800 text-slate-300 border border-slate-700 uppercase">
                    ESRI Dark Canvas
                </span>
            </div>
            <h2 class="text-xl sm:text-2xl font-bold text-white tracking-wide uppercase mt-1">
                Live Maritime Tracking
            </h2>
            <p class="text-xs text-slate-400 mt-0.5">
                Pengawasan langsung pergerakan armada perikanan global, heading, kecepatan, dan track historis kapal.
            </p>
        </div>

        <div class="flex items-center gap-2">
            <button type="button" id="btnFitAllVessels" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-sky-300 font-bold text-xs transition border border-slate-700 flex items-center gap-1.5 shadow-md">
                <span>🚢</span>
                <span>Fit All Vessels</span>
            </button>
            <a href="{{ route('map.index') }}" class="px-4 py-2 rounded-xl bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold text-xs transition shadow-lg shadow-teal-500/20 flex items-center gap-1.5">
                <span>🗺️</span>
                <span>Buka Peta Global</span>
            </a>
        </div>
    </div>

    <!-- Provider Telemetry Status Metrics -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-xs">
        <div class="glass-panel p-4 rounded-2xl border border-slate-800 space-y-1 shadow-lg">
            <div class="flex items-center justify-between">
                <span class="text-slate-400 text-[11px]">Kanal Ingestion AIS:</span>
                @php
                    $status = $aisProvider?->health_status ?? 'STANDBY';
                    $badgeClass = match ($status) {
                        'streaming', 'connected' => 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/40',
                        'awaiting_telemetry' => 'bg-amber-500/20 text-amber-300 border border-amber-500/40',
                        'stale' => 'bg-amber-500/20 text-amber-300 border border-amber-500/40',
                        default => 'bg-slate-800 text-slate-400 border border-slate-700',
                    };
                @endphp
                <span class="px-1.5 py-0.2 rounded text-[9px] font-mono uppercase {{ $badgeClass }}">
                    {{ str_replace('_', ' ', $status) }}
                </span>
            </div>
            <div class="font-bold text-white text-sm">{{ $aisProvider?->name ?? 'AIS Stream Live Gateway' }}</div>
            <div class="text-[10px] text-slate-400 font-mono">Format: WGS84 NMEA / JSON Ingest</div>
        </div>

        <div class="glass-panel p-4 rounded-2xl border border-slate-800 space-y-1 shadow-lg">
            <span class="text-slate-400 text-[11px]">Posisi Masuk (24 Jam Terakhir):</span>
            <div class="font-bold text-teal-300 font-mono text-xl">{{ number_format($recentPositionsCount) }}</div>
            <div class="text-[10px] text-slate-400 font-mono">Titik telemetri aktual tersimpan</div>
        </div>

        <div class="glass-panel p-4 rounded-2xl border border-slate-800 space-y-1 shadow-lg">
            <span class="text-slate-400 text-[11px]">Kapal Aktif Terpetakan:</span>
            <div class="font-bold text-sky-300 font-mono text-xl">{{ number_format($activeVessels->count()) }}</div>
            <div class="text-[10px] text-slate-400 font-mono">Broadcast aktif dalam viewport</div>
        </div>
    </div>

    <!-- MAIN TRACKING LAYOUT: MAPLIBRE (8 COLS) + TELEMETRY SIDEBAR (4 COLS) -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
        
        <!-- LEFT: MapLibre Live AIS Map Canvas Container (8 Cols) -->
        <div class="lg:col-span-8 glass-panel p-4 rounded-2xl border border-slate-800 shadow-xl space-y-3">
            <div class="flex items-center justify-between border-b border-slate-800 pb-2.5">
                <div class="flex items-center gap-2">
                    <span class="text-base">🗺️</span>
                    <h3 class="font-bold uppercase tracking-wider text-white text-xs">
                        MapLibre Live AIS Tracking Canvas
                    </h3>
                </div>
                <div class="flex items-center gap-2 text-[10px] font-mono text-slate-400">
                    <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-sky-400"></span> Live (&lt;2h)</span>
                    <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-slate-500"></span> Stale (&gt;2h)</span>
                </div>
            </div>

            <!-- Dedicated Map Container with explicit styling -->
            <div class="relative w-full h-[460px] sm:h-[500px] rounded-xl overflow-hidden border border-slate-800 bg-slate-950 shadow-inner">
                <div id="tracking-map-canvas" class="w-full h-full bg-slate-950 min-h-[460px]"></div>
                
                <!-- Floating Info Badge on Bottom Left -->
                <div class="absolute bottom-2 left-2 z-10 px-2.5 py-1 rounded bg-slate-950/80 backdrop-blur-md border border-slate-800 text-[10px] font-mono text-slate-300 pointer-events-none">
                    ESRI World Dark Gray &bull; Live AIS Telemetry
                </div>
            </div>
        </div>

        <!-- RIGHT: Vessel Telemetry Inspector Sidebar (4 Cols) -->
        <div class="lg:col-span-4 space-y-4">
            
            <!-- Focused Telemetry Card -->
            <div class="glass-panel p-5 rounded-2xl border border-slate-800 shadow-xl space-y-3.5">
                <div class="flex items-center justify-between border-b border-slate-800 pb-2.5">
                    <h3 class="font-bold uppercase tracking-wider text-teal-300 text-xs flex items-center gap-2">
                        <span>📡</span>
                        <span>Vessel Telemetry Inspector</span>
                    </h3>
                    <span id="selected-vessel-badge" class="px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-sky-500/20 text-sky-300 border border-sky-500/30">
                        PILIH KAPAL
                    </span>
                </div>

                <div id="telemetry-detail-content" class="space-y-3 text-xs">
                    <div class="p-3.5 rounded-xl bg-slate-900 border border-slate-800 space-y-2">
                        <div class="flex items-center justify-between">
                            <span class="text-slate-400 text-[11px]">Nama Kapal:</span>
                            <span id="tel-vessel-name" class="font-bold text-white text-sm">
                                {{ $activeVessels->first()?->name ?? 'Belum ada pilihan' }}
                            </span>
                        </div>
                        <div class="grid grid-cols-2 gap-2 text-[11px] font-mono pt-1 border-t border-slate-800/80">
                            <div>MMSI: <strong id="tel-vessel-mmsi" class="text-teal-300">{{ $activeVessels->first()?->mmsi ?? '-' }}</strong></div>
                            <div>Bendera: <strong id="tel-vessel-flag" class="text-slate-300">{{ $activeVessels->first()?->flag_info['label'] ?? 'UN' }}</strong></div>
                            <div>SOG (Kec): <strong id="tel-vessel-sog" class="text-sky-300">{{ $activeVessels->first()?->latestPosition?->speed_knots ?? 0 }} kn</strong></div>
                            <div>COG (Arah): <strong id="tel-vessel-cog" class="text-sky-300">{{ $activeVessels->first()?->latestPosition?->course_degrees ?? 0 }}°</strong></div>
                        </div>
                        <div class="text-[10px] font-mono text-slate-400 pt-1">
                            Koordinat: <span id="tel-vessel-coords" class="text-slate-200">
                                {{ $activeVessels->first()?->latestPosition ? number_format($activeVessels->first()->latestPosition->latitude, 4) . '°, ' . number_format($activeVessels->first()->latestPosition->longitude, 4) . '°' : '-' }}
                            </span>
                        </div>
                        <div class="text-[10px] font-mono text-slate-400">
                            Waktu: <span id="tel-vessel-time" class="text-slate-300">
                                {{ $activeVessels->first()?->latestPosition?->observed_at?->format('Y-m-d H:i:s') ?? '-' }} UTC
                            </span>
                        </div>
                    </div>

                    <!-- AIS Vessel Track Action Controls (Tahap 11) -->
                    <div class="p-2.5 rounded-xl bg-slate-900 border border-slate-800 space-y-2">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-cyan-400 font-mono flex items-center justify-between">
                            <span>🛰️ AIS Historical Track</span>
                            <span id="trackingTrackStatusBadge" class="text-slate-400">Siap</span>
                        </div>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <button type="button" id="btnTrackingShowTrack" class="px-2.5 py-1 rounded-lg bg-cyan-500/20 hover:bg-cyan-500/30 text-cyan-300 font-bold text-xs border border-cyan-500/40 flex items-center gap-1 transition" title="Tampilkan garis lintasan kapal terpilih (Show Track)">
                                <span>🗺️</span> Show Track
                            </button>
                            <button type="button" id="btnTrackingFitTrack" class="px-2 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-sky-300 font-bold text-xs border border-slate-700 flex items-center gap-1 transition" title="Pusatkan peta ke garis lintasan (Fit Track)">
                                <span>🧭</span> Fit Track
                            </button>
                            <button type="button" id="btnTrackingToggleTrack" class="px-2 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold text-xs border border-slate-700 flex items-center gap-1 transition" title="Sembunyikan / Tampilkan garis lintasan (Hide/Show)">
                                <span id="btnTrackingToggleIcon">👁️</span> <span id="btnTrackingToggleLabel">Hide</span>
                            </button>
                            <button type="button" id="btnTrackingClearTrack" class="px-2 py-1 rounded-lg bg-slate-800 hover:bg-rose-950 text-rose-300 font-bold text-xs border border-slate-700 hover:border-rose-700 flex items-center gap-1 transition" title="Hapus garis lintasan dari peta (Clear Track)">
                                <span>✖️</span> Clear
                            </button>
                        </div>
                    </div>

                    @if($activeVessels->first())
                    <div class="pt-1 text-right">
                        <a id="tel-vessel-link" href="{{ route('vessels.show', $activeVessels->first()->id) }}" class="text-xs text-teal-400 hover:text-teal-300 font-bold underline">
                            Buka Profil & Riwayat Track &rarr;
                        </a>
                    </div>
                    @endif
                </div>
            </div>

            <!-- Quick Vessel Jump List -->
            <div class="glass-panel p-4 rounded-2xl border border-slate-800 shadow-xl space-y-2.5">
                <div class="flex items-center justify-between border-b border-slate-800 pb-2">
                    <span class="font-bold uppercase tracking-wider text-slate-300 text-[11px]">
                        Quick Radar Focus ({{ $activeVessels->count() }})
                    </span>
                    <span class="text-[10px] text-slate-500 font-mono">Klik untuk Pusatkan</span>
                </div>

                <div class="max-h-60 overflow-y-auto space-y-1.5 pr-1 text-xs font-mono">
                    @forelse($activeVessels as $v)
                    @php
                        $lat = $v->latestPosition?->latitude;
                        $lng = $v->latestPosition?->longitude;
                        $hasValidPos = $lat !== null && $lng !== null && is_finite($lat) && is_finite($lng) && $lat >= -90.0 && $lat <= 90.0 && $lng >= -180.0 && $lng <= 180.0 && !($lat == 0.0 && $lng == 0.0);
                        $flagInfo = $v->flag_info;
                        $observedTime = $v->latestPosition?->observed_at?->format('Y-m-d H:i:s') ?? '-';
                        $speedKnots = $v->latestPosition?->speed_knots !== null ? (float)$v->latestPosition->speed_knots : 0;
                        $courseDeg = $v->latestPosition?->course_degrees !== null ? (float)$v->latestPosition->course_degrees : 0;
                    @endphp
                    <button type="button"
                            class="vessel-focus-btn w-full text-left p-2 rounded-xl bg-slate-900/80 hover:bg-slate-800/90 border border-slate-800 hover:border-sky-500/40 transition flex items-center justify-between group {{ !$hasValidPos ? 'opacity-50 cursor-not-allowed' : '' }}"
                            data-has-coords="{{ $hasValidPos ? 'true' : 'false' }}"
                            data-vessel-id="{{ $v->id }}"
                            data-lat="{{ $lat ?? '' }}"
                            data-lng="{{ $lng ?? '' }}"
                            data-name="{{ $v->name }}"
                            data-mmsi="{{ $v->mmsi }}"
                            data-flag="{{ $flagInfo['label'] }}"
                            data-sog="{{ $speedKnots }}"
                            data-cog="{{ $courseDeg }}"
                            data-time="{{ $observedTime }}"
                            data-url="{{ route('vessels.show', $v->id) }}"
                            {{ !$hasValidPos ? 'disabled' : '' }}
                            title="{{ $hasValidPos ? 'Pusatkan radar ke kapal ini' : 'Koordinat posisi belum tersedia' }}">
                        <div class="truncate">
                            <div class="font-bold text-slate-200 group-hover:text-white truncate flex items-center gap-1.5">
                                <span>{{ $flagInfo['flag_emoji'] }}</span>
                                <span class="truncate">{{ $v->name }}</span>
                            </div>
                            <div class="text-[10px] text-slate-500">MMSI: {{ $v->mmsi }} &bull; {{ $speedKnots }} kn &bull; {{ $flagInfo['iso2'] }}</div>
                        </div>
                        <span class="text-sky-400 text-xs shrink-0">&bull;&rarr;</span>
                    </button>
                    @empty
                    <div class="text-center text-slate-500 py-4 text-xs">
                        Tidak ada kapal aktif
                    </div>
                    @endforelse
                </div>
            </div>

        </div>

    </div>

    <!-- Active Broadcasts Table -->
    <div class="glass-panel rounded-2xl border border-slate-800 overflow-hidden shadow-xl space-y-3 p-5">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <h3 class="text-xs font-bold uppercase tracking-wider text-white flex items-center gap-2">
                <span>📋</span>
                <span>Daftar Posisi Broadcast Terkini (Live Feed Telemetry)</span>
            </h3>
            <span class="text-[11px] font-mono text-slate-400">
                Total {{ number_format($activeVessels->count()) }} Entri
            </span>
        </div>

        <div class="overflow-x-auto text-xs">
            <table class="w-full text-left">
                <thead class="text-slate-400 uppercase text-[10px] border-b border-slate-800 font-mono">
                    <tr>
                        <th class="py-2.5 px-3">Kapal</th>
                        <th class="py-2.5 px-3">MMSI</th>
                        <th class="py-2.5 px-3">Bendera</th>
                        <th class="py-2.5 px-3">Koordinat (Lat, Lng)</th>
                        <th class="py-2.5 px-3">SOG / COG</th>
                        <th class="py-2.5 px-3">Status Navigasi</th>
                        <th class="py-2.5 px-3">Waktu Observasi</th>
                        <th class="py-2.5 px-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60 font-mono text-[11px]">
                    @forelse($activeVessels as $vessel)
                    <tr class="hover:bg-slate-900/40 transition">
                        <td class="py-2.5 px-3 font-bold text-white font-sans">{{ $vessel->name }}</td>
                        <td class="py-2.5 px-3 text-teal-300">{{ $vessel->mmsi }}</td>
                        <td class="py-2.5 px-3">
                            <div class="flex items-center gap-1.5" title="{{ $vessel->flag_info['source'] }}">
                                <span class="text-base">{{ $vessel->flag_info['flag_emoji'] }}</span>
                                <div>
                                    <span class="px-1.5 py-0.5 rounded text-[10px] font-bold {{ $vessel->flag_info['status'] === 'verified_explicit' ? 'bg-emerald-950/80 text-emerald-300 border border-emerald-500/30' : ($vessel->flag_info['status'] === 'conflict_detected' ? 'bg-rose-950/80 text-rose-300 border border-rose-500/30' : 'bg-slate-800 text-slate-300') }}">
                                        {{ $vessel->flag_info['iso2'] }}
                                    </span>
                                    <div class="text-[9px] text-slate-400 font-sans truncate max-w-[110px]" title="{{ $vessel->flag_info['country_name'] }} ({{ $vessel->flag_info['status'] }})">
                                        {{ $vessel->flag_info['country_name'] }}
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="py-2.5 px-3 text-slate-300">
                            {{ $vessel->latestPosition?->latitude ? number_format($vessel->latestPosition->latitude, 4) . '°' : '-' }},
                            {{ $vessel->latestPosition?->longitude ? number_format($vessel->latestPosition->longitude, 4) . '°' : '-' }}
                        </td>
                        <td class="py-2.5 px-3 text-sky-300">
                            {{ $vessel->latestPosition?->speed_knots ?? 0 }} kn &bull; {{ $vessel->latestPosition?->course_degrees ?? 0 }}&deg;
                        </td>
                        <td class="py-2.5 px-3 capitalize text-slate-400 font-sans">
                            {{ $vessel->latestPosition?->nav_status ?? 'under_way' }}
                        </td>
                        <td class="py-2.5 px-3 text-slate-400 text-[10px]">
                            {{ $vessel->latestPosition?->observed_at?->format('Y-m-d H:i:s') ?? '-' }} UTC
                        </td>
                        <td class="py-2.5 px-3 text-right">
                            <a href="{{ route('vessels.show', $vessel->id) }}" class="text-teal-400 hover:text-teal-300 font-bold underline font-sans text-xs">
                                Track &rarr;
                            </a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="8" class="py-8 text-center text-slate-500 font-sans">
                            Belum ada telemetri AIS posisi live yang masuk ke sistem.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection

@push('scripts')
<script>
    let trackingMapController = null;

    function initializeTrackingMap() {
        if (typeof window.GlobalMapController !== 'function') {
            if (typeof window.loadMapLibreEngine === 'function') {
                window.loadMapLibreEngine().then(initializeTrackingMap);
                return;
            }
            setTimeout(initializeTrackingMap, 50);
            return;
        }

        trackingMapController = new window.GlobalMapController('tracking-map-canvas', {
            center: [100.0, 5.0],
            zoom: 4.0,
            layers: {
                fao: false,
                eez: true,
                encounters: false,
                vessels: true,
                darkVessels: false,
                sarScenes: false
            }
        });

        // Fit All Vessels button
        const btnFit = document.getElementById('btnFitAllVessels');
        if (btnFit) {
            btnFit.addEventListener('click', () => {
                if (trackingMapController) {
                    trackingMapController.fitToData('vessels');
                }
            });
        }

        // Track Control Handlers (Tahap 11)
        const badgeTrack = document.getElementById('trackingTrackStatusBadge');
        const btnShowTrack = document.getElementById('btnTrackingShowTrack');
        const btnFitTrack = document.getElementById('btnTrackingFitTrack');
        const btnToggleTrack = document.getElementById('btnTrackingToggleTrack');
        const btnClearTrack = document.getElementById('btnTrackingClearTrack');
        const toggleIcon = document.getElementById('btnTrackingToggleIcon');
        const toggleLabel = document.getElementById('btnTrackingToggleLabel');

        trackingMapController.onTrackUpdated = (geoJson) => {
            if (!badgeTrack) return;
            if (geoJson && geoJson.properties && geoJson.properties.position_count > 0) {
                badgeTrack.textContent = `${geoJson.properties.position_count} pts`;
                badgeTrack.className = 'text-emerald-400 font-bold';
            } else if (geoJson && geoJson.properties && geoJson.properties.track_status === 'empty') {
                badgeTrack.textContent = 'Kosong';
                badgeTrack.className = 'text-amber-400 font-bold';
            } else {
                badgeTrack.textContent = 'Siap';
                badgeTrack.className = 'text-slate-400';
            }
        };

        trackingMapController.onTrackVisibilityChanged = (visible) => {
            if (toggleLabel && toggleIcon) {
                toggleLabel.textContent = visible ? 'Hide' : 'Show';
                toggleIcon.textContent = visible ? '👁️' : '🙈';
            }
        };

        if (btnShowTrack) {
            btnShowTrack.addEventListener('click', () => {
                const target = window.currentFocusVesselId || window.currentFocusMmsi || '{{ $activeVessels->first()?->id }}';
                if (!target) {
                    alert('Pilih kapal terlebih dahulu dari daftar di bawah.');
                    return;
                }
                trackingMapController.showVesselTrack(target, { fitBounds: true });
            });
        }

        if (btnFitTrack) {
            btnFitTrack.addEventListener('click', () => {
                trackingMapController.fitVesselTrack();
            });
        }

        if (btnToggleTrack) {
            btnToggleTrack.addEventListener('click', () => {
                if (trackingMapController.trackVisible) {
                    trackingMapController.hideVesselTrack();
                } else {
                    trackingMapController.showTrackLayer();
                }
            });
        }

        if (btnClearTrack) {
            btnClearTrack.addEventListener('click', () => {
                trackingMapController.clearVesselTrack();
            });
        }

        // Attach click listeners to Quick Radar Focus vessel buttons
        document.querySelectorAll('.vessel-focus-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const hasCoords = btn.getAttribute('data-has-coords') === 'true';
                if (!hasCoords) {
                    alert('Posisi koordinat kapal ini belum tersedia atau tidak valid.');
                    return;
                }
                const lat = parseFloat(btn.getAttribute('data-lat'));
                const lng = parseFloat(btn.getAttribute('data-lng'));
                const name = btn.getAttribute('data-name') || '-';
                const mmsi = btn.getAttribute('data-mmsi') || '-';
                const vesselId = btn.getAttribute('data-vessel-id') || '';
                const flag = btn.getAttribute('data-flag') || 'UN';
                const sog = parseFloat(btn.getAttribute('data-sog')) || 0;
                const cog = parseFloat(btn.getAttribute('data-cog')) || 0;
                const time = btn.getAttribute('data-time') || '-';
                const linkUrl = btn.getAttribute('data-url') || '';

                window.currentFocusMmsi = mmsi;
                window.currentFocusVesselId = vesselId;

                focusVessel(lat, lng, name, mmsi, flag, sog, cog, time, linkUrl, vesselId);
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeTrackingMap);
    } else {
        initializeTrackingMap();
    }

    function focusVessel(lat, lng, name, mmsi, flag, sog, cog, time, linkUrl, vesselId) {
        window.currentFocusMmsi = mmsi;
        if (vesselId) window.currentFocusVesselId = vesselId;

        if (typeof lat !== 'number' || typeof lng !== 'number' || !isFinite(lat) || !isFinite(lng) || lat < -90.0 || lat > 90.0 || lng < -180.0 || lng > 180.0 || (lat === 0.0 && lng === 0.0)) {
            console.warn('[Tracking] Invalid coordinates rejected in focusVessel:', { lat, lng, mmsi });
            return;
        }

        if (trackingMapController && trackingMapController.map) {
            trackingMapController.setSelectedVesselByMmsi(mmsi);

            trackingMapController.map.flyTo({
                center: [lng, lat],
                zoom: 8.5,
                essential: true
            });

            // Update Telemetry Sidebar content safely using textContent
            const elName = document.getElementById('tel-vessel-name');
            const elMmsi = document.getElementById('tel-vessel-mmsi');
            const elFlag = document.getElementById('tel-vessel-flag');
            const elSog = document.getElementById('tel-vessel-sog');
            const elCog = document.getElementById('tel-vessel-cog');
            const elCoords = document.getElementById('tel-vessel-coords');
            const elTime = document.getElementById('tel-vessel-time');
            const elBadge = document.getElementById('selected-vessel-badge');
            const linkEl = document.getElementById('tel-vessel-link');

            if (elName) elName.textContent = name;
            if (elMmsi) elMmsi.textContent = mmsi;
            if (elFlag) elFlag.textContent = flag || 'UN';
            if (elSog) elSog.textContent = sog + ' kn';
            if (elCog) elCog.textContent = cog + '°';
            if (elCoords) elCoords.textContent = lat.toFixed(4) + '°, ' + lng.toFixed(4) + '°';
            if (elTime) elTime.textContent = time + ' UTC';
            if (elBadge) {
                elBadge.textContent = 'FOKUS AKTIF';
                elBadge.className = 'px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/40';
            }
            if (linkEl && linkUrl) {
                linkEl.href = linkUrl;
            }
        }
    }
    window.focusVessel = focusVessel;
</script>
@endpush
