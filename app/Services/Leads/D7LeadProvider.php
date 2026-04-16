<?php

namespace App\Services\Leads;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class D7LeadProvider implements LeadProvider
{
    private const D7_SEARCH_ENDPOINT = 'https://dash.d7leadfinder.com/app/api/search/';
    private const D7_RESULTS_ENDPOINT = 'https://dash.d7leadfinder.com/app/api/results/';

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
        $timeout = max(15, (int) config('services.d7.timeout_seconds', 60));
        $maxFetch = max(1, (int) config('services.d7.max_fetch', 1200));

        if ($apiKey === '') {
            throw new RuntimeException('D7_API_KEY is not configured.');
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

        [$countryCode, $city] = $this->resolveCountryAndCity($filters);
        $requestedLimit = min(max(1, $limit), $maxFetch);
        $limit = $requestedLimit;
        $searchCacheKey = $this->d7SearchCacheKey($keyword, $countryCode, $city, $limit);

        $cachedSearch = Cache::get($searchCacheKey);
        if (is_array($cachedSearch) && (int) ($cachedSearch['searchid'] ?? 0) > 0) {
            $searchId = (int) $cachedSearch['searchid'];
            $waitSeconds = max(1, (int) ($cachedSearch['wait_seconds'] ?? 1));
            $createdAtTs = (int) ($cachedSearch['created_at'] ?? time());
            $elapsedSeconds = max(0, time() - $createdAtTs);
            $remainingWait = max(1, $waitSeconds - $elapsedSeconds);
        } else {
            $searchQuery = [
                'keyword' => $keyword,
                'country' => $countryCode,
                'location' => $city,
                'key' => $apiKey,
            ];

            $searchResponse = Http::timeout($timeout)->get(self::D7_SEARCH_ENDPOINT, $searchQuery);
            if (! $searchResponse->successful()) {
                throw new RuntimeException('D7 search request failed with status '.$searchResponse->status().'.');
            }

            $searchPayload = $searchResponse->json();
            if (! is_array($searchPayload)) {
                throw new RuntimeException('D7 search response is invalid.');
            }

            if (isset($searchPayload['error'])) {
                throw new RuntimeException('D7 search error: '.trim((string) $searchPayload['error']));
            }

            $searchId = (int) ($searchPayload['searchid'] ?? 0);
            $waitSeconds = max(1, (int) ($searchPayload['wait_seconds'] ?? 15));
            if ($searchId <= 0) {
                throw new RuntimeException('D7 search did not return a valid searchid.');
            }

            Cache::put($searchCacheKey, [
                'searchid' => $searchId,
                'wait_seconds' => $waitSeconds,
                'created_at' => time(),
            ], now()->addMinutes(20));
            $remainingWait = $waitSeconds;
        }

        sleep($remainingWait);

        $resultPayload = [];
        $attempts = 0;
        while ($attempts < 5) {
            $attempts++;
            $resultsResponse = Http::timeout($timeout)->get(self::D7_RESULTS_ENDPOINT, [
                'id' => $searchId,
                'key' => $apiKey,
            ]);

            if (! $resultsResponse->successful()) {
                if ($attempts >= 5) {
                    throw new RuntimeException('D7 results request failed with status '.$resultsResponse->status().'.');
                }

                sleep(3);
                continue;
            }

            $payload = $resultsResponse->json();
            if (! is_array($payload)) {
                if ($attempts >= 5) {
                    throw new RuntimeException('D7 results response is invalid.');
                }

                sleep(3);
                continue;
            }

            if (isset($payload['error'])) {
                $error = trim((string) $payload['error']);
                if ($error !== '' && $error !== 'results_not_ready') {
                    throw new RuntimeException('D7 results error: '.$error);
                }

                if ($attempts >= 5) {
                    throw new RuntimeException('D7 results are not ready yet. Please retry shortly.');
                }

                sleep(3);
                continue;
            }

            $resultPayload = $payload;
            break;
        }

        // Clear cached search id after a successful results read.
        if ($resultPayload !== []) {
            Cache::forget($searchCacheKey);
        }

        $rows = data_get($resultPayload, 'data');
        if (! is_array($rows)) {
            $rows = data_get($resultPayload, 'results');
        }
        if (! is_array($rows)) {
            $rows = data_get($resultPayload, 'leads');
        }
        if (! is_array($rows)) {
            $rows = data_get($resultPayload, 'businesses');
        }
        if (! is_array($rows) && array_is_list($resultPayload)) {
            $rows = $resultPayload;
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
     * @param array{job_title?: string, industry?: string, location?: string, company_size?: string, keyword?: string} $filters
     * @return array{0: string, 1: string}
     */
    private function resolveCountryAndCity(array $filters): array
    {
        $location = trim((string) ($filters['location'] ?? ''));
        if ($location === '') {
            return ['US', 'Miami'];
        }

        $parts = preg_split('/,/', $location) ?: [];
        $city = trim((string) ($parts[0] ?? $location));
        $tail = strtoupper(trim((string) (end($parts) ?: '')));

        if (strlen($tail) === 2 && ctype_alpha($tail)) {
            return [$tail, $city !== '' ? $city : 'Miami'];
        }

        $normalized = strtolower($location);
        if (str_contains($normalized, 'united kingdom') || str_contains($normalized, 'uk')) {
            return ['GB', $city !== '' ? $city : 'London'];
        }
        if (str_contains($normalized, 'canada')) {
            return ['CA', $city !== '' ? $city : 'Toronto'];
        }
        if (str_contains($normalized, 'australia')) {
            return ['AU', $city !== '' ? $city : 'Sydney'];
        }

        return ['US', $city !== '' ? $city : 'Miami'];
    }

    private function d7SearchCacheKey(string $keyword, string $countryCode, string $city, int $limit): string
    {
        $signature = implode('|', [
            strtolower(trim($keyword)),
            strtoupper(trim($countryCode)),
            strtolower(trim($city)),
            (string) $limit,
        ]);

        return 'd7:search:'.sha1($signature);
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

