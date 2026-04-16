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
        // Over-fetch to allow enforcing one-contact-per-company while still filling the requested limit.
        $fetchLimit = min(1000, max($limit, $limit * 3));
        $contacts = $this->apolloLeadService->searchContacts($filters, $fetchLimit);

        $uniqueByCompany = [];
        foreach ($contacts as $contact) {
            $email = strtolower(trim((string) ($contact['email'] ?? '')));
            if ($email === '') {
                continue;
            }

            $company = trim((string) ($contact['company'] ?? ''));
            $companyDomain = strtolower(trim((string) ($contact['company_domain'] ?? '')));
            if ($companyDomain === '') {
                $companyDomain = strtolower((string) strstr($email, '@') ?: '');
                $companyDomain = ltrim($companyDomain, '@');
            }

            $companyKey = $companyDomain !== '' ? $companyDomain : strtolower($company);
            if ($companyKey === '') {
                // Last-resort fallback to keep deterministic uniqueness when company data is missing.
                $companyKey = $email;
            }

            if (isset($uniqueByCompany[$companyKey])) {
                continue;
            }

            $uniqueByCompany[$companyKey] = [
                'email' => $email,
                'name' => trim((string) ($contact['name'] ?? '')),
                'company' => $company !== '' ? $company : ($companyDomain !== '' ? $companyDomain : null),
                'source' => 'apollo',
            ];

            if (count($uniqueByCompany) >= $limit) {
                break;
            }
        }

        return array_values($uniqueByCompany);
    }
}

