<?php

namespace App\Services\Shop;

use App\Enums\Shop\ProductStatus;
use App\Models\Shop\Category;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

final class CategoryContentRenderer
{
    public function render(Category $category): ?string
    {
        if ($category->description_long === null || $category->description_long === '') {
            return null;
        }

        $products = $category->products()
            ->where('status', ProductStatus::Published)
            ->orderByDesc('name')
            ->get(['shop_products.id', 'shop_products.name', 'shop_products.slug']);
        if ($products->isEmpty()) {
            return $category->description_long;
        }

        libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8"><div id="category-copy-root">'.$category->description_long.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $root = $document->getElementById('category-copy-root');
        if (! $root instanceof DOMElement) {
            return $category->description_long;
        }

        foreach ($this->textNodes($root) as $node) {
            if ($node->parentNode instanceof DOMElement && strtolower($node->parentNode->tagName) === 'a') {
                continue;
            }
            $this->linkProducts($document, $node, $products->map(fn ($product): array => [
                'name' => $product->name,
                'slug' => $product->slug,
            ])->all());
        }

        $output = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $output .= $document->saveHTML($child);
        }

        return trim($output) ?: null;
    }

    /**
     * @return list<DOMText>
     */
    private function textNodes(DOMNode $node): array
    {
        $nodes = [];
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMText) {
                $nodes[] = $child;
            } else {
                $nodes = [...$nodes, ...$this->textNodes($child)];
            }
        }

        return $nodes;
    }

    /**
     * @param  list<array{name: string, slug: string}>  $products
     */
    private function linkProducts(DOMDocument $document, DOMText $node, array $products): void
    {
        $text = $node->nodeValue ?? '';
        $matched = false;
        $fragment = $document->createDocumentFragment();

        while ($text !== '') {
            $match = null;
            foreach ($products as $product) {
                $position = mb_stripos($text, $product['name']);
                if ($position === false || ($match !== null && $position >= $match['position'])) {
                    continue;
                }
                $match = ['position' => $position, 'product' => $product];
            }
            if ($match === null) {
                $fragment->appendChild($document->createTextNode($text));
                break;
            }

            $before = mb_substr($text, 0, $match['position']);
            if ($before !== '') {
                $fragment->appendChild($document->createTextNode($before));
            }
            $link = $document->createElement('a');
            $link->setAttribute('href', \App\Support\Shop\ShopUrls::product($match['product']['slug']));
            $link->appendChild($document->createTextNode($match['product']['name']));
            $fragment->appendChild($link);
            $text = mb_substr($text, $match['position'] + mb_strlen($match['product']['name']));
            $matched = true;
        }

        if ($matched) {
            $node->parentNode?->replaceChild($fragment, $node);
        }
    }
}
