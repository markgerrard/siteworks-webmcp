<?php

namespace Tests\Feature\Render;

use App\Enums\PageKind;
use App\Models\BusinessProfile;
use App\Models\GeneratedPage;
use App\Models\LayoutPreset;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\FormChrome;
use App\Services\Site\PageRenderer;
use App\Support\FormFieldDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LeadFormQaMatrixTest extends TestCase
{
    use RefreshDatabase;

    /** Three representative home lead_form schemas + two synthetic edges. */
    public static function schemas(): array
    {
        return [
            'eden' => [[
                ['name' => 'service_type', 'type' => 'select', 'label' => 'Service Required', 'options' => ['Kitchen', 'Extension'], 'required' => true],
                ['name' => 'property_type', 'type' => 'radio', 'label' => 'Property Type', 'options' => ['House', 'Flat', 'Commercial'], 'required' => true],
                ['name' => 'budget_band', 'type' => 'select', 'label' => 'Approximate Budget', 'options' => ['<10k', '10-25k']],
            ], ['Free no-obligation quote', 'London renovation specialists', 'Fully insured']],
            'hunt' => [[
                ['name' => 'service_interest', 'type' => 'select', 'label' => 'Service Interest', 'options' => ['Build', 'Invest'], 'required' => true],
                ['name' => 'investor_type', 'type' => 'radio', 'label' => 'Investor Type', 'options' => ['Private', 'Institutional'], 'required' => true],
                ['name' => 'budget_band', 'type' => 'select', 'label' => 'Budget', 'options' => ['<250k', '250k+']],
            ], ['Institutional-scale builds', 'North West England', 'High-yield opportunities']],
            'nh-civils' => [[
                ['name' => 'service_type', 'type' => 'select', 'label' => 'Service', 'options' => ['Demolition', 'Groundworks'], 'required' => true],
                ['name' => 'job_type', 'type' => 'radio', 'label' => 'Job Type', 'options' => ['Domestic', 'Commercial'], 'required' => true],
                ['name' => 'postcode', 'type' => 'text', 'label' => 'Postcode', 'required' => true],
            ], ['Experienced crews', 'Fully insured', 'Site left clean']],
            'eleven-fields' => [ChromeCharacterisationSnapshotTest::fullFormFields(), []],
            'no-benefits' => [[['name' => 'when', 'type' => 'date', 'label' => 'When']], []],
        ];
    }

    public static function compositions(): array
    {
        return [
            'centered' => ['centered', ['form_input_style' => 'boxed', 'form_trust_style' => 'chips-under-button']],
            'phone-ledger' => ['phone-ledger', ['form_trust_style' => 'icon-box']],
            'inline-band' => ['inline-band', ['form_input_style' => 'soft-filled']],
            'inline-editorial' => ['inline-editorial', ['form_input_style' => 'underline', 'form_surface' => 'panel-inverted', 'form_trust_style' => 'inline-piped', 'form_submit_style' => 'auto-arrow']],
            'split-screen' => ['split-screen', ['form_input_style' => 'soft-filled', 'form_trust_style' => 'pill-badges']],
            'image-backed' => ['image-backed', ['form_input_style' => 'boxed', 'form_trust_style' => 'tick-list']],
            'centered-tiles' => ['centered', ['form_input_style' => 'boxed', 'form_trust_style' => 'chips-under-button', 'form_radio_style' => 'tiles', 'form_submit_style' => 'auto-arrow']],
            'inline-band-dark' => ['inline-band', ['form_input_style' => 'boxed', 'form_surface' => 'card-on-dark']],
            'editorial-ledger' => ['editorial-ledger', ['form_input_style' => 'underline', 'form_submit_style' => 'auto']],
        ];
    }

    public static function matrix(): array
    {
        $rows = [];
        foreach (self::compositions() as $ck => [$variant, $options]) {
            foreach (self::schemas() as $sk => [$fields, $benefits]) {
                foreach (['trades-bold', 'professional-clean', 'local-friendly'] as $theme) {
                    $rows["{$ck} × {$sk} × {$theme}"] = [$variant, $options, $fields, $benefits, $theme];
                }
            }
        }

        return $rows;
    }

    #[DataProvider('matrix')]
    public function test_every_composition_holds_the_form_contract(string $variant, array $options, array $fields, array $benefits, string $theme): void
    {
        [$site, $page] = $this->homeWithLeadForm($theme, $fields, $benefits, $variant, $options);
        $html = app(PageRenderer::class)->render($site, $page->id);
        $formId = 'lf-'.$page->id.'-1';

        // the recipe reached the section (registry → stampSection → dispatch), not a stored variant key.
        // image-backed with no hero/band rows falls back to identity (no variant marker).
        if ($variant === 'image-backed') {
            $this->assertStringNotContainsString('data-lead-form-variant=', $html);
        } else {
            $this->assertStringContainsString('data-lead-form-variant="'.$variant.'"', $html);
        }
        $this->assertStringContainsString('name="website"', $html, 'honeypot');
        $this->assertStringContainsString('name="page_type"', $html);
        $this->assertSame(1, substr_count($html, 'name="name"'));
        $this->assertSame(1, substr_count($html, 'name="email"'));
        $this->assertSame(1, substr_count($html, 'type="submit"'));
        $this->assertStringContainsString('>Send it<', $html, 'submit label');

        // exactly one group per operator field up to the clamp; radios repeat the name once per option
        $operator = array_values(array_filter($fields, fn ($f) => $f['name'] !== 'message'));
        $kept = array_slice($operator, 0, FormFieldDefinition::MAX_FIELDS);
        foreach ($kept as $f) {
            $expected = $f['type'] === 'radio' ? count($f['options']) : 1;
            $this->assertSame($expected, substr_count($html, 'name="'.$f['name'].'"'), $f['name']);
        }
        foreach (array_slice($operator, FormFieldDefinition::MAX_FIELDS) as $dropped) {
            $this->assertStringNotContainsString('name="'.$dropped['name'].'"', $html, 'clamped: '.$dropped['name']);
        }
        $this->assertSame(1, substr_count($html, 'name="message"'));

        if (($options['form_input_style'] ?? 'boxed') !== 'boxed') {
            $this->assertStringNotContainsString('border-gray-300', $this->formOnly($html));
        }
        $hasRadio = collect($fields)->contains(fn (array $f): bool => ($f['type'] ?? '') === 'radio');
        if (($options['form_radio_style'] ?? null) === 'tiles' && $hasRadio) {
            $this->assertStringContainsString(FormChrome::LEAD_RADIO_TILE, $this->formOnly($html));
        }
        if (($options['form_submit_style'] ?? null) === 'auto-arrow') {
            $form = $this->formOnly($html);
            $this->assertStringContainsString(FormChrome::SUBMIT_AUTO_ARROW, $form);
            $this->assertStringContainsString('<span aria-hidden="true">→</span>', $form);
        }
        if ($variant === 'inline-band' && ($options['form_surface'] ?? null) === 'card-on-dark') {
            $form = $this->formOnly($html);
            $this->assertStringNotContainsString('text-gray-800', $form);
            // Dark selectClass appends [&>option]:text-gray-900; Blade e() HTML-encodes the selector.
            $withoutOptionInk = preg_replace('/\[[^\]]+]:text-gray-900/', '', $form) ?? $form;
            $this->assertStringNotContainsString('text-gray-900', $withoutOptionInk);
        }
        $trust = $this->trustOnly($html);
        if ($benefits === [] || $variant === 'inline-band' || $variant === 'editorial-ledger') {
            $this->assertNull($trust, 'no trust block expected');
        } elseif ($variant === 'image-backed') {
            $this->assertNull($trust, 'identity fallback has no data-trust-style');
            foreach ($benefits as $b) {
                $this->assertStringContainsString(e($b), $html);
            }
        } else {
            $this->assertNotNull($trust);
            foreach ($benefits as $b) {
                $this->assertStringContainsString(e($b), $trust);
            }
            if (isset($options['form_trust_style'])) {
                $this->assertStringContainsString('data-trust-style="'.$options['form_trust_style'].'"', $trust, 'trust style from __options');
            }
        }

        // admin-edit marker SET equals the classic path's for the same content (spec §Shared form core)
        [$classicSite, $classicPage] = $this->homeWithLeadForm($theme, $fields, $benefits, null, []);
        $edit = app(PageRenderer::class)->render($site, $page->id, 'admin-edit', formPanel: true);
        $classic = app(PageRenderer::class)->render($classicSite, $classicPage->id, 'admin-edit', formPanel: true);
        $this->assertSame($this->markerSet($classic), $this->markerSet($edit), 'editor marker set differs from classic');
        $this->assertSame(1, substr_count($edit, 'data-form-editable='));
        $this->assertStringContainsString('data-form-kind="lead_form"', $edit);
    }

    /** Sorted, de-duplicated data-editable-field values of the lead_form section (path contains ".section.1."). */
    private function markerSet(string $html): array
    {
        preg_match_all('/data-editable="page\.\d+\.section\.1\.([^"]+)" data-editable-type="[^"]+" data-editable-section-type="lead_form" data-editable-field="([^"]+)"/', $html, $m);
        $set = array_values(array_unique($m[2]));
        sort($set);

        return $set;
    }

    private function formOnly(string $html): string
    {
        $start = strpos($html, '<form');
        $end = strpos($html, '</form>', $start);

        return substr($html, $start, $end - $start);
    }

    /** The trust partial's root element (it carries data-trust-style) through the end of its list; null when absent. */
    private function trustOnly(string $html): ?string
    {
        $start = strpos($html, 'data-trust-style="');
        if ($start === false) {
            return null;
        }
        $tag = substr($html, strrpos(substr($html, 0, $start), '<') + 1, 2) === 'ul' ? '</ul>' : '</p>';
        $end = strpos($html, $tag, $start);

        return substr($html, $start, $end - $start);
    }

    /**
     * Published home with hero + one stored lead_form (index 1). $variant === null ⇒ classic (no LayoutPreset row).
     *
     * @return array{0: Site, 1: GeneratedPage}
     */
    private function homeWithLeadForm(string $theme, array $fields, array $benefits, ?string $variant, array $options): array
    {
        $site = Site::factory()->create([
            'business_name' => 'Acme',
            'theme' => $theme,
            'home_layout' => $variant === null ? 'classic' : 'forms-qa',
        ]);
        if ($variant !== null) {
            LayoutPreset::factory()->for($site)->active()->create([
                'page_kind' => 'home',
                'key' => 'forms-qa',
                'recipe' => [
                    'label' => 'Forms QA', 'description' => 'matrix row', 'schema_version' => 1,
                    'variants' => ['lead_form' => $variant], 'options' => $options,
                    'eyebrow_policy' => 'all', 'eyebrow_sections' => [], 'insert_sections' => [],
                ],
            ]);
        }
        BusinessProfile::factory()->for($site)->create(['profile_data' => [
            'contact' => ['phones' => ['0161 123 4567'], 'emails' => ['hello@acme.test']],
            'geo' => ['service_area' => 'Manchester'],
        ]]);
        $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home', 'kind' => PageKind::Core]);
        $rev = PageRevision::factory()->for($page, 'page')->create(['content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Welcome'],
            ['type' => 'lead_form', 'title' => 'Get a quote', 'intro' => 'Tell us about the job.', 'eyebrow' => 'Quote',
                'submit_label' => 'Send it', 'extra_fields' => $fields, 'benefits' => $benefits],
        ]]]);
        $page->update(['published_revision_id' => $rev->id]);
        $version = SiteVersion::create([
            'site_id' => $site->id, 'version' => 1,
            'composition' => [
                'nav' => ['items' => []], 'footer' => ['columns' => [], 'show_credit' => true],
                'theme' => ['key' => $theme, 'primary_override' => null, 'accent_override' => null],
                'homepage_page_id' => $page->id,
            ],
            'page_revisions' => [['page_id' => $page->id, 'revision_id' => $rev->id]],
            'published_at' => now(),
        ]);
        SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

        return [$site, $page];
    }
}
