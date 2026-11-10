<?php

namespace Tests\Feature;

use App\Models\EezBoundary;
use App\Models\EezZone;
use App\Models\User;
use App\Services\SpatialQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AseanEezMapRefactorTest extends TestCase
{
    use RefreshDatabase;

    protected User $analyst;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyst = User::factory()->create([
            'role' => 'analyst',
            'is_active' => true,
        ]);
    }

    public function test_asean_eez_api_requires_authenticated_authorized_user(): void
    {
        $response = $this->getJson('/api/layers/asean-eez');
        $this->assertTrue(in_array($response->status(), [302, 401, 403]));
    }

    public function test_asean_eez_api_returns_geojson_feature_collection(): void
    {
        $this->actingAs($this->analyst);

        // Seed sample ASEAN zone
        EezZone::create([
            'mrgid' => 8480,
            'geoname' => 'Indonesian Exclusive Economic Zone',
            'sovereign_state' => 'Indonesia',
            'sovereign_iso3' => 'IDN',
            'pol_type' => '200NM',
            'area_sqkm' => 6159032.0,
            'version' => 'v12',
            'source_name' => 'Flanders Marine Institute (VLIZ) Marine Regions',
            'license_name' => 'CC BY 4.0 International',
            'attribution_notice' => 'VLIZ Marine Regions',
            'geometry_geojson' => json_encode([
                'type' => 'Polygon',
                'coordinates' => [[[95.0, -10.0], [141.0, -10.0], [141.0, 6.0], [95.0, 6.0], [95.0, -10.0]]]
            ]),
        ]);

        // Seed sample ASEAN treaty boundary (Agreed)
        EezBoundary::create([
            'line_id' => 127,
            'line_name' => 'Indonesia - Malaysia',
            'line_type' => 'Treaty',
            'sovereign1' => 'Indonesia',
            'sovereign2' => 'Malaysia',
            'territory1' => 'Indonesia',
            'territory2' => 'Malaysia',
            'eez1' => 'Indonesian Exclusive Economic Zone',
            'eez2' => 'Malaysian Exclusive Economic Zone',
            'source1' => 'Treaty 1969-10-27',
            'doc_date' => '1969-10-27',
            'length_km' => 540.2,
            'geometry_geojson' => json_encode([
                'type' => 'LineString',
                'coordinates' => [[101.0, 1.5], [102.0, 2.0], [103.0, 2.5]]
            ]),
        ]);

        // Seed sample ASEAN median line boundary (Undelimited)
        EezBoundary::create([
            'line_id' => 128,
            'line_name' => 'Thailand - Malaysia Median Line',
            'line_type' => 'Median line',
            'sovereign1' => 'Thailand',
            'sovereign2' => 'Malaysia',
            'territory1' => 'Thailand',
            'territory2' => 'Malaysia',
            'eez1' => 'Thailand Exclusive Economic Zone',
            'eez2' => 'Malaysian Exclusive Economic Zone',
            'source1' => 'Median line calculation',
            'doc_date' => '1981-01-11',
            'length_km' => 210.0,
            'geometry_geojson' => json_encode([
                'type' => 'LineString',
                'coordinates' => [[100.5, 6.5], [101.5, 7.0]]
            ]),
        ]);

        $response = $this->getJson('/api/layers/asean-eez');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'type',
                'features' => [
                    '*' => [
                        'type',
                        'id',
                        'geometry' => ['type', 'coordinates'],
                        'properties' => [
                            'id',
                            'feature_type',
                            'country',
                            'delimitation_status',
                            'status_label',
                            'source_dataset',
                            'version',
                        ]
                    ]
                ],
                'metadata' => [
                    'title',
                    'scope',
                    'member_states',
                    'landlocked_states',
                    'count',
                    'polygon_count',
                    'boundary_line_count',
                    'statuses',
                    'dataset',
                ]
            ]);

        $this->assertEquals('FeatureCollection', $response->json('type'));
        $this->assertCount(3, $response->json('features'));
        $this->assertContains('Laos', $response->json('metadata.landlocked_states'));

        $features = collect($response->json('features'));
        $zone = $features->firstWhere('properties.feature_type', 'zone');
        $this->assertNotNull($zone);
        $this->assertEquals('Indonesia', $zone['properties']['country']);
        $this->assertEquals('AGREED', $zone['properties']['delimitation_status']);

        $treatyLine = $features->firstWhere('properties.line_type', 'Treaty');
        $this->assertNotNull($treatyLine);
        $this->assertEquals('AGREED', $treatyLine['properties']['delimitation_status']);

        $medianLine = $features->firstWhere('properties.line_type', 'Median line');
        $this->assertNotNull($medianLine);
        $this->assertEquals('UNDELIMITED', $medianLine['properties']['delimitation_status']);
    }

    public function test_asean_delimitation_status_classification_rules(): void
    {
        // 1. Agreed / Delimitated
        $resAgreed = SpatialQueryService::classifyBoundaryStatus('Treaty', 'Indonesia - Malaysia');
        $this->assertEquals('AGREED', $resAgreed['status']);

        $resCourt = SpatialQueryService::classifyBoundaryStatus('Court ruling', 'Myanmar - Bangladesh');
        $this->assertEquals('AGREED', $resCourt['status']);

        // 2. Undelimited / Unresolved
        $resMedian = SpatialQueryService::classifyBoundaryStatus('Median line', 'Median line Thailand - Malaysia');
        $this->assertEquals('UNDELIMITED', $resMedian['status']);

        $resConn = SpatialQueryService::classifyBoundaryStatus('Connection line', 'Connection line Vietnam - China');
        $this->assertEquals('UNDELIMITED', $resConn['status']);

        // 3. Disputed / Overlapping Claim
        $resDisputed = SpatialQueryService::classifyBoundaryStatus('Unsettled', 'Overlapping claim Malaysia / Singapore');
        $this->assertEquals('DISPUTED', $resDisputed['status']);

        // 4. Joint Regime Area
        $resJoint = SpatialQueryService::classifyBoundaryStatus('Joint regime', 'Malaysia - Thailand Joint Development Area');
        $this->assertEquals('JOINT_REGIME', $resJoint['status']);

        // 5. Unknown fallback
        $resUnknown = SpatialQueryService::classifyBoundaryStatus('Other', 'Custom unspecified line');
        $this->assertEquals('UNKNOWN', $resUnknown['status']);
    }

    public function test_existing_endpoints_support_asean_region_filtering(): void
    {
        $this->actingAs($this->analyst);

        EezZone::create([
            'mrgid' => 8480,
            'geoname' => 'Indonesian Exclusive Economic Zone',
            'sovereign_state' => 'Indonesia',
            'sovereign_iso3' => 'IDN',
            'pol_type' => '200NM',
            'geometry_geojson' => json_encode([
                'type' => 'Polygon',
                'coordinates' => [[[95.0, -10.0], [141.0, -10.0], [141.0, 6.0], [95.0, 6.0], [95.0, -10.0]]]
            ]),
        ]);

        $resEez = $this->getJson('/api/layers/eez?region=asean');
        $resEez->assertStatus(200);
        $this->assertEquals('FeatureCollection', $resEez->json('type'));
        $this->assertCount(1, $resEez->json('features'));

        $resBoundaries = $this->getJson('/api/layers/eez-boundaries?region=asean');
        $resBoundaries->assertStatus(200);
        $this->assertEquals('FeatureCollection', $resBoundaries->json('type'));
    }

    public function test_map_page_renders_asean_eez_layer_and_removes_obsolete_ui_elements(): void
    {
        $this->actingAs($this->analyst);

        $response = $this->get('/map');
        $response->assertStatus(200);

        // 1. New ASEAN EEZ layer and status legends must exist
        $response->assertSee('ASEAN Exclusive Economic Zones (EEZ)');
        $response->assertSee('toggle-layer-asean-eez');
        $this->assertTrue(
            str_contains($response->getContent(), 'ASEAN Maritime Boundary Status') ||
            str_contains($response->getContent(), 'Status Batas Maritim ASEAN')
        );
        $response->assertSee('Agreed / Delimitated');
        $response->assertSee('Undelimited / Unresolved');
        $response->assertSee('Disputed / Overlapping Claim');
        $response->assertSee('Joint Regime Area');

        // 2. Old UI items must NOT appear in the rendered HTML
        $response->assertDontSee('toggle-layer-eez-boundaries');
        $response->assertDontSee('toggle-layer-eez');
        $response->assertDontSee('toggle-layer-fao');
        $response->assertDontSee('World EEZ Zones (Poligon)');
        $response->assertDontSee('FAO Major Fishing Areas');
        $response->assertDontSee('btnToggleBasemapMenu');
        $response->assertDontSee('basemapMenuDropdown');
        $response->assertDontSee('OpenFreeMap Liberty');
        $response->assertDontSee('OpenFreeMap Bright');
        $response->assertDontSee('ESRI Dark Gray');

        // 3. Removed Right components must NOT appear
        $response->assertDontSee('btnOpenMetadataModal');
        $response->assertDontSee('Legal Provenance');
        $response->assertDontSee('Region Scope Context');
        $response->assertDontSee('Kawasan: ASEAN Exclusive Economic Zones');
    }
}

