<?php

namespace App\Support\Textures;

final readonly class ResolvedTexture
{
    public function __construct(
        public string $key,
        public float $opacity,
        public int $size,
        public int $height,
        public ?string $svgUri,
        public string $mode = 'svg',
        public string $imageMode = 'tile',
        public ?string $imageUrl = null,
        public bool $overridesRoot = false,
    ) {}

    public function isNone(): bool
    {
        return $this->key === 'none' || $this->mode === 'none' || $this->cssImage() === null;
    }

    public function cssImage(): ?string
    {
        if ($this->key === 'none' || $this->mode === 'none') {
            return null;
        }

        if ($this->mode === 'image' && is_string($this->imageUrl) && $this->imageUrl !== '') {
            return 'url('.json_encode($this->imageUrl, JSON_UNESCAPED_SLASHES).')';
        }

        if (is_string($this->svgUri) && $this->svgUri !== '') {
            return 'url("'.$this->svgUri.'")';
        }

        return null;
    }

    public function sizeCss(): string
    {
        if ($this->mode === 'image' && $this->imageMode === 'cover') {
            return 'cover';
        }

        if ($this->mode === 'image') {
            return 'auto';
        }

        if ($this->height > 0 && $this->height !== $this->size) {
            return $this->size.'px '.$this->height.'px';
        }

        return $this->size.'px';
    }

    public function repeatCss(): string
    {
        if ($this->mode === 'image' && $this->imageMode === 'cover') {
            return 'no-repeat';
        }

        return 'repeat';
    }
}
