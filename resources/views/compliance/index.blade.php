@extends('layouts.app')

@section('header_title', 'Fishing Compliance — Rule Engine & Infraction Alerts')

@section('content')
<div class="space-y-6">

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold text-white tracking-wide uppercase">Fishing Compliance Engine</h2>
            <p class="text-xs text-slate-400 mt-1">
                Global maritime compliance audit engine: vessel permit validity, jurisdictional waters, seasonal moratoria, and anomalous behavior at sea.
            </p>
        </div>
    </div>

    <!-- Metrics Cards -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div class="glass-panel p-4 rounded-xl border border-slate-800 text-center">
            <div class="text-[10px] uppercase font-bold text-slate-400">Total Alerts</div>
            <div class="text-xl font-black text-white font-mono mt-0.5">{{ number_format($stats['total_alerts']) }}</div>
        </div>
        <div class="glass-panel p-4 rounded-xl border border-slate-800 text-center">
            <div class="text-[10px] uppercase font-bold text-amber-400">Alert Open / Under Review</div>
            <div class="text-xl font-black text-amber-300 font-mono mt-0.5">{{ number_format($stats['open_alerts']) }}</div>
        </div>
        <div class="glass-panel p-4 rounded-xl border border-slate-800 text-center">
            <div class="text-[10px] uppercase font-bold text-rose-400">Critical Severity</div>
            <div class="text-xl font-black text-rose-400 font-mono mt-0.5">{{ number_format($stats['critical_alerts']) }}</div>
        </div>
        <div class="glass-panel p-4 rounded-xl border border-slate-800 text-center">
            <div class="text-[10px] uppercase font-bold text-teal-400">Active Rules (Rule Catalog)</div>
            <div class="text-xl font-black text-teal-300 font-mono mt-0.5">{{ number_format($stats['active_rules']) }}</div>
        </div>
    </div>

    <!-- Active Compliance Rules Base -->
    <div class="glass-panel p-5 rounded-2xl border border-slate-800 space-y-4 shadow-xl">
        <div class="flex items-center justify-between border-b border-slate-800 pb-2">
            <h3 class="text-xs font-bold uppercase tracking-wider text-teal-300 flex items-center gap-2">
                <span>📜</span>
                <span>Global Maritime Compliance Rule Catalog</span>
            </h3>
            <span class="text-[10px] text-slate-400 font-mono">{{ $rules->count() }} Active Rules</span>
        </div>

        <div class="overflow-x-auto text-xs responsive-table-container">
            <table class="w-full text-left min-w-[700px]">
                <thead class="text-slate-400 uppercase text-[10px] border-b border-slate-800">
                    <tr>
                        <th class="py-2.5">Rule Code</th>
                        <th class="py-2.5">Regulation Name &amp; Description</th>
                        <th class="py-2.5">Jurisdiction &amp; Legal Basis</th>
                        <th class="py-2.5">Category</th>
                        <th class="py-2.5">Severity</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60">
                    @forelse($rules as $rule)
                    <tr>
                        <td class="py-2.5 font-bold font-mono text-teal-300">{{ $rule->rule_code }}</td>
                        <td class="py-2.5 font-semibold text-white">{{ $rule->rule_name }}</td>
                        <td class="py-2.5 text-slate-300 text-[11px]">
                            <div>{{ $rule->jurisdiction }}</div>
                            <div class="text-slate-500 text-[10px] font-mono">{{ $rule->legal_source }}</div>
                        </td>
                        <td class="py-2.5 font-mono uppercase text-[10px] text-slate-400">{{ $rule->category }}</td>
                        <td class="py-2.5">
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase font-mono
                                {{ $rule->severity === 'critical' ? 'bg-rose-500/20 text-rose-300 border border-rose-500/40' : ($rule->severity === 'high' ? 'bg-orange-500/20 text-orange-300 border border-orange-500/40' : 'bg-amber-500/20 text-amber-300 border border-amber-500/40') }}">
                                {{ $rule->severity }}
                            </span>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" class="py-4 text-center text-slate-500">No compliance rules configured.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Compliance Events / Alerts List -->
    <div class="glass-panel p-5 rounded-2xl border border-slate-800 space-y-4 shadow-xl">
        <div class="flex items-center justify-between border-b border-slate-800 pb-2">
            <h3 class="text-xs font-bold uppercase tracking-wider text-rose-400 flex items-center gap-2">
                <span>⚠️</span>
                <span>Open Infraction Incident Alerts</span>
            </h3>
        </div>

        <div class="overflow-x-auto text-xs responsive-table-container">
            <table class="w-full text-left min-w-[700px]">
                <thead class="text-slate-400 uppercase text-[10px] border-b border-slate-800">
                    <tr>
                        <th class="py-2.5">Alert Code</th>
                        <th class="py-2.5">Subject Vessel</th>
                        <th class="py-2.5">Compliance Rule</th>
                        <th class="py-2.5">Incident Time</th>
                        <th class="py-2.5">Severity</th>
                        <th class="py-2.5">Review Status</th>
                        <th class="py-2.5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60">
                    @forelse($events as $event)
                    <tr>
                        <td class="py-2.5 font-mono font-bold text-rose-300">{{ $event->event_code }}</td>
                        <td class="py-2.5 font-bold text-white">{{ $event->vessel?->name ?? 'Vessel' }}</td>
                        <td class="py-2.5 text-slate-300">{{ $event->rule?->rule_name ?? '-' }}</td>
                        <td class="py-2.5 text-slate-400 font-mono text-[11px]">{{ $event->event_time->format('Y-m-d H:i') }} UTC</td>
                        <td class="py-2.5">
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase font-mono
                                {{ $event->severity === 'critical' ? 'bg-rose-500/20 text-rose-300 border border-rose-500/40' : 'bg-amber-500/20 text-amber-300 border border-amber-500/40' }}">
                                {{ $event->severity }}
                            </span>
                        </td>
                        <td class="py-2.5">
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase font-mono bg-slate-800 text-slate-300 border border-slate-700">
                                {{ $event->status }}
                            </span>
                        </td>
                        <td class="py-2.5 text-right">
                            <a href="{{ route('compliance.show', $event->id) }}" class="px-2.5 py-1 rounded bg-slate-800 hover:bg-slate-700 text-teal-300 font-semibold transition border border-slate-700">
                                Review
                            </a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="py-8 text-center text-slate-500">
                            No compliance alert incidents recorded.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection
