<?php

use App\Exceptions\UnsupportedImageException;
use App\Livewire\Concerns\AuthorizesSiteAccess;
use App\Models\LayoutPreset;
use App\Models\SiteMedia;
use App\Services\Images\ImageOptimiserService;
use App\Services\Media\MediaAssignService;
use App\Services\Site\PageLayoutRegistry;
use App\Services\Site\PublicPageCache;
use App\Support\ChromeKnobs;
use App\Support\NavCta;
use App\Support\Site\SitePublicObject;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use AuthorizesSiteAccess;
    use WithFileUploads;

    /**
     * @var array<string, list<string>>
     */
    private const KNOBS = [
        'header_mode' => ['solid', 'overlay'],
        'overlay_glass' => ['off', 'scrolled', 'floating', 'always'],
        'right_action' => ['phone', 'cta', 'phone_cta', 'none'],
        'nav_cta_target' => ['url', 'form'],
        'form_style' => ['boxed', 'underline'],
        'accent_style' => ['default', 'italic'],
        'hero_copy_style' => ChromeKnobs::HERO_COPY_STYLES,
        'nav_case' => ['normal', 'upper', 'lower'],
        'nav_container_style' => ChromeKnobs::NAV_CONTAINER_STYLES,
        'nav_container_fill' => ChromeKnobs::NAV_CONTAINER_FILLS,
        'header_shrink' => ['on', 'off'],
        'header_fit' => ['comfortable', 'tight'],
        'overlay_inner_scale' => ['overlay', 'main'],
        'shop_nav_style' => ['link', 'dropdown', 'mega'],
    ];

    #[Locked]
    public int $siteId;

    #[Locked]
    public string $headerBg = '#ffffff';

    #[Locked]
    public string $headerMode = 'solid';

    #[Locked]
    public string $overlayGlass = 'off';

    public string $navCase = 'normal';

    public string $navContainerStyle = 'none';

    public string $navContainerFill = 'surface';

    public string $headerShrink = 'on';

    public string $headerFit = 'comfortable';

    public string $overlayInnerScale = 'overlay';

    public string $shopNavStyle = 'link';

    #[Locked]
    public bool $hasOverlayLogoSize = false;

    public ?int $headerPadding = null;

    #[Locked]
    public string $rightAction = 'phone';

    #[Locked]
    public string $navCtaTarget = 'url';

    #[Locked]
    public string $formStyle = 'boxed';

    #[Locked]
    public string $accentStyle = 'default';

    #[Locked]
    public string $heroCopyStyle = 'preset';

    public string $navCtaLabel = '';

    public string $navCtaUrl = '';

    #[Locked]
    public string $footerMotto = '';

    #[Locked]
    public bool $footerShowLogo = false;

    public string $chromeLayout = 'classic';

    /**
     * @var array<string, array{label: string, description: string|null}>
     */
    #[Locked]
    public array $chromeOptions = [];

    #[Locked]
    public bool $chromeIsCentred = false;

    #[Locked]
    public bool $chromeUsesImagePattern = false;

    #[Locked]
    public ?string $brandImageUrl = null;

    #[Locked]
    public ?int $brandImageMediaId = null;

    #[Locked]
    public int $brandImageOpacity = 12;

    #[Locked]
    public string $brandImageFit = 'cover';

    public $brandImage = null;

    #[Locked]
    public ?string $navRowBg = null;

    #[Locked]
    public string $navRowPattern = 'none';

    #[Locked]
    public ?string $navRowImageUrl = null;

    #[Locked]
    public ?int $navRowImageMediaId = null;

    #[Locked]
    public int $navRowImageOpacity = 12;

    #[Locked]
    public string $navRowImageFit = 'cover';

    #[Locked]
    public int $navRowImagePositionY = 50;

    #[Locked]
    public string $navRowAccentBorder = 'off';

    #[Locked]
    public bool $announcementEnabled = false;

    /**
     * @var list<array{text: string, url?: string}>
     */
    #[Locked]
    public array $announcementMessages = [];

    #[Locked]
    public ?string $announcementBg = null;

    public string $announcementDraftText = '';

    public string $announcementDraftUrl = '';

    public function mount(int $siteId): void
    {
        $this->siteId = $siteId;
        $site = $this->assertAuthorizedSiteAccess();
        $this->headerBg = $site->header_bg ?? '#ffffff';
        $this->navRowBg = ChromeKnobs::navRowBg($site);
        $this->syncChromeSurface($site);
        $this->headerMode = ChromeKnobs::headerMode($site);
        $this->overlayGlass = ChromeKnobs::overlayGlass($site);
        $this->rightAction = ChromeKnobs::rightAction($site);
        $this->navCtaTarget = ChromeKnobs::navCtaTarget($site);
        $this->formStyle = ChromeKnobs::formStyle($site);
        $this->accentStyle = ChromeKnobs::accentStyle($site);
        $this->heroCopyStyle = ChromeKnobs::heroCopyStyle($site);
        $this->navCase = ChromeKnobs::navCase($site);
        $this->navContainerStyle = $site->nav_container_style ?? '';
        $this->navContainerFill = $site->nav_container_fill ?? '';
        $this->headerShrink = ChromeKnobs::headerShrink($site);
        $this->headerFit = ChromeKnobs::headerFit($site);
        $this->overlayInnerScale = ChromeKnobs::overlayInnerScale($site);
        $this->shopNavStyle = ChromeKnobs::shopNavStyle($site);
        $this->hasOverlayLogoSize = $site->overlay_logo_size !== null;
        $this->headerPadding = $site->header_padding;
        $this->navCtaLabel = $site->nav_cta_label ?? '';
        $this->navCtaUrl = $site->nav_cta_url ?? '';
        $this->footerMotto = $site->footer_motto ?? '';
        $this->footerShowLogo = (bool) $site->footer_show_logo;
    }

    public function setHeaderBg(string $hex): void
    {
        $site = $this->assertAuthorizedSiteAccess();

        if (! preg_match('/^#[0-9a-fA-F]{6}$/', $hex)) {
            return;
        }
        $hex = strtolower($hex);

        // White is the platform default — store null so the site keeps
        // following any future default change rather than pinning it.
        $site->update(['header_bg' => $hex === '#ffffff' ? null : $hex]);
        $this->headerBg = $hex;

        // Nav reads header_bg live; the public surface memoises rendered
        // HTML in PublicPageCache. Invalidate so the colour flip is
        // visible on the next request.
        app(PublicPageCache::class)->invalidate($site);
    }

    public function setHeaderPadding(?int $px): void
    {
        $site = $this->assertAuthorizedSiteAccess();

        $px = ($px === null || $px === 0) ? null : max(0, min(24, $px));
        $site->update(['header_padding' => $px]);
        $this->headerPadding = $px;
        app(PublicPageCache::class)->invalidate($site);
    }

    public function resetToWhite(): void
    {
        $this->setHeaderBg('#ffffff');
    }

    public function setChromeLayout(string $layout): void
    {
        $site = $this->assertAuthorizedSiteAccess();
        $options = app(PageLayoutRegistry::class)->optionsFor($site, 'chrome');

        if (! array_key_exists($layout, $options)) {
            throw ValidationException::withMessages([
                'chromeLayout' => 'The selected header preset is not available for this site.',
            ]);
        }

        $site->update(['chrome_layout' => $layout]);
        $fresh = $site->fresh();
        $this->syncChromeSurface($fresh);
        $this->headerMode = ChromeKnobs::headerMode($fresh);
        app(PublicPageCache::class)->invalidate($site);
    }

    public function updatedBrandImage(): void
    {
        $this->validate([
            'brandImage' => 'required|file|mimes:png,jpg,jpeg,webp|max:4096|dimensions:min_width=1200',
        ]);

        $site = $this->assertAuthorizedSiteAccess();
        if ($this->brandImage === null) {
            return;
        }

        try {
            $out = app(ImageOptimiserService::class)->optimise($this->brandImage->get(), 2000, 78);
        } catch (UnsupportedImageException) {
            $this->addError('brandImage', 'That image could not be processed.');

            return;
        }

        if (strlen($out['bytes']) > 600 * 1024) {
            $this->addError('brandImage', 'The brand row image is still too large after optimisation (max 600 KB).');
            $this->brandImage = null;

            return;
        }

        $filename = sprintf('uploaded-%s.%s', now()->format('Ymd-His'), $out['extension']);
        $path = SitePublicObject::put($site->id, 'brand', $filename, $out['bytes']);
        $previous = $site->brand_image_path;
        $site->update(['brand_image_path' => $path]);
        if (is_string($previous) && $previous !== '' && $previous !== $path) {
            Storage::disk('s3')->delete($previous);
        }

        $this->brandImage = null;
        $this->brandImageUrl = ChromeKnobs::brandImageUrl($site->fresh());
        app(PublicPageCache::class)->invalidate($site);
    }

    #[On('media-selected')]
    public function onMediaSelected(int $id, string $model = 'brandImageMediaId'): void
    {
        if ($model === 'navRowImageMediaId') {
            $this->selectNavRowMedia($id);

            return;
        }
        if ($model !== 'brandImageMediaId') {
            return; // another picker on this page
        }
        $this->selectBrandMedia($id);
    }

    public function selectBrandMedia(int $mediaId): void
    {
        $site = $this->assertAuthorizedSiteAccess();
        $media = SiteMedia::query()
            ->library()
            ->where('site_id', $site->id)
            ->findOrFail($mediaId);

        // The replaced file input enforced a 1200px floor; keep it when the asset's width is known.
        if (is_int($media->width) && $media->width < 1200) {
            $this->addError('brandImage', 'Brand row images need to be at least 1200px wide.');

            return;
        }

        $path = $this->brandPathFor($site, $media);
        $site->update([
            'brand_image_media_id' => $media->id,
            'brand_image_path' => $path,
        ]);
        app(MediaAssignService::class)->assign($media, $site, 'brand_row');

        $this->brandImageMediaId = $media->id;
        $this->brandImageUrl = ChromeKnobs::brandImageUrl($site->fresh());
        app(PublicPageCache::class)->invalidate($site);
    }

    public function removeBrandImage(): void
    {
        $site = $this->assertAuthorizedSiteAccess();
        app(MediaAssignService::class)->release($site, 'brand_row');
        $previous = $site->brand_image_path;
        $libraryKey = $site->brandImageMedia?->s3_key;
        $site->update([
            'brand_image_path' => null,
            'brand_image_media_id' => null,
        ]);
        if (is_string($previous) && $previous !== '' && $previous !== $libraryKey) {
            Storage::disk('s3')->delete($previous);
        }
        $this->brandImageUrl = null;
        $this->brandImageMediaId = null;
        app(PublicPageCache::class)->invalidate($site);
    }

    public function setBrandImageOpacity(int $percent): void
    {
        $site = $this->assertAuthorizedSiteAccess();
        $percent = max(0, min(100, $percent));
        $site->update(['brand_image_opacity' => $percent]);
        $this->brandImageOpacity = $percent;
        app(PublicPageCache::class)->invalidate($site);
    }

    public function setBrandImageFit(string $fit): void
    {
        $site = $this->assertAuthorizedSiteAccess();
        if (! in_array($fit, ['cover', 'tile'], true)) {
            return;
        }
        $site->update(['brand_image_fit' => $fit]);
        $this->brandImageFit = $fit;
        app(PublicPageCache::class)->invalidate($site);
    }

    public function setNavRowBg(string $hex): void
    {
        $site = $this->assertAuthorizedSiteAccess();

        if (! preg_match('/^#[0-9a-fA-F]{6}$/', $hex)) {
            return;
        }
        $hex = strtolower($hex);

        $site->update(['nav_row_bg' => $hex]);
        $this->navRowBg = $hex;
        app(PublicPageCache::class)->invalidate($site);
    }

    public function resetNavRowBg(): void
    {
        $site = $this->assertAuthorizedSiteAccess();
        $site->update(['nav_row_bg' => null]);
        $this->navRowBg = null;
        app(PublicPageCache::class)->invalidate($site);
    }

    public function setAnnouncementEnabled(bool $enabled): void
    {
        $site = $this->assertAuthorizedSiteAccess();
        $site->update(['announcement_enabled' => $enabled]);
        $this->announcementEnabled = $enabled;
        app(PublicPageCache::class)->invalidate($site);
    }

    public function addAnnouncementMessage(string $text, ?string $url = null): void
    {
        $site = $this->assertAuthorizedSiteAccess();
        $next = $this->storedAnnouncementMessages($site);
        $next[] = $this->announcementEntry($text, $url);
        $this->validateAnnouncementMessages($next);
        $this->persistAnnouncementMessages($site, $this->normaliseAnnouncementMessages($next));
        $this->announcementDraftText = '';
        $this->announcementDraftUrl = '';
    }

    public function addDraftAnnouncementMessage(): void
    {
        $this->addAnnouncementMessage(
            $this->announcementDraftText,
            $this->announcementDraftUrl === '' ? null : $this->announcementDraftUrl,
        );
    }

    public function setAnnouncementMessageText(int $index, string $text): void
    {
        $site = $this->assertAuthorizedSiteAccess();
        $next = $this->storedAnnouncementMessages($site);
        if (! isset($next[$index])) {
            return;
        }
        $next[$index]['text'] = $text;
        $this->validateAnnouncementMessages($next);
        $this->persistAnnouncementMessages($site, $this->normaliseAnnouncementMessages($next));
    }

    public function setAnnouncementMessageUrl(int $index, string $url): void
    {
        $site = $this->assertAuthorizedSiteAccess();
        $next = $this->storedAnnouncementMessages($site);
        if (! isset($next[$index])) {
            return;
        }
        if (trim($url) === '') {
            unset($next[$index]['url']);
        } else {
            $next[$index]['url'] = $url;
        }
        $this->validateAnnouncementMessages($next);
        $this->persistAnnouncementMessages($site, $this->normaliseAnnouncementMessages($next));
    }

    public function removeAnnouncementMessage(int $index): void
    {
        $site = $this->assertAuthorizedSiteAccess();
        $next = $this->storedAnnouncementMessages($site);
        if (! isset($next[$index])) {
            return;
        }
        unset($next[$index]);
        $this->persistAnnouncementMessages($site, array_values($next));
    }

    public function moveAnnouncementMessage(int $index, int $delta): void
    {
        $site = $this->assertAuthorizedSiteAccess();
        $next = $this->storedAnnouncementMessages($site);
        $target = $index + $delta;
        if (! isset($next[$index]) || ! isset($next[$target])) {
            return;
        }
        $swap = $next[$index];
        $next[$index] = $next[$target];
        $next[$target] = $swap;
        $this->persistAnnouncementMessages($site, array_values($next));
    }

    public function setAnnouncementBg(string $hex): void
    {
        $site = $this->assertAuthorizedSiteAccess();
        if (! preg_match('/^#[0-9a-fA-F]{6}$/', $hex)) {
            return;
        }
        $hex = strtolower($hex);
        $site->update(['announcement_bg' => $hex]);
        $this->announcementBg = $hex;
        app(PublicPageCache::class)->invalidate($site);
    }

    public function resetAnnouncementBg(): void
    {
        $site = $this->assertAuthorizedSiteAccess();
        $site->update(['announcement_bg' => null]);
        $this->announcementBg = null;
        app(PublicPageCache::class)->invalidate($site);
    }

    public function setNavRowAccentBorder(string $mode): void
    {
        $site = $this->assertAuthorizedSiteAccess();
        if (! in_array($mode, ['off', 'on', 'no_hero'], true)) {
            return;
        }

        $site->update(['nav_row_accent_border' => $mode === 'off' ? null : $mode]);
        $this->navRowAccentBorder = $mode;
        app(PublicPageCache::class)->invalidate($site);
    }

    public function setNavRowPattern(string $pattern): void
    {
        $site = $this->assertAuthorizedSiteAccess();
        if (! in_array($pattern, ['none', 'swirl', 'dots', 'image'], true)) {
            return;
        }

        $site->update(['nav_row_pattern' => $pattern === 'none' ? null : $pattern]);
        $this->navRowPattern = $pattern;
        app(PublicPageCache::class)->invalidate($site);
    }

    public function setNavRowImageOpacity(int $percent): void
    {
        $site = $this->assertAuthorizedSiteAccess();
        $percent = max(0, min(100, $percent));
        $site->update(['nav_row_image_opacity' => $percent]);
        $this->navRowImageOpacity = $percent;
        app(PublicPageCache::class)->invalidate($site);
    }

    public function setNavRowImageFit(string $fit): void
    {
        $site = $this->assertAuthorizedSiteAccess();
        if (! in_array($fit, ['cover', 'tile'], true)) {
            return;
        }
        $site->update(['nav_row_image_fit' => $fit]);
        $this->navRowImageFit = $fit;
        app(PublicPageCache::class)->invalidate($site);
    }

    public function setNavRowImagePositionY(int $percent): void
    {
        $site = $this->assertAuthorizedSiteAccess();
        $percent = max(0, min(100, $percent));
        $site->update(['nav_row_image_position_y' => $percent]);
        $this->navRowImagePositionY = $percent;
        app(PublicPageCache::class)->invalidate($site);
    }

    public function selectNavRowMedia(int $mediaId): void
    {
        $site = $this->assertAuthorizedSiteAccess();
        $media = SiteMedia::query()
            ->library()
            ->where('site_id', $site->id)
            ->findOrFail($mediaId);

        if (is_int($media->width) && $media->width < 1200) {
            $this->addError('navRowImage', 'Nav row images need to be at least 1200px wide.');

            return;
        }

        $path = $this->navRowPathFor($site, $media);
        $site->update([
            'nav_row_image_media_id' => $media->id,
            'nav_row_image_path' => $path,
        ]);
        app(MediaAssignService::class)->assign($media, $site, 'nav_row');

        $this->navRowImageMediaId = $media->id;
        $this->navRowImageUrl = ChromeKnobs::navRowImageUrl($site->fresh());
        app(PublicPageCache::class)->invalidate($site);
    }

    public function removeNavRowImage(): void
    {
        $site = $this->assertAuthorizedSiteAccess();
        app(MediaAssignService::class)->release($site, 'nav_row');
        $previous = $site->nav_row_image_path;
        $libraryKey = $site->navRowImageMedia?->s3_key;
        $site->update([
            'nav_row_image_path' => null,
            'nav_row_image_media_id' => null,
        ]);
        if (is_string($previous) && $previous !== '' && $previous !== $libraryKey) {
            Storage::disk('s3')->delete($previous);
        }
        $this->navRowImageUrl = null;
        $this->navRowImageMediaId = null;
        app(PublicPageCache::class)->invalidate($site);
    }

    public function useImagePattern(): void
    {
        $site = $this->assertAuthorizedSiteAccess();
        $recipe = ChromeKnobs::recipe($site);
        if (($recipe['layout'] ?? 'standard') !== 'centred') {
            return;
        }
        if (($recipe['brand_pattern'] ?? 'none') === 'image') {
            return;
        }

        $currentKey = ChromeKnobs::chromeKey($site);
        $newKey = $currentKey.'-image';
        $cloned = $recipe;
        $cloned['brand_pattern'] = 'image';
        $label = is_string($recipe['label'] ?? null) && $recipe['label'] !== ''
            ? $recipe['label'].' (image)'
            : $newKey;

        LayoutPreset::query()->updateOrCreate(
            [
                'site_id' => $site->id,
                'page_kind' => 'chrome',
                'key' => $newKey,
            ],
            [
                'label' => $label,
                'description' => is_string($recipe['description'] ?? null) ? $recipe['description'] : null,
                'recipe' => $cloned,
                'status' => LayoutPreset::STATUS_ACTIVE,
            ],
        );

        $this->setChromeLayout($newKey);
    }

    public function setKnob(string $column, ?string $value): void
    {
        $site = $this->assertAuthorizedSiteAccess();

        if (! array_key_exists($column, self::KNOBS)) {
            return;
        }

        $allowed = self::KNOBS[$column];
        $default = $allowed[0];

        if ($value === null || $value === '') {
            $stored = null;
            $display = in_array($column, ['nav_container_style', 'nav_container_fill'], true) ? '' : $default;
        } elseif (! in_array($value, $allowed, true)) {
            return;
        } elseif ($value === $default && ! in_array($column, ['nav_container_style', 'nav_container_fill'], true)) {
            $stored = null;
            $display = $default;
        } else {
            $stored = $value;
            $display = $value;
        }

        $site->update([$column => $stored]);
        $this->assignKnobProp($column, $display);
        app(PublicPageCache::class)->invalidate($site);
    }

    public function setCta(string $label, string $url): void
    {
        $site = $this->assertAuthorizedSiteAccess();

        $persistUrl = ChromeKnobs::navCtaTarget($site) !== 'form';
        $safe = null;
        if ($persistUrl) {
            $safe = NavCta::safeUrl($url);
            if ($safe === null) {
                throw ValidationException::withMessages([
                    'nav_cta_url' => 'The CTA URL is not allowed.',
                ]);
            }
        }

        $rawLabel = $label;
        $label = trim($this->stripDisallowedChars($label));
        if ($label === '' && trim($rawLabel) !== '') {
            throw ValidationException::withMessages([
                'nav_cta_label' => 'The CTA label is not allowed.',
            ]);
        }

        if (mb_strlen($label) > 40) {
            throw ValidationException::withMessages([
                'nav_cta_label' => 'The CTA label may not be greater than 40 characters.',
            ]);
        }

        $payload = [
            'nav_cta_label' => $label === '' ? null : $label,
        ];
        if ($persistUrl) {
            $payload['nav_cta_url'] = $safe;
        }
        $site->update($payload);
        $this->navCtaLabel = $label;
        if ($persistUrl) {
            $this->navCtaUrl = $safe;
        }
        app(PublicPageCache::class)->invalidate($site);
    }

    public function saveCta(): void
    {
        $this->setCta($this->navCtaLabel, $this->navCtaUrl);
    }

    public function clearCta(): void
    {
        $site = $this->assertAuthorizedSiteAccess();

        $site->update([
            'nav_cta_label' => null,
            'nav_cta_url' => null,
        ]);
        $this->navCtaLabel = '';
        $this->navCtaUrl = '';
        app(PublicPageCache::class)->invalidate($site);
    }

    public function setMotto(?string $motto): void
    {
        $site = $this->assertAuthorizedSiteAccess();

        if ($motto === null) {
            $stored = null;
        } else {
            $rawMotto = $motto;
            $motto = trim($this->stripDisallowedChars($motto));
            if ($motto === '' && trim($rawMotto) !== '') {
                throw ValidationException::withMessages([
                    'footer_motto' => 'The footer motto is not allowed.',
                ]);
            }
            if (mb_strlen($motto) > 120) {
                throw ValidationException::withMessages([
                    'footer_motto' => 'The footer motto may not be greater than 120 characters.',
                ]);
            }
            $stored = $motto === '' ? null : $motto;
        }

        $site->update(['footer_motto' => $stored]);
        $this->footerMotto = $stored ?? '';
        app(PublicPageCache::class)->invalidate($site);
    }

    public function setFooterLogo(bool $show): void
    {
        $site = $this->assertAuthorizedSiteAccess();

        $site->update(['footer_show_logo' => $show]);
        $this->footerShowLogo = $show;
        app(PublicPageCache::class)->invalidate($site);
    }

    private function assignKnobProp(string $column, string $display): void
    {
        match ($column) {
            'header_mode' => $this->headerMode = $display,
            'overlay_glass' => $this->overlayGlass = $display,
            'nav_case' => $this->navCase = $display,
            'nav_container_style' => $this->navContainerStyle = $display,
            'nav_container_fill' => $this->navContainerFill = $display,
            'header_shrink' => $this->headerShrink = $display,
            'header_fit' => $this->headerFit = $display,
            'overlay_inner_scale' => $this->overlayInnerScale = $display,
            'right_action' => $this->rightAction = $display,
            'nav_cta_target' => $this->navCtaTarget = $display,
            'form_style' => $this->formStyle = $display,
            'accent_style' => $this->accentStyle = $display,
            'shop_nav_style' => $this->shopNavStyle = $display,
            'hero_copy_style' => $this->heroCopyStyle = $display,
            default => null,
        };
    }

    private function stripDisallowedChars(string $value): string
    {
        $stripped = preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', '', $value);

        return is_string($stripped) ? $stripped : '';
    }

    private function syncChromeSurface(\App\Models\Site $site): void
    {
        $this->chromeLayout = ChromeKnobs::chromeKey($site);
        $this->chromeOptions = app(PageLayoutRegistry::class)->optionsFor($site, 'chrome');
        $this->chromeIsCentred = ChromeKnobs::layout($site) === 'centred';
        $this->chromeUsesImagePattern = (ChromeKnobs::recipe($site)['brand_pattern'] ?? 'none') === 'image';
        $this->brandImageUrl = ChromeKnobs::brandImageUrl($site);
        $this->brandImageMediaId = $site->brand_image_media_id;
        $this->brandImageOpacity = is_numeric($site->brand_image_opacity) ? (int) $site->brand_image_opacity : 12;
        $this->brandImageFit = ChromeKnobs::brandImageFit($site);
        $this->navRowBg = ChromeKnobs::navRowBg($site);
        $this->navRowPattern = $this->selectedNavRowPattern($site);
        $this->navRowImageUrl = ChromeKnobs::navRowImageUrl($site);
        $this->navRowImageMediaId = $site->nav_row_image_media_id;
        $this->navRowImageOpacity = is_numeric($site->nav_row_image_opacity) ? (int) $site->nav_row_image_opacity : 12;
        $this->navRowImageFit = ChromeKnobs::navRowImageFit($site);
        $this->navRowImagePositionY = ChromeKnobs::navRowImagePositionY($site);
        $this->navRowAccentBorder = ChromeKnobs::navRowAccentBorder($site);
        $this->syncAnnouncementSurface($site);
    }

    private function selectedNavRowPattern(\App\Models\Site $site): string
    {
        $column = $site->nav_row_pattern;
        if (is_string($column) && in_array($column, ['none', 'swirl', 'dots', 'image'], true)) {
            return $column;
        }

        $fromRecipe = ChromeKnobs::recipe($site)['nav_row_pattern'] ?? 'none';

        return is_string($fromRecipe) && in_array($fromRecipe, ['none', 'swirl', 'dots', 'image'], true)
            ? $fromRecipe
            : 'none';
    }

    private function syncAnnouncementSurface(\App\Models\Site $site): void
    {
        $this->announcementEnabled = (bool) $site->announcement_enabled;
        $this->announcementMessages = $this->storedAnnouncementMessages($site);
        $this->announcementBg = ChromeKnobs::announcementBg($site);
    }

    /**
     * @return list<array{text: string, url?: string}>
     */
    private function storedAnnouncementMessages(\App\Models\Site $site): array
    {
        $raw = $site->announcement_messages;

        return is_array($raw) ? array_values($raw) : [];
    }

    /**
     * @param  list<array{text?: mixed, url?: mixed}>  $messages
     * @return list<array{text: string, url?: string}>
     */
    private function normaliseAnnouncementMessages(array $messages): array
    {
        $out = [];
        foreach ($messages as $item) {
            if (count($out) >= 5) {
                break;
            }
            $entry = $this->announcementEntry(
                is_string($item['text'] ?? null) ? $item['text'] : '',
                is_string($item['url'] ?? null) ? $item['url'] : null,
            );
            if ($entry['text'] === '') {
                continue;
            }
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * @return array{text: string, url?: string}
     */
    private function announcementEntry(string $text, ?string $url): array
    {
        $entry = ['text' => trim($this->stripDisallowedChars($text))];
        $cleanUrl = is_string($url) ? trim($this->stripDisallowedChars($url)) : '';
        if ($cleanUrl !== '') {
            $entry['url'] = $cleanUrl;
        }

        return $entry;
    }

    /**
     * @param  list<array{text: string, url?: string}>  $messages
     */
    private function persistAnnouncementMessages(\App\Models\Site $site, array $messages): void
    {
        $stored = $messages === [] ? null : array_values($messages);
        $site->update(['announcement_messages' => $stored]);
        $this->announcementMessages = $messages;
        app(PublicPageCache::class)->invalidate($site);
    }

    /**
     * @param  list<array{text?: mixed, url?: mixed}>  $messages
     */
    private function validateAnnouncementMessages(array $messages): void
    {
        validator(
            ['announcement_messages' => $messages],
            [
                'announcement_messages' => ['array', 'max:5'],
                'announcement_messages.*.text' => ['required', 'string', 'max:120'],
                'announcement_messages.*.url' => [
                    'nullable',
                    'string',
                    function (string $attribute, mixed $value, \Closure $fail): void {
                        if (! is_string($value) || trim($value) === '') {
                            return;
                        }
                        if (NavCta::safeUrl(trim($this->stripDisallowedChars($value))) === null) {
                            $fail('The announcement link must be a relative, https, or tel URL.');
                        }
                    },
                ],
            ],
        )->validate();
    }

    private function brandPathFor(\App\Models\Site $site, SiteMedia $media): string
    {
        $key = (string) $media->s3_key;
        $prefix = 'sites/'.$site->id.'/brand/';
        if ($key !== '' && str_starts_with($key, $prefix)) {
            return $key;
        }

        $bytes = '';
        foreach (array_unique(['s3', (string) config('filesystems.default')]) as $diskName) {
            $disk = Storage::disk($diskName);
            if ($key !== '' && $disk->exists($key)) {
                $bytes = (string) $disk->get($key);
                break;
            }
        }

        $filename = basename($key);
        if ($filename === '' || $filename === '.' || $filename === '/') {
            $filename = 'library-'.$media->id.'.webp';
        }

        if ($bytes === '') {
            throw new \RuntimeException('Media object is missing from storage; cannot publish an empty brand image.');
        }

        return SitePublicObject::put($site->id, 'brand', $filename, $bytes);
    }

    private function navRowPathFor(\App\Models\Site $site, SiteMedia $media): string
    {
        $key = (string) $media->s3_key;
        $prefix = 'sites/'.$site->id.'/nav-row/';
        if ($key !== '' && str_starts_with($key, $prefix)) {
            return $key;
        }

        $bytes = '';
        foreach (array_unique(['s3', (string) config('filesystems.default')]) as $diskName) {
            $disk = Storage::disk($diskName);
            if ($key !== '' && $disk->exists($key)) {
                $bytes = (string) $disk->get($key);
                break;
            }
        }

        $filename = basename($key);
        if ($filename === '' || $filename === '.' || $filename === '/') {
            $filename = 'library-'.$media->id.'.webp';
        }

        if ($bytes === '') {
            throw new \RuntimeException('Media object is missing from storage; cannot publish an empty nav-row image.');
        }

        return SitePublicObject::put($site->id, 'nav-row', $filename, $bytes);
    }
}; ?>

<div data-livewire-component="header-style-settings">
    <div class="flex items-center gap-3 text-sm">
        <input type="color"
               value="{{ $headerBg }}"
               wire:change="setHeaderBg($event.target.value)"
               class="h-8 w-12 cursor-pointer rounded border border-zinc-300 dark:border-neutral-600 bg-transparent p-0.5"
               aria-label="Header background colour">
        <span class="font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $headerBg }}</span>
        @if ($headerBg !== '#ffffff')
            <button type="button" wire:click="resetToWhite"
                    class="text-xs text-zinc-500 underline hover:text-zinc-800 dark:hover:text-zinc-200">
                Reset to white
            </button>
        @endif
    </div>
    <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
        Nav link colours adapt automatically on dark backgrounds. White (default) keeps today's header.
    </p>

    <div class="mt-3 flex flex-col gap-3 text-sm">
        <label class="flex flex-col gap-1">
            <span class="text-xs text-zinc-500 dark:text-zinc-400">Header preset</span>
            <select wire:change="setChromeLayout($event.target.value)"
                    class="rounded border border-zinc-300 dark:border-neutral-600 bg-transparent px-2 py-1 text-sm"
                    aria-label="Header preset">
                @foreach ($chromeOptions as $key => $opt)
                    <option value="{{ $key }}" @selected($chromeLayout === $key)>{{ $opt['label'] }}</option>
                @endforeach
            </select>
        </label>

        @if ($chromeIsCentred)
            <div class="flex flex-col gap-2 rounded border border-zinc-200 p-3 dark:border-neutral-700" data-brand-row-background>
                <span class="text-xs text-zinc-500 dark:text-zinc-400">Brand row background</span>
                @if ($brandImageUrl)
                    <img src="{{ $brandImageUrl }}" alt="" class="h-16 w-auto rounded">
                    <button type="button" wire:click="removeBrandImage"
                            class="self-start text-xs text-zinc-500 underline hover:text-zinc-800 dark:hover:text-zinc-200">
                        Remove
                    </button>
                @endif
                <x-media-picker :site-id="$siteId" model="brandImageMediaId" kinds="image" slot-label="Brand row" aspect="21:9" />
                @error('brandImage') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                <label class="flex items-center gap-3 text-sm">
                    <span class="text-xs text-zinc-500 dark:text-zinc-400">Strength</span>
                    <input type="range" min="0" max="40" step="1"
                           value="{{ $brandImageOpacity }}"
                           wire:change="setBrandImageOpacity($event.target.valueAsNumber)"
                           aria-label="Brand row background opacity">
                    <span class="font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $brandImageOpacity }}%</span>
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-xs text-zinc-500 dark:text-zinc-400">Fit</span>
                    <select wire:change="setBrandImageFit($event.target.value)"
                            class="rounded border border-zinc-300 dark:border-neutral-600 bg-transparent px-2 py-1 text-sm"
                            aria-label="Brand row background fit">
                        <option value="cover" @selected($brandImageFit === 'cover')>Cover</option>
                        <option value="tile" @selected($brandImageFit === 'tile')>Tile</option>
                    </select>
                </label>
                @if (! $chromeUsesImagePattern)
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">
                        The current header preset uses a drawn pattern. Switch this site to an image pattern to show the upload behind the logo.
                    </p>
                    <button type="button" wire:click="useImagePattern"
                            class="self-start rounded border border-zinc-300 dark:border-neutral-600 px-2 py-1 text-xs">
                        Use image pattern
                    </button>
                @endif
            </div>
        @endif

        <div class="flex flex-col gap-2 rounded border border-zinc-200 p-3 dark:border-neutral-700" data-nav-row-background>
            <span class="text-xs text-zinc-500 dark:text-zinc-400">Nav row</span>
            <div class="flex items-center gap-3 text-sm">
                <input type="color"
                       value="{{ $navRowBg ?? $headerBg }}"
                       wire:change="setNavRowBg($event.target.value)"
                       class="h-8 w-12 cursor-pointer rounded border border-zinc-300 dark:border-neutral-600 bg-transparent p-0.5"
                       aria-label="Nav row background colour">
                <span class="font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $navRowBg ?? 'inherit' }}</span>
                @if ($navRowBg !== null)
                    <button type="button" wire:click="resetNavRowBg"
                            class="text-xs text-zinc-500 underline hover:text-zinc-800 dark:hover:text-zinc-200">
                        Reset
                    </button>
                @endif
            </div>
            <div class="flex items-center gap-2">
                <span class="text-xs text-zinc-500 dark:text-zinc-400">Accent border</span>
                <div class="flex flex-wrap gap-1" role="group" aria-label="Accent border below nav row">
                    @foreach (['off' => 'Off', 'on' => 'On', 'no_hero' => 'No hero'] as $mode => $label)
                        <button type="button"
                                wire:click="setNavRowAccentBorder('{{ $mode }}')"
                                class="rounded border px-2 py-1 text-xs {{ $navRowAccentBorder === $mode ? 'border-zinc-800 bg-zinc-100 dark:border-zinc-200 dark:bg-neutral-800' : 'border-zinc-300 dark:border-neutral-600' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>
            <div class="flex flex-wrap gap-1" role="group" aria-label="Nav row pattern">
                @foreach (['none', 'swirl', 'dots', 'image'] as $pattern)
                    <button type="button"
                            wire:click="setNavRowPattern('{{ $pattern }}')"
                            class="rounded border px-2 py-1 text-xs {{ $navRowPattern === $pattern ? 'border-zinc-800 bg-zinc-100 dark:border-zinc-200 dark:bg-neutral-800' : 'border-zinc-300 dark:border-neutral-600' }}">
                        {{ ucfirst($pattern) }}
                    </button>
                @endforeach
            </div>
            @if ($navRowPattern === 'image')
                <div data-nav-row-image-controls class="flex flex-col gap-2">
                @if ($navRowImageUrl)
                    <img src="{{ $navRowImageUrl }}" alt="" class="h-16 w-auto rounded">
                    <button type="button" wire:click="removeNavRowImage"
                            class="self-start text-xs text-zinc-500 underline hover:text-zinc-800 dark:hover:text-zinc-200">
                        Remove
                    </button>
                @endif
                <x-media-picker :site-id="$siteId" model="navRowImageMediaId" kinds="image" slot-label="Nav row" aspect="21:9" />
                @error('navRowImage') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                <label class="flex items-center gap-3 text-sm">
                    <span class="text-xs text-zinc-500 dark:text-zinc-400">Strength</span>
                    <input type="range" min="0" max="40" step="1"
                           value="{{ $navRowImageOpacity }}"
                           wire:change="setNavRowImageOpacity($event.target.valueAsNumber)"
                           aria-label="Nav row background opacity">
                    <span class="font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $navRowImageOpacity }}%</span>
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-xs text-zinc-500 dark:text-zinc-400">Fit</span>
                    <select wire:change="setNavRowImageFit($event.target.value)"
                            class="rounded border border-zinc-300 dark:border-neutral-600 bg-transparent px-2 py-1 text-sm"
                            aria-label="Nav row background fit">
                        <option value="cover" @selected($navRowImageFit === 'cover')>Cover</option>
                        <option value="tile" @selected($navRowImageFit === 'tile')>Tile</option>
                    </select>
                </label>
                <label class="flex items-center gap-3 text-sm">
                    <span class="text-xs text-zinc-500 dark:text-zinc-400">Position</span>
                    <input type="range" min="0" max="100" step="1"
                           value="{{ $navRowImagePositionY }}"
                           wire:change="setNavRowImagePositionY($event.target.valueAsNumber)"
                           aria-label="Nav row image vertical position">
                    <span class="font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $navRowImagePositionY }}%</span>
                </label>
                </div>
            @endif
        </div>

        <div class="flex flex-col gap-2 rounded border border-zinc-200 p-3 dark:border-neutral-700" data-announcement-settings>
            <span class="text-xs text-zinc-500 dark:text-zinc-400">Announcement</span>
            <label class="inline-flex items-center gap-2 text-sm">
                <input type="checkbox"
                       wire:change="setAnnouncementEnabled($event.target.checked)"
                       @checked($announcementEnabled)
                       class="text-zinc-900"
                       aria-label="Enable announcement strip">
                <span>Show announcement strip</span>
            </label>
            <div class="flex flex-col gap-2" role="list" aria-label="Announcement messages">
                @foreach ($announcementMessages as $index => $message)
                    <div class="flex flex-col gap-1 rounded border border-zinc-200 p-2 dark:border-neutral-700" role="listitem">
                        <input type="text" maxlength="120"
                               value="{{ $message['text'] }}"
                               wire:change="setAnnouncementMessageText({{ $index }}, $event.target.value)"
                               class="rounded border border-zinc-300 dark:border-neutral-600 bg-transparent px-2 py-1 text-sm"
                               aria-label="Announcement message {{ $index + 1 }}">
                        <input type="text" maxlength="255"
                               value="{{ $message['url'] ?? '' }}"
                               wire:change="setAnnouncementMessageUrl({{ $index }}, $event.target.value)"
                               class="rounded border border-zinc-300 dark:border-neutral-600 bg-transparent px-2 py-1 text-sm"
                               aria-label="Announcement link {{ $index + 1 }}"
                               placeholder="Optional URL">
                        <div class="flex flex-wrap gap-2">
                            <button type="button" wire:click="moveAnnouncementMessage({{ $index }}, -1)"
                                    class="text-xs text-zinc-500 underline hover:text-zinc-800 dark:hover:text-zinc-200"
                                    @disabled($index === 0)>
                                Up
                            </button>
                            <button type="button" wire:click="moveAnnouncementMessage({{ $index }}, 1)"
                                    class="text-xs text-zinc-500 underline hover:text-zinc-800 dark:hover:text-zinc-200"
                                    @disabled($index === count($announcementMessages) - 1)>
                                Down
                            </button>
                            <button type="button" wire:click="removeAnnouncementMessage({{ $index }})"
                                    class="text-xs text-zinc-500 underline hover:text-zinc-800 dark:hover:text-zinc-200">
                                Remove
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>
            @error('announcement_messages') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
            @error('announcement_messages.0.text') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
            @error('announcement_messages.0.url') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
            @if (count($announcementMessages) < 5)
                <div class="flex flex-col gap-1">
                    <input type="text" maxlength="120" wire:model="announcementDraftText"
                           class="rounded border border-zinc-300 dark:border-neutral-600 bg-transparent px-2 py-1 text-sm"
                           aria-label="New announcement message"
                           placeholder="New message">
                    <input type="text" maxlength="255" wire:model="announcementDraftUrl"
                           class="rounded border border-zinc-300 dark:border-neutral-600 bg-transparent px-2 py-1 text-sm"
                           aria-label="New announcement link"
                           placeholder="Optional URL">
                    <button type="button"
                            wire:click="addDraftAnnouncementMessage"
                            class="self-start rounded border border-zinc-300 dark:border-neutral-600 px-2 py-1 text-xs">
                        Add message
                    </button>
                </div>
            @endif
            <div class="flex items-center gap-3 text-sm">
                <input type="color"
                       value="{{ $announcementBg ?? '#f59e0b' }}"
                       wire:change="setAnnouncementBg($event.target.value)"
                       class="h-8 w-12 cursor-pointer rounded border border-zinc-300 dark:border-neutral-600 bg-transparent p-0.5"
                       aria-label="Announcement background colour">
                <span class="font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $announcementBg ?? 'accent' }}</span>
                @if ($announcementBg !== null)
                    <button type="button" wire:click="resetAnnouncementBg"
                            class="text-xs text-zinc-500 underline hover:text-zinc-800 dark:hover:text-zinc-200">
                        Reset
                    </button>
                @endif
            </div>
        </div>

        <label class="flex flex-col gap-1">
            <span class="text-xs text-zinc-500 dark:text-zinc-400">Header mode</span>
            <select wire:change="setKnob('header_mode', $event.target.value)"
                    class="rounded border border-zinc-300 dark:border-neutral-600 bg-transparent px-2 py-1 text-sm"
                    aria-label="Header mode">
                <option value="solid" @selected($headerMode === 'solid')>Solid</option>
                <option value="overlay" @selected($headerMode === 'overlay')>Overlay</option>
            </select>
        </label>

        @if ($headerMode === 'overlay')
            <label class="flex flex-col gap-1">
                <span class="text-xs text-zinc-500 dark:text-zinc-400">Overlay glass</span>
                <select wire:change="setKnob('overlay_glass', $event.target.value)"
                        class="rounded border border-zinc-300 dark:border-neutral-600 bg-transparent px-2 py-1 text-sm"
                        aria-label="Overlay glass">
                    <option value="off" @selected($overlayGlass === 'off')>Off</option>
                    <option value="scrolled" @selected($overlayGlass === 'scrolled')>When scrolled</option>
                    <option value="floating" @selected($overlayGlass === 'floating')>While floating</option>
                    <option value="always" @selected($overlayGlass === 'always')>Always</option>
                </select>
            </label>
            @if ($hasOverlayLogoSize)
                <label class="flex flex-col gap-1">
                    <span class="text-xs text-zinc-500 dark:text-zinc-400">Inner pages floating bar</span>
                    <select wire:change="setKnob('overlay_inner_scale', $event.target.value)"
                            class="rounded border border-zinc-300 dark:border-neutral-600 bg-transparent px-2 py-1 text-sm"
                            aria-label="Inner pages floating bar">
                        <option value="overlay" @selected($overlayInnerScale === 'overlay')>Match floating logo (default)</option>
                        <option value="main" @selected($overlayInnerScale === 'main')>Standard (smaller)</option>
                    </select>
                </label>
            @endif

        @else
            <p class="text-xs text-zinc-500 dark:text-zinc-400">Switch header mode to Overlay to set a frosted glass treatment.</p>
        @endif

            <label class="flex flex-col gap-1">
                <span class="text-xs text-zinc-500 dark:text-zinc-400">Shop navigation</span>
                <select wire:change="setKnob('shop_nav_style', $event.target.value)"
                        class="rounded border border-zinc-300 dark:border-neutral-600 bg-transparent px-2 py-1 text-sm"
                        aria-label="Shop navigation">
                    <option value="link" @selected($shopNavStyle === 'link')>Link (Shop goes to /shop)</option>
                    <option value="dropdown" @selected($shopNavStyle === 'dropdown')>Dropdown (categories)</option>
                    <option value="mega" @selected($shopNavStyle === 'mega')>Mega menu</option>
                </select>
            </label>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <label class="flex flex-col gap-1">
                    <span class="text-xs text-zinc-500 dark:text-zinc-400">Navigation container</span>
                    <select wire:change="setKnob('nav_container_style', $event.target.value)"
                            class="rounded border border-zinc-300 dark:border-neutral-600 bg-transparent px-2 py-1 text-sm"
                            aria-label="Navigation container">
                        <option value="" @selected($navContainerStyle === '')>Inherit (recipe)</option>
                        @foreach (ChromeKnobs::NAV_CONTAINER_STYLES as $style)
                            <option value="{{ $style }}" @selected($navContainerStyle === $style)>{{ ucfirst($style) }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-xs text-zinc-500 dark:text-zinc-400">Navigation container fill</span>
                    <select wire:change="setKnob('nav_container_fill', $event.target.value)"
                            class="rounded border border-zinc-300 dark:border-neutral-600 bg-transparent px-2 py-1 text-sm"
                            aria-label="Navigation container fill">
                        <option value="" @selected($navContainerFill === '')>Inherit (recipe)</option>
                        @foreach (ChromeKnobs::NAV_CONTAINER_FILLS as $fill)
                            <option value="{{ $fill }}" @selected($navContainerFill === $fill)>{{ ucfirst($fill) }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
            <label class="flex items-center gap-3 text-sm">
                <span class="text-xs text-zinc-500 dark:text-zinc-400">Navigation case</span>
                <select wire:change="setKnob('nav_case', $event.target.value)"
                        class="rounded border border-zinc-300 dark:border-neutral-600 bg-transparent px-2 py-1 text-sm"
                        aria-label="Navigation case">
                    <option value="normal" @selected($navCase === 'normal')>Normal</option>
                    <option value="upper" @selected($navCase === 'upper')>Uppercase</option>
                    <option value="lower" @selected($navCase === 'lower')>Lowercase</option>
                </select>
            </label>
            <label class="flex items-center gap-3 text-sm">
                <span class="text-xs text-zinc-500 dark:text-zinc-400">Sticky shrink</span>
                <select wire:change="setKnob('header_shrink', $event.target.value)"
                        class="rounded border border-zinc-300 dark:border-neutral-600 bg-transparent px-2 py-1 text-sm"
                        aria-label="Sticky shrink">
                    <option value="on" @selected($headerShrink === 'on')>On (bar and logo drop a notch when scrolled)</option>
                    <option value="off" @selected($headerShrink === 'off')>Off (keep full size when solid)</option>
                </select>
            </label>
            <label class="flex items-center gap-3 text-sm">
                <span class="text-xs text-zinc-500 dark:text-zinc-400">Bar fit</span>
                <select wire:change="setKnob('header_fit', $event.target.value)"
                        class="rounded border border-zinc-300 dark:border-neutral-600 bg-transparent px-2 py-1 text-sm"
                        aria-label="Bar fit">
                    <option value="comfortable" @selected($headerFit === 'comfortable')>Comfortable</option>
                    <option value="tight" @selected($headerFit === 'tight')>Tight (logo plus 1rem)</option>
                </select>
            </label>
            <label class="flex items-center gap-3 text-sm">
                <span class="text-xs text-zinc-500 dark:text-zinc-400">Header padding</span>
                <input type="number" min="0" max="24" step="1"
                       value="{{ $headerPadding }}"
                       wire:change="setHeaderPadding($event.target.value === '' ? null : $event.target.valueAsNumber)"
                       class="w-16 rounded border border-zinc-300 dark:border-neutral-600 bg-transparent px-2 py-1 text-sm"
                       aria-label="Header padding in pixels">
                <span class="text-xs text-zinc-500 dark:text-zinc-400">px top and bottom (blank = none, max 24)</span>
            </label>

        <label class="flex flex-col gap-1">
            <span class="text-xs text-zinc-500 dark:text-zinc-400">Right action</span>
            <select wire:change="setKnob('right_action', $event.target.value)"
                    class="rounded border border-zinc-300 dark:border-neutral-600 bg-transparent px-2 py-1 text-sm"
                    aria-label="Right action">
                <option value="phone" @selected($rightAction === 'phone')>Phone</option>
                <option value="cta" @selected($rightAction === 'cta')>CTA</option>
                <option value="phone_cta" @selected($rightAction === 'phone_cta')>Phone and CTA</option>
                <option value="none" @selected($rightAction === 'none')>None</option>
            </select>
        </label>

        @if (in_array($rightAction, ['cta', 'phone_cta'], true))
            <div class="flex flex-col gap-2">
                <label class="flex flex-col gap-1">
                    <span class="text-xs text-zinc-500 dark:text-zinc-400">CTA target</span>
                    <select wire:change="setKnob('nav_cta_target', $event.target.value)"
                            class="rounded border border-zinc-300 dark:border-neutral-600 bg-transparent px-2 py-1 text-sm"
                            aria-label="CTA target">
                        <option value="url" @selected($navCtaTarget === 'url')>Custom URL</option>
                        <option value="form" @selected($navCtaTarget === 'form')>Enquiry form on this page, else Contact</option>
                    </select>
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-xs text-zinc-500 dark:text-zinc-400">CTA label</span>
                    <input type="text" maxlength="40" wire:model="navCtaLabel"
                           class="rounded border border-zinc-300 dark:border-neutral-600 bg-transparent px-2 py-1 text-sm"
                           aria-label="CTA label"
                           @if ($navCtaTarget === 'form') placeholder="Get a free quote" @endif>
                    @error('nav_cta_label') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                </label>
                @if ($navCtaTarget !== 'form')
                <label class="flex flex-col gap-1">
                    <span class="text-xs text-zinc-500 dark:text-zinc-400">CTA URL</span>
                    <input type="text" maxlength="255" wire:model="navCtaUrl"
                           class="rounded border border-zinc-300 dark:border-neutral-600 bg-transparent px-2 py-1 text-sm"
                           aria-label="CTA URL"
                           placeholder="/contact">
                    @error('nav_cta_url') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                </label>
                @endif
                <div class="flex items-center gap-3">
                    <button type="button" wire:click="saveCta"
                            class="rounded border border-zinc-300 dark:border-neutral-600 px-2 py-1 text-xs">
                        Save CTA
                    </button>
                    <button type="button" wire:click="clearCta"
                            class="text-xs text-zinc-500 underline hover:text-zinc-800 dark:hover:text-zinc-200">
                        Clear
                    </button>
                </div>
            </div>
        @endif

        <label class="inline-flex items-center gap-2">
            <input type="checkbox"
                   wire:change="setFooterLogo($event.target.checked)"
                   @checked($footerShowLogo)
                   class="text-zinc-900">
            <span>Show logo in footer</span>
        </label>

        <label class="flex flex-col gap-1">
            <span class="text-xs text-zinc-500 dark:text-zinc-400">Footer motto</span>
            <input type="text" maxlength="120" value="{{ $footerMotto }}"
                   wire:change="setMotto($event.target.value)"
                   class="rounded border border-zinc-300 dark:border-neutral-600 bg-transparent px-2 py-1 text-sm"
                   aria-label="Footer motto">
            @error('footer_motto') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
        </label>

        <label class="flex flex-col gap-1">
            <span class="text-xs text-zinc-500 dark:text-zinc-400">Hero copy</span>
            <select wire:change="setKnob('hero_copy_style', $event.target.value)"
                    class="rounded border border-zinc-300 dark:border-neutral-600 bg-transparent px-2 py-1 text-sm"
                    aria-label="Hero copy">
                @foreach (ChromeKnobs::HERO_COPY_STYLES as $style)
                    <option value="{{ $style }}" @selected($heroCopyStyle === $style)>{{ ucfirst($style) }}</option>
                @endforeach
            </select>
        </label>

        <label class="flex flex-col gap-1">
            <span class="text-xs text-zinc-500 dark:text-zinc-400">Form style</span>
            <select wire:change="setKnob('form_style', $event.target.value)"
                    class="rounded border border-zinc-300 dark:border-neutral-600 bg-transparent px-2 py-1 text-sm"
                    aria-label="Form style">
                <option value="boxed" @selected($formStyle === 'boxed')>Boxed</option>
                <option value="underline" @selected($formStyle === 'underline')>Underline</option>
            </select>
        </label>

        <label class="flex flex-col gap-1">
            <span class="text-xs text-zinc-500 dark:text-zinc-400">Accent style</span>
            <select wire:change="setKnob('accent_style', $event.target.value)"
                    class="rounded border border-zinc-300 dark:border-neutral-600 bg-transparent px-2 py-1 text-sm"
                    aria-label="Accent style">
                <option value="default" @selected($accentStyle === 'default')>Default</option>
                <option value="italic" @selected($accentStyle === 'italic')>Italic</option>
            </select>
        </label>
    </div>
</div>
