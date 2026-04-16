<?php

namespace App\Services\Leads;

interface LeadProvider
{
    public function key(): string;

    /**
     * @param array{job_title?: string, industry?: string, location?: string, company_size?: string, keyword?: string} $filters
     * @return array<int, array{email: string, name: string, company?: string|null, source: string}>
     */
    public function searchContacts(array $filters, int $limit): array;
}

