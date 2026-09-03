<?php

namespace App\Services\Shop;

use App\Models\Site;
use App\Services\Site\PublicPageCache;
use App\Support\Shop\ShopIndexBlocks;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ShopIndexBlockSettings
{
    public function __construct(private readonly PublicPageCache $publicCache) {}

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    public function save(Site $site, array $blocks, ?string $expectedRevision = null): array
    {
        $parsed = ShopIndexBlocks::parse($blocks);
        DB::transaction(function () use ($site, $parsed, $expectedRevision): void {
            $locked = Site::query()->whereKey($site->id)->lockForUpdate()->firstOrFail();
            if ($expectedRevision !== null && ! hash_equals(self::revision($locked), $expectedRevision)) {
                throw new InvalidArgumentException('Shop index blocks were changed elsewhere — reload and try again.');
            }
            $locked->update(['shop_index_blocks' => $parsed]);
        });
        $this->publicCache->invalidate($site);

        return $parsed;
    }

    public static function revision(Site $site): string
    {
        return hash('sha256', json_encode($site->shop_index_blocks ?? [], JSON_THROW_ON_ERROR));
    }
}
