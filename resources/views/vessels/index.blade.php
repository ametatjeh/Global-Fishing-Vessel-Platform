@extends('layouts.app')

@section('header_title', 'Vessel Registry — Identitas, Bendera, Kepemilikan & Operator Armada Global')

@section('content')
<div class="space-y-6">

    <!-- Header Section -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2.5 flex-wrap">
                <h2 class="text-xl font-bold text-white tracking-wide uppercase">Vessel Registry</h2>
                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-mono font-bold tracking-wider uppercase bg-teal-500/15 text-teal-300 border border-teal-500/30">
                    Armada Global & Intelijen Maritim
                </span>
                @if($activeFiltersCount > 0)
                <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-semibold bg-amber-500/15 text-amber-300 border border-amber-500/30">
                    <span class="w-1.5 h-1.5 rounded-full bg-amber-400 animate-pulse"></span>
                    {{ $activeFiltersCount }} filter aktif
                </span>
                @endif
            </div>
            <p class="text-xs text-slate-400 mt-1">
                Katalog identitas kapal, registrasi bendera (Flag State), entitas operator, dan struktur kepemilikan (Owner) terverifikasi.
            </p>
        </div>

        <div class="flex items-center gap-2">
            @if($activeFiltersCount > 0)
            <a href="{{ route('vessels.index') }}" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white border border-slate-700 text-xs font-medium transition shadow-sm" title="Hapus semua filter pencarian">
                <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
                Reset Filters
            </a>
            @endif
        </div>
    </div>

    <!-- Multi-Dimensional Filter Bar (Compact Mode) -->
    <div class="glass-panel p-4 rounded-2xl border border-slate-800 shadow-xl">
        <form action="{{ route('vessels.index') }}" method="GET" id="vesselsFilterForm" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 text-xs">

                <!-- 1. Search Query -->
                <div class="space-y-1">
                    <label class="block text-slate-400 font-semibold uppercase tracking-wider text-[10px]">
                        Pencarian Kapal / Entitas
                    </label>
                    <div class="relative">
                        <input type="text" name="search" value="{{ request('search') }}" placeholder="Nama, MMSI, IMO, Callsign, Owner, Operator..."
                            class="w-full pl-8 pr-3 py-2 rounded-xl bg-slate-900/90 border border-slate-700 text-slate-200 placeholder-slate-500 focus:outline-none focus:border-teal-400 font-sans transition">
                        <div class="absolute inset-y-0 left-0 pl-2.5 flex items-center pointer-events-none text-slate-500">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                        </div>
                    </div>
                </div>

                <!-- 2. Flag Country Filter -->
                <div class="space-y-1">
                    <label for="flag_country" class="block text-slate-400 font-semibold uppercase tracking-wider text-[10px]">
                        Flag Country (Negara Bendera)
                    </label>
                    <select id="flag_country" name="flag_country" onchange="this.form.submit()"
                        class="w-full px-3 py-2 rounded-xl bg-slate-900/90 border border-slate-700 text-slate-200 focus:outline-none focus:border-teal-400 font-sans cursor-pointer transition">
                        <option value="">All Countries (Semua Bendera)</option>
                        @foreach($availableFlagCountries as $country)
                        <option value="{{ $country['code'] }}" {{ (request('flag_country') == $country['code'] || request('flag_state') == $country['code']) ? 'selected' : '' }}>
                            {{ $country['flag_emoji'] }} {{ $country['name'] }} ({{ $country['code'] }})
                        </option>
                        @endforeach
                        <option value="unknown" {{ (request('flag_country') === 'unknown' || request('flag_state') === 'unknown') ? 'selected' : '' }}>
                            🌐 Unknown (Negara Tidak Diketahui)
                        </option>
                    </select>
                </div>

                <!-- 3. Vessel Type Filter -->
                <div class="space-y-1">
                    <label for="vessel_type" class="block text-slate-400 font-semibold uppercase tracking-wider text-[10px]">
                        Vessel Type (Tipe Armada)
                    </label>
                    <select id="vessel_type" name="vessel_type" onchange="this.form.submit()"
                        class="w-full px-3 py-2 rounded-xl bg-slate-900/90 border border-slate-700 text-slate-200 focus:outline-none focus:border-teal-400 font-sans cursor-pointer transition">
                        <option value="">All Types (Semua Tipe)</option>
                        <option value="fishing" {{ request('vessel_type') === 'fishing' ? 'selected' : '' }}>Fishing (Penangkap Ikan)</option>
                        <option value="carrier" {{ request('vessel_type') === 'carrier' ? 'selected' : '' }}>Carrier (Pengangkut/Reefer)</option>
                        <option value="support" {{ request('vessel_type') === 'support' ? 'selected' : '' }}>Support (Pendukung/Bunker)</option>
                        <option value="passenger" {{ request('vessel_type') === 'passenger' ? 'selected' : '' }}>Passenger (Penumpang/Yacht)</option>
                        <option value="patrol" {{ request('vessel_type') === 'patrol' ? 'selected' : '' }}>Patrol (Pengawas/SAR)</option>
                        <option value="tug" {{ request('vessel_type') === 'tug' ? 'selected' : '' }}>Tug (Kapal Tunda)</option>
                        <option value="pleasure" {{ request('vessel_type') === 'pleasure' ? 'selected' : '' }}>Pleasure (Rekreasi/Layar)</option>
                        <option value="research" {{ request('vessel_type') === 'research' ? 'selected' : '' }}>Research (Riset Ilmiah)</option>
                        <option value="cargo" {{ request('vessel_type') === 'cargo' ? 'selected' : '' }}>Cargo (Kargo Barang)</option>
                        <option value="tanker" {{ request('vessel_type') === 'tanker' ? 'selected' : '' }}>Tanker (Minyak/Gas)</option>
                        <option value="other" {{ request('vessel_type') === 'other' ? 'selected' : '' }}>Other (Lainnya)</option>
                        <option value="unknown" {{ request('vessel_type') === 'unknown' ? 'selected' : '' }}>Unknown (Belum Diketahui)</option>
                    </select>
                </div>
            </div>

            <!-- Filter Controls and Quick Country Search -->
            <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 pt-2 border-t border-slate-800/80 text-xs">
                <div class="flex items-center gap-2">
                    <input type="text" id="quickCountryFilter" placeholder="Cari nama bendera negara..."
                        class="w-full sm:w-64 px-3 py-1.5 rounded-lg bg-slate-900 border border-slate-700/80 text-slate-300 placeholder-slate-500 focus:outline-none focus:border-teal-400 text-[11px]"
                        title="Ketik untuk memfilter daftar opsi bendera negara pada dropdown">
                    <span class="text-[10px] text-slate-500 hidden sm:inline">(Live filter bendera)</span>
                </div>

                <div class="flex items-center gap-2 justify-end">
                    @if($activeFiltersCount > 0)
                    <a href="{{ route('vessels.index') }}" class="px-3.5 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 border border-slate-700 text-xs font-semibold transition">
                        Reset Filters
                    </a>
                    @endif
                    <button type="submit" class="px-5 py-1.5 rounded-lg bg-teal-500 hover:bg-teal-400 text-slate-950 text-xs font-bold transition shadow-md shadow-teal-500/20">
                        Terapkan Filter
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Vessels Registry Table (Horizontal Scrollable, Compact Single-Line Mode) -->
    <div class="glass-panel rounded-2xl border border-slate-800 overflow-hidden shadow-xl">
        <div class="overflow-x-auto text-xs responsive-table-container">
            <table class="w-full text-left min-w-[850px] xl:min-w-[1000px]">
                <thead class="bg-slate-900/90 text-slate-400 uppercase tracking-wider text-[10px] border-b border-slate-800">
                    <tr>
                        <th class="py-2.5 px-3 text-center w-12 text-slate-400 whitespace-nowrap">No.</th>
                        <th class="py-2.5 px-4 font-semibold text-slate-300 whitespace-nowrap">VESSEL <span class="sr-only">Nama Kapal</span></th>
                        <th class="py-2.5 px-4 font-semibold text-slate-300 whitespace-nowrap">MMSI</th>
                        <th class="py-2.5 px-4 font-semibold text-slate-300 whitespace-nowrap">IMO</th>
                        <th class="py-2.5 px-4 font-semibold text-slate-300 whitespace-nowrap">FLAG</th>
                        <th class="py-2.5 px-4 font-semibold text-slate-300 whitespace-nowrap">OPERATOR</th>
                        <th class="py-2.5 px-4 font-semibold text-slate-300 whitespace-nowrap">OWNER</th>
                        <th class="py-2.5 px-4 font-semibold text-slate-300 whitespace-nowrap">VESSEL TYPE</th>
                        <th class="py-2.5 px-4 font-semibold text-slate-300 whitespace-nowrap">LAST SEEN</th>
                        <th class="py-2.5 px-4 text-right font-semibold text-slate-300 whitespace-nowrap">ACTIONS</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60">
                    @forelse($vessels as $vessel)
                    @php
                        $flagDetails = $vessel->flag_details;
                    @endphp
                    <tr class="hover:bg-slate-900/40 transition">

                        <!-- 0. Sequential Row Number -->
                        <td class="py-2.5 px-3 text-center font-mono text-slate-400 text-xs whitespace-nowrap">
                            {{ $loop->iteration + ($vessels->firstItem() ? $vessels->firstItem() - 1 : 0) }}
                        </td>

                        <!-- 1. VESSEL Name (Strictly Single Line, without Callsign or UUID) -->
                        <td class="py-2.5 px-4 whitespace-nowrap">
                            <a href="{{ route('vessels.show', $vessel->id) }}" class="font-bold text-white hover:text-teal-300 transition inline-flex items-center gap-1.5 group max-w-[200px]" title="{{ $vessel->name }}">
                                <span class="text-sm shrink-0">🚢</span>
                                <span class="underline-offset-2 group-hover:underline truncate">{{ $vessel->name }}</span>
                            </a>
                        </td>

                        <!-- 2. MMSI -->
                        <td class="py-2.5 px-4 font-mono text-teal-300 font-semibold text-xs whitespace-nowrap">
                            {{ $vessel->mmsi ?? '-' }}
                        </td>

                        <!-- 3. IMO -->
                        <td class="py-2.5 px-4 font-mono text-slate-300 text-xs whitespace-nowrap">
                            {{ $vessel->imo ?: '-' }}
                        </td>

                        <!-- 4. FLAG ([Flag Icon] [Country Name]) -->
                        <td class="py-2.5 px-4 whitespace-nowrap">
                            @if($flagDetails['is_known'])
                            <div class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded bg-slate-800/90 border border-slate-700/80 text-slate-200 text-xs shadow-sm"
                                title="Flag State: {{ $flagDetails['name'] }} ({{ $vessel->flag_state }})">
                                <span class="text-xs leading-none">{{ $flagDetails['flag_emoji'] }}</span>
                                <span class="font-medium truncate max-w-[130px]">{{ $flagDetails['name'] }}</span>
                            </div>
                            @else
                            <div class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-slate-800/40 border border-slate-700/50 text-slate-400 text-xs"
                                title="Flag state not reported or unverified">
                                <span class="text-xs leading-none">🌐</span>
                                <span class="italic">Unknown</span>
                            </div>
                            @endif
                        </td>

                        <!-- 5. OPERATOR (Strictly Single Line, without Flag Icon) -->
                        <td class="py-2.5 px-4 whitespace-nowrap">
                            @if(!empty($vessel->operator))
                            <div class="font-medium text-slate-200 text-xs max-w-[170px] truncate" title="Operator: {{ $vessel->operator }}">
                                {{ $vessel->display_operator }}
                            </div>
                            @else
                            <span class="text-slate-500 italic text-[11px]">{{ $vessel->display_operator }}</span>
                            @endif
                        </td>

                        <!-- 6. OWNER (Strictly Single Line, without Flag Icon) -->
                        <td class="py-2.5 px-4 whitespace-nowrap">
                            @if(!empty($vessel->owner))
                            <div class="font-medium text-slate-200 text-xs max-w-[170px] truncate" title="Owner: {{ $vessel->owner }}">
                                {{ $vessel->display_owner }}
                            </div>
                            @else
                            <span class="text-slate-500 italic text-[11px]">{{ $vessel->display_owner }}</span>
                            @endif
                        </td>

                        <!-- 7. VESSEL TYPE (Strictly Single Line Badge) -->
                        <td class="py-2.5 px-4 whitespace-nowrap font-medium">
                            @php
                                $typeKey = strtolower($vessel->vessel_type ?? 'unknown');
                                $typeBadgeClass = match($typeKey) {
                                    'fishing' => 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30',
                                    'carrier', 'support' => 'bg-amber-500/15 text-amber-300 border-amber-500/30',
                                    'passenger' => 'bg-sky-500/15 text-sky-300 border-sky-500/30',
                                    'patrol' => 'bg-rose-500/15 text-rose-300 border-rose-500/30',
                                    'tug' => 'bg-orange-500/15 text-orange-300 border-orange-500/30',
                                    'pleasure' => 'bg-violet-500/15 text-violet-300 border-violet-500/30',
                                    'research' => 'bg-indigo-500/15 text-indigo-300 border-indigo-500/30',
                                    'cargo', 'tanker' => 'bg-red-950 text-red-400 border-red-500/40',
                                    default => 'bg-slate-800 text-slate-400 border-slate-700',
                                };
                            @endphp
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-mono capitalize border {{ $typeBadgeClass }}">
                                {{ $vessel->vessel_type ?: 'Unknown' }}
                            </span>
                        </td>

                        <!-- 8. LAST SEEN (Strictly Single Line, Last Detect) -->
                        <td class="py-2.5 px-4 text-xs font-mono whitespace-nowrap text-slate-300">
                            @php
                                $lastDetect = $vessel->last_seen_at ?? $vessel->latestPosition?->observed_at;
                            @endphp
                            @if($lastDetect)
                            <span class="inline-flex items-center gap-1.5" title="{{ $lastDetect->format('Y-m-d H:i:s') }} UTC @if($vessel->latestPosition) &bull; {{ sprintf('%.2f°, %.2f°', $vessel->latestPosition->latitude, $vessel->latestPosition->longitude) }} @endif">
                                @if($vessel->isRecentlyActive())
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse shrink-0" title="Aktif (&le; 24 jam)"></span>
                                @endif
                                <span class="text-slate-300">{{ $lastDetect->diffForHumans() }}</span>
                            </span>
                            @else
                            <span class="text-slate-500 italic text-[11px]" title="Belum pernah terdeteksi telemetri">-</span>
                            @endif
                        </td>

                        <!-- 9. ACTIONS (Strictly Single Line) -->
                        <td class="py-2.5 px-4 text-right whitespace-nowrap">
                            <a href="{{ route('vessels.show', $vessel->id) }}" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-teal-300 hover:text-teal-200 border border-slate-700 font-semibold transition text-xs shadow-sm" title="Lihat profil kapal">
                                <span>Profil</span>
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                </svg>
                            </a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="10" class="py-12 text-center text-slate-500">
                            <span class="text-4xl block mb-2">🌊</span>
                            Belum ada kapal yang terdaftar dalam database registry saat ini.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $vessels->links('partials.pagination') }}
    </div>

