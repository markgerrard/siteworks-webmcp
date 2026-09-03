<?php

namespace App\Livewire\Concerns;

use App\Jobs\Shop\RebuildShopSnapshot;
use App\Models\Shop\Category;

trait ManagesCategoryHeroLayout
{
    public function setCategoryHeroHeight(int $catId, string $h): void
    {
        $this->abortUnlessShopEnabled();
        if (! in_array($h, ['small', 'medium', 'large'], true)) {
            return;
        }

        $category = Category::where('site_id', $this->siteId)->findOrFail($catId);
        $category->update(['hero_height' => $h]);
        RebuildShopSnapshot::dispatchSync($this->siteId);
        session()->flash('shop-hero-msg', "Hero height updated for \"{$category->name}\".");
        $this->afterCategoryHeroLayoutPersisted();
    }

    public function setCategoryBgPositionY(int $catId, int $y): void
    {
        $this->abortUnlessShopEnabled();
        $category = Category::where('site_id', $this->siteId)->findOrFail($catId);
        $category->update(['bg_position_y' => max(0, min(100, $y))]);
        RebuildShopSnapshot::dispatchSync($this->siteId);
        session()->flash('shop-hero-msg', "Hero crop updated for \"{$category->name}\".");
        $this->afterCategoryHeroLayoutPersisted();
    }

    public function setCategoryTextZone(int $catId, string $zone): void
    {
        $this->abortUnlessShopEnabled();
        if (! $this->isValidCategoryTextZone($zone)) {
            return;
        }

        $category = Category::where('site_id', $this->siteId)->findOrFail($catId);
        $category->update(['text_zone' => $zone]);
        RebuildShopSnapshot::dispatchSync($this->siteId);
        session()->flash('shop-hero-msg', "Text position updated for \"{$category->name}\".");
        $this->afterCategoryHeroLayoutPersisted();
    }

    public function resetCategoryTextZone(int $catId): void
    {
        $this->setCategoryTextZone($catId, 'middle-left');
    }

    public function setCategoryHeroEnabled(int $catId, bool $enabled): void
    {
        $this->abortUnlessShopEnabled();

        $category = Category::where('site_id', $this->siteId)->findOrFail($catId);
        $category->update(['hero_enabled' => $enabled]);
        RebuildShopSnapshot::dispatchSync($this->siteId);
        session()->flash('shop-hero-msg', $enabled
            ? "Hero shown for \"{$category->name}\"."
            : "Hero hidden for \"{$category->name}\".");
        $this->afterCategoryHeroLayoutPersisted();
    }

    public function setCategoryHeroMode(int $catId, string $mode): void
    {
        $this->abortUnlessShopEnabled();
        if (! in_array($mode, ['none', 'shared', 'custom'], true)) {
            return;
        }

        $category = Category::where('site_id', $this->siteId)->findOrFail($catId);
        $category->update([
            'hero_mode' => $mode,
            'hero_enabled' => $mode !== 'none',
        ]);
        RebuildShopSnapshot::dispatchSync($this->siteId);
        session()->flash('shop-hero-msg', "Hero mode updated for \"{$category->name}\".");
        $this->afterCategoryHeroLayoutPersisted();
    }

    public function setCategoryHeroTextStyle(int $catId, string $value): void
    {
        $this->abortUnlessShopEnabled();
        if (! in_array($value, ['plain', 'boxed'], true)) {
            return;
        }

        $category = Category::where('site_id', $this->siteId)->findOrFail($catId);
        $category->update(['hero_text_style' => $value]);
        RebuildShopSnapshot::dispatchSync($this->siteId);
        session()->flash('shop-hero-msg', "Hero text style updated for \"{$category->name}\".");
        $this->afterCategoryHeroLayoutPersisted();
    }

    public function resetCategoryHeroTextStyle(int $catId): void
    {
        $this->abortUnlessShopEnabled();

        $category = Category::where('site_id', $this->siteId)->findOrFail($catId);
        $category->update(['hero_text_style' => null]);
        RebuildShopSnapshot::dispatchSync($this->siteId);
        session()->flash('shop-hero-msg', "Hero text style updated for \"{$category->name}\".");
        $this->afterCategoryHeroLayoutPersisted();
    }

    public function setCategoryHeroAccentWord(int $catId, string $value): void
    {
        $this->abortUnlessShopEnabled();

        $category = Category::where('site_id', $this->siteId)->findOrFail($catId);
        $word = trim($value);
        $name = (string) $category->name;

        validator(
            ['accentWord' => $word],
            [
                'accentWord' => [
                    'required', 'string', 'max:30',
                    function (string $attribute, mixed $value, \Closure $fail) use ($name): void {
                        if (preg_match('/\s/u', (string) $value) === 1) {
                            $fail('Pick a single word.');

                            return;
                        }
                        if (mb_stripos($name, (string) $value) === false) {
                            $fail('That word is not in the category name.');
                        }
                    },
                ],
            ],
        )->validate();

        $category->update(['hero_accent_word' => $word]);
        RebuildShopSnapshot::dispatchSync($this->siteId);
        session()->flash('shop-hero-msg', "Accent word updated for \"{$category->name}\".");
        $this->afterCategoryHeroLayoutPersisted();
    }

    public function resetCategoryHeroAccentWord(int $catId): void
    {
        $this->abortUnlessShopEnabled();

        $category = Category::where('site_id', $this->siteId)->findOrFail($catId);
        $category->update(['hero_accent_word' => null]);
        RebuildShopSnapshot::dispatchSync($this->siteId);
        session()->flash('shop-hero-msg', "Accent word cleared for \"{$category->name}\".");
        $this->afterCategoryHeroLayoutPersisted();
    }

    public function setCategoryIntroBand(int $catId, bool $enabled): void
    {
        $this->abortUnlessShopEnabled();

        $category = Category::where('site_id', $this->siteId)->findOrFail($catId);
        $category->update(['intro_band' => $enabled]);
        RebuildShopSnapshot::dispatchSync($this->siteId);
        session()->flash('shop-hero-msg', $enabled
            ? "Intro band shown for \"{$category->name}\"."
            : "Intro band hidden for \"{$category->name}\".");
        $this->afterCategoryHeroLayoutPersisted();
    }

    public function setCategoryHeroWidth(int $catId, string $width): void
    {
        $this->abortUnlessShopEnabled();
        if (! in_array($width, ['boxed', 'full'], true)) {
            return;
        }

        $category = Category::where('site_id', $this->siteId)->findOrFail($catId);
        $category->update(['hero_width' => $width]);
        RebuildShopSnapshot::dispatchSync($this->siteId);
        session()->flash('shop-hero-msg', "Hero width updated for \"{$category->name}\".");
        $this->afterCategoryHeroLayoutPersisted();
    }

    private function isValidCategoryTextZone(string $zone): bool
    {
        return in_array($zone, [
            'top-left', 'top-center', 'top-right',
            'middle-left', 'middle-center', 'middle-right',
            'bottom-left', 'bottom-center', 'bottom-right',
        ], true);
    }

    protected function afterCategoryHeroLayoutPersisted(): void
    {
        if (method_exists($this, 'refresh')) {
            $this->refresh();
        }
    }
}
