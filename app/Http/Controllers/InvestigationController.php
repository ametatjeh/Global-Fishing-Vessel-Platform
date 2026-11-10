<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Investigation;
use App\Models\User;
use App\Models\Vessel;
use App\Services\Research\PrivateInvestigationService;
use Illuminate\Http\Request;

class InvestigationController extends Controller
{
    public function __construct(
        protected PrivateInvestigationService $investigationService
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();

        $filters = [
            'status' => $request->input('status'),
            'priority' => $request->input('priority'),
            'verdict_status' => $request->input('verdict_status'),
            'confidentiality_level' => $request->input('confidentiality_level'),
        ];

        $investigations = $this->investigationService
            ->getAuthorizedInvestigationsQuery($user, $filters)
            ->paginate(15)
            ->withQueryString();

        return view('investigations.index', compact('investigations'));
    }

    public function show(Request $request, string $id)
    {
        $investigation = Investigation::with([
            'targetVessel.latestPosition',
            'relatedEncounter.vessel1',
            'relatedEncounter.vessel2',
            'relatedDetection.scene',
            'relatedComplianceEvent.rule',
            'assignedTo',
            'createdBy',
            'leadResearcher',
        ])->findOrFail($id);

        $user = $request->user();

        if (!$this->investigationService->canUserAccess($user, $investigation)) {
            abort(403, 'Akses ditolak: Dokumen investigasi ini bersifat privat dan terbatas untuk tim peneliti yang memiliki otorisasi.');
        }

        // Forensic audit trail logging for viewing confidential investigation
        $this->investigationService->logAccess($user, $investigation, 'view_investigation');

        $vessel = $investigation->targetVessel;
        $identityAnalysis = $vessel ? $this->investigationService->analyzeIdentityAndFlagHistory($vessel) : null;
        $corporateAnalysis = $vessel ? $this->investigationService->analyzeCorporateNetwork($vessel) : null;
        $eezAnalysis = $vessel ? $this->investigationService->analyzeEezCompliance($vessel) : null;
        $iuuAnalysis = $vessel ? $this->investigationService->analyzeIuuAndAuthorization($vessel) : null;

        $canManage = $this->investigationService->canUserManage($user, $investigation);
        $canExport = $this->investigationService->canUserExport($user, $investigation);

        $analysts = User::where('is_active', true)
            ->whereIn('role', ['super_admin', 'admin', 'research_lead', 'researcher', 'analyst', 'institutional_researcher'])
            ->orderBy('name')
            ->get();

        return view('investigations.show', compact(
            'investigation',
            'identityAnalysis',
            'corporateAnalysis',
            'eezAnalysis',
            'iuuAnalysis',
            'canManage',
            'canExport',
            'analysts'
        ));
    }

