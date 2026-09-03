<?php

namespace Tests\Feature\Render;

use App\Services\Site\PageLayoutRegistry;
use App\Services\Site\ThemeResolver;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VariantTrustInkLedgerTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $sectionOverrides
     * @param  array<string, mixed>  $viewOverrides
     * @return array<string, mixed>
     */
    private function trustVars(bool $markers, int $count = 4, array $sectionOverrides = [], array $viewOverrides = []): array
    {
        $items = [];
        foreach (range(1, $count) as $n) {
            $items[] = ['title' => "Signal {$n}", 'body' => "Proof {$n}."];
        }

        return array_merge([
            'section' => array_merge([
                'type' => 'trust',
                'variant' => 'ink-ledger',
                'title' => 'Trusted locally. Built to last.',
                'eyebrow' => 'Why Choose Us',
                'items' => $items,
            ], $sectionOverrides),
            'sectionIndex' => 2,
            'pageId' => 42,
            'mode' => 'public',
            'emitMarkers' => $markers,
            'emitFormMarkers' => false,
            'schema' => [],
            'theme' => [],
            'pagesBySlug' => [],
        ], $viewOverrides);
    }

    /**
     * @param  array<string, mixed>  $sectionOverrides
     * @param  array<string, mixed>  $viewOverrides
     */
    private function render(bool $markers, int $count = 4, array $sectionOverrides = [], array $viewOverrides = []): string
    {
        return View::make('site.sections.trust', $this->trustVars($markers, $count, $sectionOverrides, $viewOverrides))->render();
    }

    public function test_renders_route_and_all_items(): void
    {
        $html = $this->render(false, 4);

        $this->assertStringContainsString('data-svc-variant="ink-ledger"', $html);
        $this->assertStringContainsString('Why Choose Us', $html);
        $this->assertStringContainsString('>01<', $html);
        $this->assertStringContainsString('>04<', $html);
        $this->assertStringContainsString('lg:grid-cols-[1fr_1.2fr]', $html);
        $this->assertStringContainsString('font-family: var(--font-display)', $html);
        $this->assertStringNotContainsString('rounded-full', $html);

        foreach (range(1, 4) as $n) {
            $this->assertStringContainsString("Signal {$n}", $html);
            $this->assertStringContainsString("Proof {$n}.", $html);
        }
    }

    public function test_all_items_render_in_the_ledger_with_no_intro_slot(): void
    {
        $html = $this->render(true, 4, ['intro' => 'Borrowed pillar must not become an intro.']);

        $this->assertStringContainsString('data-svc-variant="ink-ledger"', $html);
        $this->assertStringNotContainsString('data-editable-field="intro"', $html);
        $this->assertStringNotContainsString('Borrowed pillar must not become an intro.', $html);
        $this->assertSame(4, preg_match_all('/>0[1-4]</', $html));

        foreach (range(1, 4) as $n) {
            $this->assertStringContainsString("Signal {$n}", $html);
            $this->assertStringContainsString("Proof {$n}.", $html);
        }
    }

    public function test_stamped_contrast_uses_numbered_rows_swap_set(): void
    {
        $html = $this->render(false, 4, ['__surface' => 'contrast']);

        $this->assertStringContainsString('data-svc-variant="ink-ledger"', $html);
        $this->assertStringContainsString('background-color: var(--color-surface-contrast)', $html);
        $this->assertStringContainsString('var(--color-text-on-contrast)', $html);
        $this->assertStringContainsString('var(--color-text-muted-on-contrast)', $html);
        $this->assertStringContainsString('color-mix(in oklab, var(--color-text-on-contrast) 16%, transparent)', $html);
        $this->assertStringContainsString('var(--brand-accent-text-on-contrast)', $html);
        $this->assertStringContainsString('border-color: var(--brand-accent-text-on-contrast)', $html);
        $this->assertStringContainsString(
            'style="color: var(--brand-accent-text-on-contrast); border-color: var(--brand-accent-text-on-contrast);"',
            $html,
        );
        $this->assertStringContainsString(
            'style="color: var(--brand-accent-text-on-contrast); font-family: var(--font-display);">01<',
            $html,
        );
        $this->assertStringNotContainsString('--brand-accent-text)', $html);
        $this->assertStringNotContainsString('background-color: var(--color-surface);', $html);
        $this->assertStringNotContainsString('var(--color-band)', $html);
        $this->assertStringNotContainsString('var(--color-text-on-band)', $html);
    }

    public function test_unstamped_uses_surface_and_base_accent(): void
    {
        $html = $this->render(false, 4);

        $this->assertStringContainsString('data-svc-variant="ink-ledger"', $html);
        $this->assertStringContainsString('background-color: var(--color-surface)', $html);
        $this->assertStringContainsString('var(--color-text)', $html);
        $this->assertStringContainsString('var(--color-text-muted)', $html);
        $this->assertStringContainsString('color-mix(in oklab, var(--color-text) 16%, transparent)', $html);
        $this->assertStringContainsString('var(--brand-accent-text)', $html);
        $this->assertStringContainsString('border-color: var(--brand-accent-text)', $html);
        $this->assertStringNotContainsString('var(--color-band)', $html);
        $this->assertStringNotContainsString('var(--brand-accent-text-on-contrast)', $html);
    }

    public function test_last_sentence_of_the_title_is_wrapped_in_em(): void
    {
        $html = $this->render(false, 4);

        $this->assertMatchesRegularExpression(
            '/<em style="font-style: italic;">Built to last\.<\/em>/',
            $html,
        );
        $this->assertStringContainsString('Trusted locally.', $html);
        $this->assertStringNotContainsString('<em style="font-style: italic;">Trusted locally.', $html);
    }

    public function test_ghost_cta_href_uses_contact_page_when_present(): void
    {
        $html = $this->render(false, 4, [], ['pagesBySlug' => ['contact' => '/get-in-touch']]);

        $this->assertStringContainsString('Talk to us', $html);
        $this->assertStringContainsString('href="/get-in-touch"', $html);
        $this->assertStringNotContainsString('href="#contact"', $html);
    }

    public function test_ghost_cta_href_falls_back_to_contact_hash(): void
    {
        $html = $this->render(false, 4, [], ['pagesBySlug' => []]);

        $this->assertStringContainsString('Talk to us', $html);
        $this->assertStringContainsString('href="#contact"', $html);
    }

    public function test_registry_accepts_contrast_and_rejects_brand_for_ink_ledger(): void
    {
        $registry = app(PageLayoutRegistry::class);
        $base = [
            'schema_version' => 1,
            'variants' => ['hero' => 'boxed-left', 'trust' => 'ink-ledger'],
            'eyebrow_policy' => 'all',
            'insert_sections' => [],
        ];

        $this->assertTrue($registry->isUsable($base + ['surfaces' => ['trust' => 'contrast']], 'home'));
        $this->assertFalse($registry->isUsable($base + ['surfaces' => ['trust' => 'brand']], 'home'));
    }

    public function test_emits_eyebrow_title_item_and_hidden_icon_markers(): void
    {
        $html = $this->render(true, 4);

        foreach ([
            'eyebrow', 'title',
            'items.0.title', 'items.0.body', 'items.0.icon',
            'items.3.title', 'items.3.body', 'items.3.icon',
        ] as $field) {
            $this->assertStringContainsString('data-editable-field="'.$field.'"', $html);
        }
        $this->assertStringNotContainsString('data-editable-field="intro"', $html);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function themeKeys(): array
    {
        return [
            '51-eden' => ['51-eden'],
            '52-hunt' => ['52-hunt'],
            '54-nh' => ['54-nh'],
            'light-archetype' => ['light-archetype'],
        ];
    }

    #[DataProvider('themeKeys')]
    public function test_accent_text_on_contrast_over_wrapper_meets_aa(string $key): void
    {
        $html = $this->render(false, 4, ['__surface' => 'contrast']);

        $this->assertStringContainsString(
            'style="color: var(--brand-accent-text-on-contrast); border-color: var(--brand-accent-text-on-contrast);"',
            $html,
        );
        $this->assertStringContainsString(
            'style="color: var(--brand-accent-text-on-contrast); font-family: var(--font-display);">01<',
            $html,
        );
        $this->assertStringContainsString(
            'style="color: var(--brand-accent-text-on-contrast); font-family: var(--font-display);">04<',
            $html,
        );

        $this->assertMatchesRegularExpression(
            '/data-svc-variant="ink-ledger"[^>]*style="background-color:\s*([^;"]+)/',
            $html,
        );
        preg_match(
            '/data-svc-variant="ink-ledger"[^>]*style="background-color:\s*([^;"]+)/',
            $html,
            $wrapperMatch,
        );
        $wrapperCss = trim($wrapperMatch[1]);
        $wrapperKey = match ($wrapperCss) {
            'var(--color-surface-contrast)' => 'surface_contrast',
            'var(--color-band)' => 'band',
            'var(--color-surface)' => 'surface',
            default => null,
        };
        $this->assertNotNull($wrapperKey, "ink-ledger wrapper used unknown token [{$wrapperCss}]");

        $path = base_path('tests/fixtures/home-themes/demo-site-themes.json');
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);
        $this->assertIsArray($decoded[$key] ?? null, "missing committed theme [{$key}]");

        $resolver = app(ThemeResolver::class);
        $tokens = $resolver->renderTokens($decoded[$key]);

        $this->assertArrayHasKey('accent_text_on_contrast', $tokens);
        $this->assertArrayHasKey($wrapperKey, $tokens);

        $ratio = $resolver->contrastRatio($tokens['accent_text_on_contrast'], $tokens[$wrapperKey]);
        $this->assertGreaterThanOrEqual(
            4.5,
            $ratio,
            "{$key} brand-accent-text-on-contrast over {$wrapperCss} must pass WCAG AA, got {$ratio}",
        );
    }
}
