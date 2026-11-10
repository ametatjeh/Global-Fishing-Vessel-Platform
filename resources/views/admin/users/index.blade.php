@extends('layouts.app')

@section('header_title', 'User Account Management & Access Control — Root Control')

@section('content')
<div class="space-y-6">

    <!-- Header Section -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-amber-500/20 text-amber-300 border border-amber-500/30 uppercase tracking-wider">
                    Super Admin Root Gate
                </span>
                <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-slate-800 text-slate-300 border border-slate-700">
                    Total Accounts: {{ $stats['total'] }}
                </span>
            </div>
            <h2 class="text-xl sm:text-2xl font-bold text-white tracking-wide uppercase mt-1">
                User Management &amp; Access Control
            </h2>
            <p class="text-xs text-slate-400 mt-0.5">
                Centrally manage platform permissions, role configurations, account activations, and credential resets.
            </p>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('admin.users.create') }}" class="px-4 py-2 rounded-xl bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold text-xs uppercase tracking-wider transition shadow-lg shadow-teal-500/20 flex items-center gap-1.5 cursor-pointer">
                <span>➕</span>
                <span>Add New User</span>
            </a>
        </div>
    </div>

    <!-- Quick Stats Cards -->
    <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
        <div class="glass-panel p-3.5 rounded-xl border border-slate-800">
            <div class="text-[10px] uppercase font-bold text-slate-400 font-mono">Total Accounts</div>
            <div class="text-xl font-extrabold text-white font-mono mt-1">{{ $stats['total'] }}</div>
        </div>

        <div class="glass-panel p-3.5 rounded-xl border border-emerald-500/30 bg-emerald-500/5">
            <div class="text-[10px] uppercase font-bold text-emerald-400 font-mono">Active Accounts</div>
            <div class="text-xl font-extrabold text-emerald-300 font-mono mt-1">{{ $stats['active'] }}</div>
        </div>

        <div class="glass-panel p-3.5 rounded-xl border border-rose-500/30 bg-rose-500/5">
            <div class="text-[10px] uppercase font-bold text-rose-400 font-mono">Inactive Accounts</div>
            <div class="text-xl font-extrabold text-rose-300 font-mono mt-1">{{ $stats['inactive'] }}</div>
        </div>

        <div class="glass-panel p-3.5 rounded-xl border border-amber-500/30 bg-amber-500/5">
            <div class="text-[10px] uppercase font-bold text-amber-400 font-mono">Super Admin</div>
            <div class="text-xl font-extrabold text-amber-300 font-mono mt-1">{{ $stats['super_admin'] }}</div>
        </div>

        <div class="glass-panel p-3.5 rounded-xl border border-indigo-500/30 bg-indigo-500/5">
            <div class="text-[10px] uppercase font-bold text-indigo-400 font-mono">Google SSO</div>
            <div class="text-xl font-extrabold text-indigo-300 font-mono mt-1">{{ $stats['google_sso'] ?? 0 }}</div>
        </div>
    </div>

    <!-- Users Table -->
    <div class="glass-panel rounded-2xl border border-slate-800 overflow-hidden shadow-xl">
        <div class="overflow-x-auto responsive-table-container">
            <table class="w-full text-left text-xs min-w-[850px]">
                <thead class="bg-slate-900/90 border-b border-slate-800 text-slate-400 uppercase font-mono text-[10px] tracking-wider">
                    <tr>
                        <th class="py-3 px-4">User</th>
                        <th class="py-3 px-4">Role</th>
                        <th class="py-3 px-4">Organization</th>
                        <th class="py-3 px-4">Auth Method</th>
                        <th class="py-3 px-4">Status</th>
                        <th class="py-3 px-4">Last Login</th>
                        <th class="py-3 px-4">Registered</th>
                        <th class="py-3 px-4 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800 text-slate-200 font-sans">
                    @forelse($users as $u)
                    <tr class="hover:bg-slate-900/50 transition">
                        <!-- User Cell -->
                        <td class="py-3 px-4">
                            <div class="flex items-center gap-3">
                                <img src="{{ $u->avatar_url }}" alt="{{ $u->name }}" class="w-8 h-8 rounded-full object-cover border border-slate-700 shrink-0">
                                <div class="leading-tight">
                                    <a href="{{ route('admin.users.show', $u->id) }}" class="font-bold text-white hover:text-teal-300 transition">
                                        {{ $u->name }}
                                    </a>
                                    <div class="text-[11px] text-slate-400 font-mono">{{ $u->email }}</div>
                                </div>
                            </div>
                        </td>

                        <!-- Role Cell -->
                        <td class="py-3 px-4">
                            @if($u->role === 'super_admin')
                                <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-amber-500/15 text-amber-300 border border-amber-500/30 uppercase">
                                    SUPER ADMIN
                                </span>
                            @elseif($u->role === 'admin')
                                <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-blue-500/15 text-blue-300 border border-blue-500/30 uppercase">
                                    ADMIN
                                </span>
                            @elseif($u->role === 'analyst')
                                <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-teal-500/15 text-teal-300 border border-teal-500/30 uppercase">
                                    ANALYST
                                </span>
                            @else
                                <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-rose-500/15 text-rose-300 border border-rose-500/30 uppercase" title="Viewer role decommissioned: Account has no system access">
                                    DISABLED ({{ strtoupper($u->role) }})
                                </span>
                            @endif
                        </td>

                        <!-- Organization Cell -->
                        <td class="py-3 px-4 text-slate-300">
                            {{ $u->organization ?? '-' }}
                        </td>

                        <!-- Auth Method -->
                        <td class="py-3 px-4">
                            <div class="flex items-center gap-1.5">
                                @if($u->google_id)
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-indigo-500/10 text-indigo-300 border border-indigo-500/20 text-[10px] font-mono font-semibold" title="Linked Google SSO">
                                        <span>G</span> SSO
                                    </span>
                                @endif
                                @if($u->hasSetPassword())
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-slate-800 text-slate-300 border border-slate-700 text-[10px] font-mono" title="Has local password">
                                        🔑 Local
                                    </span>
                                @endif
                            </div>
                        </td>

                        <!-- Status Cell -->
                        <td class="py-3 px-4">
                            @if($u->is_active)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-mono font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                                    Active
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-mono font-semibold bg-rose-500/10 text-rose-400 border border-rose-500/20">
                                    <span class="w-1.5 h-1.5 rounded-full bg-rose-400"></span>
                                    Inactive
                                </span>
                            @endif
                        </td>

                        <!-- Last Login Cell -->
                        <td class="py-3 px-4">
                            @if($u->last_login_at)
                                <div class="font-mono text-slate-200 text-[11px]">{{ $u->last_login_at->format('d/m/Y H:i') }}</div>
                                <div class="text-[10px] text-teal-400 font-mono">{{ $u->last_login_at->diffForHumans() }}</div>
                            @else
                                <span class="text-slate-500 font-mono text-[10px] italic">Never</span>
                            @endif
                        </td>

                        <!-- Created At -->
                        <td class="py-3 px-4 text-slate-400 font-mono text-[11px]">
                            {{ $u->created_at ? $u->created_at->format('d/m/Y') : '-' }}
                        </td>

                        <!-- Actions -->
                        <td class="py-3 px-4 text-right">
                            <div class="flex items-center justify-end gap-1.5">
                                <a href="{{ route('admin.users.show', $u->id) }}" class="p-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white transition" title="View Profile">
                                    <span>👁️</span>
                                </a>
                                <a href="{{ route('admin.users.edit', $u->id) }}" class="p-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-teal-300 hover:text-teal-200 transition" title="Edit Account">
                                    <span>✏️</span>
                                </a>

                                @if($u->id !== auth()->id())
                                <form action="{{ route('admin.users.toggle-status', $u->id) }}" method="POST" class="inline" onsubmit="return confirm('Are you sure you want to change the active status of account {{ $u->name }}?');">
                                    @csrf
                                    <button type="submit" class="p-1.5 rounded-lg {{ $u->is_active ? 'bg-rose-500/10 hover:bg-rose-500/20 text-rose-300' : 'bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-300' }} transition cursor-pointer" title="{{ $u->is_active ? 'Deactivate Account' : 'Activate Account' }}">
                                        <span>{{ $u->is_active ? '⏸️' : '▶️' }}</span>
                                    </button>
                                </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="8" class="py-8 text-center text-slate-400">
                            No registered user accounts found.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $users->links('partials.pagination') }}
    </div>

</div>
@endsection

