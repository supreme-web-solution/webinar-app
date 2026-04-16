<?php

namespace App\Services\Leads;

use RuntimeException;

class LeadProviderManager
{
    public function __construct(
        private readonly ApolloLeadProvider $apolloLeadProvider,
        private readonly D7LeadProvider $d7LeadProvider,
    ) {
    }

    public function providerKey(): string
    {
        return $this->resolveConfiguredProvider();
    }

    /**
     * @param array{job_title?: string, industry?: string, location?: string, company_size?: string, keyword?: string} $filters
     * @return array<int, array{email: string, name: string, company?: string|null, source: string}>
     */
    public function searchContacts(array $filters, int $limit): array
    {
        $primaryKey = $this->resolveConfiguredProvider();
        $primaryProvider = $this->makeProvider($primaryKey);

        try {
            return $primaryProvider->searchContacts($filters, $limit);
        } catch (\Throwable $exception) {
            $fallbackKey = $this->resolveFallbackProvider($primaryKey);
            if ($fallbackKey === null) {
                throw $exception;
            }

            return $this->makeProvider($fallbackKey)->searchContacts($filters, $limit);
        }
    }

    private function resolveConfiguredProvider(): string
    {
        $key = strtolower(trim((string) config('services.leads.provider', 'apollo')));

        return in_array($key, ['apollo', 'd7'], true) ? $key : 'apollo';
    }

    private function resolveFallbackProvider(string $primary): ?string
    {
        $fallback = strtolower(trim((string) config('services.leads.fallback_provider', '')));
        if ($fallback === '' || $fallback === $primary) {
            return null;
        }

        return in_array($fallback, ['apollo', 'd7'], true) ? $fallback : null;
    }

    private function makeProvider(string $key): LeadProvider
    {
        return match ($key) {
            'apollo' => $this->apolloLeadProvider,
            'd7' => $this->d7LeadProvider,
            default => throw new RuntimeException('Invalid lead provider selected.'),
        };
    }
}

