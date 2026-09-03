<?php

namespace App\Services\Site\Editor;

final class MintRefused
{
    public function __construct(public readonly string $reason) {}
}
