<?php

namespace App\Services\Enrichment\Providers;

use App\Models\Vessel;
use App\Services\Enrichment\Contracts\EnrichmentProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FaoRecordEnrichmentProvider implements EnrichmentProviderInterface
{
    protected ?string $apiKey;
    protected string $baseUrl;
    protected int $timeout;

    public function __construct()
    {
        $this->apiKey = config('maritime.providers.fao_global_record.api_key', env('FAO_GLOBAL_RECORD_API_KEY'));
        $this->baseUrl = config('maritime.providers.fao_global_record.base_url', env('FAO_GLOBAL_RECORD_URL', 'https://www.fao.org/global-record/api/v1'));
        $this->timeout = (int) config('maritime.providers.fao_global_record.timeout', 15);
    }

    public function getName(): string
    {
        return 'fao';
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }

    public function enrich(Vessel $vessel): array
    {
        if (!$this->isConfigured()) {
            return [
                'provider' => $this->getName(),
                'status' => 'NOT_CONFIGURED',
                'external_record_id' => null,
                'confidence_score' => null,
                'attributes' => [],
                'raw_payload' => null,
                'message' => 'FAO Global Record API credential is not configured in .env (FAO_GLOBAL_RECORD_API_KEY).',
            ];
        }

        // Must have valid IMO or MMSI
        $imo = !empty($vessel->imo) && preg_match('/^[0-9]{7}$/', trim($vessel->imo)) ? trim($vessel->imo) : null;
        $mmsi = !empty($vessel->mmsi) && preg_match('/^[0-9]{9}$/', trim($vessel->mmsi)) ? trim($vessel->mmsi) : null;

        if (!$imo && !$mmsi) {
            return [
                'provider' => $this->getName(),
                'status' => 'NOT_FOUND',
                'external_record_id' => null,
                'confidence_score' => null,
                'attributes' => [],
                'raw_payload' => null,
                'message' => 'Vessel has neither valid 7-digit IMO nor 9-digit MMSI for FAO registry query.',
            ];
        }

        try {
            $identifier = $imo ?: $mmsi;
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Accept' => 'application/json',
                ])
                ->get("{$this->baseUrl}/vessels/{$identifier}");

            if ($response->status() === 404) {
                return [
                    'provider' => $this->getName(),
                    'status' => 'NOT_FOUND',
                    'external_record_id' => null,
                    'confidence_score' => null,
                    'attributes' => [],
                    'raw_payload' => ['status' => 404],
                    'message' => "Vessel {$identifier} not found in FAO Global Record.",
                ];
            }

            if ($response->failed()) {
                return [
                    'provider' => $this->getName(),
                    'status' => 'PROVIDER_UNAVAILABLE',
                    'external_record_id' => null,
                    'confidence_score' => null,
                    'attributes' => [],
                    'raw_payload' => ['status' => $response->status()],
                    'message' => 'FAO API returned HTTP status ' . $response->status(),
                ];
            }

            $data = $response->json();
            $attrs = [];
            if (!empty($data['vessel_name'])) $attrs['name'] = trim($data['vessel_name']);
            if (!empty($data['mmsi'])) $attrs['mmsi'] = trim($data['mmsi']);
            if (!empty($data['imo'])) $attrs['imo'] = trim($data['imo']);
            if (!empty($data['flag_state'])) $attrs['flag_state'] = strtoupper(trim($data['flag_state']));
            if (!empty($data['vessel_type'])) $attrs['vessel_type'] = strtolower(trim($data['vessel_type']));
            if (!empty($data['gear_type'])) $attrs['gear_type'] = strtolower(trim($data['gear_type']));
            if (!empty($data['length'])) $attrs['length_meters'] = (float) $data['length'];
            if (!empty($data['tonnage'])) $attrs['tonnage'] = (float) $data['tonnage'];
            if (!empty($data['build_year'])) $attrs['build_year'] = (int) $data['build_year'];
            if (!empty($data['home_port'])) $attrs['home_port'] = trim($data['home_port']);
            if (!empty($data['registry_number'])) $attrs['registry_info'] = trim($data['registry_number']);

            return [
                'provider' => $this->getName(),
                'status' => 'MATCH_FOUND',
                'external_record_id' => (string) ($data['fao_record_id'] ?? $identifier),
                'confidence_score' => 0.95,
                'attributes' => $attrs,
                'raw_payload' => $data,
                'message' => 'Vessel profile retrieved from FAO Global Record.',
            ];
        } catch (\Throwable $e) {
            Log::warning("FaoRecordEnrichmentProvider exception: " . $e->getMessage());
            return [
                'provider' => $this->getName(),
                'status' => 'PROVIDER_UNAVAILABLE',
                'external_record_id' => null,
                'confidence_score' => null,
                'attributes' => [],
                'raw_payload' => ['error' => $e->getMessage()],
                'message' => 'Provider error: ' . $e->getMessage(),
            ];
        }
    }
}

