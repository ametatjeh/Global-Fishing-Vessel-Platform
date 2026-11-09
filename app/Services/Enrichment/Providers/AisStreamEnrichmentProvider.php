<?php

namespace App\Services\Enrichment\Providers;

use App\Models\Vessel;
use App\Services\Enrichment\Contracts\EnrichmentProviderInterface;

class AisStreamEnrichmentProvider implements EnrichmentProviderInterface
{
    public function getName(): string
    {
        return 'aisstream';
    }

    public function isConfigured(): bool
    {
        return !empty(config('maritime.providers.ais_stream.api_key'));
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
                'message' => 'AISStream API key is not configured in .env',
            ];
        }

        // If vessel has real AIS static data timestamp
        if ($vessel->static_data_received_at !== null) {
            $attrs = [];
            if (!empty($vessel->name) && $vessel->name !== 'UNKNOWN' && $vessel->name !== $vessel->mmsi) {
                $attrs['name'] = $vessel->name;
            }
            if (!empty($vessel->mmsi)) {
                $attrs['mmsi'] = $vessel->mmsi;
            }
            if (!empty($vessel->imo) && preg_match('/^[0-9]{7}$/', trim($vessel->imo))) {
                $attrs['imo'] = trim($vessel->imo);
            }
            if (!empty($vessel->callsign)) {
                $attrs['callsign'] = $vessel->callsign;
            }
            if (!empty($vessel->vessel_type) && !in_array($vessel->vessel_type, ['unknown', 'not_available', 'unmapped'])) {
                $attrs['vessel_type'] = $vessel->vessel_type;
            }
            if ($vessel->length_meters > 0) {
                $attrs['length_meters'] = (float) $vessel->length_meters;
            }
            if ($vessel->beam_meters > 0) {
                $attrs['beam_meters'] = (float) $vessel->beam_meters;
            }
            if ($vessel->draught > 0) {
                $attrs['draught'] = (float) $vessel->draught;
            }
            if (!empty($vessel->ais_class)) {
                $attrs['ais_class'] = $vessel->ais_class;
            }

            return [
                'provider' => $this->getName(),
                'status' => 'MATCH_FOUND',
                'external_record_id' => (string) $vessel->mmsi,
                'confidence_score' => 1.0,
                'attributes' => $attrs,
                'raw_payload' => [
                    'static_data_received_at' => $vessel->static_data_received_at?->toIso8601String(),
                    'ais_ship_type_code' => $vessel->ais_ship_type_code,
                    'classification_source' => $vessel->classification_source,
                ],
                'message' => 'Valid AIS static packet received from live telemetry stream.',
            ];
        }

        return [
            'provider' => $this->getName(),
            'status' => 'NOT_FOUND',
            'external_record_id' => null,
            'confidence_score' => null,
            'attributes' => [],
            'raw_payload' => null,
            'message' => 'No AIS Type 5, 19, or 24 static report received yet.',
        ];
    }
}

