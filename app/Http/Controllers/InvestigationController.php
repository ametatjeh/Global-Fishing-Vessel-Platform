<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Investigation;
use App\Models\User;
use App\Models\Vessel;
use Illuminate\Http\Request;

class InvestigationController extends Controller
{
    public function index(Request $request)
    {
        $query = Investigation::with(['targetVessel', 'assignedTo', 'createdBy']);

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($priority = $request->input('priority')) {
            $query->where('priority', $priority);
        }

        $investigations = $query->orderBy('created_at', 'desc')->paginate(15);

        return view('investigations.index', compact('investigations'));
    }

    public function show(string $id)
    {
        $investigation = Investigation::with([
            'targetVessel.latestPosition',
            'relatedEncounter.vessel1',
            'relatedEncounter.vessel2',
            'relatedDetection.scene',
            'relatedComplianceEvent.rule',
            'assignedTo',
            'createdBy'
        ])->findOrFail($id);

        $analysts = User::whereIn('role', ['admin', 'analyst', 'super_admin'])->get();

        return view('investigations.show', compact('investigation', 'analysts'));
    }

    public function create()
    {
        $vessels = Vessel::orderBy('name')->limit(50)->get();
        $analysts = User::whereIn('role', ['admin', 'analyst', 'super_admin'])->get();

        return view('investigations.create', compact('vessels', 'analysts'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'priority' => ['required', 'in:low,medium,high,urgent'],
            'target_vessel_id' => ['nullable', 'exists:vessels,id'],
            'summary' => ['required', 'string'],
            'assigned_to_user_id' => ['nullable', 'exists:users,id'],
        ]);

        $caseNumber = 'INV-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));

        $investigation = Investigation::create([
            'case_number' => $caseNumber,
            'title' => $validated['title'],
            'priority' => $validated['priority'],
            'status' => 'open',
            'target_vessel_id' => $validated['target_vessel_id'] ?? null,
            'summary' => $validated['summary'],
            'assigned_to_user_id' => $validated['assigned_to_user_id'] ?? auth()->id(),
            'created_by_user_id' => auth()->id(),
        ]);

        AuditLog::log('create_investigation', 'investigation', $investigation->id, [
            'case_number' => $caseNumber,
            'title' => $investigation->title,
        ]);

        return redirect()->route('investigations.show', $investigation->id)
            ->with('status', "Dossier investigasi {$caseNumber} berhasil dibuka.");
    }
}
