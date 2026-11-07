@extends('layouts.app')

@section('header_title', 'Vessel Registry — Identitas & Registrasi Armada Global')

@section('content')
<div class="space-y-6">

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold text-white tracking-wide uppercase">Vessel Registry</h2>
            <p class="text-xs text-slate-400 mt-1">Registrasi kapal global, MMSI, IMO, Callsign, bendera negara, dan status verifikasi.</p>
        </div>

        <form action="{{ route('vessels.index') }}" method="GET" class="flex flex-wrap items-center gap-2 text-xs w-full sm:w-auto">
            <select name="vessel_type" onchange="this.form.submit()"
                class="w-full sm:w-auto px-3 py-2 rounded-xl bg-slate-900 border border-slate-700 text-slate-200 focus:outline-none focus:border-teal-400 font-sans cursor-pointer">
                <option value="">Semua Tipe Armada</option>
                <option value="fishing" {{ request('vessel_type') === 'fishing' ? 'selected' : '' }}>Fishing (Penangkap)</option>
                <option value="carrier" {{ request('vessel_type') === 'carrier' ? 'selected' : '' }}>Carrier (Pengangkut)</option>
                <option value="support" {{ request('vessel_type') === 'support' ? 'selected' : '' }}>Support (Pendukung)</option>
                <option value="passenger" {{ request('vessel_type') === 'passenger' ? 'selected' : '' }}>Passenger (Penumpang/Yacht)</option>
                <option value="patrol" {{ request('vessel_type') === 'patrol' ? 'selected' : '' }}>Patrol (Pengawas/SAR)</option>
                <option value="tug" {{ request('vessel_type') === 'tug' ? 'selected' : '' }}>Tug (Kapal Tunda)</option>
                <option value="pleasure" {{ request('vessel_type') === 'pleasure' ? 'selected' : '' }}>Pleasure (Rekreasi/Layar)</option>
                <option value="research" {{ request('vessel_type') === 'research' ? 'selected' : '' }}>Research (Riset Ilmiah)</option>
                <option value="other" {{ request('vessel_type') === 'other' ? 'selected' : '' }}>Other (Lainnya)</option>
                <option value="unknown" {{ request('vessel_type') === 'unknown' ? 'selected' : '' }}>Unknown (Belum Diketahui)</option>
            </select>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Cari nama, MMSI, IMO..."
                class="w-full sm:w-56 px-3 py-2 rounded-xl bg-slate-900 border border-slate-700 text-slate-200 placeholder-slate-500 focus:outline-none focus:border-teal-400 font-mono">
            <div class="flex items-center gap-2 w-full sm:w-auto">
                <button type="submit" class="flex-1 sm:flex-none px-4 py-2 rounded-xl bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold transition">
                    Cari
                </button>
                @if(request('search') || request('vessel_type'))
                <a href="{{ route('vessels.index') }}" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-medium transition border border-slate-700" title="Reset filter">
                    Reset
                </a>
                @endif
            </div>
        </form>
    </div>

    <!-- Vessels Table -->
    <div class="glass-panel rounded-2xl border border-slate-800 overflow-hidden shadow-xl">
        <div class="overflow-x-auto text-xs responsive-table-container">
            <table class="w-full text-left min-w-[850px]">
                <thead class="bg-slate-900/90 text-slate-400 uppercase tracking-wider text-[10px] border-b border-slate-800">
                    <tr>
                        <th class="py-3 px-4 text-center w-14">No.</th>
                        <th class="py-3 px-4">Nama Kapal</th>
                        <th class="py-3 px-4">Bendera</th>
                        <th class="py-3 px-4">Tipe Armada</th>
                        <th class="py-3 px-4">MMSI</th>
                        <th class="py-3 px-4">IMO / Callsign</th>
                        <th class="py-3 px-4">Status Verifikasi</th>
                        <th class="py-3 px-4">Terakhir Terdeteksi</th>
                        <th class="py-3 px-4 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60">
                    @forelse($vessels as $vessel)
                    <tr class="hover:bg-slate-900/40 transition">
                        <td class="py-3 px-4 text-center font-mono text-slate-400 text-xs">
                            {{ $loop->iteration + ($vessels->firstItem() ? $vessels->firstItem() - 1 : 0) }}
                        </td>
                        <td class="py-3 px-4">
                            <div class="font-bold text-white flex items-center gap-2">
                                <span>🚢</span>
                                <span>{{ $vessel->name }}</span>
                            </div>
                            <div class="text-[10px] text-slate-500 font-mono">UUID: {{ substr($vessel->id, 0, 8) }}...</div>
                        </td>
                        <td class="py-3 px-4">
                            <span class="px-2 py-0.5 rounded font-mono text-[10px] bg-slate-800 border border-slate-700 text-slate-300 uppercase">
                                {{ $vessel->flag_state ?? 'UN' }}
                            </span>
                        </td>
                        <td class="py-3 px-4 font-medium">
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
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-mono capitalize border {{ $typeBadgeClass }}">
                                {{ $vessel->vessel_type ?: 'Unknown' }}
                            </span>
                        </td>
                        <td class="py-3 px-4 font-mono text-teal-300">
                            {{ $vessel->mmsi ?? '-' }}
                        </td>
                        <td class="py-3 px-4 font-mono text-slate-400">
                            {{ $vessel->imo ? 'IMO: ' . $vessel->imo : ($vessel->callsign ? 'CS: ' . $vessel->callsign : '-') }}
                        </td>
                        <td class="py-3 px-4">
                            @if($vessel->verification_status === 'verified')
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/40">Verified</span>
                            @elseif($vessel->verification_status === 'provider_reported')
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-sky-500/20 text-sky-300 border border-sky-500/40">Provider Reported</span>
                            @else
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-slate-800 text-slate-400 border border-slate-700">Unverified</span>
                            @endif
                        </td>
                        <td class="py-3 px-4 text-slate-400 font-mono text-[11px]">
                            {{ $vessel->last_seen_at ? $vessel->last_seen_at->diffForHumans() : 'Belum pernah' }}
                        </td>
                        <td class="py-3 px-4 text-right">
                            <a href="{{ route('vessels.show', $vessel->id) }}" class="px-2.5 py-1 rounded bg-slate-800 hover:bg-slate-700 text-teal-300 border border-slate-700 font-semibold transition">
                                Profil
                            </a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="9" class="py-12 text-center text-slate-500">
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
@endsection
