<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselPosition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class VesselClassificationReviewRemovalTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $admin;
    protected User $analyst;
    protected User $researcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create([
            'name' => 'Super Administrator',
            'email' => 'superadmin@gfvip.org',
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->admin = User::factory()->create([
            'name' => 'Administrator',
            'email' => 'admin@gfvip.org',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->analyst = User::factory()->create([
            'name' => 'Maritime Analyst',
            'email' => 'analyst@gfvip.org',
            'role' => 'analyst',
            'is_active' => true,
        ]);

        $this->researcher = User::factory()->create([
            'name' => 'Researcher User',
            'email' => 'researcher@gfvip.org',
            'role' => 'researcher',
            'is_active' => true,
        ]);
    }

    /**
     * Test 01: Route vessels.classification-review.* is completely unregistered in Laravel.
     */
    public function test_01_classification_review_named_routes_do_not_exist(): void
    {
        $this->assertFalse(Route::has('vessels.classification-review.index'));
        $this->assertFalse(Route::has('vessels.classification-review.show'));
        $this->assertFalse(Route::has('vessels.classification-review.decision'));
    }

    /**
     * Test 02: Accessing /vessels/classification-review returns 404 for all roles and guests.
     */
    public function test_02_url_returns_404_not_found_for_all_users(): void
    {
        // Unauthenticated guest redirected or 404
        $guestRes = $this->get('/vessels/classification-review');
        $this->assertTrue(in_array($guestRes->status(), [302, 404]));

        // Authenticated roles all receive 404
        $this->actingAs($this->researcher)->get('/vessels/classification-review')->assertStatus(404);
        $this->actingAs($this->analyst)->get('/vessels/classification-review')->assertStatus(404);
        $this->actingAs($this->admin)->get('/vessels/classification-review')->assertStatus(404);
        $this->actingAs($this->superAdmin)->get('/vessels/classification-review')->assertStatus(404);
    }

    /**
     * Test 03: Accessing sub-paths or decision POST returns 404.
     */
    public function test_03_subroutes_and_post_decisions_return_404(): void
    {
        $fakeUuid = '00000000-0000-0000-0000-000000000001';

        $this->actingAs($this->admin)->get("/vessels/classification-review/{$fakeUuid}")->assertStatus(404);
        $this->actingAs($this->admin)->post("/vessels/classification-review/{$fakeUuid}/decision", [
            'action' => 'verify_fishing',
            'reason' => 'Test decision',
        ])->assertStatus(404);
    }

    /**
     * Test 04: Navigation sidebar never renders Classification Review links or badges for any role.
     */
    public function test_04_sidebar_does_not_render_classification_review_menu_or_badges(): void
    {
        foreach ([$this->superAdmin, $this->admin, $this->analyst] as $user) {
            $response = $this->actingAs($user)->get('/dashboard');
            $response->assertStatus(200);
            $response->assertDontSee('/vessels/classification-review', false);
            $response->assertDontSee('Classification Review', false);
        }
    }

    /**
     * Test 05: Vessel registry and vessel show pages function normally with UUID routing.
     */
    public function test_05_vessel_registry_and_details_remain_fully_functional(): void
    {
        $vessel = Vessel::create([
            'name' => 'KM LAUT INDONESIA',
            'mmsi' => '525999888',
            'vessel_type' => 'fishing',
            'ais_ship_type_code' => 30,
            'verification_status' => 'verified',
        ]);

        // Registry list
        $resList = $this->actingAs($this->admin)->get('/vessels');
        $resList->assertStatus(200);
        $resList->assertSee('KM LAUT INDONESIA');

        // Vessel details
        $resShow = $this->actingAs($this->admin)->get("/vessels/{$vessel->id}");
        $resShow->assertStatus(200);
        $resShow->assertSee('KM LAUT INDONESIA');
        $resShow->assertSee('525999888');
    }

    /**
     * Test 06: Core vessel classification logic and retention safeguards remain intact.
     */
    public function test_06_classification_safeguards_and_model_methods_remain_intact(): void
    {
        $disputedVessel = Vessel::create([
            'name' => 'KM CONFLICT VESSEL',
            'mmsi' => '525000123',
            'vessel_type' => 'fishing',
            'ais_ship_type_code' => 70, // Cargo
            'verification_status' => 'verified',
        ]);

        $this->assertTrue($disputedVessel->isClassificationDisputed());
        $this->assertTrue($disputedVessel->isUnderProtectiveHold());

        $verifiedFishing = Vessel::create([
            'name' => 'KM BERKAH SAMUDERA',
            'mmsi' => '525000124',
            'vessel_type' => 'fishing',
            'ais_ship_type_code' => 30,
            'verification_status' => 'verified',
        ]);

        $this->assertFalse($verifiedFishing->isClassificationDisputed());
        $this->assertTrue($verifiedFishing->isVerifiedFishing());
    }
}
