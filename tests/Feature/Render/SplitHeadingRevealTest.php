<?php

namespace Tests\Feature\Render;

use App\Models\LayoutPreset;
use App\Models\Site\PageRevision;
use App\Services\Site\PageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Tests\Support\MakesClassicRenderSite;
use Tests\TestCase;

class SplitHeadingRevealTest extends TestCase
{
    use MakesClassicRenderSite;
    use RefreshDatabase;

    public function test_inertness_when_option_absent_emits_plain_heading_byte_identical_to_bare_h2(): void
    {
        $html = View::make('site.sections._split_heading', [
            'title' => 'Our Core Services',
            'class' => 'text-3xl md:text-4xl font-extrabold text-gray-900',
            'style' => 'color: var(--color-text);',
        ])->render();

        $expected = '<h2 class="text-3xl md:text-4xl font-extrabold text-gray-900" style="color: var(--color-text);">Our Core Services</h2>';

        $this->assertSame($expected, trim($html));
        $this->assertStringNotContainsString('data-split-heading', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('split-word', $html);
        $this->assertStringNotContainsString('IntersectionObserver', $html);
        $this->assertStringNotContainsString('data-split-heading-init', $html);
    }

    public function test_inertness_when_option_explicitly_false_in_section_options(): void
    {
        $html = View::make('site.sections._split_heading', [
            'section' => [
                'type' => 'team',
                'title' => 'Meet The Team',
                '__options' => [
                    'split_heading_reveal' => false,
                ],
            ],
            'class' => 'text-2xl font-bold',
        ])->render();

        $expected = '<h2 class="text-2xl font-bold">Meet The Team</h2>';

        $this->assertSame($expected, trim($html));
        $this->assertStringNotContainsString('data-split-heading', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('split-word', $html);
    }

    public function test_split_heading_when_option_true_renders_per_word_spans_and_inline_script(): void
    {
        $html = View::make('site.sections._split_heading', [
            'section' => [
                'type' => 'team',
                'title' => 'Meet Our Dedicated Team',
                '__options' => [
                    'split_heading_reveal' => true,
                ],
            ],
            'sectionIndex' => 2,
            'class' => 'text-3xl md:text-4xl font-extrabold',
            'style' => 'color: var(--color-text);',
        ])->render();

        $this->assertStringContainsString('data-split-heading', $html);
        $this->assertStringContainsString('data-split-heading-id="sh-2"', $html);

        // Word-by-word wrapping check
        $this->assertStringContainsString('<span class="split-word inline-block">Meet</span>', $html);
        $this->assertStringContainsString('<span class="split-word inline-block">Our</span>', $html);
        $this->assertStringContainsString('<span class="split-word inline-block">Dedicated</span>', $html);
        $this->assertStringContainsString('<span class="split-word inline-block">Team</span>', $html);

        // Space separation preserved between words
        $this->assertStringContainsString(
            '<span class="split-word inline-block">Meet</span> <span class="split-word inline-block">Our</span> <span class="split-word inline-block">Dedicated</span> <span class="split-word inline-block">Team</span>',
            $html,
        );

        // Inline script check
        $this->assertStringContainsString('<script>', $html);
        $this->assertStringContainsString('IntersectionObserver', $html);
        $this->assertStringContainsString('prefers-reduced-motion: reduce', $html);
        $this->assertStringContainsString('data-split-heading-init', $html);
        $this->assertStringContainsString('transitionDelay', $html);
        $this->assertStringContainsString('observer.disconnect()', $html);
        $this->assertStringContainsString('setTimeout(', $html);
        $this->assertStringContainsString('2500', $html);
    }

    public function test_failsafe_reveal_timer_unconditionally_reveals_headings(): void
    {
        $html = View::make('site.sections._split_heading', [
            'title' => 'Closing Marquee Heading',
            'splitHeadingReveal' => true,
        ])->render();

        // Script must contain unconditional failsafe reveal setTimeout(~2500ms)
        $this->assertStringContainsString('setTimeout(function () {', $html);
        $this->assertStringContainsString('reveal();', $html);
        $this->assertStringContainsString('observer.disconnect();', $html);
        $this->assertStringContainsString('}, 2500);', $html);
    }

    public function test_deterministic_id_derivation_from_section_index(): void
    {
        $html1 = View::make('site.sections._split_heading', [
            'section' => ['title' => 'Deterministic One'],
            'sectionIndex' => 5,
            'splitHeadingReveal' => true,
        ])->render();

        $html2 = View::make('site.sections._split_heading', [
            'section' => ['title' => 'Deterministic One'],
            'sectionIndex' => 5,
            'splitHeadingReveal' => true,
        ])->render();

        $this->assertStringContainsString('data-split-heading-id="sh-5"', $html1);
        $this->assertSame($html1, $html2, 'Repeated renders with the same sectionIndex must be byte-identical');

        $html3 = View::make('site.sections._split_heading', [
            'section' => ['title' => 'Deterministic Three', '__stored_index' => 9],
            'splitHeadingReveal' => true,
        ])->render();

        $this->assertStringContainsString('data-split-heading-id="sh-9"', $html3);
    }

    public function test_scope_isolation_from_ambient_page_scope_variables(): void
    {
        // Ambient page scope sets $text to theme color '#111827' and prior section might set $title
        $blade = <<<'BLADE'
@php
    $text = '#111827';
    $title = 'Leaked Previous Title';
    $heading = 'Leaked Previous Heading';
@endphp
@include('site.sections._split_heading', [
    'section' => $section,
    'class' => 'text-3xl font-bold',
])
BLADE;

        $rendered = Blade::render($blade, [
            'section' => [
                'type' => 'team',
                'title' => 'Real Section Title',
                '__options' => ['split_heading_reveal' => true],
            ],
            'sectionIndex' => 1,
        ]);

        $this->assertStringContainsString('<span class="split-word inline-block">Real</span>', $rendered);
        $this->assertStringContainsString('<span class="split-word inline-block">Section</span>', $rendered);
        $this->assertStringContainsString('<span class="split-word inline-block">Title</span>', $rendered);
        $this->assertStringNotContainsString('#111827', $rendered);
        $this->assertStringNotContainsString('Leaked Previous Title', $rendered);
        $this->assertStringNotContainsString('Leaked Previous Heading', $rendered);
    }

    public function test_stamped_section_options_outrank_ambient_options(): void
    {
        // 1. Ambient $options['split_heading_reveal'] = false must NOT override stamped true
        $bladeOn = <<<'BLADE'
@php
    $options = ['split_heading_reveal' => false];
@endphp
@include('site.sections._split_heading', [
    'section' => $section,
])
BLADE;

        $renderedOn = Blade::render($bladeOn, [
            'section' => [
                'title' => 'Active Split Heading',
                '__options' => ['split_heading_reveal' => true],
            ],
            'sectionIndex' => 0,
        ]);
        $this->assertStringContainsString('data-split-heading', $renderedOn);
        $this->assertStringContainsString('<span class="split-word inline-block">Active</span>', $renderedOn);

        // 2. Ambient $options['split_heading_reveal'] = true must NOT override stamped false
        $bladeOff = <<<'BLADE'
@php
    $options = ['split_heading_reveal' => true];
@endphp
@include('site.sections._split_heading', [
    'section' => $section,
])
BLADE;

        $renderedOff = Blade::render($bladeOff, [
            'section' => [
                'title' => 'Disabled Split Heading',
                '__options' => ['split_heading_reveal' => false],
            ],
            'sectionIndex' => 0,
        ]);
        $this->assertStringNotContainsString('data-split-heading', $renderedOff);
        $this->assertStringNotContainsString('<script', $renderedOff);
    }

    public function test_escaping_hostile_words_and_special_characters(): void
    {
        $hostileTitle = '<script>alert("xss")</script> & "Dangerous" \'Quotes\' <img src=x onerror=alert(1)>';

        $html = View::make('site.sections._split_heading', [
            'title' => $hostileTitle,
            'splitHeadingReveal' => true,
            'class' => 'text-3xl font-bold',
        ])->render();

        // Check each hostile word is properly escaped inside its span
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&amp;', $html);
        $this->assertStringContainsString('&quot;Dangerous&quot;', $html);
        $this->assertStringContainsString('&#039;Quotes&#039;', $html);
        $this->assertStringContainsString('&lt;img', $html);
        $this->assertStringContainsString('onerror=alert(1)&gt;', $html);

        // Crucial security check: the raw script or img tags must not appear unescaped anywhere in the heading
        $this->assertStringNotContainsString('<span><script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);

        // Content-to-script boundary: user-supplied hostile title text must NOT be interpolated into the JS script block
        $scriptStart = strpos($html, '<script>');
        $this->assertNotFalse($scriptStart);
        $scriptContent = substr($html, $scriptStart);

        $this->assertStringNotContainsString('alert("xss")', $scriptContent);
        $this->assertStringNotContainsString('onerror=alert(1)', $scriptContent);
        $this->assertStringNotContainsString('Dangerous', $scriptContent);
    }

    public function test_reduced_motion_and_no_io_fallback_is_default_visible(): void
    {
        $html = View::make('site.sections._split_heading', [
            'title' => 'Visible By Default Heading',
            'splitHeadingReveal' => true,
        ])->render();

        // Extract heading HTML (before script)
        $scriptPos = strpos($html, '<script>');
        $headingHtml = $scriptPos !== false ? substr($html, 0, $scriptPos) : $html;

        // SSR default state must be visible — no opacity:0 or visibility:hidden in HTML / inline styles
        $this->assertStringNotContainsString('opacity: 0', $headingHtml);
        $this->assertStringNotContainsString('opacity:0', $headingHtml);
        $this->assertStringNotContainsString('visibility: hidden', $headingHtml);
        $this->assertStringNotContainsString('display: none', $headingHtml);

        // Script must verify prefers-reduced-motion and IntersectionObserver before altering styles
        $this->assertStringContainsString('if (window.matchMedia && window.matchMedia(\'(prefers-reduced-motion: reduce)\').matches) return;', $html);
        $this->assertStringContainsString('if (!(\'IntersectionObserver\' in window)) return;', $html);
    }

    public function test_idempotence_marker_prevents_duplicate_initialization(): void
    {
        $html = View::make('site.sections._split_heading', [
            'title' => 'Idempotent Heading',
            'splitHeadingReveal' => true,
        ])->render();

        $this->assertStringContainsString('if (!heading || heading.hasAttribute(\'data-split-heading-init\')) return;', $html);
        $this->assertStringContainsString('heading.setAttribute(\'data-split-heading-init\', \'true\');', $html);
    }

    public function test_tag_flexibility_and_editor_attributes(): void
    {
        $html = View::make('site.sections._split_heading', [
            'tag' => 'h1',
            'title' => 'Custom H1 Heading',
            'class' => 'text-4xl font-extrabold',
            'attrs' => 'data-editable="page.1.section.0.title" data-editable-type="plain"',
            'splitHeadingReveal' => false,
        ])->render();

        $expected = '<h1 class="text-4xl font-extrabold" data-editable="page.1.section.0.title" data-editable-type="plain">Custom H1 Heading</h1>';
        $this->assertSame($expected, trim($html));

        $htmlSplit = View::make('site.sections._split_heading', [
            'tag' => 'h3',
            'title' => 'Split H3 Heading',
            'class' => 'text-2xl font-bold',
            'attrs' => 'data-editable="page.1.section.0.title"',
            'splitHeadingReveal' => true,
        ])->render();

        $this->assertStringStartsWith('<h3 class="text-2xl font-bold" data-editable="page.1.section.0.title" data-split-heading data-split-heading-id="sh-', trim($htmlSplit));
        $this->assertStringContainsString('</h3>', $htmlSplit);
    }

    public function test_empty_heading_emits_empty_tag_without_script(): void
    {
        $htmlOff = View::make('site.sections._split_heading', [
            'title' => '',
            'class' => 'text-3xl',
            'splitHeadingReveal' => false,
        ])->render();

        $this->assertSame('<h2 class="text-3xl"></h2>', trim($htmlOff));

        $htmlOn = View::make('site.sections._split_heading', [
            'title' => '   ',
            'class' => 'text-3xl',
            'splitHeadingReveal' => true,
        ])->render();

        $this->assertSame('<h2 class="text-3xl"></h2>', trim($htmlOn));
        $this->assertStringNotContainsString('<script', $htmlOn);
    }

    public function test_minimal_test_only_consumer_rendering(): void
    {
        // Minimal test-only consumer template rendering the partial with section data
        $blade = <<<'BLADE'
@php
    $editor = function ($field, $type) { return ' data-editable="'.$field.'"'; };
@endphp
<div class="team-section">
    @include('site.sections._split_heading', [
        'section' => $section,
        'class' => 'text-3xl md:text-4xl font-extrabold',
        'style' => 'color: var(--color-text);',
        'attrs' => $editor('title', 'plain'),
    ])
</div>
BLADE;

        // Render with split_heading_reveal = false
        $renderedOff = Blade::render($blade, [
            'section' => [
                'type' => 'team',
                'title' => 'Our Expert Team',
                '__options' => ['split_heading_reveal' => false],
            ],
        ]);

        $this->assertStringContainsString('<h2 class="text-3xl md:text-4xl font-extrabold" style="color: var(--color-text);" data-editable="title">Our Expert Team</h2>', $renderedOff);
        $this->assertStringNotContainsString('data-split-heading', $renderedOff);
        $this->assertStringNotContainsString('<script', $renderedOff);

        // Render with split_heading_reveal = true
        $renderedOn = Blade::render($blade, [
            'section' => [
                'type' => 'team',
                'title' => 'Our Expert Team',
                '__options' => ['split_heading_reveal' => true],
            ],
        ]);

        $this->assertStringContainsString('data-split-heading', $renderedOn);
        $this->assertStringContainsString('data-editable="title"', $renderedOn);
        $this->assertStringContainsString('<span class="split-word inline-block">Our</span> <span class="split-word inline-block">Expert</span> <span class="split-word inline-block">Team</span>', $renderedOn);
        $this->assertStringContainsString('<script>', $renderedOn);
    }

    protected function setUp(): void
    {
        parent::setUp();
        View::addLocation(base_path('tests/Fixtures/views'));
    }

    public function test_real_render_path_with_page_renderer_and_layout_preset(): void
    {
        $keys = [
            'primary_color' => '#1e40af', 'accent_color' => '#f97316', 'tertiary_color' => '#0f766e',
            'surface_color' => '#ffffff', 'surface_alt_color' => '#f5f5f5', 'border_color' => '#e5e7eb',
            'text_color' => '#111827', 'text_muted_color' => '#6b7280',
        ];
        [$site, $home] = $this->makeClassicSite($keys);

        $site->update(['home_layout' => 'motion-preset']);
        LayoutPreset::factory()->for($site)->active()->create([
            'page_kind' => 'home',
            'key' => 'motion-preset',
            'recipe' => [
                'schema_version' => 1,
                'variants' => ['team' => 'split-probe'],
                'eyebrow_policy' => 'all',
                'options' => ['split_heading_reveal' => true],
            ],
        ]);

        PageRevision::find($home->published_revision_id)->update([
            'content_data' => [
                'sections' => [
                    ['type' => 'hero', 'title' => 'Welcome to Acme'],
                    ['type' => 'team', 'title' => 'Meet Our Dedicated Experts'],
                ],
            ],
        ]);

        $html = app(PageRenderer::class)->render($site->fresh(), $home->id, mode: 'public');

        // 1. Heading text must be the SECTION title on a real page, NOT the theme text color '#111827' (guard)
        $this->assertStringContainsString('<span class="split-word inline-block">Meet</span>', $html);
        $this->assertStringContainsString('<span class="split-word inline-block">Our</span>', $html);
        $this->assertStringContainsString('<span class="split-word inline-block">Dedicated</span>', $html);
        $this->assertStringContainsString('<span class="split-word inline-block">Experts</span>', $html);
        $this->assertStringNotContainsString('<span class="split-word inline-block">#111827</span>', $html);

        // 2. Deterministic ID presence derived from section index (guard)
        $this->assertStringContainsString('data-split-heading-id="sh-1"', $html);

        // 3. Failsafe presence (guard)
        $this->assertStringContainsString('setTimeout(', $html);
        $this->assertStringContainsString('2500', $html);
        $this->assertStringContainsString('observer.disconnect()', $html);
    }
}
