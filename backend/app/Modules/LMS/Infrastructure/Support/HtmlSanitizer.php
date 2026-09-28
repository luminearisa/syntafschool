<?php

namespace App\Modules\LMS\Infrastructure\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Pembersih HTML untuk konten PAGE / BOOK / pengumuman (§13, §40).
 *
 * Konten guru disimpan apa adanya tetapi SELALU dibersihkan sebelum dikirim ke
 * klien, sehingga tidak ada script/handler yang lolos ke halaman siswa. Daftar
 * putih tag dibuat cukup luas untuk kebutuhan penyusunan materi (heading, tabel,
 * gambar, embed video, callout, code block) tetapi menutup `<script>`, `<iframe>`
 * dari domain tak dikenal, dan seluruh atribut `on*`.
 */
class HtmlSanitizer
{
    /** @var list<string> */
    protected const ALLOWED_TAGS = [
        'p', 'br', 'hr', 'strong', 'b', 'em', 'i', 'u', 's', 'del', 'mark', 'small', 'sub', 'sup',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'figure', 'figcaption', 'div', 'span',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'colgroup', 'col',
        'a', 'img', 'iframe', 'video', 'audio', 'source', 'details', 'summary', 'aside', 'section',
    ];

    /** @var list<string> */
    protected const ALLOWED_ATTRIBUTES = [
        'href', 'title', 'target', 'rel',
        'src', 'alt', 'width', 'height', 'loading', 'allow', 'allowfullscreen', 'frameborder', 'controls',
        'colspan', 'rowspan', 'scope',
        'class', 'style', 'id', 'data-callout', 'data-align',
    ];

    /** Properti gaya yang boleh dipertahankan (termasuk warna callout). */
    protected const ALLOWED_STYLES = [
        'text-align', 'font-weight', 'font-style', 'text-decoration', 'color', 'background-color',
        'border', 'border-left', 'border-radius', 'padding', 'margin', 'width', 'max-width', 'height',
        'display', 'gap', 'justify-content', 'align-items', 'float',
    ];

    /** @var list<string> */
    protected const ALLOWED_EMBED_HOSTS = [
        'www.youtube.com', 'youtube.com', 'youtu.be', 'player.vimeo.com', 'vimeo.com',
        'www.google.com', 'docs.google.com', 'drive.google.com',
    ];

    public function clean(?string $html, int $maxLength = 200000): ?string
    {
        if ($html === null || trim($html) === '') {
            return $html;
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        // Pembungkus + encoding agar karakter Indonesia tidak rusak.
        $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="lms-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('lms-root');

        if ($root === null) {
            return '';
        }

        $this->sanitizeNode($root);

        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $document->saveHTML($child);
        }

        return mb_substr($output, 0, $maxLength);
    }

    /** Versi teks-bersih untuk ringkasan/pencarian. */
    public function toText(?string $html, int $limit = 200): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $html)) ?? '');

        return mb_substr($text, 0, $limit);
    }

    protected function sanitizeNode(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->nodeName);

            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                // Tag terlarang dibuang, isinya dipertahankan agar teks tidak hilang.
                $this->unwrap($child);

                continue;
            }

            $this->sanitizeAttributes($child, $tag);
            $this->sanitizeNode($child);
        }
    }

    protected function sanitizeAttributes(DOMElement $element, string $tag): void
    {
        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            $name = strtolower($attribute->nodeName);
            $value = (string) $attribute->nodeValue;

            $forbidden = str_starts_with($name, 'on')
                || str_contains($value, 'javascript:')
                || str_contains($value, 'data:text/html')
                || ! in_array($name, self::ALLOWED_ATTRIBUTES, true);

            if ($name === 'style') {
                $element->setAttribute('style', $this->cleanStyle($value));

                continue;
            }

            if ($forbidden) {
                $element->removeAttribute($attribute->nodeName);

                continue;
            }

            if (in_array($name, ['href', 'src'], true) && ! $this->isSafeUrl($value)) {
                $element->removeAttribute($name);
            }
        }

        if ($tag === 'a' && $element->getAttribute('target') === '_blank') {
            $element->setAttribute('rel', 'noopener noreferrer');
        }

        if ($tag === 'iframe') {
            $host = parse_url($element->getAttribute('src'), PHP_URL_HOST);

            if ($host === null || ! in_array(strtolower((string) $host), self::ALLOWED_EMBED_HOSTS, true)) {
                $this->unwrap($element);
            }
        }
    }

    protected function cleanStyle(string $style): string
    {
        $kept = [];

        foreach (explode(';', $style) as $declaration) {
            if (! str_contains($declaration, ':')) {
                continue;
            }

            [$property, $value] = array_map('trim', explode(':', $declaration, 2));

            if (! in_array(strtolower($property), self::ALLOWED_STYLES, true)) {
                continue;
            }

            if (str_contains($value, 'expression') || str_contains($value, 'url(')) {
                continue;
            }

            $kept[] = strtolower($property).': '.$value;
        }

        return implode('; ', $kept);
    }

    protected function isSafeUrl(string $url): bool
    {
        $url = trim($url);

        if ($url === '' || str_starts_with($url, '#')) {
            return true;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['', 'http', 'https', 'mailto', 'tel'], true);
    }

    protected function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;

        if ($parent === null) {
            return;
        }

        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }
}
