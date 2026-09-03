<?php

namespace App\Services\Site\Editor\Shop;

final class ShopCatalogueChanges
{
    public const VALUE_LIMIT_BYTES = 512;

    /**
     * @return array{scope: 'product', product_slug: string, path: string, before: mixed, after: mixed, kind: string, truncated?: true}
     */
    public static function change(string $productSlug, string $path, mixed $before, mixed $after, string $kind): array
    {
        $beforePresented = self::present($before);
        $afterPresented = self::present($after);
        $row = [
            'scope' => 'product',
            'product_slug' => $productSlug,
            'path' => $path,
            'before' => $beforePresented['value'],
            'after' => $afterPresented['value'],
            'kind' => $kind,
        ];

        if ($beforePresented['truncated'] || $afterPresented['truncated']) {
            $row['truncated'] = true;
        }

        return $row;
    }

    /**
     * @return array{value: mixed, truncated: bool}
     */
    private static function present(mixed $value): array
    {
        if (is_string($value) && strlen($value) > self::VALUE_LIMIT_BYTES) {
            return [
                'value' => mb_strcut($value, 0, self::VALUE_LIMIT_BYTES, 'UTF-8'),
                'truncated' => true,
            ];
        }

        return ['value' => $value, 'truncated' => false];
    }
}
