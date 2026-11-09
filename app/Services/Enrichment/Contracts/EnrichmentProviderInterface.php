<?php

namespace App\Services\Enrichment\Contracts;

use App\Models\Vessel;

interface EnrichmentProviderInterface
{
    /**
     * Get unique provider identifier slug.
     */
    public function getName(): string;

    /**
     * Check if provider credentials and network endpoints are configured.
     */
    public function isConfigured(): bool;

    /**
     * Search and extract vessel attributes from this source.
     *
     * @return array{
     *     provider: string,
     *     status: string,
     *     external_record_id: ?string,
     *     confidence_score: ?float,
     *     attributes: array<string, mixed>,
     *     raw_payload: ?array,
     *     message: ?string
     * }
     */
    public function enrich(Vessel $vessel): array;
}

