<?php

namespace App\Services\Site\Editor\Shop;

use App\Models\Site;
use App\Services\Site\Editor\EditorState;
use App\Services\Site\Editor\OperationFailed;
use App\Services\Site\Editor\OperationResult;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;

final class ShopProductCursor
{
    public static function encode(int $siteId, int $offset): string
    {
        return Crypt::encryptString(json_encode(
            ['site_id' => $siteId, 'offset' => $offset],
            JSON_THROW_ON_ERROR,
        ));
    }

    public static function decode(Site $site, string $cursor, EditorState $state): int
    {
        try {
            $payload = json_decode(Crypt::decryptString($cursor), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw new OperationFailed(OperationResult::fail(
                'validation',
                'Cursor is invalid.',
                $state,
                ['fields' => ['cursor' => ['must be a server-signed cursor']]],
            ));
        }

        if (! is_array($payload) || ! isset($payload['site_id'], $payload['offset'])) {
            throw new OperationFailed(OperationResult::fail(
                'validation',
                'Cursor is invalid.',
                $state,
                ['fields' => ['cursor' => ['must be a server-signed cursor']]],
            ));
        }

        if ((int) $payload['site_id'] !== $site->id) {
            throw new OperationFailed(OperationResult::fail(
                'not_found',
                'Not found.',
                $state,
            ));
        }

        return max(0, (int) $payload['offset']);
    }
}
