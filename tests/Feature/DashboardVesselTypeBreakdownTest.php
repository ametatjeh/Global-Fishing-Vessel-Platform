<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselPosition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardVesselTypeBreakdownTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->user = User::factory()->create([
            'email' => 'operator@maritime-intel.org',
            'role' => 'analyst',
            'is_active' => true,
        ]);
        $this->actingAs($this->user);
    }

    public function test_dashboard_renders_empty_state_when_no_vessels_exist(): void
    {
        $response = $this->get('/dashboard');

        $response->assertStatus(200);
        $response->assertSee('Vessel Type Breakdown');
        $response->assertSee('Total Vessels');
        $response->assertSee('0 Categories');
        $response->assertSee('No registered vessels available in the system database.');
        $response->assertSee('vessel-type-doughnut-chart');
    }

    public function test_breakdown_aggregates_unique_vessels_accurately_with_data(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            Vessel::create(['name' => "FISHING {$i}", 'mmsi' => "10000000{$i}", 'vessel_type' => 'fishing']);
        }
        for ($i = 1; $i <= 4; $i++) {
            Vessel::create(['name' => "CARGO {$i}", 'mmsi' => "20000000{$i}", 'vessel_type' => 'cargo']);
        }
        for ($i = 1; $i <= 3; $i++) {
            Vessel::create(['name' => "TANKER {$i}", 'mmsi' => "30000000{$i}", 'vessel_type' => 'tanker']);
        }
        for ($i = 1; $i <= 2; $i++) {
            Vessel::create(['name' => "PLEASURE {$i}", 'mmsi' => "40000000{$i}", 'vessel_type' => 'pleasure']);
        }
        Vessel::create(['name' => "UNKNOWN EXPLICIT", 'mmsi' => "500000001", 'vessel_type' => 'unknown']);
        Vessel::create(['name' => "UNKNOWN SPACE", 'mmsi' => "500000002", 'vessel_type' => '   ']);

        $totalInDb = 16;
        $this->assertEquals($totalInDb, Vessel::count());

        $response = $this->get('/dashboard');

        $response->assertStatus(200);
        $response->assertSee('Vessel Type Breakdown');
        $response->assertSee('16');
        $response->assertSee('5 Categories');

        $breakdown = $response->viewData('vesselTypeBreakdown');
        $totalReported = $response->viewData('totalVessels');
        $categoryCount = $response->viewData('vesselTypeCategoryCount');
        $topVesselType = $response->viewData('topVesselType');

        $this->assertEquals(16, $totalReported);
        $this->assertEquals(5, $categoryCount);
        $this->assertCount(5, $breakdown);

        // Top category must be fishing (5)
        $this->assertEquals('Fishing', $topVesselType['label']);
        $this->assertEquals(5, $topVesselType['count']);

        // Check sum equals total
        $sum = array_sum(array_column($breakdown, 'count'));
        $this->assertEquals(16, $sum);

        // Check Unknown has 2 (from unknown and whitespace)
        $unknown = collect($breakdown)->firstWhere('key', 'unknown');
        $this->assertNotNull($unknown);
        $this->assertEquals(2, $unknown['count']);
        $this->assertEquals('Unknown', $unknown['label']);

        // Check descending sort
        $counts = array_column($breakdown, 'count');
        $sortedCounts = $counts;
        rsort($sortedCounts);
        $this->assertEquals($sortedCounts, $counts);
    }

    public function test_vessel_positions_count_does_not_multiply_vessel_count(): void
    {
        $vessel = Vessel::create(['name' => 'FISHING TEST', 'mmsi' => '999000111', 'vessel_type' => 'fishing']);

        // Attach multiple positions via Model to simulate AIS telemetry history
        for ($i = 0; $i < 10; $i++) {
            VesselPosition::create([
                'vessel_id' => $vessel->id,
                'latitude' => -6.2 + ($i * 0.01),
                'longitude' => 106.8 + ($i * 0.01),
                'observed_at' => now()->subMinutes($i * 5),
                'received_at' => now(),
            ]);
        }

        $response = $this->get('/dashboard');
        $breakdown = $response->viewData('vesselTypeBreakdown');

        $fishingItem = collect($breakdown)->firstWhere('key', 'fishing');
        $this->assertNotNull($fishingItem);
        // Still exactly 1 vessel, not 10!
        $this->assertEquals(1, $fishingItem['count']);
        $this->assertEquals(1, $response->viewData('totalVessels'));
    }
}
