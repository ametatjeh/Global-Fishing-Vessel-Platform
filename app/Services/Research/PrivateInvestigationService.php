<?php

namespace App\Services\Research;

use App\Models\AuditLog;
use App\Models\ComplianceEvent;
use App\Models\EezZone;
use App\Models\Investigation;
use App\Models\ProhibitedVessel;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselAuthorization;
use App\Models\VesselIdentifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class PrivateInvestigationService
{
    /**
     * Verify whether a user is authorized to access an investigation dossier.
     */
    public function canUserAccess(User $user, Investigation $investigation): bool
    {
        // 1. Inactive or decommissioned roles are strictly denied
        if (!$user->is_active || $user->role === 'viewer') {
            return false;
        }

        // 2. Super Administrator has root supervisory authority
        if ($user->isSuperAdmin()) {
            return true;
        }

        // 3. User is directly assigned or created the dossier
        if ($investigation->assigned_to_user_id === $user->id || $investigation->created_by_user_id === $user->id) {
            return true;
        }

        // 4. User is assigned as Lead Researcher
        if ($investigation->lead_user_id === $user->id) {
            return true;
        }

        // 5. User is explicitly included in collaborator IDs
        $collaborators = $investigation->collaborator_ids ?? [];
        if (in_array($user->id, $collaborators, false)) {
            return true;
        }

        // 6. Research Lead can access investigations scoped to their organization
        if ($user->isResearchLead() && !empty($user->organization) && !empty($investigation->organization)) {
            if (strcasecmp($user->organization, $investigation->organization) === 0) {
                return true;
            }
        }

        // 7. System Administrator has access only if organization matches or if assigned
        if ($user->role === 'admin' && !empty($user->organization) && !empty($investigation->organization)) {
            if (strcasecmp($user->organization, $investigation->organization) === 0) {
                return true;
            }
        }

        // All other users (including unassigned researchers or different institutions) are denied
        return false;
    }

    /**
     * Verify whether a user can update findings or modify an investigation dossier.
     */
    public function canUserManage(User $user, Investigation $investigation): bool
    {
        if (!$this->canUserAccess($user, $investigation)) {
            return false;
        }

        if ($user->isSuperAdmin() || $user->isResearchLead()) {
            return true;
        }

        // Assigned researcher or creator can update findings
        if ($investigation->assigned_to_user_id === $user->id || $investigation->created_by_user_id === $user->id) {
            return true;
        }

        $collaborators = $investigation->collaborator_ids ?? [];
        return in_array($user->id, $collaborators, false);
    }

    /**
     * Verify whether a user can export an investigation dossier.
     */
    public function canUserExport(User $user, Investigation $investigation): bool
    {
        if (!$this->canUserAccess($user, $investigation)) {
            return false;
        }

        return $user->hasPermission('investigations.export');
    }

    /**
     * Build an authorization-scoped query for investigations.
     */
    public function getAuthorizedInvestigationsQuery(User $user, array $filters = []): Builder
    {
        $query = Investigation::with(['targetVessel', 'assignedTo', 'createdBy', 'leadResearcher']);

        // Inactive or viewer users see nothing
        if (!$user->is_active || $user->role === 'viewer') {
            return $query->whereRaw('1 = 0');
        }

        // Super Administrator sees all dossiers
        if (!$user->isSuperAdmin()) {
            $query->where(function (Builder $sub) use ($user) {
                $sub->where('assigned_to_user_id', $user->id)
                    ->orWhere('created_by_user_id', $user->id)
                    ->orWhere('lead_user_id', $user->id)
                    ->orWhereJsonContains('collaborator_ids', $user->id);

                // Scope to organization for Research Lead
                if ($user->isResearchLead() && !empty($user->organization)) {
                    $sub->orWhere('organization', $user->organization);
                }
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if (!empty($filters['verdict_status'])) {
            $query->where('verdict_status', $filters['verdict_status']);
        }

        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Focus A: Analyze Vessel Identity & Flag Hopping History.
     */
    public function analyzeIdentityAndFlagHistory(Vessel $vessel): array
    {
        $identifiers = VesselIdentifier::where('vessel_id', $vessel->id)
            ->orderBy('valid_from', 'desc')
            ->get();

        $historyTimeline = [];
        $flagHoppingDetected = false;
        $uniqueFlags = [];

        if ($vessel->flag_state) {
            $uniqueFlags[] = strtoupper($vessel->flag_state);
        }

        foreach ($identifiers as $idRecord) {
            $type = $idRecord->identifier_type;
            $val = $idRecord->identifier_value;

            if ($type === 'flag' && !empty($val)) {
                $uniqueFlags[] = strtoupper($val);
            }

            $historyTimeline[] = [
                'type' => $type,
                'value' => $val,
                'is_current' => (bool) $idRecord->is_current,
                'valid_from' => $idRecord->valid_from?->toIso8601String(),
                'valid_to' => $idRecord->valid_to?->toIso8601String(),
                'source_provider' => $idRecord->sourceProvider?->name ?? 'AIS Static Telemetry / Registry',
                'provenance' => $idRecord->metadata_json['provenance'] ?? 'Observed AIS Type 5/24 or GFW Identity',
            ];
        }

        $uniqueFlags = array_values(array_unique($uniqueFlags));
        if (count($uniqueFlags) > 1) {
            $flagHoppingDetected = true;
        }

        return [
            'focus' => 'vessel_identity_and_flag_hopping',
            'current_identity' => [
                'name' => $vessel->name,
                'mmsi' => $vessel->mmsi,
                'imo' => $vessel->imo,
                'callsign' => $vessel->callsign,
                'flag_state' => $vessel->flag_state,
                'hull_code' => $vessel->hull_code,
            ],
            'flag_hopping_detected' => $flagHoppingDetected,
            'observed_flags' => $uniqueFlags,
            'identity_timeline' => $historyTimeline,
            'provenance_notes' => 'Riwayat perubahan identitas dan bendera divalidasi dari rekonsiliasi data AIS telemetri dan GFW Identity dataset.',
        ];
    }

    /**
     * Focus B: Analyze Corporate Network & Beneficial Ownership.
     */
    public function analyzeCorporateNetwork(Vessel $vessel): array
    {
        $registeredOwner = $vessel->owner ?? 'Tidak Tercatat di Pangkalan Data Resmi';
        $operator = $vessel->operator ?? 'Tidak Tercatat';

        // Check metadata for verified beneficial ownership documentation
        $meta = $vessel->metadata ?? [];
        $beneficialOwner = $meta['beneficial_owner'] ?? null;
        $confidence = $meta['ownership_confidence'] ?? 'unverified';
        $ownershipSource = $meta['ownership_source'] ?? 'Equasis / IHS Maritime / National Fisheries Registry';

        // Find sister vessels sharing the same operator or owner
        $sisterVessels = [];
        if (!empty($vessel->operator) && $vessel->operator !== 'Tidak Tercatat') {
            $sisterVessels = Vessel::where('id', '!=', $vessel->id)
                ->where(function ($q) use ($vessel) {
                    $q->where('operator', $vessel->operator)
                        ->orWhere('owner', $vessel->owner);
                })
                ->limit(10)
                ->get(['id', 'name', 'mmsi', 'flag_state', 'vessel_type', 'operator', 'owner'])
                ->toArray();
        }

        return [
            'focus' => 'corporate_network_and_beneficial_ownership',
            'registered_owner' => $registeredOwner,
            'beneficial_owner' => $beneficialOwner ?: 'Belum Tervalidasi Dokumen Kepemilikan Sah',
            'operator' => $operator,
            'confidence_level' => $confidence,
            'verification_date' => $meta['ownership_verified_at'] ?? now()->toDateString(),
            'source_reference' => $ownershipSource,
            'related_fleet_count' => count($sisterVessels),
            'related_sister_vessels' => $sisterVessels,
            'investigative_disclaimer' => 'Kepemilikan sebenarnya (beneficial ownership) disimpulkan hanya berdasarkan bukti dokumentasi resmi dan struktur hukum korporasi terverifikasi, bukan kemiripan nama atau alamat.',
        ];
    }

    /**
     * Focus C: Analyze Suspected EEZ Boundary Violations.
     */
    public function analyzeEezCompliance(Vessel $vessel): array
    {
        $events = ComplianceEvent::with('rule')
            ->where('vessel_id', $vessel->id)
            ->latest('event_time')
            ->limit(10)
            ->get();

        $latestPos = $vessel->latestPosition;
        $eezInfractions = [];
        $anomaliesCount = 0;

        foreach ($events as $event) {
            $isBoundaryRelated = in_array($event->rule?->category, ['spatial_entry', 'dark_vessel_gap'], true);
            if ($isBoundaryRelated) {
                $anomaliesCount++;
                $eezInfractions[] = [
                    'event_id' => $event->id,
                    'rule' => $event->rule?->rule_name ?? 'EEZ Territorial Monitoring',
                    'severity' => $event->severity,
                    'status' => $event->status,
                    'event_time' => $event->event_time?->toIso8601String(),
                    'coordinates' => [
                        'latitude' => $event->latitude,
                        'longitude' => $event->longitude,
                    ],
                    'classification' => ($event->status === 'confirmed' ? 'Pelanggaran Terbukti' : 'Indikasi Anomali / Perlu Telaah'),
                ];
            }
        }

        return [
            'focus' => 'eez_boundary_compliance',
            'target_vessel' => $vessel->name,
            'latest_position' => $latestPos ? [
                'latitude' => $latestPos->latitude,
                'longitude' => $latestPos->longitude,
                'speed_knots' => $latestPos->speed_knots,
                'observed_at' => $latestPos->observed_at?->toIso8601String(),
            ] : null,
            'anomalies_detected_count' => $anomaliesCount,
            'boundary_events' => $eezInfractions,
            'methodology_note' => 'Analisis lintasan batas ZEE membedakan secara tegas antara indikasi anomali geospasial (AIS gap/entry) dan pelanggaran hukum yang terbukti secara formil.',
        ];
    }

    /**
     * Focus D: Analyze IUU Fishing & Vessel Authorizations.
     */
    public function analyzeIuuAndAuthorization(Vessel $vessel): array
    {
        $isBlacklisted = ProhibitedVessel::isProhibited((string) $vessel->mmsi);

        $authorizations = VesselAuthorization::where('vessel_id', $vessel->id)
            ->orderBy('valid_to', 'desc')
            ->get();

        $activeAuth = $authorizations->firstWhere('status', 'active');
        $hasActiveAuth = $activeAuth && $activeAuth->valid_to && $activeAuth->valid_to->isFuture();

        // Categorize into the 5 rigorous research categories
        $verdictCategory = 'insufficient_data';
        $verdictRationale = '';

        if ($isBlacklisted) {
            $verdictCategory = 'verified_violation';
            $verdictRationale = 'Kapal tercatat dalam Daftar Hitam IUU Fishing / Kapal Terlarang resmi.';
        } elseif (!$hasActiveAuth && $vessel->vessel_type === 'fishing' && $authorizations->isNotEmpty()) {
            $verdictCategory = 'suspected_violation';
            $verdictRationale = 'Izin penangkapan ikan telah kedaluwarsa atau dibekukan saat kapal terpantau aktif.';
        } elseif ($hasActiveAuth && $activeAuth->valid_to->lte(now()->addDays(30))) {
            $verdictCategory = 'potential_risk';
            $verdictRationale = 'Otorisasi penangkapan aktif namun mendekati masa berakhir (kurang dari 30 hari).';
        } elseif ($hasActiveAuth) {
            $verdictCategory = 'no_evidence_found';
            $verdictRationale = 'Kapal memiliki otorisasi penangkapan aktif yang sah dari otoritas maritim penerbit.';
        } else {
            $verdictCategory = 'insufficient_data';
            $verdictRationale = 'Data perizinan resmi belum tersedia atau belum terdaftar dalam basis data otorisasi maritim.';
        }

        $authList = [];
        foreach ($authorizations as $auth) {
            $authList[] = [
                'code' => $auth->authorization_code,
                'permit_type' => $auth->permit_type,
                'issuing_authority' => $auth->issuing_authority,
                'target_species' => $auth->target_species,
                'gear' => $auth->authorized_gear,
                'valid_from' => $auth->valid_from?->toDateString(),
                'valid_to' => $auth->valid_to?->toDateString(),
                'status' => $auth->status,
                'is_current' => $auth->valid_to?->isFuture() ?? false,
            ];
        }

        return [
            'focus' => 'iuu_fishing_and_vessel_authorization',
            'verdict_category' => $verdictCategory,
            'verdict_rationale' => $verdictRationale,
            'iuu_blacklist_status' => $isBlacklisted ? 'BLACKLISTED' : 'CLEAN',
            'authorizations_count' => count($authList),
            'authorizations' => $authList,
            'audit_standard' => 'Pemeriksaan otorisasi membedakan secara tegas: verified_violation, suspected_violation, potential_risk, no_evidence_found, dan insufficient_data.',
        ];
    }

    /**
     * Log investigation access, modification, or export in the forensic audit trail.
     */
    public function logAccess(User $user, Investigation $investigation, string $action, array $extra = []): void
    {
        AuditLog::log($action, 'investigation', (string) $investigation->id, array_merge([
            'case_number' => $investigation->case_number,
            'user_email' => $user->email,
            'user_role' => $user->role,
            'organization' => $user->organization,
            'confidentiality' => $investigation->confidentiality_level,
        ], $extra));
    }

    /**
     * Export complete private investigation dossier.
     */
    public function exportDossier(User $user, Investigation $investigation): array
    {
        $this->logAccess($user, $investigation, 'export_investigation', [
            'export_format' => 'dossier_json',
        ]);

        $vessel = $investigation->targetVessel;
        $identityAnalysis = $vessel ? $this->analyzeIdentityAndFlagHistory($vessel) : null;
        $corporateAnalysis = $vessel ? $this->analyzeCorporateNetwork($vessel) : null;
        $eezAnalysis = $vessel ? $this->analyzeEezCompliance($vessel) : null;
        $iuuAnalysis = $vessel ? $this->analyzeIuuAndAuthorization($vessel) : null;

        return [
            'dossier_metadata' => [
                'case_number' => $investigation->case_number,
                'title' => $investigation->title,
                'confidentiality_level' => $investigation->confidentiality_level ?? 'private',
                'watermark' => 'RESTRICTED — RESEARCH TEAM ONLY — DO NOT DISTRIBUTE',
                'exported_by' => $user->name . ' (' . $user->email . ')',
                'institution' => $user->organization ?? $investigation->organization ?? 'GFVIP Intelligence Team',
                'exported_at' => now()->toIso8601String(),
                'verdict_status' => $investigation->verdict_status ?? 'under_review',
            ],
            'case_overview' => [
                'priority' => $investigation->priority,
                'status' => $investigation->status,
                'summary' => $investigation->summary,
                'findings' => $investigation->findings,
                'recommendation' => $investigation->recommendation,
                'target_vessel' => $vessel ? [
                    'name' => $vessel->name,
                    'mmsi' => $vessel->mmsi,
                    'imo' => $vessel->imo,
                    'flag_state' => $vessel->flag_state,
                    'vessel_type' => $vessel->vessel_type,
                ] : null,
            ],
            'investigative_analyses' => [
                'focus_a_identity_and_flag' => $identityAnalysis,
                'focus_b_corporate_network' => $corporateAnalysis,
                'focus_c_eez_compliance' => $eezAnalysis,
                'focus_d_iuu_and_authorizations' => $iuuAnalysis,
            ],
            'evidence_matrix' => $investigation->evidence_matrix ?? [],
        ];
    }
}
