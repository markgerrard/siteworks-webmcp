<?php

namespace App\Enums\Shop;

enum ShopSnapshotStatus: string
{
    case Building = 'building';
    case Success = 'success';
    case Failed = 'failed';
}
