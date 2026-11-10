@extends('layouts.app')

@section('header_title', 'Investigations — Maritime Dossiers & Private Research')

@section('content')
<div class="space-y-6">

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <h2 class="text-xl font-bold text-white tracking-wide uppercase">Investigations Dossier</h2>
                <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold tracking-wider uppercase bg-rose-500/20 text-rose-300 border border-rose-500/40">
                    PRIVATE — RESEARCH TEAM ONLY
                </span>
            </div>
            <p class="text-xs text-slate-400 mt-1">
                Compilation of maritime intelligence dossiers: identity alteration, corporate beneficial ownership, EEZ boundary compliance, and IUU fishing risk verification.
            </p>
        </div>

        <div class="flex items-center gap-2">
            @if(auth()->user()->hasPermission('investigations.create'))
            <a href="{{ route('investigations.create') }}" class="px-4 py-2 rounded-xl bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold text-xs transition shadow-lg shadow-teal-500/20">
                + Initiate New Investigation Case
            </a>
            @endif
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="glass-panel p-4 rounded-xl border border-slate-800">
        <form method="GET" action="{{ route('investigations.index') }}" class="grid grid-cols-1 sm:grid-cols-4 gap-3 text-xs">
            <div>
                <label class="block text-slate-400 mb-1 font-semibold">Case Status</label>
                <select name="status" class="w-full px-3 py-1.5 rounded-lg bg-slate-900 border border-slate-700 text-slate-200">
                    <option value="">All Statuses</option>
                    <option value="open" {{ request('status') === 'open' ? 'selected' : '' }}>Open</option>
                    <option value="in_progress" {{ request('status') === 'in_progress' ? 'selected' : '' }}>In Progress</option>
                    <option value="closed" {{ request('status') === 'closed' ? 'selected' : '' }}>Closed</option>
                    <option value="archived" {{ request('status') === 'archived' ? 'selected' : '' }}>Archived</option>
                </select>
            </div>
            <div>
                <label class="block text-slate-400 mb-1 font-semibold">Priority Level</label>
                <select name="priority" class="w-full px-3 py-1.5 rounded-lg bg-slate-900 border border-slate-700 text-slate-200">
                    <option value="">All Priorities</option>
                    <option value="urgent" {{ request('priority') === 'urgent' ? 'selected' : '' }}>Urgent</option>
                    <option value="high" {{ request('priority') === 'high' ? 'selected' : '' }}>High</option>
                    <option value="medium" {{ request('priority') === 'medium' ? 'selected' : '' }}>Medium</option>
                    <option value="low" {{ request('priority') === 'low' ? 'selected' : '' }}>Low</option>
                </select>
            </div>
            <div>
                <label class="block text-slate-400 mb-1 font-semibold">Analytical Verdict</label>
                <select name="verdict_status" class="w-full px-3 py-1.5 rounded-lg bg-slate-900 border border-slate-700 text-slate-200">
                    <option value="">All Verdicts</option>
                    <option value="confirmed" {{ request('verdict_status') === 'confirmed' ? 'selected' : '' }}>Confirmed Violation</option>
                    <option value="suspected" {{ request('verdict_status') === 'suspected' ? 'selected' : '' }}>Suspected Violation</option>
                    <option value="potential" {{ request('verdict_status') === 'potential' ? 'selected' : '' }}>Potential Risk</option>
                    <option value="under_review" {{ request('verdict_status') === 'under_review' ? 'selected' : '' }}>Under Review</option>
                    <option value="inconclusive" {{ request('verdict_status') === 'inconclusive' ? 'selected' : '' }}>Inconclusive</option>
                </select>
            </div>
            <div class="flex items-end gap-2">
                <button type="submit" class="w-full px-4 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 font-bold transition border border-slate-700">
                    Filter
                </button>
                @if(request()->anyFilled(['status', 'priority', 'verdict_status', 'confidentiality_level']))
                <a href="{{ route('investigations.index') }}" class="px-3 py-1.5 rounded-lg bg-slate-900 hover:bg-slate-800 text-slate-400 text-center transition border border-slate-800">
                    Reset
                </a>
                @endif
            </div>
        </form>
    </div>

    <!-- Investigations Table -->
    <div class="glass-panel rounded-2xl border border-slate-800 overflow-hidden shadow-xl">
        <div class="overflow-x-auto text-xs responsive-table-container">
            <table class="w-full text-left min-w-[850px]">
                <thead class="bg-slate-900/90 text-slate-400 uppercase tracking-wider text-[10px] border-b border-slate-800">
                    <tr>
                        <th class="py-3 px-4">Case Number</th>
                        <th class="py-3 px-4">Case Title</th>
                        <th class="py-3 px-4">Confidentiality</th>
                        <th class="py-3 px-4">Target Vessel</th>
                        <th class="py-3 px-4">Verdict</th>
                        <th class="py-3 px-4">Institution / Lead</th>
                        <th class="py-3 px-4">Date Opened</th>
                        <th class="py-3 px-4 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60">
                    @forelse($investigations as $inv)
                    <tr class="hover:bg-slate-900/40 transition">
                        <td class="py-3 px-4 font-mono font-bold text-indigo-300">
                            {{ $inv->case_number }}
                        </td>
                        <td class="py-3 px-4">
                            <div class="font-bold text-white">{{ $inv->title }}</div>
                            <div class="text-[10px] text-slate-400 font-mono">Priority: <span class="uppercase {{ $inv->priority === 'urgent' ? 'text-rose-400 font-bold' : '' }}">{{ $inv->priority }}</span></div>
                        </td>
                        <td class="py-3 px-4">
                            <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold uppercase
                                {{ $inv->confidentiality_level === 'strictly_confidential' ? 'bg-rose-500/20 text-rose-300 border border-rose-500/40' : ($inv->confidentiality_level === 'restricted' ? 'bg-amber-500/20 text-amber-300 border border-amber-500/40' : 'bg-slate-800 text-slate-300 border border-slate-700') }}">
                                {{ $inv->confidentiality_level ?? 'private' }}
                            </span>
                        </td>
                        <td class="py-3 px-4 text-teal-300 font-semibold">
                            {{ $inv->targetVessel?->name ?? 'Unidentified Vessel' }}
                        </td>
                        <td class="py-3 px-4">
                            @php
                                $badgeClass = match($inv->verdict_status) {
                                    'confirmed' => 'bg-rose-500/20 text-rose-300 border-rose-500/40',
                                    'suspected' => 'bg-amber-500/20 text-amber-300 border-amber-500/40',
                                    'potential' => 'bg-yellow-500/20 text-yellow-300 border-yellow-500/40',
                                    'no_evidence_found' => 'bg-emerald-500/20 text-emerald-300 border-emerald-500/40',
                                    default => 'bg-slate-800 text-slate-300 border-slate-700',
                                };
                            @endphp
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase font-mono border {{ $badgeClass }}">
                                {{ str_replace('_', ' ', $inv->verdict_status ?? 'under_review') }}
                            </span>
                        </td>
                        <td class="py-3 px-4 text-slate-300">
                            <div>{{ $inv->leadResearcher?->name ?? $inv->assignedTo?->name ?? 'Unassigned' }}</div>
                            <div class="text-[10px] text-slate-400">{{ $inv->organization ?? 'GFVIP Internal Team' }}</div>
                        </td>
                        <td class="py-3 px-4 text-slate-400 font-mono text-[11px]">{{ $inv->created_at->format('Y-m-d') }}</td>
                        <td class="py-3 px-4 text-right">
                            <a href="{{ route('investigations.show', $inv->id) }}" class="px-2.5 py-1 rounded bg-slate-800 hover:bg-slate-700 text-teal-300 font-semibold transition border border-slate-700">
                                Dossier
                            </a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="8" class="py-12 text-center text-slate-500">
                            <span class="text-4xl block mb-2">📁</span>
                            No active investigation cases accessible under your role and organizational permissions.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($investigations->hasPages())
        <div class="p-4 border-t border-slate-800">
            {{ $investigations->links() }}
        </div>
        @endif
    </div>

</div>
@endsection
