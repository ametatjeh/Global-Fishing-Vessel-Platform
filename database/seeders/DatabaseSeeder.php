<?php

namespace Database\Seeders;

use App\Models\ComplianceRule;
use App\Models\DataProvider;
use App\Models\User;
use App\Services\Providers\FaoFishingAreaAdapter;
use App\Services\Providers\MarineRegionsEezAdapter;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Create Default Administrative & Analyst Users
        User::firstOrCreate(
            ['email' => 'admin@gfw.nagakecil.site'],
            [
                'name' => 'Chief Maritime Officer',
                'password' => Hash::make('Password123!'),
                'role' => 'super_admin',
                'organization' => 'Global Maritime Security Operations Center',
                'email_verified_at' => now(),
            ]
        );

        User::firstOrCreate(
            ['email' => 'analyst@gfw.nagakecil.site'],
            [
                'name' => 'Senior Intelligence Analyst',
                'password' => Hash::make('Password123!'),
                'role' => 'analyst',
                'organization' => 'Fisheries Intelligence Unit',
                'email_verified_at' => now(),
            ]
        );

        // 2. Initialize Known Providers with Transparent Configuration Status
        $providers = [
            [
                'slug' => 'global-fishing-watch',
                'name' => 'Global Fishing Watch (Events API v3)',
                'type' => 'gfw_events',
                'api_url' => 'https://gateway.api.globalfishingwatch.org/v3',
                'auth_type' => 'bearer_token',
                'is_enabled' => false,
                'health_status' => 'not_configured',
                'license_type' => 'GFW Research & Non-Commercial',
                'commercial_use_allowed' => false,
                'retention_allowed' => true,
                'redistribution_allowed' => false,
            ],
            [
                'slug' => 'copernicus-sentinel-1',
                'name' => 'Copernicus Data Space Ecosystem (Sentinel-1 SAR)',
                'type' => 'copernicus_sar',
                'api_url' => 'https://dataspace.copernicus.eu/analyse/apis',
                'auth_type' => 'oauth2',
                'is_enabled' => false,
                'health_status' => 'not_configured',
                'license_type' => 'Copernicus Open Access',
                'commercial_use_allowed' => true,
                'retention_allowed' => true,
                'redistribution_allowed' => true,
            ],
            [
                'slug' => 'ais-stream',
                'name' => 'AIS Live Stream Telemetry',
                'type' => 'ais_stream',
                'api_url' => 'wss://stream.aisstream.io/v0/stream',
                'auth_type' => 'bearer_token',
                'is_enabled' => false,
                'health_status' => 'not_configured',
                'license_type' => 'Proprietary Live Feed',
                'commercial_use_allowed' => false,
                'retention_allowed' => true,
                'redistribution_allowed' => false,
            ],
            [
                'slug' => 'fao-fisheries',
                'name' => 'Food and Agriculture Organization (FAO) Fisheries',
                'type' => 'fao',
                'api_url' => 'https://www.fao.org/fishery/docs/maps/Default.htm',
                'auth_type' => 'none',
                'is_enabled' => true,
                'health_status' => 'connected',
                'license_type' => 'FAO Open Access Terms / CC-BY-NC-SA 3.0 IGO',
                'commercial_use_allowed' => false,
                'retention_allowed' => true,
                'redistribution_allowed' => true,
            ],
            [
                'slug' => 'marine-regions-eez',
                'name' => 'Marine Regions (VLIZ Flanders Marine Institute)',
                'type' => 'marine_regions',
                'api_url' => 'https://www.marineregions.org/downloads.php',
                'auth_type' => 'none',
                'is_enabled' => true,
                'health_status' => 'connected',
                'license_type' => 'Creative Commons Attribution 4.0 International (CC BY 4.0)',
                'commercial_use_allowed' => true,
                'retention_allowed' => true,
                'redistribution_allowed' => true,
            ],
        ];

        foreach ($providers as $p) {
            DataProvider::firstOrCreate(['slug' => $p['slug']], $p);
        }

        // 3. Initialize Standard Global Compliance Rules
        $rules = [
            [
                'rule_code' => 'RULE-UNCLOS-62',
                'rule_name' => 'Unauthorized Operation in Sovereign EEZ',
                'jurisdiction' => 'International / Sovereign Coastal State',
                'category' => 'license_validity',
                'severity' => 'critical',
                'legal_source' => 'UNCLOS Part V (Articles 56, 62) - Utilization of Living Resources',
                'version' => '1.0',
                'rule_logic' => [
                    'require_active_permit' => true,
                    'action_on_violation' => 'flag_for_interception',
                ],
            ],
            [
                'rule_code' => 'RULE-TRANS-01',
                'rule_name' => 'Unverified High-Seas Transshipment Encounter',
                'jurisdiction' => 'RFMO / International Waters',
                'category' => 'encounter_transshipment',
                'severity' => 'high',
                'legal_source' => 'FAO Code of Conduct for Responsible Fisheries / RFMO Transshipment Measure',
                'version' => '1.0',
                'rule_logic' => [
                    'flag_unverified_transshipment' => true,
                    'minimum_proximity_meters' => 500,
                    'minimum_duration_minutes' => 30,
                ],
            ],
            [
                'rule_code' => 'RULE-AIS-GAP',
                'rule_name' => 'Prolonged Telemetry Gap (Dark Vessel Candidate)',
                'jurisdiction' => 'IMO / SOLAS Chapter V Regulation 19',
                'category' => 'ais_gap',
                'severity' => 'medium',
                'legal_source' => 'SOLAS Chapter V (Safety of Life at Sea) Mandatory AIS Requirement',
                'version' => '1.0',
                'rule_logic' => [
                    'max_gap_hours' => 8,
                    'cross_reference_sar' => true,
                ],
            ],
        ];

        foreach ($rules as $r) {
            ComplianceRule::firstOrCreate(['rule_code' => $r['rule_code']], $r);
        }

        // 4. Automatically Import Official Seed Geodatasets (FAO & Marine Regions EEZ)
        app(FaoFishingAreaAdapter::class)->sync();
        app(MarineRegionsEezAdapter::class)->sync();
    }
}