    public function create(Request $request)
    {
        $user = $request->user();
        if (!$user->hasPermission('investigations.create')) {
            abort(403, 'You are not authorized to create investigation cases.');
        }

        $vessels = Vessel::orderBy('name')->limit(100)->get();
        $analysts = User::where('is_active', true)
            ->whereIn('role', ['super_admin', 'admin', 'research_lead', 'researcher', 'analyst', 'institutional_researcher'])
            ->orderBy('name')
            ->get();

        return view('investigations.create', compact('vessels', 'analysts'));
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user->hasPermission('investigations.create')) {
            abort(403, 'You are not authorized to create investigation cases.');
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'priority' => ['required', 'in:low,medium,high,urgent'],
            'confidentiality_level' => ['required', 'in:private,restricted,strictly_confidential'],
            'organization' => ['nullable', 'string', 'max:255'],
            'target_vessel_id' => ['nullable', 'exists:vessels,id'],
            'summary' => ['required', 'string'],
            'assigned_to_user_id' => ['nullable', 'exists:users,id'],
            'lead_user_id' => ['nullable', 'exists:users,id'],
            'focus_areas' => ['nullable', 'array'],
        ]);

        $caseNumber = 'INV-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));

        $investigation = Investigation::create([
            'case_number' => $caseNumber,
            'title' => $validated['title'],
            'priority' => $validated['priority'],
            'status' => 'open',
            'confidentiality_level' => $validated['confidentiality_level'],
            'organization' => $validated['organization'] ?? $user->organization,
            'target_vessel_id' => $validated['target_vessel_id'] ?? null,
            'summary' => $validated['summary'],
            'assigned_to_user_id' => $validated['assigned_to_user_id'] ?? $user->id,
            'lead_user_id' => $validated['lead_user_id'] ?? ($user->isResearchLead() ? $user->id : null),
            'created_by_user_id' => $user->id,
            'focus_areas' => $validated['focus_areas'] ?? [
                Investigation::FOCUS_IDENTITY_FLAG,
                Investigation::FOCUS_CORPORATE_OWNERSHIP,
                Investigation::FOCUS_EEZ_COMPLIANCE,
                Investigation::FOCUS_IUU_AUTHORIZATION,
            ],
            'verdict_status' => Investigation::VERDICT_UNDER_REVIEW,
        ]);

        $this->investigationService->logAccess($user, $investigation, 'create_investigation', [
            'title' => $investigation->title,
        ]);

        return redirect()->route('investigations.show', $investigation->id)
            ->with('status', "Private investigation dossier {$caseNumber} initiated successfully.");
    }

    public function updateFindings(Request $request, string $id)
    {
        $investigation = Investigation::findOrFail($id);
        $user = $request->user();

        if (!$this->investigationService->canUserManage($user, $investigation)) {
            abort(403, 'Access Denied: You do not have permissions to update findings on this investigation case.');
        }

        $validated = $request->validate([
            'verdict_status' => ['required', 'in:confirmed,suspected,potential,under_review,inconclusive'],
            'findings' => ['nullable', 'string'],
            'recommendation' => ['nullable', 'string'],
            'status' => ['nullable', 'in:open,in_progress,closed,archived'],
            'new_evidence_title' => ['nullable', 'string', 'max:255'],
            'new_evidence_notes' => ['nullable', 'string'],
        ]);

        $evidenceMatrix = $investigation->evidence_matrix ?? [];
        if (!empty($validated['new_evidence_title'])) {
            $evidenceMatrix[] = [
                'title' => $validated['new_evidence_title'],
                'notes' => $validated['new_evidence_notes'] ?? '',
                'recorded_by' => $user->name,
                'recorded_at' => now()->toIso8601String(),
            ];
        }

        $investigation->update([
            'verdict_status' => $validated['verdict_status'],
            'findings' => $validated['findings'] ?? $investigation->findings,
            'recommendation' => $validated['recommendation'] ?? $investigation->recommendation,
            'status' => $validated['status'] ?? $investigation->status,
            'evidence_matrix' => $evidenceMatrix,
        ]);

        $this->investigationService->logAccess($user, $investigation, 'update_investigation_findings', [
            'verdict_status' => $investigation->verdict_status,
            'status' => $investigation->status,
        ]);

        return redirect()->route('investigations.show', $investigation->id)
            ->with('status', 'Analysis findings and evidence matrix updated successfully.');
    }

    public function export(Request $request, string $id)
    {
        $investigation = Investigation::with(['targetVessel.latestPosition'])->findOrFail($id);
        $user = $request->user();

        if (!$this->investigationService->canUserExport($user, $investigation)) {
            abort(403, 'Access Denied: You do not have export permissions for this investigation dossier.');
        }

        $dossier = $this->investigationService->exportDossier($user, $investigation);

        $filename = 'dossier-' . $investigation->case_number . '-' . date('Ymd-His') . '.json';

        return response()->json($dossier, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'X-Confidentiality-Level' => $investigation->confidentiality_level ?? 'private',
        ]);
    }
}
