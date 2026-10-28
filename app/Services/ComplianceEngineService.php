<?php

namespace App\Services;

use App\Models\ComplianceEvent;
use App\Models\ComplianceRule;
use App\Models\MaritimeZone;
use App\Models\Vessel;
use App\Models\VesselAuthorization;
use App\Models\VesselEncounter;
use App\Models\VesselPosition;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ComplianceEngineService
{
    /**
     * Evaluate compliance rules against vessel events and telemetry
     *
     * @param Vessel $vessel
     * @param VesselPosition|null $position
     * @return Collection<ComplianceEvent>
     */
    public function evaluateVessel(Vessel $vessel, ?VesselPosition $position = null): Collection
    {
        $events = collect();
        $position = $position ?? $vessel->latestPosition;

        if (!$position) {
            return $events;
        }

        $activeRules = ComplianceRule::where('is_active', true)->get();

        foreach ($activeRules as $rule) {
            $violation = $this->checkRule($rule, $vessel, $position);
            if ($violation) {
                // Prevent duplicate open alerts for same rule & vessel on the same day
                $alreadyReported = ComplianceEvent::where('compliance_rule_id', $rule->id)
                    ->where('vessel_id', $vessel->id)
                    ->whereDate('event_time', $position->observed_at->toDateString())
                    ->whereIn('status', ['open', 'under_review'])
                    ->exists();

                if (!$alreadyReported) {
                    $eventCode = 'ALRT-' . strtoupper(substr(md5($rule->id . $vessel->id . $position->observed_at), 0, 8));

                    $event = ComplianceEvent::create([
                        'event_code' => $eventCode,
                        'compliance_rule_id' => $rule->id,
                        'vessel_id' => $vessel->id,
                        'event_time' => $position->observed_at,
                        'latitude' => $position->latitude,
                        'longitude' => $position->longitude,
                        'severity' => $rule->severity,
                        'status' => 'open',
                        'evidence_summary' => $violation['summary'],
                        'supporting_data' => array_merge($violation['data'], [
                            'rule_name' => $rule->rule_name,
                            'legal_source' => $rule->legal_source,
                            'vessel_name' => $vessel->name,
                            'flag' => $vessel->flag_state,
                        ]),
                    ]);

                    $events->push($event);
                }
            }
        }

        return $events;
    }

    /**
     * Check individual rule logic against vessel status
     */
    protected function checkRule(ComplianceRule $rule, Vessel $vessel, VesselPosition $position): ?array
    {
        $logic = $rule->rule_logic ?? [];
        $category = $rule->category;

        switch ($category) {
            case 'license_validity':
                // Check if vessel has an active valid authorization
                $hasActivePermit = VesselAuthorization::where('vessel_id', $vessel->id)
                    ->where('status', 'active')
                    ->where('valid_from', '<=', $position->observed_at)
                    ->where('valid_to', '>=', $position->observed_at)
                    ->exists();

                if (!$hasActivePermit && ($logic['require_active_permit'] ?? false)) {
                    return [
                        'summary' => "Kapal {$vessel->name} belum terverifikasi memiliki dokumen izin aktif dalam basis data sistem pada waktu observasi.",
                        'data' => [
                            'observed_at' => $position->observed_at->toIso8601String(),
                            'position' => [$position->longitude, $position->latitude],
                            'requirement' => 'Valid fishing/support authorization',
                            'confidence_level' => 'tentative_unverified',
                            'legal_disclaimer' => 'Ketiadaan catatan izin dalam basis data lokal adalah indikasi perlunya verifikasi otoritas (unverified status), bukan bukti definitif bahwa izin tidak dimiliki dari negara bendera atau RFMO.',
                        ]
                    ];
                }
                break;

            case 'encounter_transshipment':
                // Check if vessel engaged in unverified high-seas or carrier encounter
                $recentEncounter = VesselEncounter::where(function ($q) use ($vessel) {
                    $q->where('vessel_1_id', $vessel->id)->orWhere('vessel_2_id', $vessel->id);
                })
                ->where('start_time', '>=', (clone $position->observed_at)->subHours(24))
                ->where('category', 'fishing_carrier')
                ->where('status', 'candidate')
                ->first();

                if ($recentEncounter && ($logic['flag_unverified_transshipment'] ?? false)) {
                    return [
                        'summary' => "Terdeteksi potensi encounter penangkapan–pengangkut (candidate transshipment) dengan kode {$recentEncounter->encounter_code}.",
                        'data' => [
                            'encounter_id' => $recentEncounter->id,
                            'encounter_code' => $recentEncounter->encounter_code,
                            'duration_minutes' => $recentEncounter->duration_minutes,
                            'min_distance_meters' => $recentEncounter->min_distance_meters,
                        ]
                    ];
                }
                break;

            case 'ais_gap':
                // Check if vessel had prolonged telemetry gap (> threshold hours)
                $maxGapHours = $logic['max_gap_hours'] ?? 8;
                $previousPosition = VesselPosition::where('vessel_id', $vessel->id)
                    ->where('observed_at', '<', $position->observed_at)
                    ->orderBy('observed_at', 'desc')
                    ->first();

                if ($previousPosition) {
                    $gapHours = (int) abs($position->observed_at->diffInHours($previousPosition->observed_at));
                    if ($gapHours >= $maxGapHours) {
                        return [
                            'summary' => "Terdeteksi diskontinuitas transmisi AIS selama {$gapHours} jam sebelum posisi tercatat saat ini.",
                            'data' => [
                                'gap_hours' => $gapHours,
                                'last_seen_prior' => $previousPosition->observed_at->toIso8601String(),
                                'reappeared_at' => $position->observed_at->toIso8601String(),
                            ]
                        ];
                    }
                }
                break;
        }

        return null;
    }
}
