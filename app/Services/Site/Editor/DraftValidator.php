<?php

namespace App\Services\Site\Editor;

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\SiteDraft;
use App\Models\SiteMedia;
use App\Services\Site\HeroResolution;
use App\Services\Site\SectionSchema;
use App\Services\Site\ThemeResolver;

final class DraftValidator
{
    /**
     * @var list<string>
     */
    public const CODES = [
        'alt_text_missing',
        'broken_internal_link',
        'contrast_below_aa',
        'invalid_form',
        'layout_not_checked',
        'meta_description_long',
        'meta_title_long',
        'missing_image',
        'published_video_image_conflict',
        'theme_token_bypass',
        'unchecked_external_link',
        'video_image_conflict',
    ];

    /**
     * @var list<string>|null
     */
    private ?array $checks = null;

    /**
     * @var list<array<string, mixed>>
     */
    private array $findings = [];

    public function __construct(
        private readonly HeroResolution $heroResolution,
        private readonly ThemeResolver $themeResolver,
        private readonly FormDefinitionWriter $forms,
        private readonly SectionSchema $schema,
        private readonly DraftAssetSelections $draftSelections,
    ) {}

    /**
     * @param  list<string>|null  $checks
     * @return list<array<string, mixed>>
     */
    public function findings(Site $site, ?int $pageId, ?array $checks): array
    {
        $this->checks = $checks;
        $this->findings = [];
        $site->loadMissing('businessProfile');

        $pages = GeneratedPage::query()
            ->with(['draftRevision', 'publishedRevision'])
            ->where('site_id', $site->id)
            ->whereNull('archived_at')
            ->when($pageId !== null, fn ($query) => $query->where('id', $pageId))
            ->orderBy('id')
            ->get();

        $liveIds = GeneratedPage::query()
            ->where('site_id', $site->id)
            ->whereNull('archived_at')
            ->pluck('id', 'page_type');

        $composition = SiteDraft::query()->where('site_id', $site->id)->first()?->composition ?? [];

        if ($pageId === null) {
            $this->checkNav($composition['nav']['items'] ?? [], $liveIds);
        }

        $this->checkContrast($site, is_array($composition['theme'] ?? null) ? $composition['theme'] : []);

        foreach ($pages as $page) {
            $this->checkPage($site, $page, $liveIds);
        }

        if ($this->wants('layout_not_checked')) {
            $this->add([
                'code' => 'layout_not_checked',
                'severity' => 'info',
                'message' => 'Overflow and mobile layout are not statically decidable from content_data.',
                'fix_hint' => 'Inspect desktop and mobile screenshots rather than trusting a static check.',
            ]);
        }

        return $this->sorted();
    }

    /**
     * @param  list<mixed>  $items
     * @param  \Illuminate\Support\Collection<string, int>  $liveIds
     */
    private function checkNav(array $items, $liveIds): void
    {
        foreach (array_values($items) as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $type = $item['type'] ?? 'page';
            $path = 'nav.items.'.$index;

            if ($type === 'group') {
                $this->checkNavChildren(is_array($item['children'] ?? null) ? $item['children'] : [], $liveIds, $path);
                continue;
            }

            if ($type === 'page') {
                $this->checkNavPageId($item['page_id'] ?? null, $liveIds, $path);
                continue;
            }

            if ($type === 'external') {
                $this->classifyHref(is_string($item['url'] ?? null) ? $item['url'] : '', $path, null, null);
            }
        }
    }

