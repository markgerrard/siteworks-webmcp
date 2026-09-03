<?php

namespace App\Services\Site\SiteClone;

class SiteCloneOptions
{
    public function __construct(
        public string $sourceConnection,
        public string $sourcePrefix,
        public string $destPrefix,
        public bool $skipSpaces,
        public ?string $previewDomain = null,
        public bool $preservePreviewDomain = false,
        public string $sourceLabel = 'staging',
        public bool $legacyDevOutput = false,
        public ?int $destClientId = null,
    ) {}
}
