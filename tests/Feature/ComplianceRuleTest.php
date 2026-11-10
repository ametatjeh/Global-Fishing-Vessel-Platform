<?php

namespace Tests\Feature;

use App\Models\ComplianceEvent;
use App\Models\ComplianceRule;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselPosition;
use App\Services\ComplianceEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComplianceRuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_compliance_rule_evaluates_ais_gap_indicator(): void
    {
        $vessel = Vessel::create([
            'name' => 'F/V SEA HUNTER',
            'mmsi' => '525999001',
            'vessel_type' => 'Trawler',
            'flag_state' => 'IDN',
        ]);

        $rule = ComplianceRule::create([
            'rule_code' => 'GAP-AIS-001',
            'rule_name' => 'Prolonged AIS Transponder Deactivation in Maritime Zone',
            'jurisdiction' => 'UNCLOS / Flag State Maritime Authority',
            'category' => 'ais_gap',
            'severity' => 'high',
            'rule_logic' => ['max_gap_hours' => 4],
            'legal_source' => 'SOLAS Chapter V Reg 19 / IMO Res A.1106(29)',
            'version' => '1.0',
            'is_active' => true,
        ]);

        // Prior position 10 hours ago
        VesselPosition::create([
            'vessel_id' => $vessel->id,
            'latitude' => -3.5000,
            'longitude' => 108.2000,
            'speed_knots' => 4.0,
            'observed_at' => now()->subHours(10),
            'received_at' => now()->subHours(10),
        ]);

        // Current position now (10h gap > 4h threshold)
        $currentPos = VesselPosition::create([
            'vessel_id' => $vessel->id,
            'latitude' => -3.6000,
            'longitude' => 108.3000,
            'speed_knots' => 4.2,
            'observed_at' => now(),
            'received_at' => now(),
            'is_gap_indicator' => true,
        ]);

        $service = app(ComplianceEngineService::class);
        $events = $service->evaluateVessel($vessel, $currentPos);

        $this->assertCount(1, $events);
        $this->assertDatabaseHas('compliance_events', [
            'vessel_id' => $vessel->id,
            'compliance_rule_id' => $rule->id,
            'status' => 'open',
            'severity' => 'high',
        ]);

        $event = ComplianceEvent::first();
        $this->assertEquals('high', $event->severity);
        $this->assertStringContainsString('diskon', $event->evidence_summary);
    }

    public function test_compliance_events_list_page_renders_for_analyst(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);

        $response = $this->actingAs($user)->get('/compliance');

        $response->assertStatus(200);
        $response->assertSee('Fishing Compliance');
        $response->assertSee('Global Maritime Compliance Rule Catalog');
    }
}
