<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselPosition;
use App\Services\Maritime\VesselClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VesselClassificationTest extends TestCase
{
    use RefreshDatabase;

    protected function createTestVessel(array $attributes = []): Vessel
    {
        return Vessel::create(array_merge([
            'name' => 'TEST VESSEL',
            'mmsi' => '123456789',
            'vessel_type' => 'unknown',
            'verification_status' => 'provider_reported',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ], $attributes));
    }

    /**
     * 1. Test standard ITU AIS code mapping
     */
    public function test_standard_ais_code_taxonomy_mapping(): void
    {
        $this->assertEquals('fishing', VesselClassificationService::mapAisCodeToTaxonomy(30));
        $this->assertEquals('towing', VesselClassificationService::mapAisCodeToTaxonomy(31));
        $this->assertEquals('towing', VesselClassificationService::mapAisCodeToTaxonomy(32));
        $this->assertEquals('dredging', VesselClassificationService::mapAisCodeToTaxonomy(33));
        $this->assertEquals('diving', VesselClassificationService::mapAisCodeToTaxonomy(34));
        $this->assertEquals('military', VesselClassificationService::mapAisCodeToTaxonomy(35));
        $this->assertEquals('sailing', VesselClassificationService::mapAisCodeToTaxonomy(36));
        $this->assertEquals('pleasure', VesselClassificationService::mapAisCodeToTaxonomy(37));
        $this->assertEquals('high_speed_craft', VesselClassificationService::mapAisCodeToTaxonomy(40));
        $this->assertEquals('high_speed_craft', VesselClassificationService::mapAisCodeToTaxonomy(49));
        $this->assertEquals('pilot', VesselClassificationService::mapAisCodeToTaxonomy(50));
        $this->assertEquals('sar', VesselClassificationService::mapAisCodeToTaxonomy(51));
        $this->assertEquals('tug', VesselClassificationService::mapAisCodeToTaxonomy(52));
        $this->assertEquals('port_tender', VesselClassificationService::mapAisCodeToTaxonomy(53));
        $this->assertEquals('anti_pollution', VesselClassificationService::mapAisCodeToTaxonomy(54));
        $this->assertContains(VesselClassificationService::mapAisCodeToTaxonomy(55), ['law_enforcement', 'patrol']);
        $this->assertEquals('medical', VesselClassificationService::mapAisCodeToTaxonomy(58));
        $this->assertContains(VesselClassificationService::mapAisCodeToTaxonomy(59), ['other', 'unknown', 'non_combatant']);
        $this->assertEquals('passenger', VesselClassificationService::mapAisCodeToTaxonomy(60));
        $this->assertEquals('passenger', VesselClassificationService::mapAisCodeToTaxonomy(69));
        $this->assertEquals('cargo', VesselClassificationService::mapAisCodeToTaxonomy(70));
        $this->assertEquals('cargo', VesselClassificationService::mapAisCodeToTaxonomy(79));
        $this->assertEquals('tanker', VesselClassificationService::mapAisCodeToTaxonomy(80));
        $this->assertEquals('tanker', VesselClassificationService::mapAisCodeToTaxonomy(89));
        $this->assertEquals('other', VesselClassificationService::mapAisCodeToTaxonomy(90));
        $this->assertContains(VesselClassificationService::mapAisCodeToTaxonomy(99), ['other', 'unknown']);
        $this->assertEquals('not_available', VesselClassificationService::mapAisCodeToTaxonomy(0));
        $this->assertEquals('unknown', VesselClassificationService::mapAisCodeToTaxonomy(null));
        $this->assertEquals('unknown', VesselClassificationService::mapAisCodeToTaxonomy('invalid'));
    }

    /**
     * 2. Test verified manual classification is protected
     */
    public function test_verified_manual_classification_is_protected(): void
    {
        $vessel = $this->createTestVessel([
            'name' => 'VERIFIED CRAFT',
            'vessel_type' => 'fishing',
            'verification_status' => 'verified',
            'metadata' => [
                'registry_vessel_type' => 'cargo', // Registry says cargo, but analyst verified fishing
            ]
        ]);

        $service = app(VesselClassificationService::class);
        $result = $service->resolveClassification($vessel);

        $this->assertTrue($result['is_protected']);
        $this->assertFalse($result['eligible_for_change']);
        $this->assertEquals('fishing', $result['resolved_type']);
        $this->assertEquals('VERIFIED_MANUAL_REVIEW', $result['source']);
    }

    /**
     * 3. Test authoritative registry takes priority over unverified local type
     */
    public function test_authoritative_registry_takes_priority(): void
    {
        $vessel = $this->createTestVessel([
            'name' => 'REGISTRY CRAFT',
            'vessel_type' => 'unknown',
            'verification_status' => 'provider_reported',
            'metadata' => [
                'registry_vessel_type' => 'patrol',
            ]
        ]);

        $service = app(VesselClassificationService::class);
        $result = $service->resolveClassification($vessel);

        $this->assertFalse($result['is_protected']);
        $this->assertTrue($result['eligible_for_change']);
        $this->assertEquals('patrol', $result['resolved_type']);
        $this->assertEquals('AUTHORITATIVE_REGISTRY', $result['source']);
    }

    /**
     * 4. Test AIS static payload resolution
     */
    public function test_ais_static_payload_resolution(): void
    {
        $vessel = $this->createTestVessel([
            'name' => 'TUG BOAT 1',
            'vessel_type' => 'unknown',
            'verification_status' => 'provider_reported',
        ]);

        VesselPosition::create([
            'vessel_id' => $vessel->id,
            'latitude' => 1.0,
            'longitude' => 104.0,
            'observed_at' => now(),
            'received_at' => now(),
            'raw_payload' => json_encode(['ShipType' => 52]), // 52 = Tug
        ]);

        $service = app(VesselClassificationService::class);
        $result = $service->resolveClassification($vessel);

        $this->assertTrue($result['eligible_for_change']);
        $this->assertEquals('tug', $result['resolved_type']);
        $this->assertEquals('AIS_STATIC_MESSAGE_SHIPTYPE', $result['source']);
    }

    /**
     * 5. Test conflict detection between local type and authoritative source
     */
    public function test_conflict_detection_when_sources_disagree(): void
    {
        $vessel = $this->createTestVessel([
            'name' => 'CONFLICT CRAFT',
            'vessel_type' => 'fishing', // Local says fishing
            'verification_status' => 'provider_reported',
            'metadata' => [
                'registry_vessel_type' => 'passenger', // Registry says passenger
            ]
        ]);

        $service = app(VesselClassificationService::class);
        $result = $service->resolveClassification($vessel);

        $this->assertTrue($result['has_conflict']);
        $this->assertStringContainsString('contradicts registry type', $result['conflict_reason']);
    }

    /**
     * 6. Test dry run does not modify database
     */
    public function test_dry_run_command_does_not_modify_database(): void
    {
        $vessel = $this->createTestVessel([
            'name' => 'DRY RUN CRAFT',
            'vessel_type' => 'unknown',
            'metadata' => [
                'registry_vessel_type' => 'passenger',
            ]
        ]);

        $this->artisan('vessels:classify', [
            '--vessel' => $vessel->id,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('DRY-RUN SIMULATION')
            ->expectsOutputToContain('CANDIDATE CLASSIFICATION CHANGES')
            ->expectsOutputToContain('passenger')
            ->assertSuccessful();

        $vessel->refresh();
        $this->assertEquals('unknown', $vessel->vessel_type);
    }

    /**
     * 7. Test apply mode updates database with audit log
     */
    public function test_apply_mode_updates_database_with_audit_trail(): void
    {
        $vessel = $this->createTestVessel([
            'name' => 'APPLY CRAFT',
            'vessel_type' => 'unknown',
            'metadata' => [
                'registry_vessel_type' => 'passenger',
            ]
        ]);

        $this->artisan('vessels:classify', [
            '--vessel' => $vessel->id,
            '--apply' => true,
        ])
            ->expectsOutputToContain('APPLY MODE')
            ->expectsOutputToContain('Successfully updated 1 vessel record')
            ->assertSuccessful();

        $vessel->refresh();
        $this->assertEquals('passenger', $vessel->vessel_type);
        $this->assertNotNull($vessel->metadata['classification_audit']);
        $this->assertEquals('AUTHORITATIVE_REGISTRY', $vessel->metadata['classification_audit']['source']);
    }

    /**
     * 8. Test frontend vessels table filter by fleet type
     */
    public function test_frontend_vessels_index_filters_by_fleet_type(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);

        $fishingVessel = $this->createTestVessel(['name' => 'FISHING ONE', 'vessel_type' => 'fishing']);
        $passengerVessel = $this->createTestVessel(['name' => 'PASSENGER ONE', 'vessel_type' => 'passenger']);

        $response = $this->actingAs($user)->get(route('vessels.index', ['vessel_type' => 'passenger']));

        $response->assertStatus(200);
        $response->assertSee('PASSENGER ONE');
        $response->assertDontSee('FISHING ONE');
    }
}

