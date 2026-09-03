<?php

namespace App\Support\Shop;

use App\Models\Shop\ProductReview;
use App\Models\Site;
use App\Support\Shop\ProductFacts;
use App\Support\Shop\ProductReviewSettings;

final class ShopJsonLd
{
    public const ENCODE_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

    /**
     * @param  list<array{label: string, href?: string|null}>  $trail
     * @return array<string, mixed>
     */
    public static function breadcrumbList(array $trail, string $canonical): array
    {
        $elements = [];
        foreach (array_values($trail) as $index => $crumb) {
            $href = $crumb['href'] ?? null;
            if (! is_string($href) || $href === '') {
                $href = $canonical;
            }

            $elements[] = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $crumb['label'],
                'item' => self::absolute($href),
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $elements,
        ];
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>
     */
    public static function product(array $product, Site $site, string $canonical, bool $includeOffers): array
    {
        $detail = is_array($product['product_detail'] ?? null) ? $product['product_detail'] : [];
        $payload = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => (string) ($detail['name'] ?? $product['slug'] ?? ''),
            'description' => self::plainText((string) ($detail['description'] ?? '')),
            'image' => self::imageUrls($product),
            'brand' => [
                '@type' => 'Brand',
                'name' => (string) ($site->business_name ?? ''),
            ],
        ];

        $sku = self::sku($product);
        if ($sku !== null) {
            $payload['sku'] = $sku;
        }

        if ($includeOffers) {
            $payload['offers'] = self::offers($product, $site, $canonical);
        }

        $groups = ProductFacts::groups($site->product_fact_groups);
        $facts = is_array($product['product_detail']['facts'] ?? null) ? $product['product_detail']['facts'] : [];
        if ($groups !== [] && $facts !== []) {
            $payload = ProductFacts::applyJsonLd($payload, $groups, $facts);
        }

        $settings = ProductReviewSettings::fromSite($site);
        $count = (int) data_get($product, 'rating.count', 0);
        if ($settings->enabled && $count >= 1) {
            $payload['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => number_format((float) data_get($product, 'rating.avg', 0), 1, '.', ''),
                'reviewCount' => $count,
                'bestRating' => 5,
                'worstRating' => 1,
            ];
            $reviews = ProductReview::query()
                ->where('site_id', $site->id)
                ->where('product_id', (int) ($product['id'] ?? 0))
                ->published()
                ->latest()
                ->limit(5)
                ->get();
            if ($reviews->isNotEmpty()) {
                $payload['review'] = $reviews->map(fn (ProductReview $review): array => [
                    '@type' => 'Review',
                    'name' => $review->title,
                    'reviewBody' => $review->body,
                    'author' => [
                        '@type' => 'Person',
                        'name' => $review->author_name,
                    ],
                    'reviewRating' => [
                        '@type' => 'Rating',
                        'ratingValue' => $review->rating,
                        'bestRating' => 5,
                        'worstRating' => 1,
                    ],
                ])->all();
            }
        }

        return $payload;
    }

    /**
     * Strip markup and collapse whitespace to plain text, then clamp to
     * schema.org's practical description length so a large HTML blob never
     * bloats the JSON-LD payload.
     */
    public static function plainText(string $raw, int $limit = 500): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($raw)) ?? '');

        return mb_substr($text, 0, $limit);
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>
     */
    private static function offers(array $product, Site $site, string $canonical): array
    {
        $currency = strtoupper((string) ($site->shop_currency ?? 'GBP'));
        $availability = self::availability($product);

        $variants = array_values(array_filter(
            is_array($product['variants'] ?? null) ? $product['variants'] : [],
            fn ($v) => is_array($v),
        ));

        if (count($variants) > 1) {
            $prices = array_map(fn (array $v) => ((int) ($v['price_cents'] ?? 0)) / 100, $variants);

            return [
                '@type' => 'AggregateOffer',
                'priceCurrency' => $currency,
                'lowPrice' => number_format(min($prices), 2, '.', ''),
                'highPrice' => number_format(max($prices), 2, '.', ''),
                'availability' => $availability,
                'url' => $canonical,
            ];
        }

        return [
            '@type' => 'Offer',
            'price' => number_format(((int) ($product['price_cents'] ?? 0)) / 100, 2, '.', ''),
            'priceCurrency' => $currency,
            'availability' => $availability,
            'url' => $canonical,
        ];
    }

    /**
     * price_from marks a guide price for a made-to-order item: never claim
     * it is in stock at that exact price, but do not invent a definitive
     * one either — PreOrder communicates "orderable, price is a guide"
     * without asserting stock we cannot see.
     *
     * @param  array<string, mixed>  $product
     */
    private static function availability(array $product): string
    {
        if ((bool) ($product['price_from'] ?? false)) {
            return 'https://schema.org/PreOrder';
        }

        return ((bool) ($product['in_stock_any'] ?? false))
            ? 'https://schema.org/InStock'
            : 'https://schema.org/OutOfStock';
    }

    public static function encode(array $payload): string
    {
        return json_encode($payload, self::ENCODE_FLAGS);
    }

    /**
     * @param  list<array{q: string, a: string}>  $faqs
     * @return array<string, mixed>
     */
    public static function faqPage(array $faqs): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array_map(fn (array $faq): array => [
                '@type' => 'Question',
                'name' => $faq['q'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $faq['a'],
                ],
            ], $faqs),
        ];
    }

    public static function absolute(string $href): string
    {
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }

        return url($href === '' ? '/' : $href);
    }

    /**
     * @param  array<string, mixed>  $product
     * @return list<string>
     */
    private static function imageUrls(array $product): array
    {
        $urls = $product['image_urls'] ?? null;
        if (! is_array($urls)) {
            return [];
        }

        $ordered = [];
        foreach (['full', 'card', 'thumb'] as $size) {
            $src = $urls[$size] ?? null;
            if (! is_string($src) || $src === '') {
                continue;
            }
            $ordered[] = self::absolute($src);
        }

        return array_values(array_unique($ordered));
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private static function sku(array $product): ?string
    {
        foreach ($product['variants'] ?? [] as $variant) {
            if (! is_array($variant)) {
                continue;
            }
            $sku = $variant['sku'] ?? null;
            if (is_string($sku) && $sku !== '') {
                return $sku;
            }
        }

        return null;
    }
}
