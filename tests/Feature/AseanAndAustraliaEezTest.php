<?php

namespace Tests\Feature;

use App\Models\EezBoundary;
use App\Models\EezZone;
use App\Models\User;
use App\Services\SpatialQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AseanAndAustraliaEezTest extends TestCase
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

    public function test_australia_eez_api_requires_authenticated_user(): void
    {
        $response = $this->getJson('/api/layers/australia-eez');
        $this->assertTrue(in_array($response->status(), [302, 401, 403]));
    }

    public function test_australia_eez_api_returns_verified_geojson_feature_collection(): void
    {
        $this->actingAs($this->analyst);

        // Seed genuine Australia EEZ Zone (Mainland Australia)
        EezZone::create([
            'mrgid' => 8323,
            'geoname' => 'Australian Exclusive Economic Zone',
            'sovereign_state' => 'Australia',
            'sovereign_iso3' => 'AUS',
            'territory1' => 'Australia',
            'pol_type' => '200NM',
            'area_sqkm' => 6873830.0,
            'version' => 'v12',
            'source_name' => 'Flanders Marine Institute (VLIZ) Marine Regions',
            'license_name' => 'Creative Commons Attribution 4.0 International (CC BY 4.0)',
            'attribution_notice' => 'VLIZ Marine Regions & Geoscience Australia (AMB)',
            'geometry_geojson' => json_encode([
                'type' => 'Polygon',
                'coordinates' => [[[110.0, -10.0], [155.0, -10.0], [155.0, -45.0], [110.0, -45.0], [110.0, -10.0]]]
            ]),
        ]);

        // Seed Australia 200 NM Outer Limit Line
        EezBoundary::create([
            'line_id' => 2059,
            'line_name' => 'Australia 200 NM',
            'line_type' => '200 NM',
            'sovereign1' => 'Australia',
            'sovereign2' => null,
            'territory1' => 'Australia',
            'eez1' => 'Australian Exclusive Economic Zone',
            'source1' => 'Geoscience Australia AMB / UNCLOS 1982',
            'length_km' => 10790.68,
            'geometry_geojson' => json_encode([
                'type' => 'LineString',
                'coordinates' => [[110.0, -10.0], [115.0, -8.0], [120.0, -9.0]]
            ]),
        ]);

        // Seed Australia - Indonesia Bilateral Treaty Line
        EezBoundary::create([
            'line_id' => 1288,
            'line_name' => 'Indonesia - Australia',
            'line_type' => 'Treaty',
            'sovereign1' => 'Indonesia',
            'sovereign2' => 'Australia',
            'territory1' => 'Indonesia',
            'territory2' => 'Australia',
            'eez1' => 'Indonesian Exclusive Economic Zone',
            'eez2' => 'Australian Exclusive Economic Zone',
            'source1' => 'Perth Treaty 1997-03-14',
            'doc_date' => '1997-03-14',
            'length_km' => 2354.58,
            'geometry_geojson' => json_encode([
                'type' => 'LineString',
                'coordinates' => [[123.0, -11.0], [128.0, -10.5], [134.0, -9.5]]
            ]),
        ]);

        // Seed Australia - Indonesia Median Line
        EezBoundary::create([
            'line_id' => 3586,
            'line_name' => 'Christmas Island (Australia) - Indonesia',
            'line_type' => 'Median line',
            'sovereign1' => 'Australia',
            'sovereign2' => 'Indonesia',
            'territory1' => 'Christmas Island',
            'territory2' => 'Indonesia',
            'eez1' => 'Australian Exclusive Economic Zone (Christmas Island)',
            'eez2' => 'Indonesian Exclusive Economic Zone',
            'source1' => 'Equidistant Line calculation',
            'length_km' => 808.23,
            'geometry_geojson' => json_encode([
                'type' => 'LineString',
                'coordinates' => [[105.0, -10.0], [106.0, -9.8]]
            ]),
        ]);

        $response = $this->getJson('/api/layers/australia-eez');

        $response->assertOk()
            ->assertJsonStructure([
                'type',
                'features',
                'metadata' => [
                    'title',
                    'scope',
                    'count',
                    'polygon_count',
                    'boundary_line_count',
                    'source',
                    'doi',
                    'license',
                ]
            ]);

        $data = $response->json();
        $this->assertEquals('FeatureCollection', $data['type']);
        $this->assertEquals(4, $data['metadata']['count']);
        $this->assertEquals(1, $data['metadata']['polygon_count']);
        $this->assertEquals(3, $data['metadata']['boundary_line_count']);
        $this->assertStringContainsString('Australia', $data['metadata']['title']);

        // Verify status classification
        $features = collect($data['features']);
        $treatyFeat = $features->firstWhere('properties.line_type', 'Treaty');
        $this->assertNotNull($treatyFeat);
        $this->assertEquals('AGREED', $treatyFeat['properties']['delimitation_status']);
        $this->assertEquals('Australia', $treatyFeat['properties']['country']);

        $outerFeat = $features->firstWhere('properties.line_type', '200 NM');
        $this->assertNotNull($outerFeat);
        $this->assertEquals('200NM_LIMIT', $outerFeat['properties']['delimitation_status']);

        $medianFeat = $features->firstWhere('properties.line_type', 'Median line');
        $this->assertNotNull($medianFeat);
        $this->assertEquals('UNDELIMITED', $medianFeat['properties']['delimitation_status']);
    }

    public function test_layers_catalog_includes_both_asean_and_australia_eez(): void
    {
        $this->actingAs($this->analyst);

        $response = $this->getJson('/api/layers');
        $response->assertOk();

        $layerIds = collect($response->json('layers'))->pluck('id')->all();
        $this->assertContains('layer-asean-eez', $layerIds);
        $this->assertContains('layer-australia-eez', $layerIds);
    }

    public function test_map_page_renders_separate_asean_and_australia_eez_layers_and_legend(): void
    {
        $this->actingAs($this->analyst);

        $response = $this->get(route('map.index'));
        $response->assertOk();

        // 1. ASEAN EEZ toggle and label
        $response->assertSee('id="toggle-layer-asean-eez"', false);
        $response->assertSee('ASEAN Exclusive Economic Zones (EEZ)', false);
        $response->assertSee('Garis Batas ZEE &amp; Batas Maritim Resmi ASEAN (v12)', false);

        // 2. Australia EEZ toggle and label
        $response->assertSee('id="toggle-layer-australia-eez"', false);
        $response->assertSee('Australia Exclusive Economic Zone (EEZ)', false);
        $response->assertSee('Garis Batas ZEE &amp; Batas Maritim Resmi Australia (AMSIS / AMB / v12)', false);

        // 3. Clear distinct legends
        $response->assertSee('ASEAN EEZ (Marine Regions v12)', false);
        $response->assertSee('Australia EEZ (AMSIS / AMB / v12)', false);
        $response->assertSee('Australia 200 NM Outer Limit (ZEE)', false);
        $response->assertSee('Agreed Bilateral Maritime Treaty', false);
    }

    public function test_global_map_script_implements_dark_ocean_eez_activation_and_separate_sources(): void
    {
        $scriptPath = resource_path('js/map/global-map.js');
        $this->assertFileExists($scriptPath);
        $content = file_get_contents($scriptPath);

        // Both activeLayers present
        $this->assertStringContainsString('aseanEez: true', $content);
        $this->assertStringContainsString('australiaEez: true', $content);

        // Separate sources and methods
        $this->assertStringContainsString('addAseanEezLayer', $content);
        $this->assertStringContainsString('addAustraliaEezLayer', $content);
        $this->assertStringContainsString("'asean-eez-source'", $content);
        $this->assertStringContainsString("'australia-eez-source'", $content);

        // Dark Ocean activation hook
        $this->assertStringContainsString("if (normalizedKey === 'openfreemap-dark')", $content);
        $this->assertStringContainsString('this.activeLayers.aseanEez = true;', $content);
        $this->assertStringContainsString('this.activeLayers.australiaEez = true;', $content);

        // Preserves both layers across basemap changes in restoreCachedLayerData
        $this->assertStringContainsString('restoreCachedLayerData()', $content);
        $this->assertStringContainsString("this.map.getSource('asean-eez-source').setData(this.layerData.aseanEez);", $content);
        $this->assertStringContainsString("this.map.getSource('australia-eez-source').setData(this.layerData.australiaEez);", $content);

        // Strict layer ordering keeps vessels topmost
        $this->assertStringContainsString("'vessels-oriented-layer'", $content);
        $this->assertStringContainsString("'australia-eez-line-treaty'", $content);
        $this->assertStringContainsString("'australia-eez-line-200nm'", $content);

        // Thin line width and 70% opacity
        $this->assertStringContainsString("'line-opacity': 0.70", $content);
        $this->assertStringContainsString("'IDN', '#06b6d4'", $content);
        $this->assertStringContainsString("'MYS', '#f97316'", $content);
        $this->assertStringContainsString("'THA', '#eab308'", $content);
        $this->assertStringContainsString("'VNM', '#d946ef'", $content);
        $this->assertStringContainsString("'PHL', '#22c55e'", $content);
        $this->assertStringContainsString("'BRN', '#8b5cf6'", $content);
        $this->assertStringContainsString("'SGP', '#fb7185'", $content);
        $this->assertStringContainsString("'KHM', '#3b82f6'", $content);
        $this->assertStringContainsString("'MMR', '#ec4899'", $content);
        $this->assertStringContainsString("'LAO', '#14b8a6'", $content);
        $this->assertStringContainsString("australiaLineColor = '#ef4444'", $content);
    }

    public function test_eez_country_colors_and_metadata_attributes(): void
    {
        $this->actingAs($this->analyst);

        // Seed sample ASEAN Boundary (Indonesia - Malaysia)
        EezBoundary::create([
            'line_id' => 9001,
            'line_name' => 'Indonesia - Malaysia',
            'line_type' => 'Treaty',
            'sovereign1' => 'Indonesia',
            'sovereign2' => 'Malaysia',
            'territory1' => 'Indonesia',
            'territory2' => 'Malaysia',
            'eez1' => 'Indonesian Exclusive Economic Zone',
            'eez2' => 'Malaysian Exclusive Economic Zone',
            'source1' => 'Treaty 1969',
            'length_km' => 150.0,
            'geometry_geojson' => json_encode([
                'type' => 'LineString',
                'coordinates' => [[102.0, 2.0], [103.0, 1.5]]
            ]),
        ]);

        $service = app(SpatialQueryService::class);
        $aseanFeatures = $service->getAseanEezFeatureCollection();

        $line = collect($aseanFeatures['features'])->firstWhere('properties.line_id', 9001);
        $this->assertNotNull($line);
        $this->assertEquals('IDN', $line['properties']['country_code']);
        $this->assertEquals('#06b6d4', $line['properties']['color']);
        $this->assertEquals('Indonesia', $line['properties']['country']);
    }
}

