<?php

namespace App\Support\Shop;

use App\Models\Site;
use Illuminate\Support\Str;

final class ShopCopy
{
    private const DEFAULT_SINGULAR = 'item';

    public static function noun(?Site $site = null, int $count = 2): string
    {
        $singular = trim((string) ($site?->shop_noun ?? ''));
        if ($singular === '') {
            $singular = self::DEFAULT_SINGULAR;
        }

        return $count === 1 ? $singular : Str::plural($singular);
    }

    public static function counted(int $count, ?Site $site = null): string
    {
        return $count.' '.self::noun($site, $count);
    }

    public static function heading(?Site $site = null): string
    {
        return Str::ucfirst(self::noun($site, 2));
    }

    /**
     * @return array{singular: string, plural: string}
     */
    public static function pair(?Site $site = null): array
    {
        return [
            'singular' => self::noun($site, 1),
            'plural' => self::noun($site, 2),
        ];
    }
}
