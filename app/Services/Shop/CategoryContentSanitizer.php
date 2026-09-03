<?php

namespace App\Services\Shop;

use App\Models\Site;
use DOMDocument;
use DOMElement;
use DOMNode;

final class CategoryContentSanitizer
{
    /** @var array<string, list<string>> */
    private const ALLOWED = [
        'p' => [], 'h2' => [], 'h3' => [], 'ul' => [], 'ol' => [], 'li' => [],
        'strong' => [], 'em' => [], 'a' => ['href'],
    ];

    /** @var list<string> */
    private const DROP_TAGS = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'link', 'meta'];

    public function clean(?string $html, Site $site): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8"><div id="category-content-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $root = $document->getElementById('category-content-root');
        if (! $root instanceof DOMElement) {
            return null;
        }

        $this->walk($root, $site);

        $output = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $output .= $document->saveHTML($child);
        }

        return trim($output) ?: null;
    }

    private function walk(DOMNode $node, Site $site): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $this->walk($child, $site);
            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROP_TAGS, true)) {
                $child->parentNode?->removeChild($child);

                continue;
            }
            if (! array_key_exists($tag, self::ALLOWED)) {
                $this->unwrap($child);

                continue;
            }

            foreach (iterator_to_array($child->attributes) as $attribute) {
                if (! in_array(strtolower($attribute->name), self::ALLOWED[$tag], true)) {
                    $child->removeAttribute($attribute->name);
                }
            }
            if ($tag === 'a' && (! $child->hasAttribute('href') || ! $this->safeHref($child->getAttribute('href'), $site))) {
                $this->unwrap($child);
            }
        }
    }

    private function safeHref(string $href, Site $site): bool
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, '//')) {
            return false;
        }
        if (str_starts_with($href, '/')) {
            return true;
        }

        $parts = parse_url($href);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $siteHost = strtolower((string) $site->publicHost());

        return $host !== '' && $host === $siteHost;
    }

    private function unwrap(DOMElement $element): void
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
