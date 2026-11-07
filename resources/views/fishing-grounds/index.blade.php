@extends('layouts.app')

@section('header_title', 'Fishing Grounds — FAO Statistical Areas & EEZ Maritime Boundaries')

@section('content')
<div class="space-y-6">

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold text-white tracking-wide uppercase">Fishing Grounds Geodatabase</h2>
            <p class="text-xs text-slate-400 mt-1">
                Katalog batas spasial perikanan: FAO Major Fishing Areas untuk statistik global dan Marine Regions Exclusive Economic Zones (EEZ 200NM).
            </p>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('map.index') }}" class="px-4 py-2 rounded-xl bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold text-xs transition shadow-lg shadow-teal-500/20">
                Visualisasikan di Peta
            </a>
        </div>
    </div>

    <!-- Legal Disclaimers Banner -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
        <div class="p-4 rounded-xl bg-indigo-950/40 border border-indigo-500/30 text-indigo-200 space-y-1">
            <div class="font-bold text-indigo-300 flex items-center gap-1.5">
                <span>🌐</span> FAO Statistical Fishing Areas Notice
            </div>
            <p class="text-[11px] text-slate-300 leading-relaxed">
                {{ config('maritime.fao.disclaimer') }}
            </p>
            <div class="text-[10px] text-indigo-400 font-mono pt-1">
                Sumber: FAO Fisheries &bull; Lisensi: {{ config('maritime.fao.license') }}
            </div>
        </div>

        <div class="p-4 rounded-xl bg-teal-950/40 border border-teal-500/30 text-teal-200 space-y-1">
            <div class="font-bold text-teal-300 flex items-center gap-1.5">
                <span>🛡️</span> Marine Regions EEZ (200NM) Jurisdiction Notice
            </div>
            <p class="text-[11px] text-slate-300 leading-relaxed">
                {{ config('maritime.eez.disclaimer') }}
            </p>
            <div class="text-[10px] text-teal-400 font-mono pt-1">
                Sumber: Marine Regions (VLIZ) &bull; Lisensi: {{ config('maritime.eez.license') }}
            </div>
        </div>
    </div>

    <!-- FAO Areas Section -->
    <div class="glass-panel p-5 rounded-2xl border border-slate-800 space-y-4 shadow-xl">
        <div class="flex items-center justify-between border-b border-slate-800 pb-2">
            <h3 class="text-xs font-bold uppercase tracking-wider text-indigo-300 flex items-center gap-2">
                <span>🌐</span>
                <span>FAO Major Fishing Areas (Statistik Resmi)</span>
            </h3>
            <span class="text-[10px] text-slate-400 font-mono">{{ $faoAreas->count() }} Area Terdaftar</span>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3 text-xs">
            @forelse($faoAreas as $area)
            <div class="p-3 rounded-xl bg-slate-900 border border-slate-800 hover:border-indigo-500/40 transition space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold font-mono text-indigo-300 bg-indigo-500/10 px-2 py-0.5 rounded border border-indigo-500/30">
                        Area {{ $area->area_code }}
                    </span>
                    <span class="text-[10px] font-mono text-slate-400 uppercase">{{ $area->ocean_basin ?? 'Global Basin' }}</span>
                </div>
                <div class="font-bold text-white text-sm">{{ $area->name_en }}</div>
                <div class="text-[11px] text-slate-400 flex justify-between">
                    <span>Luas Permukaan:</span>
                    <span class="font-mono text-slate-300">{{ $area->surface_water_sqkm ? number_format($area->surface_water_sqkm) . ' km²' : 'N/A' }}</span>
                </div>
            </div>
            @empty
            <div class="col-span-3 py-6 text-center text-slate-500">
                Belum ada data area FAO yang diimpor. Jalankan perintah <code>php artisan fao:import</code>.
            </div>
            @endforelse
        </div>
    </div>

    <!-- EEZ Zones Section -->
    <div class="glass-panel p-5 rounded-2xl border border-slate-800 space-y-4 shadow-xl">
        <div class="flex items-center justify-between border-b border-slate-800 pb-2">
            <h3 class="text-xs font-bold uppercase tracking-wider text-teal-300 flex items-center gap-2">
                <span>🛡️</span>
                <span>Maritime Boundaries & Exclusive Economic Zones (EEZ)</span>
            </h3>
            <span class="text-[10px] text-slate-400 font-mono">Batas Yurisdiksi 200NM</span>
        </div>

        <div class="overflow-x-auto text-xs responsive-table-container">
            <table class="w-full text-left min-w-[700px]">
                <thead class="text-slate-400 uppercase text-[10px] border-b border-slate-800">
                    <tr>
                        <th class="py-2.5">Wilayah Yurisdiksi (GEONAME)</th>
                        <th class="py-2.5">Negara Berdaulat</th>
                        <th class="py-2.5">MRGID</th>
                        <th class="py-2.5">Tipe Yurisdiksi</th>
                        <th class="py-2.5">Estimasi Luas (km²)</th>
                        <th class="py-2.5">Antimeridian</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60">
                    @forelse($eezZones as $eez)
                    <tr>
                        <td class="py-2.5 font-bold text-white">{{ $eez->geoname }}</td>
                        <td class="py-2.5 font-medium text-teal-300">{{ $eez->sovereign_state }} ({{ $eez->sovereign_iso3 ?? '-' }})</td>
                        <td class="py-2.5 font-mono text-slate-400">{{ $eez->mrgid }}</td>
                        <td class="py-2.5 font-mono text-[11px] text-slate-300">{{ $eez->pol_type }}</td>
                        <td class="py-2.5 font-mono text-slate-300">{{ number_format($eez->area_sqkm) }}</td>
                        <td class="py-2.5">
                            @if($eez->crosses_antimeridian)
                                <span class="px-1.5 py-0.5 rounded text-[10px] bg-amber-500/20 text-amber-300 border border-amber-500/40">Yes (180&deg;)</span>
                            @else
                                <span class="text-slate-500 text-[11px]">No</span>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="py-6 text-center text-slate-500">
                            Belum ada data EEZ yang diimpor. Jalankan perintah <code>php artisan eez:import</code>.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($eezZones->hasPages())
        <div class="pt-3 border-t border-slate-800">
            {{ $eezZones->links() }}
        </div>
        @endif
    </div>

</div>
@endsection
