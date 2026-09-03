<?php

namespace App\Support\Site;

use App\Models\Site;

class SiteJsonLd
{
    private const ENCODE_FLAGS = JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT;

    /**
     * @param  array{average: float, count: int}  $summary
     * @return array<string, mixed>
     */
    public static function localBusiness(Site $site, string $url, string $description, array $summary): array
    {
        $payload = [
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            'name' => $site->business_name,
            'url' => $url,
            'aggregateRating' => [
                '@type' => 'AggregateRating',
                'ratingValue' => round((float) $summary['average'], 1),
                'reviewCount' => (int) $summary['count'],
                'bestRating' => 5,
                'worstRating' => 1,
            ],
        ];

        if ($description !== '') {
            $payload['description'] = $description;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function encode(array $payload): string
    {
        return json_encode($payload, self::ENCODE_FLAGS);
    }
}
