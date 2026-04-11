<?php

namespace App\Services\Apollo;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ApolloLeadService
{
    /**
     * @param array{job_title?: string, industry?: string, location?: string, company_size?: string, keyword?: string} $filters
     * @return array<int, array{email: string, name: string}>
     */
    public function searchContacts(array $filters, int $limit): array
    {
        $apiKey = trim((string) config('services.apollo.api_key', ''));
        if ($apiKey === '') {
            throw new RuntimeException('APOLLO_API_KEY is not configured.');
        }

        $configuredEndpoint = trim((string) config('services.apollo.search_endpoint', 'https://api.apollo.io/api/v1/mixed_people/search'));
        $endpoints = $this->buildEndpointCandidates($configuredEndpoint);
        $perPage = min(100, max(10, $limit));

        $collected = [];
        $page = 1;

        while (count($collected) < $limit && $page <= 20) {
            $jobTitle = $this->cleanFilterString($filters['job_title'] ?? '');
            $industry = $this->cleanFilterString($filters['industry'] ?? '');
            $location = $this->cleanFilterString($filters['location'] ?? '');
            $keyword = $this->cleanFilterString($filters['keyword'] ?? '');

            $payload = [
                'page' => $page,
                'per_page' => $perPage,
            ];

            if ($jobTitle !== '') {
                $payload['person_titles'] = [$jobTitle];
            }

            if ($industry !== '') {
                $payload['organization_industries'] = [$industry];
            }

            if ($location !== '') {
                $payload['person_locations'] = [$location];
            }

            if ($keyword !== '') {
                $payload['q_keywords'] = $keyword;
            }

            $normalizedRange = $this->normalizeEmployeeRange((string) ($filters['company_size'] ?? ''));
            if ($normalizedRange !== null) {
                $payload['organization_num_employees_ranges'] = [$normalizedRange];
            }

            if (! isset($payload['q_keywords'])) {
                $fallbackKeywords = $this->synthesizeSearchKeywords($payload);
                if ($fallbackKeywords !== '') {
                    $payload['q_keywords'] = $fallbackKeywords;
                }
            }

            $response = $this->sendSearchRequestWithFallbacks($endpoints, $apiKey, $payload);

            if ($response instanceof Response && $response->status() === 422) {
                $response = $this->sendSearchRequestWithPayloadFallbacks($endpoints, $apiKey, $payload);
            }

            if (! $response instanceof Response) {
                throw new RuntimeException('Apollo request failed: no response received.');
            }

            if (! $response->successful()) {
                if (in_array($response->status(), [401, 403], true)) {
                    $providerMessage = $this->extractApolloValidationMessage($response);

                    throw new RuntimeException($providerMessage !== ''
                        ? 'Apollo authorization failed (status '.$response->status().'): '.$providerMessage
                        : 'Apollo authorization failed (status '.$response->status().'). Check APOLLO_API_KEY permissions and subscription access.');
                }

                if ($response->status() === 422) {
                    $validationMessage = $this->extractApolloValidationMessage($response);
                    throw new RuntimeException($validationMessage !== ''
                        ? 'Apollo validation failed (422): '.$validationMessage
                        : 'Apollo validation failed (422). One or more selected filters are invalid for this Apollo account.');
                }

                throw new RuntimeException('Apollo request failed with status '.$response->status().'.');
            }

            /** @var array<string, mixed> $json */
            $json = $response->json();

            /** @var array<int, array<string, mixed>> $people */
            $people = data_get($json, 'people', []);
            if (! is_array($people) || $people === []) {
                $people = data_get($json, 'contacts', []);
            }
            if (! is_array($people) || $people === []) {
                $people = data_get($json, 'data.people', []);
            }

            if (! is_array($people) || $people === []) {
                break;
            }

            foreach ($people as $person) {
                if (! is_array($person)) {
                    continue;
                }

                $email = strtolower(trim((string) ($person['email'] ?? $person['work_email'] ?? '')));
                if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }

                $name = trim((string) ($person['name'] ?? ''));
                if ($name === '') {
                    $first = trim((string) ($person['first_name'] ?? ''));
                    $last = trim((string) ($person['last_name'] ?? ''));
                    $name = trim($first.' '.$last);
                }

                if ($name === '') {
                    $name = ucfirst(str_replace(['.', '_', '-'], ' ', strstr($email, '@', true) ?: 'Lead'));
                }

                $collected[$email] = [
                    'email' => $email,
                    'name' => $name,
                ];

                if (count($collected) >= $limit) {
                    break;
                }
            }

            if (count($people) < $perPage) {
                break;
            }

            $page++;
        }

