<?php

namespace App\Services\AI;

use App\Models\WebinarAiKnowledgeChunk;
use App\Models\WebinarAiKnowledgeSource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Smalot\PdfParser\Parser;

class WebinarKnowledgeIngestionService
{
    public function __construct(
        private readonly OpenAiEmbeddingService $embeddingService,
    ) {
    }

    public function ingestSource(WebinarAiKnowledgeSource $source): void
    {
        $source->update([
            'status' => 'processing',
            'error_message' => null,
        ]);

        try {
            $text = match ($source->source_type) {
                'url' => $this->ingestFromUrl($source),
                'video_transcript' => (string) ($source->raw_text ?? ''),
                'manual_text' => (string) ($source->raw_text ?? ''),
                'file' => $this->extractTextFromStoredFile((string) $source->storage_path),
                default => '',
            };

            $plainText = $this->normalizeText($text);
            if ($plainText === '') {
                $source->update([
                    'status' => 'failed',
                    'error_message' => 'No readable text was extracted from this source.',
                ]);

                return;
            }

            $source->chunks()->delete();
            $chunks = $this->chunkText($plainText, 900, 140);

            foreach ($chunks as $index => $chunk) {
                $embedding = $this->embeddingService->embedText($chunk);

                WebinarAiKnowledgeChunk::create([
                    'webinar_id' => $source->webinar_id,
                    'source_id' => $source->id,
                    'chunk_index' => $index,
                    'content' => $chunk,
                    'content_hash' => hash('sha256', $chunk),
                    'embedding' => $embedding,
                    'meta' => [
                        'source_type' => $source->source_type,
                    ],
                ]);
            }

            $source->update([
                'status' => 'ready',
                'error_message' => null,
                'raw_text' => $plainText,
                'processed_at' => now(),
                'meta' => array_merge($source->meta ?? [], [
                    'chunk_count' => count($chunks),
                    'char_count' => mb_strlen($plainText),
                ]),
            ]);
        } catch (\Throwable $e) {
            Log::error('WebinarKnowledgeIngestionService failed', [
                'source_id' => $source->id,
                'source_type' => $source->source_type,
                'message' => $e->getMessage(),
            ]);

            $source->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    public function storeUploadedFile(UploadedFile $file): string
    {
        return $file->store('webinar-ai-sources');
    }

    private function ingestFromUrl(WebinarAiKnowledgeSource $source): string
    {
        $url = trim((string) $source->source_url);
        if ($url === '') {
            return '';
        }

        $result = $this->scrapeWithScrapingBee($url);
        if (($result['success'] ?? false) !== true) {
            throw new \RuntimeException((string) ($result['error'] ?? 'Unable to scrape URL source.'));
        }

        return (string) ($result['content'] ?? '');
    }

    /**
     * @return array{success: bool, content?: string, html?: string, url?: string, status?: int, error?: string}
     */
    private function scrapeWithScrapingBee(string $url): array
    {
        $apiKey = (string) config('services.scrapingbee.api_key', '');
        if ($apiKey === '') {
            return [
                'success' => false,
                'status' => 500,
                'error' => 'Missing SCRAPINGBEE_API_KEY in environment.',
            ];
        }

        try {
            $params = [
                'api_key' => $apiKey,
                'url' => $url,
                'render_js' => 'true',
            ];

            $timeout = 90;

            $response = Http::timeout($timeout)->get('https://app.scrapingbee.com/api/v1/', $params);

            if ($response->successful()) {
                $html = $response->body();
                $content = $this->extractTextContent($html);

                return [
                    'success' => true,
                    'content' => $content,
                    'html' => $html,
                    'url' => $url,
                ];
            }

            $errorBody = $response->body();
            Log::error('ScrapingBee API Error', [
                'url' => $url,
                'status' => $response->status(),
                'body' => substr($errorBody, 0, 500),
            ]);

            return [
                'success' => false,
                'status' => $response->status(),
                'error' => 'Failed to scrape website with ScrapingBee: HTTP '.$response->status().' - '.substr($errorBody, 0, 200),
            ];
        } catch (\Exception $e) {
            $isTimeout = str_contains($e->getMessage(), 'timed out') || str_contains($e->getMessage(), 'timeout');

            Log::error('ScrapingBee Exception', [
                'url' => $url,
                'message' => $e->getMessage(),
                'is_timeout' => $isTimeout,
            ]);

            return [
                'success' => false,
                'status' => $isTimeout ? 408 : 500,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function extractTextContent(string $html): string
    {
        $text = $this->extractSemanticHtmlText($html);

        if ($text === '') {
            $text = strip_tags($html);
        }

        return $this->normalizeText($text);
    }

    private function extractSemanticHtmlText(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $previousLibxml = libxml_use_internal_errors(true);

        try {
            $dom = new \DOMDocument();
            $loaded = $dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET | LIBXML_COMPACT);
            if (! $loaded) {
                return '';
            }

            $xpath = new \DOMXPath($dom);

            // Drop nodes that usually contain boilerplate, styles, scripts, or chrome text.
            $noiseNodes = $xpath->query('//script|//style|//noscript|//template|//svg|//iframe|//canvas|//nav|//header|//footer|//aside|//form');
            if ($noiseNodes !== false) {
                foreach ($noiseNodes as $node) {
                    $node->parentNode?->removeChild($node);
                }
            }

            $mainNodes = $xpath->query('//main|//article|//*[@role="main"]');
            $rootNodes = ($mainNodes !== false && $mainNodes->length > 0)
                ? iterator_to_array($mainNodes)
                : (($xpath->query('//body') !== false && $xpath->query('//body')->length > 0)
                    ? iterator_to_array($xpath->query('//body'))
                    : [$dom->documentElement]);

            $parts = [];
            $seen = [];

            foreach ($rootNodes as $rootNode) {
                if (! $rootNode instanceof \DOMNode) {
                    continue;
                }

                $contentNodes = $xpath->query('.//h1|.//h2|.//h3|.//h4|.//p|.//li|.//blockquote|.//pre', $rootNode);

                if ($contentNodes === false || $contentNodes->length === 0) {
                    $fallbackText = $this->sanitizeHtmlTextSegment((string) $rootNode->textContent);
                    if ($this->isUsefulTextSegment($fallbackText)) {
                        $parts[] = $fallbackText;
                    }

                    continue;
                }

                foreach ($contentNodes as $contentNode) {
                    $text = $this->sanitizeHtmlTextSegment((string) $contentNode->textContent);

                    if (! $this->isUsefulTextSegment($text)) {
                        continue;
                    }

                    $fingerprint = sha1(mb_strtolower($text));
                    if (isset($seen[$fingerprint])) {
                        continue;
                    }

                    $seen[$fingerprint] = true;
                    $parts[] = $text;
                }
            }

            return implode("\n\n", $parts);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxml);
        }
    }

    private function sanitizeHtmlTextSegment(string $text): string
    {
        $clean = preg_replace('/\s+/u', ' ', $text) ?? '';

        return trim($clean);
    }

    private function isUsefulTextSegment(string $text): bool
    {
        if ($text === '' || mb_strlen($text) < 25) {
            return false;
        }

        // Skip obvious JS/CSS/runtime boilerplate often present in SPAs.
        if (preg_match('/\b(function\s*\(|window\.|document\.|matchMedia\(|classList\.|sourceMappingURL|oklch\(|prefers-color-scheme|addEventListener\(|=>)\b/iu', $text)) {
            return false;
        }

        // Skip CSS/theme/config fragments that often leak from raw HTML.
        if (preg_match('/(--[a-z0-9-]{2,}|\{[^\}]{3,}\}|@media|font-family\s*:|sourceURL|var\(--)/iu', $text)) {
            return false;
        }

        $letters = preg_match_all('/\p{L}/u', $text);
        $symbols = preg_match_all('/[\{\}\[\];:=]/u', $text);

        if ($letters === false || $symbols === false) {
            return true;
        }

        if ($letters === 0) {
            return false;
        }

        return ($symbols / max(1, $letters)) < 0.08;
    }

    private function normalizeText(string $text): string
    {
        $clean = preg_replace('/\s+/u', ' ', $text) ?? '';
        return trim($clean);
    }

    /**
     * @return array<int, string>
     */
    private function chunkText(string $text, int $chunkSize = 900, int $overlap = 140): array
    {
        $chunks = [];
        $length = mb_strlen($text);

        if ($length <= $chunkSize) {
            return [$text];
        }

        $start = 0;
        while ($start < $length) {
            $slice = mb_substr($text, $start, $chunkSize);
            $slice = trim($slice);

            if ($slice !== '') {
                $chunks[] = $slice;
            }

            if ($start + $chunkSize >= $length) {
                break;
            }

            $start += max(1, $chunkSize - $overlap);
        }

        return $chunks;
    }

    private function extractTextFromStoredFile(string $storagePath): string
    {
        if ($storagePath === '' || ! Storage::exists($storagePath)) {
            return '';
        }

        $fullPath = Storage::path($storagePath);
        $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));

        return match ($extension) {
            'txt', 'md' => (string) file_get_contents($fullPath),
            'csv' => $this->extractCsvText($fullPath),
            'xlsx', 'xls' => $this->extractSpreadsheetText($fullPath),
            'pdf' => $this->extractPdfText($fullPath),
            'docx' => $this->extractDocxText($fullPath),
            default => '',
        };
    }

    private function extractPdfText(string $fullPath): string
    {
        $parser = new Parser();
        $pdf = $parser->parseFile($fullPath);

        return $pdf->getText();
    }

    private function extractCsvText(string $fullPath): string
    {
        $lines = file($fullPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (! is_array($lines)) {
            return '';
        }

        return implode("\n", $lines);
    }

    private function extractSpreadsheetText(string $fullPath): string
    {
        $spreadsheet = IOFactory::load($fullPath);
        $parts = [];

        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            $rows = $sheet->toArray(null, true, true, true);
            foreach ($rows as $row) {
                $line = implode(' | ', array_filter(array_map(static fn ($cell): string => trim((string) $cell), $row)));
                if ($line !== '') {
                    $parts[] = $line;
                }
            }
        }

        return implode("\n", $parts);
    }

    private function extractDocxText(string $fullPath): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($fullPath) !== true) {
            return '';
        }

        $xml = $zip->getFromName('word/document.xml') ?: '';
        $zip->close();

        if ($xml === '') {
            return '';
        }

        return strip_tags($xml);
    }
}
