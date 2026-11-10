@extends('layouts.app')

@section('header_title', 'Open New Research Investigation Dossier (Private Research)')

@section('content')
<div class="max-w-3xl mx-auto space-y-6">

    <div class="glass-panel p-6 rounded-2xl border border-slate-800 shadow-xl space-y-4">
        <div class="border-b border-slate-800 pb-3 flex items-start justify-between">
            <div>
                <div class="flex items-center gap-2">
                    <h2 class="text-base font-bold text-white uppercase tracking-wider flex items-center gap-2">
                        <span>📁</span>
                        <span>Initiate Maritime Investigation &amp; Surveillance Case</span>
                    </h2>
                    <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-rose-500/20 text-rose-300 border border-rose-500/40">
                        RESTRICTED — RESEARCH ONLY
                    </span>
                </div>
                <p class="text-xs text-slate-400 mt-1">
                    Create a restricted investigation dossier for in-depth research on a target vessel: identity alterations, corporate networks, EEZ compliance, and fishery authorization validity.
                </p>
            </div>
        </div>

        <form action="{{ route('investigations.store') }}" method="POST" class="space-y-4 text-xs">
            @csrf

            <div>
                <label for="title" class="block font-semibold text-slate-300 mb-1">Investigation Title / Subject:</label>
                <input type="text" name="title" id="title" required placeholder="e.g. Flag Hopping & Beneficial Ownership Tracing of Distant-Water Fleet"
                    class="w-full px-3.5 py-2 rounded-xl bg-slate-900 border border-slate-700 text-slate-100 placeholder-slate-500 focus:outline-none focus:border-teal-400">
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="confidentiality_level" class="block font-semibold text-slate-300 mb-1">Confidentiality Level:</label>
                    <select name="confidentiality_level" id="confidentiality_level" class="w-full px-3.5 py-2 rounded-xl bg-slate-900 border border-slate-700 text-slate-100 focus:outline-none focus:border-teal-400">
                        <option value="private" selected>Private — Internal Research Team</option>
                        <option value="restricted">Restricted — Researchers &amp; Partner Institutions</option>
                        <option value="strictly_confidential">Strictly Confidential — Designated Leadership &amp; Analysts Only</option>
                    </select>
                </div>

                <div>
                    <label for="organization" class="block font-semibold text-slate-300 mb-1">Research Institution / Organization:</label>
                    <input type="text" name="organization" id="organization" value="{{ auth()->user()->organization }}" placeholder="e.g. Maritime Research Center / Conservation Institute"
                        class="w-full px-3.5 py-2 rounded-xl bg-slate-900 border border-slate-700 text-slate-100 placeholder-slate-500 focus:outline-none focus:border-teal-400">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="priority" class="block font-semibold text-slate-300 mb-1">Priority Level:</label>
                    <select name="priority" id="priority" class="w-full px-3.5 py-2 rounded-xl bg-slate-900 border border-slate-700 text-slate-100 focus:outline-none focus:border-teal-400">
                        <option value="low">Low</option>
                        <option value="medium" selected>Medium</option>
                        <option value="high">High</option>
                        <option value="urgent">Urgent</option>
                    </select>
                </div>

                <div>
                    <label for="target_vessel_id" class="block font-semibold text-slate-300 mb-1">Primary Target Vessel:</label>
                    <select name="target_vessel_id" id="target_vessel_id" class="w-full px-3.5 py-2 rounded-xl bg-slate-900 border border-slate-700 text-slate-100 focus:outline-none focus:border-teal-400">
                        <option value="">-- Unselected / Target Not Yet Registered --</option>
                        @foreach($vessels as $v)
                            <option value="{{ $v->id }}" {{ request('target_vessel_id') == $v->id ? 'selected' : '' }}>
                                {{ $v->name }} (MMSI: {{ $v->mmsi ?? '-' }} | Flag: {{ $v->flag_state ?? 'UN' }})
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="lead_user_id" class="block font-semibold text-slate-300 mb-1">Lead Researcher (Principal Investigator):</label>
                    <select name="lead_user_id" id="lead_user_id" class="w-full px-3.5 py-2 rounded-xl bg-slate-900 border border-slate-700 text-slate-100 focus:outline-none focus:border-teal-400">
                        <option value="">-- Unselected (Default: Self) --</option>
                        @foreach($analysts as $analyst)
                            <option value="{{ $analyst->id }}" {{ auth()->id() == $analyst->id ? 'selected' : '' }}>
                                {{ $analyst->name }} ({{ $analyst->role }} - {{ $analyst->organization ?? 'Internal' }})
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="assigned_to_user_id" class="block font-semibold text-slate-300 mb-1">Assign Operational Analyst:</label>
                    <select name="assigned_to_user_id" id="assigned_to_user_id" class="w-full px-3.5 py-2 rounded-xl bg-slate-900 border border-slate-700 text-slate-100 focus:outline-none focus:border-teal-400">
                        @foreach($analysts as $analyst)
                            <option value="{{ $analyst->id }}" {{ auth()->id() == $analyst->id ? 'selected' : '' }}>
                                {{ $analyst->name }} ({{ $analyst->role }})
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div>
                <label class="block font-semibold text-slate-300 mb-2">Primary Investigative Focus Areas:</label>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 p-3 rounded-xl bg-slate-900/80 border border-slate-800">
                    <label class="flex items-center gap-2 text-slate-300 cursor-pointer">
                        <input type="checkbox" name="focus_areas[]" value="vessel_identity_and_flag_hopping" checked class="rounded border-slate-700 text-teal-500 focus:ring-0">
                        <span>A. Identity Alteration &amp; Flag Hopping</span>
                    </label>
                    <label class="flex items-center gap-2 text-slate-300 cursor-pointer">
                        <input type="checkbox" name="focus_areas[]" value="corporate_network_and_beneficial_ownership" checked class="rounded border-slate-700 text-teal-500 focus:ring-0">
                        <span>B. Corporate Networks &amp; Beneficial Ownership</span>
                    </label>
                    <label class="flex items-center gap-2 text-slate-300 cursor-pointer">
                        <input type="checkbox" name="focus_areas[]" value="eez_boundary_compliance" checked class="rounded border-slate-700 text-teal-500 focus:ring-0">
                        <span>C. Geospatial Trajectory &amp; Suspected EEZ Boundary Violations</span>
                    </label>
                    <label class="flex items-center gap-2 text-slate-300 cursor-pointer">
                        <input type="checkbox" name="focus_areas[]" value="iuu_fishing_and_vessel_authorization" checked class="rounded border-slate-700 text-teal-500 focus:ring-0">
                        <span>D. IUU Fishing Risk &amp; Authorization Verification</span>
                    </label>
                </div>
            </div>

            <div>
                <label for="summary" class="block font-semibold text-slate-300 mb-1">Initial Case Summary / Research Hypothesis:</label>
                <textarea name="summary" id="summary" rows="4" required placeholder="Describe the background context, observed time range, supporting AIS telemetry or satellite radar evidence..."
                    class="w-full px-3.5 py-2 rounded-xl bg-slate-900 border border-slate-700 text-slate-100 placeholder-slate-500 focus:outline-none focus:border-teal-400"></textarea>
            </div>

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-800">
                <a href="{{ route('investigations.index') }}" class="px-4 py-2 rounded-xl bg-slate-800 text-slate-300 hover:bg-slate-700 font-semibold transition">
                    Cancel
                </a>
                <button type="submit" class="px-5 py-2 rounded-xl bg-teal-500 hover:bg-teal-400 text-slate-950 font-bold transition shadow-md shadow-teal-500/20">
                    Initiate Investigation Dossier
                </button>
            </div>
        </form>
    </div>

</div>
@endsection
