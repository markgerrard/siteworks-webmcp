<?php

namespace App\Services\Site\Editor\Shop;

use App\Enums\Shop\ProductStatus;
use App\Models\Shop\Category;
use App\Models\Shop\Product;
use App\Models\Shop\ShopDraft;
use App\Models\Site;
use Illuminate\Support\Str;

final class SkillCurrentState
{
    /**
     * Caps on merchant-controlled data embedded in the returned protocol text.
     * The prefix is injected into a visiting agent's context, so everything a
     * merchant can grow or free-type is clamped (review).
     */
    private const MAX_SLUGS = 20;

    private const MAX_NAME = 80;

    public function prefix(Site $site): string
    {
        $name = is_string($site->business_name) && $site->business_name !== ''
            ? $site->business_name
            : (string) $site->slug;
        // Single line, bounded length: the site name is merchant-authored text
        // travelling into an agent's context — data, never instructions.
        // \p{C} kills control chars, \p{Zl}/\p{Zp} the Unicode line/paragraph
        // separators the plain \s class misses; quotes can't break the
        // "…"-delimited boundary.
        $name = (string) preg_replace('/[\p{C}\p{Zl}\p{Zp}]+/u', ' ', $name);
        $name = (string) preg_replace('/\s+/u', ' ', $name);
        $name = str_replace('"', "'", $name);
        $name = Str::limit(trim($name), self::MAX_NAME, '…');
        if ($name === '') {
            $name = trim((string) ($site->slug ?: $site->preview_domain)) ?: 'this site';
        }
        $currency = strtoupper((string) ($site->shop_currency ?: 'GBP'));
        $slugs = Category::query()
            ->where('site_id', $site->id)
            ->orderBy('slug')
            ->pluck('slug')
            ->map(fn (mixed $slug): string => (string) $slug)
            ->values()
            ->all();
        $productCount = Product::query()->where('site_id', $site->id)->count();
        $draftCount = Product::query()
            ->where('site_id', $site->id)
            ->where('status', ProductStatus::Draft)
            ->count();
        // Metadata-only: a boolean must never cost object-storage I/O.
        $hasLogo = $site->logoConcepts()
            ->whereNotNull('path')
            ->where('path', '!=', '')
            ->exists();
        $revision = (int) (ShopDraft::query()->where('site_id', $site->id)->value('catalogue_revision') ?? 0);

        $shownSlugs = array_slice($slugs, 0, self::MAX_SLUGS);
        $slugList = $slugs === [] ? '(none)' : implode(', ', $shownSlugs);
        if (count($slugs) > self::MAX_SLUGS) {
            $slugList .= sprintf(' +%d more', count($slugs) - self::MAX_SLUGS);
        }

        return sprintf(
            'Site: "%s". Currency: %s. Categories (%d): %s. Products: %d (%d drafts). has_logo: %s. Current revision: %d.',
            $name,
            $currency,
            count($slugs),
            $slugList,
            $productCount,
            $draftCount,
            $hasLogo ? 'true' : 'false',
            $revision,
        );
    }
}
