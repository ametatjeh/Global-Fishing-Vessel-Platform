@extends('layouts.app')

@section('header_title', 'SAR Evidence Inspector & Multi-Source Intelligence — ' . $detection->detection_identifier)

@section('content')
<div class="space-y-6">

    <!-- Flash Alert Messages -->
    @if(session('success'))
    <div class="p-4 rounded-xl bg-emerald-950/40 border border-emerald-500/40 text-emerald-200 text-xs flex items-center justify-between shadow-lg">
        <div class="flex items-center gap-2.5">
            <span class="text-base">✓</span>
            <span class="font-medium">{{ session('success') }}</span>
        </div>
        <span class="text-[10px] font-mono text-emerald-400 uppercase">Jejak Audit Forensik Tersimpan</span>
    </div>
    @endif

    @if($errors->any())
    <div class="p-4 rounded-xl bg-rose-950/40 border border-rose-500/40 text-rose-200 text-xs space-y-1">
        <div class="font-bold flex items-center gap-2">
            <span>⚠️</span>
            <span>Terdapat kesalahan validasi peninjauan:</span>
        </div>
        <ul class="list-disc list-inside text-[11px] text-rose-300">
            @foreach($errors->all() as $err)
                <li>{{ $err }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    <!-- PANEL 1: Candidate Summary & Evidence Status Header -->
    <div class="glass-panel p-6 rounded-2xl border border-slate-800 shadow-xl space-y-4">
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-2">
                    <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-teal-500/20 text-teal-300 border border-teal-500/30 uppercase">
                        Tahap 8 Evidence-Based Inspector
                    </span>
                    <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 uppercase">
                        Tahap 9 Multi-Source Intelligence
                    </span>
                    <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-indigo-500/20 text-indigo-300 border border-indigo-500/30 uppercase">
                        Sentinel-1 SAR C-Band
                    </span>
                    <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-purple-500/20 text-purple-300 border border-purple-500/30 uppercase">
                        WGS84 EPSG:4326
                    </span>
                </div>
                <div class="flex flex-wrap items-center gap-2.5 mt-1.5">
                    <h2 class="text-xl sm:text-2xl font-black text-white tracking-wide font-mono">
                        {{ $detection->detection_identifier }}
                    </h2>
                    <!-- Correlation Badge -->
                    @if($detection->correlation_status === 'matched')
                        <span class="px-2.5 py-0.5 rounded text-[10px] font-mono font-bold uppercase bg-emerald-500/20 text-emerald-300 border border-emerald-500/40">
                            Matched AIS
                        </span>
                    @elseif($detection->correlation_status === 'dark_vessel_candidate')
                        <span class="px-2.5 py-0.5 rounded text-[10px] font-mono font-bold uppercase bg-rose-500/20 text-rose-300 border border-rose-500/40">
                            Dark Vessel Candidate
                        </span>
                    @else
                        <span class="px-2.5 py-0.5 rounded text-[10px] font-mono font-bold uppercase bg-amber-500/20 text-amber-300 border border-amber-500/40" title="Cakupan penerima AIS tidak memadai pada lokasi ini">
                            Insufficient AIS Coverage
                        </span>
                    @endif

                    <!-- Review Status Badge -->
                    @if($detection->review_status === \App\Models\SatelliteDetection::REVIEW_POSSIBLE_VESSEL)
                        <span class="px-2.5 py-0.5 rounded text-[10px] font-mono font-bold uppercase bg-teal-500/20 text-teal-300 border border-teal-500/40">
                            ✓ Possible Vessel
                        </span>
                    @elseif($detection->review_status === \App\Models\SatelliteDetection::REVIEW_POSSIBLE_ICE_CLUTTER)
                        <span class="px-2.5 py-0.5 rounded text-[10px] font-mono font-bold uppercase bg-cyan-500/20 text-cyan-300 border border-cyan-500/40">
                            ❄️ Possible Ice / Clutter
                        </span>
                    @elseif($detection->review_status === \App\Models\SatelliteDetection::REVIEW_INCONCLUSIVE)
                        <span class="px-2.5 py-0.5 rounded text-[10px] font-mono font-bold uppercase bg-slate-800 text-slate-300 border border-slate-700">
                            ❓ Inconclusive
                        </span>
                    @elseif($detection->review_status === \App\Models\SatelliteDetection::REVIEW_OTHER)
                        <span class="px-2.5 py-0.5 rounded text-[10px] font-mono font-bold uppercase bg-purple-500/20 text-purple-300 border border-purple-500/40">
                            📝 Other Review
                        </span>
                    @else
                        <span class="px-2.5 py-0.5 rounded text-[10px] font-mono font-bold uppercase bg-amber-500/15 text-amber-400 border border-amber-500/30">
                            ⏳ Pending Review
                        </span>
                    @endif
                </div>

                <div class="text-xs text-slate-400 mt-2 font-mono flex flex-wrap items-center gap-x-4 gap-y-1">
                    <span>Scene: <strong class="text-slate-200">{{ $detection->scene?->scene_id }}</strong></span>
                    <span>Waktu: <strong class="text-slate-200">{{ $detection->detected_at?->format('Y-m-d H:i:s') }} UTC</strong></span>
                    <span>Koordinat: <strong class="text-teal-300">{{ number_format($detection->latitude, 5) }}&deg; S, {{ number_format($detection->longitude, 5) }}&deg; E</strong></span>
                    <span>Peak TCR: <strong class="text-emerald-300">{{ $detection->tcr_db !== null ? number_format($detection->tcr_db, 1) . ' dB' : '-' }}</strong></span>
                    <span>Dimensi Radar: <strong class="text-white">{{ $detection->estimated_length_meters }}m &times; {{ $detection->estimated_width_meters }}m</strong></span>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <a href="{{ route('dark-vessels.index') }}" class="px-3.5 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-medium text-xs transition border border-slate-700 flex items-center gap-1.5">
                    <span>&larr;</span>
                    <span>Daftar Kandidat</span>
                </a>
                <a href="{{ route('api.sar.candidates.evidence', $detection->detection_identifier) }}" target="_blank" class="px-3.5 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-teal-300 font-bold text-xs transition border border-slate-700 flex items-center gap-1.5" title="Metadata JSON Bukti Forensik">
                    <span>🔗</span>
                    <span>API Evidence</span>
                </a>
                <a href="{{ route('api.sar.candidates.track', $detection->detection_identifier) }}" target="_blank" class="px-3.5 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-amber-300 font-bold text-xs transition border border-slate-700 flex items-center gap-1.5" title="GeoJSON Historical Track AIS">
                    <span>🛰️</span>
                    <span>API Track</span>
                </a>
                <a href="{{ route('map.index') }}" class="px-4 py-2 rounded-xl bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold text-xs transition shadow-lg shadow-teal-500/20 flex items-center gap-1.5">
                    <span>🗺️</span>
                    <span>Peta Global</span>
                </a>
            </div>
        </div>

        <!-- THREE CONFIDENCE LEVELS CLASSIFICATION (Tahap 9 - Requirement 5) -->
        <div class="p-3.5 rounded-xl bg-slate-900 border border-slate-800 space-y-2">
            <div class="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Pemilahan Tiga Level Keyakinan Ilmiah (Three Confidence Levels)</div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
                <!-- Level 1: Detection Confidence -->
                <div class="p-2.5 rounded-lg bg-slate-950/60 border border-slate-800 space-y-1">
                    <div class="text-[9px] uppercase font-bold text-indigo-400 flex items-center justify-between">
                        <span>Level 1: Detection Confidence</span>
                        <span class="font-mono">{{ round($detection->confidence_score * 100) }}%</span>
                    </div>
                    <div class="text-[11px] font-bold text-white">Algoritma CA-CFAR Radar</div>
                    <div class="text-[9px] text-slate-400 leading-snug">Probabilitas statistik bahwa piksel bukan puncak gelombang laut latar (Pfa = 1e-6).</div>
                </div>

                <!-- Level 2: Correlation Evidence Score -->
                <div class="p-2.5 rounded-lg bg-slate-950/60 border border-slate-800 space-y-1">
                    <div class="text-[9px] uppercase font-bold text-amber-400 flex items-center justify-between">
                        <span>Level 2: Correlation Evidence</span>
                        <span class="font-mono">
                            {{ isset($evidence['correlation_evidence']['correlation_evidence_score']) && $evidence['correlation_evidence']['correlation_evidence_score'] !== null ? round($evidence['correlation_evidence']['correlation_evidence_score'] * 100) . '%' : 'N/A' }}
                        </span>
                    </div>
                    <div class="text-[11px] font-bold text-white">Hubungan Spasio-Temporal AIS</div>
                    <div class="text-[9px] text-slate-400 leading-snug">
                        {{ isset($evidence['correlation_evidence']['correlation_evidence_score']) && $evidence['correlation_evidence']['correlation_evidence_score'] !== null ? 'Skor fusi berbasis jarak, waktu, dan lintasan kapal.' : 'Ketiadaan stasiun AIS di lintang tinggi (Gap penerima).' }}
                    </div>
                </div>

                <!-- Level 3: Analyst Assessment -->
                <div class="p-2.5 rounded-lg bg-slate-950/60 border border-slate-800 space-y-1">
                    <div class="text-[9px] uppercase font-bold text-teal-400 flex items-center justify-between">
                        <span>Level 3: Analyst Assessment</span>
                        <span class="font-mono text-teal-300">{{ $detection->review_status }}</span>
                    </div>
                    <div class="text-[11px] font-bold text-white">Keputusan Pakar Maritim</div>
                    <div class="text-[9px] text-slate-400 leading-snug">Penilaian komprehensif manusia. Possible Vessel ≠ Confirmed Vessel.</div>
                </div>
            </div>
        </div>

        <!-- Mandatory Scientific & Legal Disclaimers -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-[11px] text-slate-400">
            <div class="p-3 rounded-xl bg-slate-900 border border-slate-800 flex items-start gap-2.5">
                <span class="text-sm shrink-0">⚠️</span>
                <p class="leading-relaxed">
                    <strong class="text-slate-300">Prinsip Validitas Ilmiah:</strong> Radar-object dimensions are estimated from SAR backscatter Point Spread Function (PSF) blooming and do not represent confirmed physical vessel dimensions. Detection ≠ Vessel Confirmation. No AIS ≠ Dark Vessel. Radar Signature ≠ Proof of Vessel Identity. Possible Vessel ≠ Confirmed Vessel.
                </p>
            </div>
            <div class="p-3 rounded-xl bg-slate-900 border border-slate-800 flex items-start gap-2.5">
                <span class="text-sm shrink-0">❄️</span>
                <p class="leading-relaxed">
                    <strong class="text-cyan-300">Southern Ocean Polar Caution:</strong> Koordinat {{ number_format($detection->latitude, 2) }}° LS berada di zona es musiman Samudra Selatan. {{ $evidence['sea_ice_status']['description'] }}
                </p>
            </div>
        </div>
    </div>

    <!-- MULTI-SOURCE INTELLIGENCE EVIDENCE MATRIX (Tahap 9 - Requirement 6) -->
    <div class="glass-panel p-5 rounded-2xl border border-slate-800 shadow-xl space-y-3">
        <div class="flex items-center justify-between border-b border-slate-800 pb-2.5">
            <h3 class="font-bold uppercase tracking-wider text-teal-300 text-xs flex items-center gap-2">
                <span>🧬</span>
                <span>Multi-Source Maritime Intelligence Evidence Matrix</span>
            </h3>
            <span class="text-[10px] font-mono text-slate-400">Version 2.0-GFVIP</span>
        </div>
        <div class="overflow-x-auto text-xs">
            <table class="w-full text-left">
                <thead class="bg-slate-900/90 text-slate-400 uppercase tracking-wider text-[10px] border-b border-slate-800 font-mono">
                    <tr>
                        <th class="py-2.5 px-3">Sumber Data</th>
                        <th class="py-2.5 px-3">Tipe Sensor / Kanal</th>
                        <th class="py-2.5 px-3">Status Operasional</th>
                        <th class="py-2.5 px-3">Bukti & Metadata Terverifikasi</th>
                        <th class="py-2.5 px-3">Provenance / Gateway</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60 font-mono text-[11px]">
                    @foreach($evidence['matrix'] as $row)
                    <tr class="hover:bg-slate-900/40 transition">
                        <td class="py-2.5 px-3 font-bold text-white">{{ $row['source'] }}</td>
                        <td class="py-2.5 px-3 text-slate-400">{{ $row['type'] }}</td>
                        <td class="py-2.5 px-3">
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold border {{ $row['status_class'] }}">
                                {{ $row['status'] }}
                            </span>
                        </td>
                        <td class="py-2.5 px-3 text-slate-200">{{ $row['evidence'] }}</td>
                        <td class="py-2.5 px-3 text-slate-500 text-[10px]">{{ $row['provenance'] }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <!-- MAIN 2-COLUMN INSPECTOR LAYOUT -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

        <!-- LEFT COLUMN: SAR Image Evidence, MapLibre AIS Track, and Timeline (7 COLS) -->
        <div class="lg:col-span-7 space-y-6">

            <!-- PANEL 2: SAR IMAGE EVIDENCE VIEWER -->
            <div class="glass-panel p-5 rounded-2xl border border-slate-800 space-y-4 shadow-xl">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-800 pb-3">
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="font-bold uppercase tracking-wider text-rose-400 text-xs flex items-center gap-2">
                                <span>🎯</span>
                                <span>Panel 2: Sentinel-1 SAR Evidence Viewer</span>
                            </h3>
                            <span class="px-1.5 py-0.2 rounded text-[9px] font-mono font-bold bg-rose-500/20 text-rose-300 border border-rose-500/30">
                                61&times;61 px (2.44 km ext.)
                            </span>
                        </div>
                        <p class="text-[11px] text-slate-400 mt-0.5">
                            Ekstraksi deterministik piksel aktual dari GeoTIFF SAFE (<code class="text-slate-300 font-mono text-[10px]">s1c-ew-grd-hh-*.tiff</code>).
                        </p>
                    </div>

                    <!-- View Mode Tabs -->
                    <div class="flex items-center p-1 rounded-xl bg-slate-900 border border-slate-800 text-xs font-mono shrink-0">
                        <button type="button" onclick="switchSarTab('raw')" id="tab-btn-raw" 
                                class="px-2.5 py-1 rounded-lg font-bold transition bg-teal-500/20 text-teal-300 border border-teal-500/30">
                            Raw Amplitude
                        </button>
                        <button type="button" onclick="switchSarTab('sigma0-linear')" id="tab-btn-sigma0-linear" 
                                class="px-2.5 py-1 rounded-lg text-slate-400 hover:text-white transition">
                            Sigma⁰ Linear
                        </button>
                        <button type="button" onclick="switchSarTab('sigma0-db')" id="tab-btn-sigma0-db" 
                                class="px-2.5 py-1 rounded-lg text-slate-400 hover:text-white transition">
                            Sigma⁰ dB
                        </button>
                    </div>
                </div>

                @if($evidence['has_evidence'])
                <!-- Interactive Evidence Display Canvas / Image Viewer -->
                <div class="relative w-full aspect-square max-w-[460px] mx-auto rounded-xl overflow-hidden bg-slate-950 border border-slate-800 shadow-2xl flex items-center justify-center group" id="sar-viewer-container">
                    
                    <!-- Evidence Images for Each Representation -->
                    <img id="sar-img-raw" src="{{ $evidence['urls']['raw_amplitude'] }}" alt="SAR Raw Amplitude" 
                         class="sar-evidence-img w-full h-full object-contain pixelated transition-transform duration-200">
                    <img id="sar-img-sigma0-linear" src="{{ $evidence['urls']['sigma0_linear'] }}" alt="SAR Sigma0 Linear" 
                         class="sar-evidence-img w-full h-full object-contain pixelated hidden transition-transform duration-200">
                    <img id="sar-img-sigma0-db" src="{{ $evidence['urls']['sigma0_db'] }}" alt="SAR Sigma0 dB" 
                         class="sar-evidence-img w-full h-full object-contain pixelated hidden transition-transform duration-200">

                    <!-- Candidate Centroid Reticle / Crosshair -->
                    <div class="absolute inset-0 pointer-events-none flex items-center justify-center">
                        <div class="relative w-12 h-12 flex items-center justify-center">
                            <!-- Reticle Ring -->
                            <div class="w-10 h-10 rounded-full border-2 border-dashed border-teal-400/80 animate-spin-slow"></div>
                            <!-- Center Dot -->
                            <div class="absolute w-2 h-2 rounded-full bg-rose-500 shadow-lg shadow-rose-500/80"></div>
                            <!-- Crosshair ticks -->
                            <div class="absolute w-full h-0.5 bg-teal-400/40"></div>
                            <div class="absolute h-full w-0.5 bg-teal-400/40"></div>
                        </div>
                    </div>

                    <!-- Top Left Label Badge -->
                    <div class="absolute top-2.5 left-2.5 px-2 py-1 rounded bg-slate-950/80 backdrop-blur-md border border-slate-800 text-[10px] font-mono text-teal-300 pointer-events-none">
                        <span id="sar-mode-label">Mode: Raw Amplitude DN</span>
                    </div>

                    <!-- Top Right: North & Flight Direction Indicator -->
                    <div class="absolute top-2.5 right-2.5 p-1.5 rounded bg-slate-950/80 backdrop-blur-md border border-slate-800 text-[9px] font-mono text-slate-300 pointer-events-none flex flex-col items-center">
                        <span class="text-teal-400 font-black">&uarr; N</span>
                        <span class="text-[8px] text-slate-500">True North</span>
                    </div>

                    <!-- Bottom Left: Scale Indicator (40m per pixel) -->
                    <div class="absolute bottom-2.5 left-2.5 px-2.5 py-1 rounded bg-slate-950/80 backdrop-blur-md border border-slate-800 text-[9px] font-mono text-slate-300 pointer-events-none flex items-center gap-1.5">
                        <div class="w-10 h-1 bg-teal-400 rounded-full"></div>
                        <span>400 m (10 px)</span>
                    </div>

                    <!-- Bottom Right: Local Centroid Coordinates -->
                    <div class="absolute bottom-2.5 right-2.5 px-2.5 py-1 rounded bg-slate-950/80 backdrop-blur-md border border-slate-800 text-[9px] font-mono text-slate-300 pointer-events-none">
                        Px [{{ $evidence['metadata']['global_pixel_coordinates']['line_row'] ?? 82 }}, {{ $evidence['metadata']['global_pixel_coordinates']['sample_col'] ?? 6276 }}]
                    </div>
                </div>

                <!-- Provenance & Scientific Metrics Sub-Panel -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5 text-xs text-slate-300 pt-1">
                    <div class="p-2.5 rounded-xl bg-slate-900/80 border border-slate-800">
                        <div class="text-[9px] uppercase font-bold text-slate-500">Peak Raw Amplitude</div>
                        <div class="font-mono text-white text-sm font-bold mt-0.5">
                            {{ $evidence['metadata']['radar_measurements']['peak_raw_dn'] ?? 'N/A' }} DN
                        </div>
                    </div>
                    <div class="p-2.5 rounded-xl bg-slate-900/80 border border-slate-800">
                        <div class="text-[9px] uppercase font-bold text-slate-500">Peak Calibrated Backscatter</div>
                        <div class="font-mono text-emerald-300 text-sm font-bold mt-0.5">
                            {{ isset($evidence['metadata']['radar_measurements']['peak_sigma0_db']) ? number_format($evidence['metadata']['radar_measurements']['peak_sigma0_db'], 2) . ' dB' : 'N/A' }}
                        </div>
                    </div>
                    <div class="p-2.5 rounded-xl bg-slate-900/80 border border-slate-800">
                        <div class="text-[9px] uppercase font-bold text-slate-500">Clutter Latar Belakang</div>
                        <div class="font-mono text-slate-300 text-sm font-bold mt-0.5">
                            {{ isset($evidence['metadata']['radar_measurements']['background_clutter_db']) ? number_format($evidence['metadata']['radar_measurements']['background_clutter_db'], 2) . ' dB' : 'N/A' }}
                        </div>
                    </div>
                    <div class="p-2.5 rounded-xl bg-slate-900/80 border border-slate-800">
                        <div class="text-[9px] uppercase font-bold text-slate-500">Resolusi Spasial Efektif</div>
                        <div class="font-mono text-white text-sm font-bold mt-0.5">
                            93m &times; 87m
                        </div>
                    </div>
                </div>
                @else
                <div class="p-8 rounded-xl bg-slate-900/40 border border-slate-800 text-center text-slate-400 space-y-2">
                    <span class="text-3xl block">📷</span>
                    <div class="font-bold text-white text-sm">Crop Raster SAR Belum Tersedia</div>
                    <p class="text-xs text-slate-500 max-w-md mx-auto">
                        File citra crop belum diekstraksi dari file SAFE mentah. Jalankan prosesor evidence untuk mengekstraksi piksel aktual kandidat ini.
                    </p>
                </div>
                @endif
            </div>

            <!-- PANEL 3: MAPLIBRE SPATIAL CONTEXT & AIS HISTORICAL TRACK (Tahap 9 - Requirement 8) -->
            <div class="glass-panel p-5 rounded-2xl border border-slate-800 space-y-3.5 shadow-xl">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-800 pb-2.5">
                    <div>
                        <h3 class="font-bold uppercase tracking-wider text-teal-400 text-xs flex items-center gap-2">
                            <span>🗺️</span>
                            <span>Panel 3: Konteks Spasial MapLibre & Lintasan AIS</span>
                        </h3>
                        <p class="text-[10px] text-slate-400 mt-0.5">ESRI World Dark Gray Canvas &bull; Footprint SAR &bull; Lintasan Posisi AIS Historis</p>
                    </div>

                    <!-- Controls: AIS Track Toggle & Temporal Window Selector -->
                    <div class="flex items-center gap-2 text-xs font-mono shrink-0">
                        <label class="flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-slate-900 border border-slate-700 cursor-pointer">
                            <input type="checkbox" id="toggle-ais-track" checked class="rounded bg-slate-800 border-slate-600 text-amber-500 focus:ring-0">
                            <span class="text-amber-300 font-bold text-[11px]">AIS Track</span>
                        </label>

                        <select id="select-track-window" class="px-2 py-1 rounded-lg bg-slate-900 border border-slate-700 text-slate-300 text-[11px] focus:outline-none focus:border-teal-500">
                            <option value="30">&plusmn;30 menit</option>
                            <option value="60" selected>&plusmn;60 menit (Standar)</option>
                            <option value="180">&plusmn;180 menit (3 Jam)</option>
                            <option value="360">&plusmn;360 menit (6 Jam)</option>
                            <option value="1440">&plusmn;24 jam (1 Hari)</option>
                        </select>
                    </div>
                </div>

                <div class="relative w-full h-84 rounded-xl overflow-hidden border border-slate-800 shadow-inner">
                    <div id="candidate-detail-map" class="w-full h-84"></div>
                    <div class="absolute bottom-2 left-2 px-2.5 py-1 rounded bg-slate-950/80 backdrop-blur-md border border-slate-800 text-[10px] font-mono text-teal-300 pointer-events-none">
                        Lat: {{ number_format($detection->latitude, 5) }}, Lng: {{ number_format($detection->longitude, 5) }}
                    </div>
                    <div id="track-status-badge" class="absolute top-2 left-2 px-2.5 py-1 rounded bg-slate-950/80 backdrop-blur-md border border-slate-800 text-[10px] font-mono text-amber-300">
                        Memuat lintasan AIS...
                    </div>
                </div>

                <div class="flex items-center justify-between text-[10px] font-mono text-slate-400 px-1">
                    <div class="flex items-center gap-3">
                        <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full bg-rose-500"></span> Target SAR</span>
                        <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full bg-cyan-400"></span> AIS Sebelum</span>
                        <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full bg-amber-400"></span> AIS Terdekat</span>
                        <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full bg-indigo-400"></span> AIS Sesudah</span>
                    </div>
                    <span class="text-slate-500">WGS84 EPSG:4326</span>
                </div>
            </div>

            <!-- PANEL 4: AIS CORRELATION & COVERAGE ASSESSMENT -->
            <div class="glass-panel p-5 rounded-2xl border border-slate-800 space-y-3.5 text-xs shadow-xl">
                <div class="flex items-center justify-between border-b border-slate-800 pb-2.5">
                    <h3 class="font-bold uppercase tracking-wider text-amber-400 flex items-center gap-2">
                        <span>📡</span>
                        <span>Panel 4: Evaluasi Korelasi Telemetri AIS Independen</span>
                    </h3>
                    <span class="text-[10px] font-mono px-2 py-0.5 rounded bg-amber-500/20 text-amber-300 border border-amber-500/30 uppercase">
                        {{ $aisComparison['reception_status'] }}
                    </span>
                </div>

                @if($detection->correlation_status === 'matched')
                <!-- Matched AIS Details -->
                <div class="p-3.5 rounded-xl bg-emerald-950/20 border border-emerald-500/30 space-y-2 text-slate-300">
                    <div class="font-bold text-emerald-300 flex items-center gap-2">
                        <span>✓</span>
                        <span>Korelasi AIS Terverifikasi (Matched Vessel):</span>
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2.5 text-[11px] font-mono mt-1">
                        <div>MMSI: <strong class="text-white">{{ $aisComparison['closest_vessel']?->mmsi ?? '-' }}</strong></div>
                        <div>Nama: <strong class="text-white">{{ $aisComparison['closest_vessel']?->name ?? '-' }}</strong></div>
                        <div>Bendera: <strong class="text-teal-300">{{ $aisComparison['closest_vessel']?->flag_info['flag_emoji'] }} {{ $aisComparison['closest_vessel']?->flag_info['country_name'] }} ({{ $aisComparison['closest_vessel']?->flag_info['iso2'] }})</strong></div>
                        <div>Status Bendera: <strong class="text-white">{{ $aisComparison['closest_vessel']?->flag_info['status'] }}</strong></div>
                        <div>Jarak: <strong class="text-emerald-300">{{ $aisComparison['distance_meters'] }} m</strong></div>
                        <div>Selisih Waktu: <strong class="text-emerald-300">{{ $aisComparison['time_diff_minutes'] }} mnt</strong></div>
                    </div>
                </div>
                @else
                <!-- No Match / Insufficient Coverage Explanation -->
                <div class="p-3.5 rounded-xl bg-amber-950/20 border border-amber-500/30 space-y-2 text-[11px] text-amber-200 leading-relaxed">
                    <div class="font-bold text-amber-300 flex items-center gap-1.5">
                        <span>⚖️</span>
                        <span>No AIS correlation available — Ketiadaan Cakupan Penerima AIS:</span>
                    </div>
                    <p class="text-slate-300">
                        {{ $aisComparison['coverage_description'] }}
                    </p>
                    <p class="text-amber-200 font-semibold pt-1 border-t border-amber-500/20">
                        Absence of AIS data does not independently establish that the target is a dark vessel. Status dark vessel memerlukan bukti aktif bahwa kapal berada di zona berjangkauan penerima dan sengaja mematikan transponder AIS.
                    </p>
                </div>
                @endif

                <!-- Correlation Evidence Score Breakdown Card -->
                <div class="p-3.5 rounded-xl bg-slate-900 border border-slate-800 space-y-2 text-slate-300">
                    <div class="flex items-center justify-between">
                        <span class="font-bold text-white text-[11px] uppercase tracking-wide">Correlation Evidence Score (Algoritmik)</span>
                        <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-amber-500/20 text-amber-300 border border-amber-500/30">
                            {{ isset($evidence['correlation_evidence']['correlation_evidence_score']) && $evidence['correlation_evidence']['correlation_evidence_score'] !== null ? round($evidence['correlation_evidence']['correlation_evidence_score'] * 100) . '%' : 'not_available' }}
                        </span>
                    </div>
                    <p class="text-[10px] text-slate-400">
                        Skor keterkaitan bukti multikriteria: kedekatan spasial (40%), temporal (25%), konsistensi arah/COG (15%), kecepatan/SOG (10%), dan kontinuitas lintasan (10%). Tidak mengasumsikan data fiktif bila AIS tidak tersedia.
                    </p>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 pt-1 text-[10px] font-mono">
                        <div>Lintasan: <strong class="text-white">{{ $evidence['correlation_evidence']['track_continuity']['trajectory'] ?? 'no_relevant_track' }}</strong></div>
                        <div>Jarak Terdekat: <strong class="text-white">{{ isset($evidence['correlation_evidence']['spatial']['nearest_ais_distance_meters']) && $evidence['correlation_evidence']['spatial']['nearest_ais_distance_meters'] !== null ? round($evidence['correlation_evidence']['spatial']['nearest_ais_distance_meters']) . ' m' : 'N/A' }}</strong></div>
                        <div>Selisih Waktu: <strong class="text-white">{{ isset($evidence['correlation_evidence']['temporal']['time_difference_minutes']) && $evidence['correlation_evidence']['temporal']['time_difference_minutes'] !== null ? $evidence['correlation_evidence']['temporal']['time_difference_minutes'] . ' mnt' : 'N/A' }}</strong></div>
                        <div>Konsistensi Arah: <strong class="text-white">{{ $evidence['correlation_evidence']['motion']['heading_consistency'] ?? 'not_available' }}</strong></div>
                    </div>
                </div>
            </div>

            <!-- EVIDENCE TIMELINE (Tahap 9 - Requirement 10) -->
            <div class="glass-panel p-5 rounded-2xl border border-slate-800 space-y-3.5 shadow-xl">
                <div class="flex items-center justify-between border-b border-slate-800 pb-2.5">
                    <h3 class="font-bold uppercase tracking-wider text-slate-200 text-xs flex items-center gap-2">
                        <span>⏱️</span>
                        <span>Evidence Timeline — Kronologi Forensik Multi-Source</span>
                    </h3>
                    <span class="text-[10px] font-mono text-teal-400">Immutable Audit Sequence</span>
                </div>

                <div class="space-y-3">
                    @foreach($evidence['timeline'] as $tl)
                    <div class="p-3 rounded-xl bg-slate-900/90 border border-slate-800 space-y-1.5 text-xs">
                        <div class="flex items-center justify-between">
                            <span class="font-bold text-white text-[11px]">{{ $tl['stage'] }}</span>
                            <span class="px-2 py-0.5 rounded text-[9px] font-mono font-bold uppercase border {{ $tl['badge'] }}">
                                {{ $tl['status'] }}
                            </span>
                        </div>
                        <div class="text-[10px] font-mono text-slate-400">{{ $tl['timestamp'] }}</div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-1 text-[10px] font-mono text-slate-300 pt-1 border-t border-slate-800/60">
                            @foreach($tl['items'] as $label => $val)
                            <div><span class="text-slate-500">{{ $label }}:</span> <strong class="text-slate-200">{{ $val }}</strong></div>
                            @endforeach
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>

        </div>

        <!-- RIGHT COLUMN: Evidence Checklist, Analyst Decision & Audit Trail (5 COLS) -->
        <div class="lg:col-span-5 space-y-6">

            <!-- PANEL 5: EVIDENCE ASSESSMENT CHECKLIST (Tahap 9 - Requirement 14: Yes / No / Unknown) -->
            <div class="glass-panel p-5 rounded-2xl border border-slate-800 space-y-3.5 text-xs shadow-xl">
                <div class="flex items-center justify-between border-b border-slate-800 pb-2.5">
                    <h3 class="font-bold uppercase tracking-wider text-indigo-400 flex items-center gap-2">
                        <span>☑️</span>
                        <span>Panel 5: Checklist Bukti Analis Maritim</span>
                    </h3>
                    <span class="text-[10px] font-mono text-slate-400">Yes / No / Unknown</span>
                </div>

                <div class="space-y-3">
                    @foreach($evidence['checklist'] as $item)
                    <div class="p-3 rounded-xl bg-slate-900/80 border border-slate-800 space-y-2">
                        <div class="space-y-0.5">
                            <div class="text-[11px] font-bold text-slate-200 leading-tight">
                                {{ $item['label'] }}
                            </div>
                            <div class="text-[9px] font-mono text-slate-400">
                                Basis: {{ $item['basis'] }}
                            </div>
                        </div>

                        <!-- 3-Choice Radio Options: Yes / No / Unknown -->
                        <div class="flex items-center gap-2 text-[10px] font-mono pt-1">
                            <label class="flex-1 flex items-center justify-center gap-1.5 py-1 px-2 rounded-lg border cursor-pointer transition
                                {{ $item['value'] === 'yes' ? 'bg-emerald-500/20 text-emerald-300 border-emerald-500/40' : 'bg-slate-950/60 text-slate-400 border-slate-800 hover:border-slate-700' }}">
                                <input type="radio" name="checklist[{{ $item['id'] }}]" value="yes" form="analyst-decision-form"
                                       {{ $item['value'] === 'yes' ? 'checked' : '' }} {{ !$canReview ? 'disabled' : '' }} class="sr-only">
                                <span>✓ Yes</span>
                            </label>

                            <label class="flex-1 flex items-center justify-center gap-1.5 py-1 px-2 rounded-lg border cursor-pointer transition
                                {{ $item['value'] === 'no' ? 'bg-rose-500/20 text-rose-300 border-rose-500/40' : 'bg-slate-950/60 text-slate-400 border-slate-800 hover:border-slate-700' }}">
                                <input type="radio" name="checklist[{{ $item['id'] }}]" value="no" form="analyst-decision-form"
                                       {{ $item['value'] === 'no' ? 'checked' : '' }} {{ !$canReview ? 'disabled' : '' }} class="sr-only">
                                <span>✕ No</span>
                            </label>

                            <label class="flex-1 flex items-center justify-center gap-1.5 py-1 px-2 rounded-lg border cursor-pointer transition
                                {{ $item['value'] === 'unknown' ? 'bg-amber-500/20 text-amber-300 border-amber-500/40' : 'bg-slate-950/60 text-slate-400 border-slate-800 hover:border-slate-700' }}">
                                <input type="radio" name="checklist[{{ $item['id'] }}]" value="unknown" form="analyst-decision-form"
                                       {{ $item['value'] === 'unknown' ? 'checked' : '' }} {{ !$canReview ? 'disabled' : '' }} class="sr-only">
                                <span>? Unknown</span>
                            </label>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>

            <!-- PANEL 6: ANALYST DECISION FORM (Requirement 15: Minimum 20 Characters Rationale) -->
            <div class="glass-panel p-5 rounded-2xl border border-teal-500/30 bg-teal-500/5 space-y-4 shadow-xl">
                <div class="flex items-center justify-between border-b border-slate-800 pb-2.5">
                    <h3 class="font-bold uppercase tracking-wider text-teal-300 text-xs flex items-center gap-2">
                        <span>⚖️</span>
                        <span>Panel 6: Keputusan & Justifikasi Analis</span>
                    </h3>
                    <span class="text-[10px] font-mono text-teal-400">
                        {{ auth()->user()?->role ?? 'Guest' }}
                    </span>
                </div>

                @if(!$canReview)
                <!-- Viewer Read-Only Banner -->
                <div class="p-3 rounded-xl bg-slate-900 border border-slate-800 text-[11px] text-slate-400 space-y-1">
                    <div class="text-slate-200 font-bold flex items-center gap-1.5">
                        <span>🔒</span>
                        <span>Mode Akses Terbatas (Read-Only):</span>
                    </div>
                    <p>
                        Akun Anda memiliki peran <strong>Viewer</strong>. Hanya Analis Intelijen Maritim (<em class="text-teal-300">analyst</em>) dan Administrator (<em class="text-teal-300">super_admin</em>) yang memiliki wewenang mencatat atau mengubah keputusan peninjauan.
                    </p>
                </div>
                @endif

                <form id="analyst-decision-form" method="POST" action="{{ route('dark-vessels.review', $detection->id) }}" class="space-y-4 text-xs">
                    @csrf

                    <!-- Status Selection -->
                    <div>
                        <label class="block text-[11px] uppercase font-bold text-slate-300 mb-1.5">
                            Status Keputusan Analis <span class="text-rose-400">*</span>
                        </label>
                        <select name="review_status" {{ !$canReview ? 'disabled' : '' }}
                                class="w-full px-3 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-slate-200 focus:outline-none focus:border-teal-500 text-xs font-medium">
                            @foreach($reviewStatuses as $val => $label)
                                <option value="{{ $val }}" {{ old('review_status', $detection->review_status) == $val ? 'selected' : '' }}>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Rationale (Required, min 20 chars) -->
                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="text-[11px] uppercase font-bold text-slate-300">
                                Alasan Keputusan & Justifikasi Teknis <span class="text-rose-400">*</span>
                            </label>
                            <span class="text-[10px] font-mono text-slate-500">Min. 20 karakter</span>
                        </div>
                        <textarea name="review_rationale" id="review_rationale" rows="4" minlength="20" required {{ !$canReview ? 'disabled' : '' }}
                                  placeholder="Jelaskan alasan penetapan keputusan ini secara terperinci (minimal 20 karakter: misal analisis morfologi crop citra SAR, rasio TCR, ketidaksesuaian es laut polar, dll)..." 
                                  class="w-full px-3 py-2 rounded-xl bg-slate-900 border border-slate-700 text-slate-200 focus:outline-none focus:border-teal-500 text-xs leading-relaxed">{{ old('review_rationale', $detection->review_rationale) }}</textarea>
                    </div>

                    <!-- Evidence Notes -->
                    <div>
                        <label class="block text-[11px] uppercase font-bold text-slate-400 mb-1">
                            Catatan Bukti Morfologi Citra SAR
                        </label>
                        <input type="text" name="evidence_notes" value="{{ old('evidence_notes', $detection->review_metadata['evidence_notes'] ?? '') }}" {{ !$canReview ? 'disabled' : '' }}
                               placeholder="Contoh: Titik pantul terang terisolasi (peak DN 874), rasio kontras 15.9 dB..." 
                               class="w-full px-3 py-2 rounded-xl bg-slate-900 border border-slate-700 text-slate-200 focus:outline-none focus:border-teal-500 text-xs">
                    </div>

                    <!-- Limitation Notes -->
                    <div>
                        <label class="block text-[11px] uppercase font-bold text-slate-400 mb-1">
                            Keterbatasan & Catatan Lingkungan Polar
                        </label>
                        <input type="text" name="limitation_notes" value="{{ old('limitation_notes', $detection->review_metadata['limitation_notes'] ?? '') }}" {{ !$canReview ? 'disabled' : '' }}
                               placeholder="Contoh: Lintang 59.5S Samudra Selatan, potensi drifting growlers..." 
                               class="w-full px-3 py-2 rounded-xl bg-slate-900 border border-slate-700 text-slate-200 focus:outline-none focus:border-teal-500 text-xs">
                    </div>

                    @if($canReview)
                    <div class="pt-2">
                        <button type="submit" class="w-full py-2.5 rounded-xl bg-teal-500 hover:bg-teal-400 text-slate-950 font-black text-xs transition shadow-lg shadow-teal-500/20 flex items-center justify-center gap-2">
                            <span>💾</span>
                            <span>Simpan Keputusan & Catat ke Audit Trail</span>
                        </button>
                    </div>
                    @endif
                </form>
            </div>

            <!-- IMMUTABLE AUDIT TRAIL TIMELINE -->
            <div class="glass-panel p-5 rounded-2xl border border-slate-800 space-y-4 text-xs shadow-xl">
                <div class="flex items-center justify-between border-b border-slate-800 pb-2.5">
                    <h3 class="font-bold uppercase tracking-wider text-slate-300 flex items-center gap-2">
                        <span>📜</span>
                        <span>Riwayat Keputusan Forensik ({{ count($auditLogs) }})</span>
                    </h3>
                    <span class="text-[10px] font-mono text-teal-400">Immutable</span>
                </div>

                @if(count($auditLogs) > 0)
                <div class="space-y-3">
                    @foreach($auditLogs as $log)
                    <div class="p-3 rounded-xl bg-slate-900/90 border border-slate-800 space-y-2">
                        <div class="flex items-center justify-between">
                            <span class="font-bold text-teal-300">
                                {{ $log->details['reviewer_name'] ?? ($log->user?->name ?? 'Analyst') }}
                            </span>
                            <span class="text-[10px] font-mono text-slate-400">
                                {{ $log->created_at?->format('Y-m-d H:i') }} UTC
                            </span>
                        </div>

                        <div class="flex items-center gap-2 text-[10px] font-mono">
                            <span class="text-slate-400">{{ $log->details['previous_status'] ?? 'pending' }}</span>
                            <span class="text-teal-400">&rarr;</span>
                            <span class="px-1.5 py-0.5 rounded bg-teal-500/20 text-teal-300 border border-teal-500/30 uppercase font-bold">
                                {{ $log->details['new_status'] ?? $log->action }}
                            </span>
                            <span class="text-slate-500 ml-auto">{{ $log->details['reviewer_role'] ?? 'analyst' }}</span>
                        </div>

                        @if(!empty($log->details['rationale']))
                        <div class="p-2 rounded bg-slate-950/60 border border-slate-800/80 text-[11px] text-slate-300 leading-relaxed font-sans">
                            {{ $log->details['rationale'] }}
                        </div>
                        @endif

                        @if(!empty($log->details['evidence_notes']))
                        <div class="text-[10px] text-slate-400 font-mono">
                            <strong>Bukti:</strong> {{ $log->details['evidence_notes'] }}
                        </div>
                        @endif
                    </div>
                    @endforeach
                </div>
                @else
                <div class="p-4 rounded-xl bg-slate-900/40 border border-slate-800 text-center text-slate-500 text-[11px]">
                    Belum ada riwayat peninjauan manusia yang tercatat untuk kandidat ini.
                </div>
                @endif
            </div>

        </div>

    </div>

</div>

<!-- JavaScript for SAR Tab Switching & MapLibre AIS Track Integration -->
<script>
function switchSarTab(mode) {
    const modes = ['raw', 'sigma0-linear', 'sigma0-db'];
    const labels = {
        'raw': 'Mode: Raw Amplitude DN',
        'sigma0-linear': 'Mode: Sigma⁰ Linear Backscatter (|DN|² / A_sigma²)',
        'sigma0-db': 'Mode: Sigma⁰ Decibel (10 * log10(sigma0))'
    };

    modes.forEach(m => {
        const img = document.getElementById('sar-img-' + m);
        const btn = document.getElementById('tab-btn-' + m);
        if (img) img.classList.add('hidden');
        if (btn) {
            btn.className = 'px-2.5 py-1 rounded-lg text-slate-400 hover:text-white transition';
        }
    });

    const activeImg = document.getElementById('sar-img-' + mode);
    const activeBtn = document.getElementById('tab-btn-' + mode);
    const labelEl = document.getElementById('sar-mode-label');

    if (activeImg) activeImg.classList.remove('hidden');
    if (activeBtn) {
        activeBtn.className = 'px-2.5 py-1 rounded-lg font-bold transition bg-teal-500/20 text-teal-300 border border-teal-500/30';
    }
    if (labelEl && labels[mode]) {
        labelEl.innerText = labels[mode];
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const candidateLat = {{ $detection->latitude }};
    const candidateLng = {{ $detection->longitude }};
    const footprintGeoJson = {!! $detection->scene?->footprint_geojson ?? 'null' !!};
    const candidateId = '{{ $detection->id }}';

    let aisTrackMarkers = [];

    // ESRI World Dark Gray Canvas Style (Consistent with Global Map & Zero Deprecated CARTO)
    const esriDarkStyle = {
        version: 8,
        sources: {
            'esri-dark': {
                type: 'raster',
                tiles: [
                    'https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Dark_Gray_Base/MapServer/tile/{z}/{y}/{x}'
                ],
                tileSize: 256,
                attribution: 'Tiles &copy; Esri &mdash; Esri, DeLorme, NAVTEQ'
            },
            'esri-labels': {
                type: 'raster',
                tiles: [
                    'https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Dark_Gray_Reference/MapServer/tile/{z}/{y}/{x}'
                ],
                tileSize: 256
            }
        },
        layers: [
            { id: 'esri-base-layer', type: 'raster', source: 'esri-dark', minzoom: 0, maxzoom: 19 },
            { id: 'esri-labels-layer', type: 'raster', source: 'esri-labels', minzoom: 0, maxzoom: 19 }
        ]
    };

    function initCandidateDetailMap() {
        const maplibregl = window.maplibregl;
        if (!maplibregl) {
            setTimeout(initCandidateDetailMap, 50);
            return;
        }

        const map = new maplibregl.Map({
        container: 'candidate-detail-map',
        style: esriDarkStyle,
        center: [candidateLng, candidateLat],
        zoom: 7,
        attributionControl: false
    });

    map.addControl(new maplibregl.NavigationControl({ showCompass: true }), 'top-right');

    map.on('error', function(e) {
        console.warn('[Evidence Inspector Map Error]:', e.error ? e.error.message : e);
    });

    map.on('load', function() {
        map.resize();

        // Add Footprint Layer
        if (footprintGeoJson) {
            let geoData = footprintGeoJson;
            if (typeof geoData === 'string') {
                try { geoData = JSON.parse(geoData); } catch (e) {}
            }

            map.addSource('scene-footprint', {
                type: 'geojson',
                data: geoData
            });

            map.addLayer({
                id: 'footprint-fill',
                type: 'fill',
                source: 'scene-footprint',
                paint: {
                    'fill-color': '#06b6d4',
                    'fill-opacity': 0.08
                }
            });

            map.addLayer({
                id: 'footprint-line',
                type: 'line',
                source: 'scene-footprint',
                paint: {
                    'line-color': '#06b6d4',
                    'line-width': 1.5,
                    'line-dasharray': [3, 2]
                }
            });
        }

        // Add SAR Target Primary Reticle Marker
        const el = document.createElement('div');
        el.className = 'w-7 h-7 rounded-full bg-rose-500/30 border-2 border-rose-500 flex items-center justify-center text-[10px] text-white font-bold shadow-lg shadow-rose-500/50 animate-pulse cursor-pointer';
        el.innerHTML = '🎯';

        new maplibregl.Marker(el)
            .setLngLat([candidateLng, candidateLat])
            .setPopup(new maplibregl.Popup({ offset: 25 }).setHTML(`
                <div class="p-1 text-xs font-mono text-slate-200 space-y-1">
                    <div class="font-bold text-rose-400 flex items-center gap-1">
                        <span>🎯 SAR Target:</span>
                        <span>{{ $detection->detection_identifier }}</span>
                    </div>
                    <div>Koordinat: {{ number_format($detection->latitude, 4) }}S, {{ number_format($detection->longitude, 4) }}E</div>
                    <div>Peak TCR: {{ $detection->tcr_db ?? '-' }} dB</div>
                    <div>Dimensi: {{ $detection->estimated_length_meters }}m &times; {{ $detection->estimated_width_meters }}m</div>
                    <div>Status: <span class="text-amber-300">{{ $detection->review_status }}</span></div>
                    <div class="text-[10px] text-slate-400 italic">Target deteksi utama radar</div>
                </div>
            `))
            .addTo(map);

        // Fetch & render AIS Track
        loadAisTrack();

        setTimeout(() => map.resize(), 300);
        setTimeout(() => map.resize(), 800);
    });

    function loadAisTrack() {
        const windowVal = document.getElementById('select-track-window').value;
        const statusBadge = document.getElementById('track-status-badge');
        statusBadge.innerText = 'Memuat lintasan ±' + windowVal + 'm...';
        statusBadge.className = 'absolute top-2 left-2 px-2.5 py-1 rounded bg-slate-950/80 backdrop-blur-md border border-slate-800 text-[10px] font-mono text-amber-300';

        // Clear existing markers
        aisTrackMarkers.forEach(m => m.remove());
        aisTrackMarkers = [];

        // Remove existing source/layer if any
        if (map.getLayer('ais-track-line-layer')) map.removeLayer('ais-track-line-layer');
        if (map.getSource('ais-track-source')) map.removeSource('ais-track-source');

        fetch(`/api/sar/candidates/${candidateId}/track?window=${windowVal}`)
            .then(res => res.json())
            .then(geoJson => {
                const count = geoJson.properties?.position_count ?? 0;
                if (count === 0) {
                    statusBadge.innerText = `0 posisi AIS dalam ±${windowVal} mnt (Coverage Gap)`;
                    statusBadge.className = 'absolute top-2 left-2 px-2.5 py-1 rounded bg-slate-950/80 backdrop-blur-md border border-slate-800 text-[10px] font-mono text-slate-400';
                    return;
                }

                statusBadge.innerText = `${count} posisi AIS terpantau (±${windowVal} mnt)`;
                statusBadge.className = 'absolute top-2 left-2 px-2.5 py-1 rounded bg-slate-950/80 backdrop-blur-md border border-slate-800 text-[10px] font-mono text-emerald-300';

                // Add GeoJSON source
                map.addSource('ais-track-source', {
                    type: 'geojson',
                    data: geoJson
                });

                // Line layer for track
                map.addLayer({
                    id: 'ais-track-line-layer',
                    type: 'line',
                    source: 'ais-track-source',
                    filter: ['==', '$type', 'LineString'],
                    paint: {
                        'line-color': '#f59e0b',
                        'line-width': 2.5,
                        'line-dasharray': [2, 1]
                    }
                });

                // Add point markers
                geoJson.features.forEach(feat => {
                    if (feat.geometry.type !== 'Point' || feat.properties.feature_type === 'sar_candidate') {
                        return;
                    }

                    const props = feat.properties;
                    const coords = feat.geometry.coordinates;

                    const markerEl = document.createElement('div');
                    let colorClass = 'bg-slate-500 border-slate-300';
                    let icon = '•';

                    if (props.role === 'nearest') {
                        colorClass = 'bg-amber-500 border-white ring-2 ring-amber-400/50 scale-125';
                        icon = '⭐';
                    } else if (props.role === 'before_sar') {
                        colorClass = 'bg-cyan-500 border-cyan-200';
                        icon = '◀';
                    } else if (props.role === 'after_sar') {
                        colorClass = 'bg-indigo-500 border-indigo-200';
                        icon = '▶';
                    }

                    markerEl.className = `w-5 h-5 rounded-full ${colorClass} border flex items-center justify-center text-[9px] text-white font-bold cursor-pointer shadow-md transition-transform hover:scale-150`;
                    markerEl.innerHTML = icon;

                    const popupHtml = `
                        <div class="p-1 text-xs font-mono text-slate-200 space-y-1">
                            <div class="font-bold text-amber-400 flex items-center gap-1">
                                <span>Kapal:</span>
                                <span>${props.vessel_name || 'AIS Vessel'}</span>
                            </div>
                            <div>MMSI: <strong>${props.mmsi || '-'}</strong></div>
                            <div>Waktu: ${props.observed_at ? props.observed_at.replace('T', ' ').substring(0, 19) : '-'} UTC</div>
                            <div>SOG: ${props.speed_knots !== null ? props.speed_knots + ' kn' : 'N/A'} &bull; COG: ${props.course_degrees !== null ? props.course_degrees + '°' : 'N/A'}</div>
                            <div>Jarak ke Target SAR: <strong class="text-rose-400">${props.distance_to_sar_meters ? Math.round(props.distance_to_sar_meters) + ' m' : '-'}</strong></div>
                            <div>Selisih Waktu: ${props.time_delta_minutes !== undefined ? props.time_delta_minutes + ' mnt' : '-'}</div>
                            <div class="text-[9px] text-slate-400 italic">Peran: ${props.role}</div>
                        </div>
                    `;

                    const marker = new maplibregl.Marker(markerEl)
                        .setLngLat(coords)
                        .setPopup(new maplibregl.Popup({ offset: 15 }).setHTML(popupHtml))
                        .addTo(map);

                    aisTrackMarkers.push(marker);
                });
            })
            .catch(err => {
                console.error('Failed to load AIS track:', err);
                statusBadge.innerText = 'Gagal memuat track AIS';
                statusBadge.className = 'absolute top-2 left-2 px-2.5 py-1 rounded bg-rose-950/80 backdrop-blur-md border border-rose-800 text-[10px] font-mono text-rose-300';
            });
    }

    // Window selector event
    document.getElementById('select-track-window').addEventListener('change', loadAisTrack);

    // Toggle track visibility event
    document.getElementById('toggle-ais-track').addEventListener('change', function(e) {
        const checked = e.target.checked;
        if (map.getLayer('ais-track-line-layer')) {
            map.setLayoutProperty('ais-track-line-layer', 'visibility', checked ? 'visible' : 'none');
        }
        aisTrackMarkers.forEach(m => {
            m.getElement().style.display = checked ? 'flex' : 'none';
        });
    });
    }

    initCandidateDetailMap();
});
</script>
@endsection
