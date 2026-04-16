<?php

namespace App\Services\Leads;

use App\Services\Apollo\ApolloLeadService;

class ApolloLeadProvider implements LeadProvider
{
    public function __construct(
        private readonly ApolloLeadService $apolloLeadService,
    ) {
    }

    public function key(): string
    {
        return 'apollo';
    }

    /**
     * @param array{job_title?: string, industry?: string, location?: string, company_size?: string, keyword?: string} $filters
     * @return array<int, array{email: string, name: string, company?: string|null, source: string}>
     */
    public function searchContacts(array $filters, int $limit): array
    {
        $contacts = $this->apolloLeadService->searchContacts($filters, $limit);

        return array_map(static fn (array $contact): array => [
            'email' => (string) ($contact['email'] ?? ''),
            'name' => (string) ($contact['name'] ?? ''),
            'company' => null,
            'source' => 'apollo',
        ], $contacts);
    }
}

