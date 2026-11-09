<?php

namespace App\Services\Enrichment\Providers;

use App\Models\Vessel;
use App\Services\Enrichment\Contracts\EnrichmentProviderInterface;
use App\Services\Maritime\FlagStateService;

class OfficialFlagRegistryProvider implements EnrichmentProviderInterface
{
    public function getName(): string
    {
        return 'flag_registry';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function enrich(Vessel $vessel): array
    {
        $mmsi = trim((string) ($vessel->mmsi ?? ''));
        if (empty($mmsi) || !preg_match('/^[0-9]{9}$/', $mmsi) || $mmsi === '000000000' || $mmsi === '111111111') {
            return [
                'provider' => $this->getName(),
                'status' => 'NOT_FOUND',
                'external_record_id' => null,
                'confidence_score' => null,
                'attributes' => [],
                'raw_payload' => null,
                'message' => 'Invalid or missing MMSI for official flag registry lookup.',
            ];
        }

        $attrs = [];
        $raw = [];

        // 1. Authoritative ITU Maritime Identification Digits (MID) resolution
        $midInfo = FlagStateService::lookupMid($mmsi);
        if ($midInfo && !empty($midInfo['iso2']) && $midInfo['iso2'] !== 'UN') {
            $attrs['flag_state'] = $midInfo['iso2'];
            $raw['mid_lookup'] = $midInfo;
        }

        // 2. Check internal authorizations or official maritime zones
        $authorization = $vessel->authorizations()->with('maritimeZone')->first();
        if ($authorization) {
            if (!empty($authorization->license_number)) {
                $attrs['registry_info'] = 'Auth: ' . $authorization->license_number;
            }
            if (!empty($authorization->issuing_authority)) {
                $attrs['home_port'] = $authorization->issuing_authority;
            }
            $raw['authorization'] = [
                'license' => $authorization->license_number,
                'status' => $authorization->status,
                'authority' => $authorization->issuing_authority,
            ];
        }

        // 3. AIS Class deduction from MMSI / transceiver structure
        // In maritime telematics: standard Class A vs Class B MMSI formats or Coast stations
        if (str_starts_with($mmsi, '00')) {
            $attrs['ais_class'] = 'Base Station';
        } elseif (str_starts_with($mmsi, '111')) {
            $attrs['ais_class'] = 'SAR Aircraft';
        } elseif (str_starts_with($mmsi, '99')) {
            $attrs['ais_class'] = 'AtoN';
        } else {
            // Default commercial Class A or Class B
            if (!empty($vessel->ais_class)) {
                $attrs['ais_class'] = $vessel->ais_class;
            }
        }

        if (empty($attrs)) {
            return [
                'provider' => $this->getName(),
                'status' => 'NOT_FOUND',
                'external_record_id' => null,
                'confidence_score' => null,
                'attributes' => [],
                'raw_payload' => null,
                'message' => 'No matching official registry record found.',
            ];
        }

        return [
            'provider' => $this->getName(),
            'status' => 'MATCH_FOUND',
            'external_record_id' => 'ITU-MID-' . substr($mmsi, 0, 3),
            'confidence_score' => 0.85,
            'attributes' => $attrs,
            'raw_payload' => $raw,
            'message' => 'Official Flag State administration resolved from ITU-R MID registry.',
        ];
    }
}

