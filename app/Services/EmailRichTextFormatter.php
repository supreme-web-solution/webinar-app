<?php

namespace App\Services;

class EmailRichTextFormatter
{
    private const ALLOWED_TAGS = '<p><br><strong><b><em><i><u><ul><ol><li><a><h1><h2><h3><blockquote>';

    private const PARAGRAPH_STYLE = 'margin:0 0 14px 0;color:#374151;font-size:15px;line-height:1.6;';

    private const SPACER_STYLE = 'margin:0 0 14px 0;font-size:1px;line-height:14px;color:transparent;';

    /**
     * Sanitize editor HTML before storing in the database.
     */
    public function sanitizeForStorage(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $sanitized = strip_tags($html, self::ALLOWED_TAGS);
        $sanitized = $this->sanitizeAnchorTags($sanitized);
        $sanitized = preg_replace('/<(p|br|strong|em|b|i|u|ul|ol|li|h1|h2|h3|blockquote)\b[^>]*>/i', '<$1>', $sanitized) ?? '';
        $sanitized = $this->normalizeQuillHtml($sanitized);

        return trim($sanitized);
    }

    /**
     * Convert stored rich text into email-client-safe HTML with inline styles.
     */
    public function formatForEmail(string $html): string
    {
        $html = trim(str_replace(["\r\n", "\r"], "\n", $html));
        if ($html === '') {
            return '';
        }

        if (! str_contains($html, '<')) {
            return $this->formatPlainTextForEmail($html);
        }

        $clean = strip_tags($html, self::ALLOWED_TAGS);
        $clean = $this->sanitizeAnchorTags($clean);
        $clean = $this->normalizeQuillHtml($clean);

        if ($clean === '') {
            return '';
        }

        return $this->applyInlineStyles($clean);
    }

    public function formatPlainTextForEmail(string $text): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($text === '') {
            return '';
        }

        $lines = explode("\n", $text);
        $html = '';
        $buffer = [];

        $flushBuffer = function () use (&$buffer, &$html): void {
            if ($buffer === []) {
                return;
            }

            $paragraph = implode("\n", $buffer);
            $html .= '<p style="'.self::PARAGRAPH_STYLE.'">'
                .nl2br(e($paragraph))
                .'</p>';
            $buffer = [];
        };

        foreach ($lines as $line) {
            if (trim($line) === '') {
                $flushBuffer();
                $html .= '<p style="'.self::SPACER_STYLE.'">&nbsp;</p>';

                continue;
            }

            $buffer[] = $line;
        }

        $flushBuffer();

        return $html;
    }

    /**
     * Normalize Quill spacing artifacts while preserving intentional blank lines.
     */
    public function normalizeQuillHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $html = preg_replace('/<p>\s*(?:&nbsp;|&#160;|&#xA0;)?\s*(?:<br\s*\/?>)?\s*<\/p>/i', '<p><br></p>', $html) ?? $html;
        $html = preg_replace('/<p>\s*<\/p>/i', '<p><br></p>', $html) ?? $html;
        $html = preg_replace('/(?:<br\s*\/?>\s*){3,}/i', '<br><br>', $html) ?? $html;

        return trim($html);
    }

    private function sanitizeAnchorTags(string $html): string
    {
        return preg_replace_callback('/<a\b[^>]*>/i', static function (array $matches): string {
            $tag = $matches[0] ?? '';
            if (! preg_match('/href\s*=\s*["\']([^"\']+)["\']/i', $tag, $hrefMatch)) {
                return '<a>';
            }

            $href = trim((string) ($hrefMatch[1] ?? ''));
            if (! preg_match('/^https?:\/\//i', $href)) {
                return '<a>';
            }

            return '<a href="'.e($href).'" target="_blank" rel="noopener noreferrer">';
        }, $html) ?? $html;
    }

    private function applyInlineStyles(string $html): string
    {
        $wrapped = '<?xml encoding="UTF-8"><div id="__email_root">'.$html.'</div>';

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $loaded = $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        if (! $loaded) {
            return $this->formatPlainTextForEmail(strip_tags($html));
        }

        $root = $dom->getElementById('__email_root');
        if (! $root) {
            return $this->formatPlainTextForEmail(strip_tags($html));
        }

        foreach (iterator_to_array($root->getElementsByTagName('*')) as $element) {
            if ($element instanceof \DOMElement) {
                $this->styleElement($element);
            }
        }

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return trim($out);
    }

    private function styleElement(\DOMElement $element): void
    {
        $tag = strtolower($element->tagName);

        $attrs = iterator_to_array($element->attributes);
        foreach ($attrs as $attr) {
            $name = strtolower($attr->nodeName);
            if (str_starts_with($name, 'on')) {
                $element->removeAttribute($attr->nodeName);

                continue;
            }

            if ($tag === 'a' && $name === 'href') {
                $href = trim($attr->nodeValue);
                if ($href === '' || ! preg_match('#\Ahttps?://#i', $href)) {
                    $element->removeAttribute('href');
                }

                continue;
            }

            $element->removeAttribute($attr->nodeName);
        }

        if ($tag === 'p' && $this->paragraphIsBlank($element)) {
            $element->setAttribute('style', self::SPACER_STYLE);
            while ($element->firstChild !== null) {
                $element->removeChild($element->firstChild);
            }
            $element->appendChild($element->ownerDocument->createTextNode("\xc2\xa0"));

            return;
        }

        $style = match ($tag) {
            'p' => self::PARAGRAPH_STYLE,
            'h1' => 'margin:0 0 14px 0;color:#111827;font-size:22px;line-height:1.3;font-weight:700;',
            'h2' => 'margin:0 0 12px 0;color:#111827;font-size:20px;line-height:1.35;font-weight:700;',
            'h3' => 'margin:0 0 10px 0;color:#111827;font-size:18px;line-height:1.4;font-weight:700;',
            'ul' => 'margin:0 0 14px 20px;padding:0;color:#374151;font-size:15px;line-height:1.6;',
            'ol' => 'margin:0 0 14px 20px;padding:0;color:#374151;font-size:15px;line-height:1.6;',
            'li' => 'margin:0 0 6px 0;',
            'a' => 'color:#2563eb;text-decoration:underline;word-break:break-word;',
            'strong', 'b' => 'font-weight:700;',
            'em', 'i' => 'font-style:italic;',
            'u' => 'text-decoration:underline;',
            'blockquote' => 'margin:0 0 14px 0;padding:0 0 0 12px;border-left:3px solid #d1d5db;color:#4b5563;font-size:15px;line-height:1.6;',
            default => null,
        };

        if ($style !== null) {
            $element->setAttribute('style', $style);
        }
    }

    private function paragraphIsBlank(\DOMElement $element): bool
    {
        $text = html_entity_decode($element->textContent ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\xc2\xa0", "\u{00A0}"], '', $text);

        return trim($text) === '';
    }
}