    /**
     * @param  list<mixed>  $children
     * @param  \Illuminate\Support\Collection<string, int>  $liveIds
     */
    private function checkNavChildren(array $children, $liveIds, string $parentPath): void
    {
        foreach (array_values($children) as $index => $child) {
            if (! is_array($child)) {
                continue;
            }

            $this->checkNavPageId($child['page_id'] ?? null, $liveIds, $parentPath.'.children.'.$index);
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<string, int>  $liveIds
     */
    private function checkNavPageId(mixed $pageId, $liveIds, string $path): void
    {
        if (! $this->wants('broken_internal_link')) {
            return;
        }

        $id = is_numeric($pageId) ? (int) $pageId : 0;
        if ($id > 0 && $liveIds->contains($id)) {
            return;
        }

        $this->add([
            'code' => 'broken_internal_link',
            'severity' => 'error',
            'path' => $path,
            'message' => "Nav page id {$id} does not resolve to a live page on this site.",
            'fix_hint' => 'Point the nav item at an existing, non-archived page of this site.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $theme
     */
    private function checkContrast(Site $site, array $theme): void
    {
        if (! $this->wants('contrast_below_aa')) {
            return;
        }

        $profile = is_array($site->businessProfile?->profile_data) ? $site->businessProfile->profile_data : [];
        $tokens = $this->themeResolver->renderTokens(
            $this->themeResolver->resolve($site, $profile, $theme),
        );

        $authorPairs = [
            ['text', 'surface', 4.5, 'error'],
            ['text_muted', 'surface', 4.5, 'error'],
            ['text_muted', 'surface_alt', 4.5, 'error'],
        ];
        $derivedPairs = [
            ['primary_text', 'surface', 4.5],
            ['accent_text', 'surface', 4.5],
            ['text_on_alt', 'surface_alt', 4.5],
            ['text_muted_on_alt', 'surface_alt', 3.0],
            ['primary_text_on_alt', 'surface_alt', 4.5],
            ['accent_text_on_alt', 'surface_alt', 4.5],
            ['text_on_band', 'band', 4.5],
            ['text_on_primary', 'primary', 4.5],
            ['text_on_accent', 'accent', 4.5],
            ['text_on_contrast', 'surface_contrast', 4.5],
            ['text_muted_on_contrast', 'surface_contrast', 4.5],
            ['accent_text_on_contrast', 'surface_contrast', 4.5],
        ];

        foreach ($authorPairs as [$fg, $bg, $floor, $severity]) {
            $this->maybeContrast($tokens, $fg, $bg, $floor, $severity);
        }
        foreach ($derivedPairs as [$fg, $bg, $floor]) {
            $this->maybeContrast($tokens, $fg, $bg, $floor, 'warning');
        }
    }

    /**
     * @param  array<string, mixed>  $tokens
     */
    private function maybeContrast(array $tokens, string $fg, string $bg, float $floor, string $severity): void
    {
        if (! is_string($tokens[$fg] ?? null) || ! is_string($tokens[$bg] ?? null)) {
            return;
        }

        if ($this->themeResolver->contrastRatio($tokens[$fg], $tokens[$bg]) >= $floor) {
            return;
        }

        $this->add([
            'code' => 'contrast_below_aa',
            'severity' => $severity,
            'path' => $fg,
            'message' => "Token pair {$fg}/{$bg} is below WCAG AA.",
            'fix_hint' => 'Raise contrast between the author-controlled tokens, then re-check.',
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<string, int>  $liveIds
     */
    private function checkPage(Site $site, GeneratedPage $page, $liveIds): void
    {
        $page->loadMissing(['draftRevision', 'publishedRevision']);
        $content = $page->draftRevision?->content_data
            ?? $page->publishedRevision?->content_data
            ?? $page->content_data
            ?? [];
        $content = is_array($content) ? $content : [];
        $sections = is_array($content['sections'] ?? null) ? array_values($content['sections']) : [];

        $this->checkHero($site, $page);
        $this->checkMeta($page, $content);

        foreach ($sections as $index => $section) {
            if (! is_array($section) || ! is_string($section['type'] ?? null) || $section['type'] === '') {
                continue;
            }

            $this->checkForm($page, $index, $section);
            $this->checkThemeBypass($page, $index, $section);
            $this->checkSectionFields($site, $page, $index, $section, $liveIds);
        }
    }

    private function checkHero(Site $site, GeneratedPage $page): void
    {
        $draft = $this->heroResolution->for($site, $page, true);

        if ($this->wants('missing_image') && $draft->mode === 'none') {
            $this->add([
                'code' => 'missing_image',
                'severity' => 'error',
                'page_id' => $page->id,
                'path' => 'hero',
                'message' => 'Hero does not resolve to an image or video.',
                'fix_hint' => 'Select a draft hero image or video so the page has a hero.',
            ]);
        }

        if ((string) $page->page_type !== 'home') {
            return;
        }

        $imageSelected = $draft->image_version_id !== null || ($draft->image_url !== null && $draft->image_url !== '');
        $draftedVideo = $this->draftSelections->heroVideoFor($site);
        $intendedOn = is_array($draftedVideo) && ($draftedVideo['mode'] ?? null) === 'on';

        if ($this->wants('video_image_conflict')
            && $draft->mode === 'video'
            && $imageSelected
            && ! $intendedOn) {
            $this->add([
                'code' => 'video_image_conflict',
                'severity' => 'warning',
                'page_id' => $page->id,
                'path' => 'hero',
                'message' => 'Draft-effective hero resolves to video by the live flag while an image is also selected, without a drafted hero_video mode=on.',
                'fix_hint' => 'Draft hero_video mode=on to keep video, or draft mode=off so the image wins.',
            ]);
        }

        if ($this->wants('published_video_image_conflict')) {
            $live = $this->heroResolution->for($site, $page, false);
            $liveImage = $live->image_version_id !== null || ($live->image_url !== null && $live->image_url !== '');
            if ($live->mode === 'video' && $liveImage) {
                $this->add([
                    'code' => 'published_video_image_conflict',
                    'severity' => 'info',
                    'page_id' => $page->id,
                    'path' => 'hero',
                    'message' => 'The live hero still plays video over an image until a human publishes.',
                    'fix_hint' => 'Publish the draft to replace the live hero, or leave this as street state.',
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function checkMeta(GeneratedPage $page, array $content): void
    {
        $seo = is_array($content['meta']['seo'] ?? null) ? $content['meta']['seo'] : [];

        if ($this->wants('meta_title_long') && is_string($seo['meta_title'] ?? null)) {
            $length = mb_strlen($seo['meta_title']);
            if ($length > 60) {
                $this->add([
                    'code' => 'meta_title_long',
                    'severity' => 'warning',
                    'page_id' => $page->id,
                    'path' => 'meta.seo.meta_title',
                    'message' => "Meta title is {$length} characters; the limit is 60.",
                    'fix_hint' => 'Shorten the meta title to 60 characters or fewer.',
                ]);
            }
        }

        if ($this->wants('meta_description_long') && is_string($seo['meta_description'] ?? null)) {
            $length = mb_strlen($seo['meta_description']);
            if ($length > 155) {
                $this->add([
                    'code' => 'meta_description_long',
                    'severity' => 'error',
                    'page_id' => $page->id,
                    'path' => 'meta.seo.meta_description',
                    'message' => "Meta description is {$length} characters; the limit is 155.",
                    'fix_hint' => 'Shorten the meta description to 155 characters or fewer.',
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $section
     */
    private function checkForm(GeneratedPage $page, int $index, array $section): void
    {
        if (! $this->wants('invalid_form')) {
            return;
        }

        $type = $section['type'];
        if (! in_array($type, ['contact_form', 'lead_form'], true)) {
            return;
        }

        $fieldsKey = $type === 'lead_form' ? 'extra_fields' : 'fields';
        $errors = $this->forms->check([
            'title' => $section['title'] ?? null,
            'submit_label' => $section['submit_label'] ?? null,
            'fields' => is_array($section[$fieldsKey] ?? null) ? array_values($section[$fieldsKey]) : [],
        ], $type);

        if ($errors === []) {
            return;
        }

        $this->add([
            'code' => 'invalid_form',
            'severity' => 'error',
            'page_id' => $page->id,
            'stored_index' => $index,
            'path' => 'fields',
            'message' => 'Form definition failed validation.',
            'fix_hint' => 'Fix the stored form fields so they pass FormDefinitionWriter::check.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $section
     */
    private function checkThemeBypass(GeneratedPage $page, int $index, array $section): void
    {
        if (! $this->wants('theme_token_bypass')) {
            return;
        }

        $type = $section['type'];
        $variant = is_string($section['variant'] ?? null) && $section['variant'] !== ''
            ? $section['variant']
            : null;
        $path = $this->variantBladePath($type, $variant);
        if ($path === null) {
            return;
        }

        $source = file_get_contents($path);
        if ($source === false || ! $this->sourceHasTokenBypass($source)) {
            return;
        }

        $name = $variant === null ? $type : "{$type}/{$variant}";
        $this->add([
            'code' => 'theme_token_bypass',
            'severity' => 'warning',
            'page_id' => $page->id,
            'stored_index' => $index,
            'path' => $name,
            'message' => "Variant {$name} paints a colour literal a theme write cannot move.",
            'fix_hint' => 'Replace the literal with a theme token, or accept the stated limitation.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $section
     * @param  \Illuminate\Support\Collection<string, int>  $liveIds
     */
    private function checkSectionFields(Site $site, GeneratedPage $page, int $index, array $section, $liveIds): void
    {
        $type = $section['type'];
        if (! $this->schema->isKnownSectionType($type)) {
            return;
        }

        foreach ($this->schema->eachEditableField($type, $section) as [$fieldPath, $fieldType, $value]) {
            if ($fieldType === 'image') {
                $this->checkImageField($site, $page, $index, $fieldPath, $value);
                continue;
            }

            if (in_array($fieldType, ['url', 'link'], true) && is_string($value) && $value !== '') {
                $this->classifyHref($value, $fieldPath, $page->id, $index, $site, $liveIds);
                continue;
            }

            if ($fieldType === 'rich' && is_array($value)) {
                foreach ($this->collectHrefs($value) as $href) {
                    $this->classifyHref($href, $fieldPath, $page->id, $index, $site, $liveIds);
                }
            }
        }
    }

    private function checkImageField(Site $site, GeneratedPage $page, int $index, string $path, mixed $value): void
    {
        if (! is_int($value) && ! (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1)) {
            return;
        }

        $mediaId = (int) $value;
        $media = SiteMedia::query()->where('site_id', $site->id)->find($mediaId);

        if ($media === null) {
            if ($this->wants('missing_image')) {
                $this->add([
                    'code' => 'missing_image',
                    'severity' => 'error',
                    'page_id' => $page->id,
                    'stored_index' => $index,
                    'path' => $path,
                    'message' => "Image field {$path} does not resolve to media on this site.",
                    'fix_hint' => 'Assign a site_media id that belongs to this site, or clear the field.',
                ]);
            }

            return;
        }

        if ($this->wants('alt_text_missing') && ! filled($media->alt_text)) {
            $this->add([
                'code' => 'alt_text_missing',
                'severity' => 'warning',
                'page_id' => $page->id,
                'stored_index' => $index,
                'path' => $path,
                'message' => "Media {$media->id} referenced by {$path} has no alt text.",
                'fix_hint' => 'Set alt_text on the media row so the image is described.',
            ]);
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<string, int>|null  $liveIds
     */
    private function classifyHref(
        string $href,
        string $path,
        ?int $pageId,
        ?int $storedIndex,
        ?Site $site = null,
        $liveIds = null,
    ): void {
        $trimmed = trim($href);
        if ($trimmed === '' || $trimmed === '#') {
            return;
        }

        $host = parse_url($trimmed, PHP_URL_HOST);
        $scheme = parse_url($trimmed, PHP_URL_SCHEME);
        $isHttp = in_array($scheme, ['http', 'https'], true);
        $ownHosts = $site === null ? [] : $this->ownHosts($site);

        if ($isHttp && is_string($host) && $host !== '' && ! in_array(strtolower($host), $ownHosts, true)) {
            if ($this->wants('unchecked_external_link')) {
                $finding = [
                    'code' => 'unchecked_external_link',
                    'severity' => 'info',
                    'path' => $path,
                    'message' => "External link {$trimmed} was not fetched.",
                    'fix_hint' => 'Inspect the URL yourself; validate_draft never requests third-party hosts.',
                ];
                if ($pageId !== null) {
                    $finding['page_id'] = $pageId;
                }
                if ($storedIndex !== null) {
                    $finding['stored_index'] = $storedIndex;
                }
                $this->add($finding);
            }

            return;
        }

        if (! $this->wants('broken_internal_link') || $liveIds === null) {
            return;
        }

        $urlPath = $isHttp || str_starts_with($trimmed, '/')
            ? (string) (parse_url($trimmed, PHP_URL_PATH) ?? '/')
            : '';
        if ($urlPath === '' && ! str_starts_with($trimmed, '/')) {
            return;
        }

        $slug = trim($urlPath, '/');
        $pageType = $slug === '' ? 'home' : $slug;
        if ($liveIds->has($pageType)) {
            return;
        }
        // The shop is route-backed, not a GeneratedPage: /shop and everything under it
        // resolve on any site with an established shop (the nav entry is a page-less item).
        if (($slug === 'shop' || str_starts_with($slug, 'shop/')) && $site->hasEstablishedShop()) {
            return;
        }

        $finding = [
            'code' => 'broken_internal_link',
            'severity' => 'error',
            'path' => $path,
            'message' => "Internal link {$trimmed} does not resolve to a page on this site.",
            'fix_hint' => 'Point the href at an existing, non-archived page of this site.',
        ];
        if ($pageId !== null) {
            $finding['page_id'] = $pageId;
        }
        if ($storedIndex !== null) {
            $finding['stored_index'] = $storedIndex;
        }
        $this->add($finding);
    }

    /**
     * @return list<string>
     */
    private function ownHosts(Site $site): array
    {
        $hosts = [];
        foreach ([$site->publicHost(), $site->previewHostname(), $site->custom_domain] as $host) {
            if (is_string($host) && $host !== '') {
                $hosts[] = strtolower($host);
            }
        }
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (is_string($appHost) && $appHost !== '') {
            $hosts[] = strtolower($appHost);
        }

        return array_values(array_unique($hosts));
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private function collectHrefs(array $node): array
    {
        $hrefs = [];
        foreach ($node['marks'] ?? [] as $mark) {
            if (! is_array($mark)) {
                continue;
            }
            if (($mark['type'] ?? null) === 'link' && is_string($mark['attrs']['href'] ?? null)) {
                $hrefs[] = $mark['attrs']['href'];
            }
        }
        foreach ($node['content'] ?? [] as $child) {
            if (is_array($child)) {
                $hrefs = [...$hrefs, ...$this->collectHrefs($child)];
            }
        }

        return $hrefs;
    }

    private function variantBladePath(string $type, ?string $variant): ?string
    {
        if (is_string($variant) && $variant !== '') {
            $file = resource_path("views/site/sections/variants/{$type}/{$variant}.blade.php");
            if (is_file($file)) {
                return $file;
            }
        }

        $stock = resource_path("views/site/sections/{$type}.blade.php");

        return is_file($stock) ? $stock : null;
    }

    private function sourceHasTokenBypass(string $source): bool
    {
        $withoutFallbacks = preg_replace(
            '/var\(\s*--[A-Za-z0-9_-]+\s*,\s*#[0-9A-Fa-f]{3,8}\s*\)/',
            '',
            $source,
        ) ?? $source;

        return preg_match('/#[0-9A-Fa-f]{3,8}\b/', $withoutFallbacks) === 1;
    }

    private function wants(string $code): bool
    {
        return $this->checks === null || in_array($code, $this->checks, true);
    }

    /**
     * @param  array<string, mixed>  $finding
     */
    private function add(array $finding): void
    {
        $ordered = [
            'code' => $finding['code'],
            'severity' => $finding['severity'],
        ];
        if (array_key_exists('page_id', $finding)) {
            $ordered['page_id'] = $finding['page_id'];
        }
        if (array_key_exists('stored_index', $finding)) {
            $ordered['stored_index'] = $finding['stored_index'];
        }
        if (array_key_exists('path', $finding)) {
            $ordered['path'] = $finding['path'];
        }
        $ordered['message'] = $finding['message'];
        if (array_key_exists('fix_hint', $finding)) {
            $ordered['fix_hint'] = $finding['fix_hint'];
        }

        $this->findings[] = $ordered;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sorted(): array
    {
        $findings = $this->findings;
        usort($findings, function (array $left, array $right): int {
            $page = ($left['page_id'] ?? -1) <=> ($right['page_id'] ?? -1);
            if ($page !== 0) {
                return $page;
            }
            $index = ($left['stored_index'] ?? -1) <=> ($right['stored_index'] ?? -1);
            if ($index !== 0) {
                return $index;
            }
            $code = ($left['code'] ?? '') <=> ($right['code'] ?? '');
            if ($code !== 0) {
                return $code;
            }

            return ($left['path'] ?? '') <=> ($right['path'] ?? '');
        });

        return array_values($findings);
    }
}