        return array_values($collected);
    }

    /**
     * @return array<int, string>
     */
    private function buildEndpointCandidates(string $configuredEndpoint): array
    {
        $defaults = [
            'https://api.apollo.io/api/v1/mixed_people/api_search',
        ];

        $candidates = [$configuredEndpoint];

        if (str_contains($configuredEndpoint, '/mixed_people/search')) {
            $candidates[] = str_replace('/mixed_people/search', '/mixed_people/api_search', $configuredEndpoint);
        }

        return array_values(array_unique(array_merge($candidates, $defaults)));
    }

    /**
     * @param array<int, string> $endpoints
     * @param array<string, mixed> $payload
     */
    private function sendSearchRequestWithFallbacks(array $endpoints, string $apiKey, array $payload): ?Response
    {
        $lastResponse = null;

        foreach ($endpoints as $endpoint) {
            $response = Http::timeout(45)
                ->withHeaders([
                    'X-Api-Key' => $apiKey,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'Cache-Control' => 'no-cache',
                ])
                ->post($endpoint, $payload);

            $lastResponse = $response;

            if ($response->successful()) {
                return $response;
            }

            // Keep trying endpoint alternates only for endpoint/auth mismatch style statuses.
            if (! in_array($response->status(), [401, 403, 404], true)) {
                return $response;
            }
        }

        return $lastResponse;
    }

    /**
     * Retry with less strict payload variants when Apollo rejects specific filters (422).
     *
     * @param array<int, string> $endpoints
     * @param array<string, mixed> $payload
     */
    private function sendSearchRequestWithPayloadFallbacks(array $endpoints, string $apiKey, array $payload): ?Response
    {
        $lastResponse = null;

        foreach ($this->buildPayloadFallbackCandidates($payload) as $candidatePayload) {
            $response = $this->sendSearchRequestWithFallbacks($endpoints, $apiKey, $candidatePayload);
            $lastResponse = $response;

            if ($response instanceof Response && $response->successful()) {
                return $response;
            }
        }

        return $lastResponse;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private function buildPayloadFallbackCandidates(array $payload): array
    {
        $base = [
            'page' => $payload['page'] ?? 1,
            'per_page' => $payload['per_page'] ?? 10,
        ];

        $variants = [];
        $variants[] = $this->withoutKeys($payload, ['organization_num_employees_ranges']);
        $variants[] = $this->withoutKeys($payload, ['person_locations']);
        $variants[] = $this->withoutKeys($payload, ['organization_industries']);
        $variants[] = $this->withoutKeys($payload, ['person_titles']);
        $variants[] = $this->withoutKeys($payload, ['organization_num_employees_ranges', 'person_locations']);
        $variants[] = $this->withoutKeys($payload, ['organization_num_employees_ranges', 'organization_industries']);
        $variants[] = $this->withoutKeys($payload, ['organization_num_employees_ranges', 'person_titles']);
        $variants[] = $this->withoutKeys($payload, ['person_locations', 'organization_industries', 'person_titles']);
        $variants[] = $this->withoutKeys($payload, ['q_keywords']);

        $existingKeyword = trim((string) ($payload['q_keywords'] ?? ''));
        if ($existingKeyword !== '') {
            $variants[] = $base + ['q_keywords' => $existingKeyword];
        }

        $synthesizedKeyword = $this->synthesizeSearchKeywords($payload);
        if ($synthesizedKeyword !== '') {
            $variants[] = $base + ['q_keywords' => $synthesizedKeyword];
        }

        $variants[] = $base;

        $unique = [];
        $seen = [];

        foreach ($variants as $variant) {
            $key = json_encode($variant);
            if ($key === false || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $variant;
        }

        return $unique;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $keys
     * @return array<string, mixed>
     */
    private function withoutKeys(array $payload, array $keys): array
    {
        foreach ($keys as $key) {
            unset($payload[$key]);
        }

        return $payload;
    }

    private function extractApolloValidationMessage(Response $response): string
    {
        $json = $response->json();
        if (! is_array($json)) {
            return '';
        }

        $message = trim((string) ($json['message'] ?? ''));
        if ($message !== '') {
            return $message;
        }

        $error = trim((string) ($json['error'] ?? ''));
        if ($error !== '') {
            return $error;
        }

        $errors = $json['errors'] ?? null;
        if (is_array($errors)) {
            foreach ($errors as $field => $errorValue) {
                if (is_array($errorValue) && $errorValue !== []) {
                    $first = trim((string) $errorValue[0]);
                    if ($first !== '') {
                        return (string) $field.': '.$first;
                    }
                }

                $single = trim((string) $errorValue);
                if ($single !== '') {
                    return (string) $field.': '.$single;
                }
            }
        }

        return '';
    }

    private function cleanFilterString(mixed $value): string
    {
        return trim((string) $value);
    }

    private function normalizeEmployeeRange(string $companySize): ?string
    {
        $companySize = trim($companySize);
        if ($companySize === '') {
            return null;
        }

        if (preg_match('/^(\d+)\s*[,\-]\s*(\d+)$/', $companySize, $matches) !== 1) {
            return null;
        }

        $min = (int) ($matches[1] ?? 0);
        $max = (int) ($matches[2] ?? 0);
        if ($min <= 0 || $max <= 0 || $min > $max) {
            return null;
        }

        return $min.','.$max;
    }

    /**
     * Apollo accepts free-text keyword searches as a low-friction fallback when strict filters fail.
     */
    private function synthesizeSearchKeywords(array $payload): string
    {
        $parts = [];

        foreach (['person_titles', 'organization_industries', 'person_locations'] as $listKey) {
            $value = $payload[$listKey] ?? null;
            if (is_array($value)) {
                foreach ($value as $item) {
                    $text = trim((string) $item);
                    if ($text !== '') {
                        $parts[] = $text;
                    }
                }
            }
        }

        $qKeywords = trim((string) ($payload['q_keywords'] ?? ''));
        if ($qKeywords !== '') {
            $parts[] = $qKeywords;
        }

        $parts = array_values(array_unique($parts));

        return implode(' ', $parts);
    }
}
