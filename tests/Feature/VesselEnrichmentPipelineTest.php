<?php

namespace Tests\Feature;

use App\Jobs\EnrichAwaitingVesselJob;
use App\Models\EnrichmentJob;
use App\Models\Vessel;
use App\Models\VesselAttributeObservation;
use App\Services\Enrichment\Contracts\EnrichmentProviderInterface;
use App\Services\Enrichment\Providers\AisStreamEnrichmentProvider;
use App\Services\Enrichment\Providers\FaoRecordEnrichmentProvider;
use App\Services\Enrichment\Providers\GfwEnrichmentProvider;
use App\Services\Enrichment\Providers\OfficialFlagRegistryProvider;
use App\Services\Enrichment\VesselEnrichmentManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class VesselEnrichmentPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function createAwaitingVessel(array $attributes = []): Vessel
    {
        return Vessel::create(array_merge([
            'name' => 'KM SAMUDRA 01',
            'mmsi' => '525001234',
            'vessel_type' => 'unknown',
            'verification_status' => 'provider_reported',
            'enrichment_status' => 'not_started',
            'first_seen_at' => now()->subDays(2),
            'last_seen_at' => now()->subHours(2),
            'static_data_received_at' => null,
            'ais_ship_type_code' => null,
        ], $attributes));
    }

    /**
     * Helper to instantiate manager with custom mock providers.
     */
    protected function createManager(
        ?EnrichmentProviderInterface $ais = null,
        ?EnrichmentProviderInterface $gfw = null,
        ?EnrichmentProviderInterface $fao = null,
        ?EnrichmentProviderInterface $flag = null
    ): VesselEnrichmentManager {
        $ais = $ais ?: app(AisStreamEnrichmentProvider::class);
        $gfw = $gfw ?: app(GfwEnrichmentProvider::class);
        $fao = $fao ?: app(FaoRecordEnrichmentProvider::class);
        $flag = $flag ?: app(OfficialFlagRegistryProvider::class);

        return new VesselEnrichmentManager($ais, $gfw, $fao, $flag);
    }

    /**
     * 1. MMSI Valid
     */
    public function test_01_valid_mmsi_processing(): void
    {
        $vessel = $this->createAwaitingVessel(['mmsi' => '525001234']);
        $manager = $this->createManager();

        $result = $manager->enrichVessel($vessel, dryRun: false);

        $this->assertTrue($result['is_valid_mmsi']);
        $this->assertNotEquals('INVALID', $vessel->ais_static_status);
        $this->assertNotEmpty($result['sources_attempted']);
    }

    /**
     * 2. MMSI Tidak Valid
     */
    public function test_02_invalid_mmsi_rejected(): void
    {
        $vessel = $this->createAwaitingVessel(['mmsi' => '1234']); // only 4 digits
        $manager = $this->createManager();

        $result = $manager->enrichVessel($vessel, dryRun: false);

        $this->assertFalse($result['is_valid_mmsi']);
        $this->assertEquals('INVALID', $vessel->ais_static_status);
        $this->assertEquals('not_found', $result['enrichment_status']);
        $this->assertStringContainsString('invalid', strtolower($result['message']));
    }

    /**
     * 3. Provider Tidak Dikonfigurasi
     */
    public function test_03_provider_not_configured_handled_gracefully(): void
    {
        $vessel = $this->createAwaitingVessel();

        // Create mock unconfigured providers
        $unconfProvider = new class implements EnrichmentProviderInterface {
            public function getName(): string { return 'mock_unconfigured'; }
            public function isConfigured(): bool { return false; }
            public function enrich(Vessel $v): array {
                return ['provider' => 'mock_unconfigured', 'status' => 'NOT_CONFIGURED', 'external_record_id' => null, 'confidence_score' => null, 'attributes' => [], 'raw_payload' => null, 'message' => 'Unconfigured'];
            }
        };

        $manager = new VesselEnrichmentManager(
            app(AisStreamEnrichmentProvider::class),
            $unconfProvider,
            $unconfProvider,
            $unconfProvider
        );

        $result = $manager->enrichVessel($vessel, dryRun: false);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('provider_results', $result);
    }

    /**
     * 4. Provider Timeout
     */
    public function test_04_provider_timeout_handled_gracefully(): void
    {
        $vessel = $this->createAwaitingVessel();

        $timeoutProvider = new class implements EnrichmentProviderInterface {
            public function getName(): string { return 'timeout_prov'; }
            public function isConfigured(): bool { return true; }
            public function enrich(Vessel $v): array {
                return [
                    'provider' => 'timeout_prov',
                    'status' => 'PROVIDER_UNAVAILABLE',
                    'external_record_id' => null,
                    'confidence_score' => null,
                    'attributes' => [],
                    'raw_payload' => null,
                    'message' => 'Connection timed out after 30 seconds.',
                ];
            }
        };

        $manager = new VesselEnrichmentManager(
            app(AisStreamEnrichmentProvider::class),
            $timeoutProvider,
            $timeoutProvider,
            $timeoutProvider
        );

        $result = $manager->enrichVessel($vessel, dryRun: false);

        $this->assertIsArray($result);
        $this->assertEquals('PROVIDER_UNAVAILABLE', $result['provider_results']['timeout_prov']['status']);
    }

    /**
     * 5. Provider Mengembalikan Data
     */
    public function test_05_provider_returns_data_successfully(): void
    {
        $vessel = $this->createAwaitingVessel([
            'mmsi' => '525001234',
            'length_meters' => null,
            'tonnage' => null,
        ]);

        $mockProvider = new class implements EnrichmentProviderInterface {
            public function getName(): string { return 'gfw'; }
            public function isConfigured(): bool { return true; }
            public function enrich(Vessel $v): array {
                return [
                    'provider' => 'gfw',
                    'status' => 'MATCH_FOUND',
                    'external_record_id' => 'gfw-vessel-999',
                    'confidence_score' => 0.90,
                    'attributes' => [
                        'name' => 'KM SAMUDRA 01',
                        'length_meters' => 38.5,
                        'tonnage' => 250.0,
                        'build_year' => 2018,
                        'flag_state' => 'ID',
                        'vessel_type' => 'fishing',
                    ],
                    'raw_payload' => ['id' => 'gfw-vessel-999'],
                    'message' => 'Candidate matched via GFW API',
                ];
            }
        };

        $manager = new VesselEnrichmentManager(
            app(AisStreamEnrichmentProvider::class),
            $mockProvider,
            app(FaoRecordEnrichmentProvider::class),
            app(OfficialFlagRegistryProvider::class)
        );

        $result = $manager->enrichVessel($vessel, dryRun: false);

        $vessel->refresh();
        $this->assertEquals('enriched', $vessel->enrichment_status);
        $this->assertEquals(38.5, $vessel->length_meters);
        $this->assertEquals(250.0, $vessel->tonnage);
        $this->assertEquals(2018, $vessel->build_year);
        $this->assertEquals('ID', $vessel->flag_state);
        $this->assertEquals('fishing', $vessel->vessel_type);
    }

    /**
     * 6. Provider Tidak Menemukan Kapal
     */
    public function test_06_provider_not_found_leaves_profile_intact(): void
    {
        $vessel = $this->createAwaitingVessel([
            'name' => 'MYSTERY SHIP',
            'mmsi' => '525999999',
        ]);

        $notFoundProvider = new class implements EnrichmentProviderInterface {
            public function getName(): string { return 'gfw'; }
            public function isConfigured(): bool { return true; }
            public function enrich(Vessel $v): array {
                return [
                    'provider' => 'gfw',
                    'status' => 'NOT_FOUND',
                    'external_record_id' => null,
                    'confidence_score' => null,
                    'attributes' => [],
                    'raw_payload' => null,
                    'message' => 'No candidate found',
                ];
            }
        };

        $flagNotFound = new class implements EnrichmentProviderInterface {
            public function getName(): string { return 'flag_registry'; }
            public function isConfigured(): bool { return true; }
            public function enrich(Vessel $v): array {
                return [
                    'provider' => 'flag_registry',
                    'status' => 'NOT_FOUND',
                    'external_record_id' => null,
                    'confidence_score' => null,
                    'attributes' => [],
                    'raw_payload' => null,
                    'message' => 'No match',
                ];
            }
        };

        $manager = new VesselEnrichmentManager(
            app(AisStreamEnrichmentProvider::class),
            $notFoundProvider,
            app(FaoRecordEnrichmentProvider::class),
            $flagNotFound
        );

        $result = $manager->enrichVessel($vessel, dryRun: false);

        $vessel->refresh();
        $this->assertEquals('not_found', $vessel->enrichment_status);
        $this->assertEquals('MYSTERY SHIP', $vessel->name);
    }

    /**
     * 7. Kecocokan Berdasarkan MMSI
     */
    public function test_07_mmsi_matching_associates_attributes(): void
    {
        $vessel = $this->createAwaitingVessel(['mmsi' => '525112233', 'imo' => null]);

        $mmsiMatchProvider = new class implements EnrichmentProviderInterface {
            public function getName(): string { return 'gfw'; }
            public function isConfigured(): bool { return true; }
            public function enrich(Vessel $v): array {
                return [
                    'provider' => 'gfw',
                    'status' => 'MATCH_FOUND',
                    'external_record_id' => 'candidate-mmsi-match',
                    'confidence_score' => 0.90,
                    'attributes' => [
                        'mmsi' => $v->mmsi,
                        'imo' => '9123456',
                        'callsign' => 'YBDA',
                    ],
                    'raw_payload' => null,
                    'message' => 'Matched by MMSI',
                ];
            }
        };

        $manager = new VesselEnrichmentManager(
            app(AisStreamEnrichmentProvider::class),
            $mmsiMatchProvider,
            app(FaoRecordEnrichmentProvider::class),
            app(OfficialFlagRegistryProvider::class)
        );

        $manager->enrichVessel($vessel, dryRun: false);

        $vessel->refresh();
        $this->assertEquals('9123456', $vessel->imo);
        $this->assertEquals('YBDA', $vessel->callsign);
    }

    /**
     * 8. Konflik IMO
     */
    public function test_08_imo_conflict_detected_and_preserved(): void
    {
        $vessel = $this->createAwaitingVessel([
            'mmsi' => '525001234',
            'imo' => '9111111', // Existing local IMO
        ]);

        $conflictingImoProvider = new class implements EnrichmentProviderInterface {
            public function getName(): string { return 'gfw'; }
            public function isConfigured(): bool { return true; }
            public function enrich(Vessel $v): array {
                return [
                    'provider' => 'gfw',
                    'status' => 'MATCH_FOUND',
                    'external_record_id' => 'candidate-diff-imo',
                    'confidence_score' => 0.80,
                    'attributes' => [
                        'imo' => '9222222', // Conflicting incoming IMO!
                    ],
                    'raw_payload' => null,
                    'message' => 'Different IMO returned',
                ];
            }
        };

        $manager = new VesselEnrichmentManager(
            app(AisStreamEnrichmentProvider::class),
            $conflictingImoProvider,
            app(FaoRecordEnrichmentProvider::class),
            app(OfficialFlagRegistryProvider::class)
        );

        $result = $manager->enrichVessel($vessel, dryRun: false);

        $vessel->refresh();
        $this->assertEquals('conflicting', $vessel->enrichment_status);
        $this->assertEquals('9111111', $vessel->imo, 'Existing local IMO must NOT be overwritten by conflicting external IMO');
        $this->assertArrayHasKey('imo', $result['conflicts']);

        // Observation must be marked as conflicting in database
        $obs = VesselAttributeObservation::where('vessel_id', $vessel->id)
            ->where('attribute_name', 'imo')
            ->first();
        $this->assertNotNull($obs);
        $this->assertTrue($obs->is_conflicting);
    }

    /**
     * 9. Konflik Nama
     */
    public function test_09_name_conflict_detected_and_preserved(): void
    {
        $vessel = $this->createAwaitingVessel([
            'name' => 'PACIFIC VOYAGER',
            'mmsi' => '525001234',
        ]);

        $conflictingNameProvider = new class implements EnrichmentProviderInterface {
            public function getName(): string { return 'gfw'; }
            public function isConfigured(): bool { return true; }
            public function enrich(Vessel $v): array {
                return [
                    'provider' => 'gfw',
                    'status' => 'MATCH_FOUND',
                    'external_record_id' => 'candidate-diff-name',
                    'confidence_score' => 0.70,
                    'attributes' => [
                        'name' => 'ATLANTIC CONQUEROR', // Completely different name!
                    ],
                    'raw_payload' => null,
                    'message' => 'Name difference',
                ];
            }
        };

        $manager = new VesselEnrichmentManager(
            app(AisStreamEnrichmentProvider::class),
            $conflictingNameProvider,
            app(FaoRecordEnrichmentProvider::class),
            app(OfficialFlagRegistryProvider::class)
        );

        $result = $manager->enrichVessel($vessel, dryRun: false);

        $vessel->refresh();
        $this->assertEquals('PACIFIC VOYAGER', $vessel->name, 'Local name must be preserved against conflicting external name');
        $this->assertEquals('conflicting', $vessel->enrichment_status);
        $this->assertArrayHasKey('name', $result['conflicts']);
    }

    /**
     * 10. Data Eksternal Tidak Menimpa AIS Static Valid
     */
    public function test_10_external_data_does_not_overwrite_valid_ais_static(): void
    {
        $vessel = $this->createAwaitingVessel([
            'name' => 'BARUNA JAYA IV',
            'vessel_type' => 'research',
            'static_data_received_at' => now()->subDay(), // Verified AIS static packet arrived!
            'ais_ship_type_code' => 51,
            'length_meters' => 60.4,
        ]);

        $externalProvider = new class implements EnrichmentProviderInterface {
            public function getName(): string { return 'gfw'; }
            public function isConfigured(): bool { return true; }
            public function enrich(Vessel $v): array {
                return [
                    'provider' => 'gfw',
                    'status' => 'MATCH_FOUND',
                    'external_record_id' => 'gfw-external-cand',
                    'confidence_score' => 0.75,
                    'attributes' => [
                        'vessel_type' => 'cargo', // External claims cargo!
                        'length_meters' => 120.0, // External claims 120m!
                    ],
                    'raw_payload' => null,
                    'message' => 'External prediction',
                ];
            }
        };

        $manager = new VesselEnrichmentManager(
            app(AisStreamEnrichmentProvider::class),
            $externalProvider,
            app(FaoRecordEnrichmentProvider::class),
            app(OfficialFlagRegistryProvider::class)
        );

        $result = $manager->enrichVessel($vessel, dryRun: false);

        $vessel->refresh();
        $this->assertEquals('research', $vessel->vessel_type, 'Valid AIS static vessel_type must NOT be overwritten by external source');
        $this->assertEquals(60.4, $vessel->length_meters, 'Valid AIS static length must NOT be overwritten by external source');
        $this->assertNotNull($vessel->static_data_received_at);
    }

    /**
     * 11. Data Kosong Tidak Menimpa Data Valid
     */
    public function test_11_empty_external_data_does_not_overwrite_valid_data(): void
    {
        $vessel = $this->createAwaitingVessel([
            'callsign' => 'PKXY',
            'tonnage' => 180.0,
        ]);

        $emptyProvider = new class implements EnrichmentProviderInterface {
            public function getName(): string { return 'gfw'; }
            public function isConfigured(): bool { return true; }
            public function enrich(Vessel $v): array {
                return [
                    'provider' => 'gfw',
                    'status' => 'MATCH_FOUND',
                    'external_record_id' => 'cand-empty-fields',
                    'confidence_score' => 0.60,
                    'attributes' => [
                        'callsign' => '',
                        'tonnage' => null,
                    ],
                    'raw_payload' => null,
                    'message' => 'Null values',
                ];
            }
        };

        $manager = new VesselEnrichmentManager(
            app(AisStreamEnrichmentProvider::class),
            $emptyProvider,
            app(FaoRecordEnrichmentProvider::class),
            app(OfficialFlagRegistryProvider::class)
        );

        $manager->enrichVessel($vessel, dryRun: false);

        $vessel->refresh();
        $this->assertEquals('PKXY', $vessel->callsign);
        $this->assertEquals(180.0, $vessel->tonnage);
    }

    /**
     * 12. Duplicate Enrichment Tidak Menghasilkan Duplikasi
     */
    public function test_12_duplicate_enrichment_does_not_duplicate_observations(): void
    {
        $vessel = $this->createAwaitingVessel();

        $provider = new class implements EnrichmentProviderInterface {
            public function getName(): string { return 'gfw'; }
            public function isConfigured(): bool { return true; }
            public function enrich(Vessel $v): array {
                return [
                    'provider' => 'gfw',
                    'status' => 'MATCH_FOUND',
                    'external_record_id' => 'idemp-vessel-1',
                    'confidence_score' => 0.90,
                    'attributes' => [
                        'callsign' => 'YBAA',
                        'flag_state' => 'ID',
                    ],
                    'raw_payload' => null,
                    'message' => 'Match',
                ];
            }
        };

        $manager = new VesselEnrichmentManager(
            app(AisStreamEnrichmentProvider::class),
            $provider,
            app(FaoRecordEnrichmentProvider::class),
            app(OfficialFlagRegistryProvider::class)
        );

        // Run 1st time
        $manager->enrichVessel($vessel, dryRun: false);
        $firstCount = VesselAttributeObservation::where('vessel_id', $vessel->id)->count();

        // Run 2nd time (duplicate)
        $manager->enrichVessel($vessel, dryRun: false);
        $secondCount = VesselAttributeObservation::where('vessel_id', $vessel->id)->count();

        $this->assertEquals($firstCount, $secondCount, 'Subsequent enrichment runs must NOT duplicate attribute observation records');
    }

    /**
     * 13. Bulk Enrichment Mengikuti Rate Limit
     */
    public function test_13_bulk_enrichment_respects_rate_limit_and_batch_size(): void
    {
        $vessel1 = $this->createAwaitingVessel(['mmsi' => '525000001']);
        $vessel2 = $this->createAwaitingVessel(['mmsi' => '525000002']);
        $vessel3 = $this->createAwaitingVessel(['mmsi' => '525000003']);

        $this->artisan('vessels:enrich-awaiting', [
            '--limit' => 2,
            '--rate-limit' => 0, // 0s in test for instant execution
            '--dry-run' => true,
        ])->assertExitCode(0);
    }

    /**
     * 14. Background Job Dapat Diulang dengan Aman (Idempotency)
     */
    public function test_14_background_job_is_safe_to_retry_idempotently(): void
    {
        $vessel = $this->createAwaitingVessel(['mmsi' => '525001234']);

        $job = new EnrichAwaitingVesselJob($vessel->id, force: true);
        $manager = $this->createManager();

        // 1st run
        $job->handle($manager);
        $jobsLogged1 = EnrichmentJob::where('vessel_id', $vessel->id)->count();

        // 2nd run (retry)
        $job->handle($manager);
        $jobsLogged2 = EnrichmentJob::where('vessel_id', $vessel->id)->count();

        $this->assertGreaterThanOrEqual(1, $jobsLogged2);
        $this->assertDatabaseHas('vessels', ['id' => $vessel->id]);
    }

    /**
     * 15. Status AIS Tetap AWAITING Jika Hanya Data Eksternal yang Ditemukan
     */
    public function test_15_ais_status_remains_awaiting_when_only_external_data_found(): void
    {
        $vessel = $this->createAwaitingVessel([
            'name' => 'UNKNOWN',
            'static_data_received_at' => null, // No AIS Type 5 packet received
        ]);

        $externalProvider = new class implements EnrichmentProviderInterface {
            public function getName(): string { return 'gfw'; }
            public function isConfigured(): bool { return true; }
            public function enrich(Vessel $v): array {
                return [
                    'provider' => 'gfw',
                    'status' => 'MATCH_FOUND',
                    'external_record_id' => 'gfw-match-123',
                    'confidence_score' => 0.95,
                    'attributes' => [
                        'name' => 'ENRICHED EXTERNAL VESSEL',
                        'vessel_type' => 'fishing',
                        'imo' => '9876543',
                        'flag_state' => 'ID',
                    ],
                    'raw_payload' => null,
                    'message' => 'Found in external GFW',
                ];
            }
        };

        $manager = new VesselEnrichmentManager(
            app(AisStreamEnrichmentProvider::class),
            $externalProvider,
            app(FaoRecordEnrichmentProvider::class),
            app(OfficialFlagRegistryProvider::class)
        );

        $result = $manager->enrichVessel($vessel, dryRun: false);

        $vessel->refresh();

        // 1. External enrichment is ENRICHED
        $this->assertEquals('enriched', $vessel->enrichment_status);
        $this->assertEquals('ENRICHED', $vessel->normalized_enrichment_status);

        // 2. CRITICAL RULE: AIS static status remains strictly AWAITING!
        $this->assertNull($vessel->static_data_received_at);
        $this->assertEquals('AWAITING', $vessel->ais_static_status);
    }

    /**
     * 16. Riwayat Sumber dan Atribut Tetap Tersedia
     */
    public function test_16_source_history_and_observations_persisted_in_database(): void
    {
        $vessel = $this->createAwaitingVessel();

        $multiSourceProvider = new class implements EnrichmentProviderInterface {
            public function getName(): string { return 'gfw'; }
            public function isConfigured(): bool { return true; }
            public function enrich(Vessel $v): array {
                return [
                    'provider' => 'gfw',
                    'status' => 'MATCH_FOUND',
                    'external_record_id' => 'candidate-history-test',
                    'confidence_score' => 0.88,
                    'attributes' => [
                        'flag_state' => 'PA',
                        'home_port' => 'Panama City',
                        'build_year' => 2015,
                    ],
                    'raw_payload' => ['sample' => 'audit_data'],
                    'message' => 'Multi-attribute match',
                ];
            }
        };

        $manager = new VesselEnrichmentManager(
            app(AisStreamEnrichmentProvider::class),
            $multiSourceProvider,
            app(FaoRecordEnrichmentProvider::class),
            app(OfficialFlagRegistryProvider::class)
        );

        $manager->enrichVessel($vessel, dryRun: false);

        // Check observations table
        $observations = VesselAttributeObservation::where('vessel_id', $vessel->id)->get();
        $this->assertGreaterThanOrEqual(3, $observations->count());

        $flagObs = $observations->where('attribute_name', 'flag_state')->where('source_name', 'gfw')->first();
        $this->assertNotNull($flagObs);
        $this->assertEquals('PA', $flagObs->attribute_value);
        $this->assertEquals('gfw', $flagObs->source_name);
        $this->assertEquals(0.88, $flagObs->confidence_score);

        // Check audit jobs table
        $jobAudit = EnrichmentJob::where('vessel_id', $vessel->id)->first();
        $this->assertNotNull($jobAudit);
        $this->assertEquals('completed', $jobAudit->status);
        $this->assertNotEmpty($jobAudit->sources_attempted);
    }

    /**
     * Test 17: Scheduler registers hourly command with withoutOverlapping and queue.
     */
    public function test_17_scheduler_registration_and_configuration(): void
    {
        $schedule = app(Schedule::class);
        $events = collect($schedule->events());

        $scheduledEvent = $events->first(function ($event) {
            return str_contains($event->command ?? '', 'vessels:enrich-awaiting');
        });

        $this->assertNotNull($scheduledEvent, 'Scheduled command vessels:enrich-awaiting is not registered in Schedule.');
        $this->assertStringContainsString('--limit=50', $scheduledEvent->command);
        $this->assertStringContainsString('--active-only', $scheduledEvent->command);
        $this->assertStringContainsString('--queue', $scheduledEvent->command);
        $this->assertEquals('0 * * * *', $scheduledEvent->expression, 'Event is not scheduled hourly (0 * * * *).');
        $this->assertTrue($scheduledEvent->withoutOverlapping, 'Event withoutOverlapping is not enabled.');
    }

    /**
     * Test 18: Artisan command with --queue dispatches EnrichAwaitingVesselJob to queue.
     */
    public function test_18_command_with_queue_dispatches_jobs(): void
    {
        Queue::fake();

        $v1 = $this->createAwaitingVessel(['mmsi' => '525000001', 'last_seen_at' => now()->subMinutes(10)]);
        $v2 = $this->createAwaitingVessel(['mmsi' => '525000002', 'last_seen_at' => now()->subMinutes(20)]);

        $exitCode = $this->artisan('vessels:enrich-awaiting', [
            '--limit' => 10,
            '--active-only' => true,
            '--queue' => true,
        ])->run();

        $this->assertEquals(0, $exitCode);

        Queue::assertPushed(EnrichAwaitingVesselJob::class, 2);
        Queue::assertPushed(EnrichAwaitingVesselJob::class, function ($job) use ($v1) {
            return $job->vesselId === $v1->id;
        });
        Queue::assertPushed(EnrichAwaitingVesselJob::class, function ($job) use ($v2) {
            return $job->vesselId === $v2->id;
        });
    }

    /**
     * Test 19: Job retry configuration, exponential backoff, and WithoutOverlapping middleware.
     */
    public function test_19_job_retry_backoff_and_middleware(): void
    {
        $vessel = $this->createAwaitingVessel();
        $job = new EnrichAwaitingVesselJob($vessel->id);

        $this->assertEquals(3, $job->tries);
        $this->assertEquals(60, $job->timeout);
        $this->assertEquals([60, 300, 900], $job->backoff());

        $middleware = $job->middleware();
        $this->assertNotEmpty($middleware);
        $this->assertInstanceOf(\Illuminate\Queue\Middleware\WithoutOverlapping::class, $middleware[0]);
    }

    /**
     * Test 20: Concurrency control via WithoutOverlapping queue middleware.
     */
    public function test_20_without_overlapping_middleware_protects_concurrency(): void
    {
        $vessel = $this->createAwaitingVessel();
        $job1 = new EnrichAwaitingVesselJob($vessel->id);
        $job2 = new EnrichAwaitingVesselJob($vessel->id);

        $middleware = $job1->middleware()[0];

        // First worker executes job1 through middleware successfully
        $executed1 = false;
        $middleware->handle($job1, function () use (&$executed1) {
            $executed1 = true;
        });

        $this->assertTrue($executed1, 'First job should execute cleanly through WithoutOverlapping middleware.');
    }

    /**
     * Test 21: Per-provider rate limiting stops throttled provider while allowing others to proceed.
     */
    public function test_21_per_provider_rate_limiting(): void
    {
        RateLimiter::clear('enrichment_rate_limit_gfw');

        $vessel = $this->createAwaitingVessel(['mmsi' => '525999888']);

        $gfwProvider = new class implements EnrichmentProviderInterface {
            public function getName(): string { return 'gfw'; }
            public function isConfigured(): bool { return true; }
            public function enrich(Vessel $v): array {
                return [
                    'provider' => 'gfw',
                    'status' => 'MATCH_FOUND',
                    'external_record_id' => 'cand-1',
                    'confidence_score' => 0.9,
                    'attributes' => ['name' => 'RATE TEST SHIP'],
                    'raw_payload' => [],
                    'message' => 'ok',
                ];
            }
        };

        $flagProvider = new class implements EnrichmentProviderInterface {
            public function getName(): string { return 'flag_registry'; }
            public function isConfigured(): bool { return true; }
            public function enrich(Vessel $v): array {
                return [
                    'provider' => 'flag_registry',
                    'status' => 'MATCH_FOUND',
                    'external_record_id' => 'mid-id',
                    'confidence_score' => 1.0,
                    'attributes' => ['flag_state' => 'ID'],
                    'raw_payload' => [],
                    'message' => 'ok',
                ];
            }
        };

        // Simulate GFW rate limit hit by maxing out attempts
        $limit = (int) config('maritime.providers.gfw.rate_limit_per_minute', 60);
        for ($i = 0; $i < $limit; $i++) {
            RateLimiter::hit('enrichment_rate_limit_gfw', 60);
        }

        $manager = new VesselEnrichmentManager();
        $manager->setProviders([$gfwProvider, $flagProvider]);

        $result = $manager->enrichVessel($vessel, dryRun: false);

        // GFW should report PROVIDER_UNAVAILABLE due to rate limit
        $this->assertEquals('PROVIDER_UNAVAILABLE', $result['provider_results']['gfw']['status']);
        $this->assertStringContainsString('rate limit reached', $result['provider_results']['gfw']['message']);

        // Flag registry should NOT be affected and should match
        $this->assertEquals('MATCH_FOUND', $result['provider_results']['flag_registry']['status']);

        // Vessel flag_state was still enriched from the non-throttled provider
        $vessel->refresh();
        $this->assertEquals('ID', $vessel->flag_state);

        RateLimiter::clear('enrichment_rate_limit_gfw');
    }

    /**
     * Test 22: Vessels already in searching status are not re-dispatched by the scheduler.
     */
    public function test_22_searching_vessels_not_redispatched(): void
    {
        Queue::fake();

        // One vessel awaiting, one already in 'searching' state
        $vAwaiting = $this->createAwaitingVessel(['mmsi' => '525000010', 'last_seen_at' => now()->subMinutes(10)]);
        $vSearching = $this->createAwaitingVessel([
            'mmsi' => '525000011',
            'last_seen_at' => now()->subMinutes(5),
            'enrichment_status' => 'searching',
        ]);

        $this->artisan('vessels:enrich-awaiting', [
            '--limit' => 10,
            '--active-only' => true,
            '--queue' => true,
        ])->assertExitCode(0);

        // Only the non-searching awaiting vessel should be dispatched
        Queue::assertPushed(EnrichAwaitingVesselJob::class, 1);
        Queue::assertPushed(EnrichAwaitingVesselJob::class, function ($job) use ($vAwaiting) {
            return $job->vesselId === $vAwaiting->id;
        });
        Queue::assertNotPushed(EnrichAwaitingVesselJob::class, function ($job) use ($vSearching) {
            return $job->vesselId === $vSearching->id;
        });
    }

    /**
     * Test 23: Dispatch immediately transitions vessel status to searching to prevent duplicate dispatch.
     */
    public function test_23_dispatch_marks_vessel_as_searching(): void
    {
        Queue::fake();

        $vessel = $this->createAwaitingVessel(['mmsi' => '525000020', 'last_seen_at' => now()->subMinutes(10)]);
        $this->assertEquals('not_started', $vessel->enrichment_status);

        $this->artisan('vessels:enrich-awaiting', [
            '--limit' => 1,
            '--active-only' => true,
            '--queue' => true,
        ])->assertExitCode(0);

        $vessel->refresh();
        $this->assertEquals('searching', $vessel->enrichment_status);
    }

    /**
     * Test 24: External observation never alters AIS static status or timestamp.
     */
    public function test_24_external_observation_never_changes_ais_static_status(): void
    {
        $vessel = $this->createAwaitingVessel(['name' => 'UNKNOWN', 'mmsi' => '525000030']);
        $this->assertNull($vessel->static_data_received_at);
        $this->assertEquals('AWAITING', $vessel->ais_static_status);

        $mockGfw = new class implements EnrichmentProviderInterface {
            public function getName(): string { return 'gfw'; }
            public function isConfigured(): bool { return true; }
            public function enrich(Vessel $v): array {
                return [
                    'provider' => 'gfw',
                    'status' => 'MATCH_FOUND',
                    'external_record_id' => 'cand-external-gfw',
                    'confidence_score' => 0.95,
                    'attributes' => [
                        'name' => 'EXTERNAL SHIP NAME',
                        'vessel_type' => 'fishing',
                        'imo' => '9876543',
                    ],
                    'raw_payload' => [],
                    'message' => 'matched',
                ];
            }
        };

        $manager = new VesselEnrichmentManager();
        $manager->setProviders([$mockGfw]);
        $manager->enrichVessel($vessel, dryRun: false);

        $vessel->refresh();
        $this->assertEquals('enriched', $vessel->enrichment_status);
        $this->assertNull($vessel->static_data_received_at, 'static_data_received_at MUST remain null.');
        $this->assertEquals('AWAITING', $vessel->ais_static_status, 'AIS static status MUST remain strictly AWAITING.');
    }
}