</div>

<!-- Client-side Interactive Search Script for Country Dropdowns -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('quickCountryFilter');
    if (!searchInput) return;

    const dropdownIds = ['flag_country'];
    const dropdowns = dropdownIds.map(id => document.getElementById(id)).filter(Boolean);

    // Store original options for filtering
    const originalOptions = dropdowns.map(dd => {
        return Array.from(dd.options).map(opt => ({
            value: opt.value,
            text: opt.text,
            selected: opt.selected
        }));
    });

    searchInput.addEventListener('input', function (e) {
        const query = e.target.value.toLowerCase().trim();

        dropdowns.forEach((dd, idx) => {
            const list = originalOptions[idx];
            const currentValue = dd.value;

            // Clear current options
            dd.innerHTML = '';

            list.forEach(item => {
                // Always keep default empty option and unknown option visible, or matching items
                const isMatch = item.value === '' ||
                                item.value === 'unknown' ||
                                item.text.toLowerCase().includes(query) ||
                                item.value.toLowerCase().includes(query);

                if (isMatch) {
                    const opt = document.createElement('option');
                    opt.value = item.value;
                    opt.text = item.text;
                    if (item.value === currentValue) {
                        opt.selected = true;
                    }
                    dd.appendChild(opt);
                }
            });
        });
    });
});
</script>
@endsection
