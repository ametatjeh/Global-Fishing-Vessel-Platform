@extends('layouts.app')

@section('header_title', 'Activity Logs — Operational Maritime Surveillance Audit Trail')

@section('content')
<div class="space-y-6">

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold text-white tracking-wide uppercase">Operational Audit Trail</h2>
            <p class="text-xs text-slate-400 mt-1">
                Recorded user access, analyst verification dispositions, detection algorithm executions, and data governance events.
            </p>
        </div>
    </div>

    <!-- Logs Table -->
    <div class="glass-panel rounded-2xl border border-slate-800 overflow-hidden shadow-xl">
        <div class="overflow-x-auto text-xs responsive-table-container">
            <table class="w-full text-left min-w-[750px]">
                <thead class="bg-slate-900/90 text-slate-400 uppercase tracking-wider text-[10px] border-b border-slate-800">
                    <tr>
                        <th class="py-3 px-4">Timestamp (UTC)</th>
                        <th class="py-3 px-4">User / Analyst</th>
                        <th class="py-3 px-4">Action</th>
                        <th class="py-3 px-4">Target Entity</th>
                        <th class="py-3 px-4">IP Address</th>
                        <th class="py-3 px-4">Metadata Details</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60 font-mono">
                    @forelse($logs as $log)
                    <tr class="hover:bg-slate-900/40 transition text-[11px]">
                        <td class="py-2.5 px-4 text-slate-400">{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
                        <td class="py-2.5 px-4 text-teal-300 font-sans font-bold">{{ $log->user?->name ?? 'System' }}</td>
                        <td class="py-2.5 px-4">
                            <span class="px-2 py-0.5 rounded uppercase font-bold text-[10px] bg-slate-800 text-slate-200 border border-slate-700">
                                {{ $log->action }}
                            </span>
                        </td>
                        <td class="py-2.5 px-4 text-slate-300">{{ $log->entity_type }} ({{ substr($log->entity_id ?? '-', 0, 8) }})</td>
                        <td class="py-2.5 px-4 text-slate-400">{{ $log->ip_address ?? '127.0.0.1' }}</td>
                        <td class="py-2.5 px-4 text-slate-500 truncate max-w-xs">{{ json_encode($log->details) }}</td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="py-8 text-center text-slate-500 font-sans">No audit log activity records found.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($logs->hasPages())
        <div class="p-4 border-t border-slate-800 bg-slate-900/40">
            {{ $logs->links() }}
        </div>
        @endif
    </div>

</div>
@endsection
