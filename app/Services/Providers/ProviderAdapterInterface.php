<?php

namespace App\Services\Providers;

interface ProviderAdapterInterface
{
    /**
     * Perform a health check against the provider
     *
     * @return array{status: string, message: string, latency_ms: ?float, timestamp: string}
     */
    public function healthCheck(): array;

    /**
     * Run data synchronization from provider
     *
     * @param array $options
     * @return array{status: string, fetched: int, inserted: int, skipped: int, errors: array}
     */
    public function sync(array $options = []): array;

    /**
     * Get provider metadata, license terms and commercial restriction status
     *
     * @return array
     */
    public function getMetadata(): array;
}
