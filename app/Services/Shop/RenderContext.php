<?php

namespace App\Services\Shop;

use Illuminate\Http\Request;

class RenderContext
{
    public function __construct(public readonly bool $includeDrafts) {}

    public static function fromRequest(Request $request, bool $isPreviewHost): self
    {
        if ($isPreviewHost) {
            return new self(includeDrafts: true);
        }

        if ($request->user()?->isAdmin()) {
            return new self(includeDrafts: true);
        }

        if ($request->query('preview') && $request->hasValidSignature()) {
            return new self(includeDrafts: true);
        }

        return new self(includeDrafts: false);
    }

    /**
     * Remove draft products and trim references to them from categories + featured.
     */
    public function filterSnapshot(array $json): array
    {
        if ($this->includeDrafts) {
            return $json;
        }

        $visibleSlugs = [];
        $products = [];
        foreach ($json['products'] ?? [] as $slug => $p) {
            if (($p['status'] ?? 'published') === 'published') {
                $products[$slug] = $p;
                $visibleSlugs[$slug] = true;
            }
        }
        $json['products'] = $products;

        foreach ($json['categories'] ?? [] as $catSlug => $cat) {
            $json['categories'][$catSlug]['product_slugs'] = array_values(array_filter(
                $cat['product_slugs'] ?? [],
                fn ($s) => isset($visibleSlugs[$s])
            ));
        }

        $json['featured_slugs'] = array_values(array_filter(
            $json['featured_slugs'] ?? [],
            fn ($s) => isset($visibleSlugs[$s])
        ));

        return SnapshotFacets::recount($json);
    }
}
