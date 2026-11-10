<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\VesselEncounter;
use Illuminate\Http\Request;

class EncounterController extends Controller
{
    public function index(Request $request)
    {
        $query = VesselEncounter::with(['vessel1', 'vessel2', 'sourceProvider']);

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($category = $request->input('category')) {
            $query->where('category', $category);
        }

        if ($method = $request->input('method')) {
            $query->where('detection_method', $method);
        }

        $encounters = $query->orderBy('start_time', 'desc')->paginate(20)->withQueryString();

        $stats = [
            'total' => VesselEncounter::count(),
            'candidate' => VesselEncounter::where('status', 'candidate')->count(),
            'needs_review' => VesselEncounter::where('status', 'needs_review')->count(),
            'verified' => VesselEncounter::where('status', 'verified')->count(),
            'rejected' => VesselEncounter::where('status', 'rejected')->count(),
        ];

        return view('encounters.index', compact('encounters', 'stats'));
    }

    public function show(string $id)
    {
        $encounter = VesselEncounter::with(['vessel1.latestPosition', 'vessel2.latestPosition', 'sourceProvider', 'verifiedByUser'])
            ->findOrFail($id);

        return view('encounters.show', compact('encounter'));
    }

    public function updateStatus(Request $request, string $id)
    {
        $request->validate([
            'status' => ['required', 'in:candidate,needs_review,verified,rejected'],
            'analyst_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $encounter = VesselEncounter::findOrFail($id);

        $encounter->update([
            'status' => $request->input('status'),
            'analyst_notes' => $request->input('analyst_notes', $encounter->analyst_notes),
            'verified_by_user_id' => auth()->id(),
            'verified_at' => now(),
        ]);

        AuditLog::log('review_encounter', 'vessel_encounter', $encounter->id, [
            'new_status' => $encounter->status,
            'notes' => $request->input('analyst_notes'),
        ]);

        return redirect()->route('encounters.show', $id)
            ->with('status', "Vessel encounter status updated successfully to: {$encounter->status}");
    }
}
