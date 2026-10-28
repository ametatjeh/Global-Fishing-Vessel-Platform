<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ComplianceEvent;
use App\Models\ComplianceRule;
use App\Models\MaritimeZone;
use App\Models\VesselAuthorization;
use Illuminate\Http\Request;

class ComplianceController extends Controller
{
    public function index(Request $request)
    {
        $eventsQuery = ComplianceEvent::with(['rule', 'vessel', 'reviewer']);

        if ($status = $request->input('status')) {
            $eventsQuery->where('status', $status);
        }

        if ($severity = $request->input('severity')) {
            $eventsQuery->where('severity', $severity);
        }

        $events = $eventsQuery->orderBy('event_time', 'desc')->paginate(15, ['*'], 'alerts_page');

        $rules = ComplianceRule::orderBy('severity')->get();
        $maritimeZones = MaritimeZone::where('is_active', true)->limit(10)->get();
        $authorizations = VesselAuthorization::with(['vessel', 'maritimeZone'])->limit(10)->get();

        $stats = [
            'total_alerts' => ComplianceEvent::count(),
            'open_alerts' => ComplianceEvent::where('status', 'open')->count(),
            'critical_alerts' => ComplianceEvent::where('severity', 'critical')->count(),
            'active_rules' => ComplianceRule::where('is_active', true)->count(),
        ];

        return view('compliance.index', compact('events', 'rules', 'maritimeZones', 'authorizations', 'stats'));
    }

    public function showEvent(string $id)
    {
        $event = ComplianceEvent::with(['rule', 'vessel.latestPosition', 'reviewer'])->findOrFail($id);

        return view('compliance.show', compact('event'));
    }

    public function updateEventStatus(Request $request, string $id)
    {
        $request->validate([
            'status' => ['required', 'in:open,under_review,resolved,dismissed'],
            'review_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $event = ComplianceEvent::findOrFail($id);
        $event->update([
            'status' => $request->input('status'),
            'review_notes' => $request->input('review_notes'),
            'reviewed_by_user_id' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        AuditLog::log('review_compliance_alert', 'compliance_event', $event->id, [
            'status' => $event->status,
            'notes' => $request->input('review_notes'),
        ]);

        return redirect()->route('compliance.show', $id)
            ->with('status', "Status peringatan kepatuhan berhasil diperbarui menjadi: {$event->status}");
    }
}
