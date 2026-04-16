<?php

namespace App\Services\Leads;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class D7LeadProvider implements LeadProvider
{
    public function key(): string
    {
        return 'd7';
    }

    /**
     * @param array{job_title?: string, industry?: string, location?: string, company_size?: string, keyword?: string} $filters
     * @return array<int, array{email: string, name: string, company?: string|null, source: string}>
     */
    public function searchContacts(array $filters, int $limit): array
    {
        $apiKey = trim((string) config('services.d7.api_key', ''));
        $endpoint = trim((string) config('services.d7.search_endpoint', ''));
        $method = strtolower(trim((string) config('services.d7.search_method', 'get')));
        $timeout = max(15, (int) config('services.d7.timeout_seconds', 60));

        if ($apiKey === '') {
            throw new RuntimeException('D7_API_KEY is not configured.');
        }

        if ($endpoint === '') {
            throw new RuntimeException('D7_SEARCH_ENDPOINT is not configured.');
        }

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword === '') {
            $keyword = trim((string) ($filters['industry'] ?? ''));
        }
        if ($keyword === '') {
            $keyword = trim((string) ($filters['job_title'] ?? ''));
        }
        if ($keyword === '') {
            $keyword = 'business';
        }

        $location = trim((string) ($filters['location'] ?? ''));
        if ($location === '') {
            $location = 'United States';
        }

        $payload = [
            'keyword' => $keyword,
            'location' => $location,
            'limit' => min(max(1, $limit), max(1, (int) config('services.d7.max_fetch', 1200))),
        ];

        $request = Http::timeout($timeout)
            ->withHeaders([
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$apiKey,
                'X-Api-Key' => $apiKey,
            ]);

        $response = $method === 'post'
            ? $request->post($endpoint, $payload)
            : $request->get($endpoint, $payload + ['api_key' => $apiKey]);

        if (! $response->successful()) {
            $message = trim((string) data_get($response->json(), 'message', ''));
            if ($message === '') {
                $message = 'D7 request failed with status '.$response->status().'.';
            }

            throw new RuntimeException($message);
        }

        $rows = data_get($response->json(), 'data');
        if (! is_array($rows)) {
            $rows = data_get($response->json(), 'results');
        }
        if (! is_array($rows)) {
            $rows = data_get($response->json(), 'leads');
        }
        if (! is_array($rows)) {
            $rows = data_get($response->json(), 'businesses');
        }
        if (! is_array($rows)) {
            $rows = [];
        }

        $contacts = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $email = $this->extractEmail($row);
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $company = $this->firstNonEmptyString($row, ['company', 'company_name', 'business_name', 'name']);
            $name = $this->firstNonEmptyString($row, ['contact_name', 'name', 'owner_name']);
            if ($name === '') {
                $name = $company !== '' ? $company : ucfirst(str_replace(['.', '_', '-'], ' ', strstr($email, '@', true) ?: 'Lead'));
            }

            $contacts[$email] = [
                'email' => $email,
                'name' => $name,
                'company' => $company !== '' ? $company : null,
                'source' => 'd7',
            ];

            if (count($contacts) >= $limit) {
                break;
            }
        }

        return array_values($contacts);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function extractEmail(array $row): string
    {
        $candidates = [
            $this->firstNonEmptyString($row, ['email']),
            $this->firstNonEmptyString($row, ['email_address']),
            $this->firstNonEmptyString($row, ['business_email']),
        ];

        foreach ($candidates as $candidate) {
            $parts = preg_split('/[;,\\s]+/', strtolower(trim($candidate))) ?: [];
            foreach ($parts as $email) {
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return $email;
                }
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $keys
     */
    private function firstNonEmptyString(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}

