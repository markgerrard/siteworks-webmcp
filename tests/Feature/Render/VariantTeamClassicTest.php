<?php

namespace Tests\Feature\Render;

use App\Models\BusinessProfile;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Models\SiteMedia;
use App\Services\Site\PageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class VariantTeamClassicTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $sectionOverrides
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function vars(bool $markers = false, array $sectionOverrides = [], array $extra = []): array
    {
        $hasMembersOverride = array_key_exists('members', $sectionOverrides);
        $members = $hasMembersOverride ? $sectionOverrides['members'] : [
            [
                'name' => 'Jane Doe',
                'role' => 'Managing Director',
                'bio' => 'Over 15 years leading high-profile construction projects.',
                'image_id' => 101,
            ],
            [
                'name' => 'John Smith',
                'role' => 'Lead Architect',
                'bio' => 'RIBA chartered architect specialising in contemporary builds.',
                'image_id' => 102,
                'alternate_image_id' => 103,
            ],
            [
                'name' => 'Alice Johnson',
                'role' => 'Project Manager',
                'bio' => 'Oversees daily on-site operations and quality standards.',
            ],
        ];
        unset($sectionOverrides['members']);

        $media101 = new SiteMedia(['url' => 'https://example.test/media/jane.jpg', 'alt_text' => 'Jane portrait']);
        $media101->id = 101;
        $media102 = new SiteMedia(['url' => 'https://example.test/media/john.jpg', 'alt_text' => 'John portrait']);
        $media102->id = 102;
        $media103 = new SiteMedia(['url' => 'https://example.test/media/john-fun.jpg', 'alt_text' => 'John alternate portrait']);
        $media103->id = 103;

        $defaultMediaById = collect([
            101 => $media101,
            102 => $media102,
            103 => $media103,
        ]);

        $section = array_merge([
            'type' => 'team',
            'variant' => 'classic',
            'title' => 'Meet Our Leadership Team',
            'eyebrow' => 'Our People',
            'intro' => 'Experienced professionals dedicated to exceptional craftsmanship.',
        ], $sectionOverrides);

        if ($hasMembersOverride) {
            if ($members !== null) {
                $section['members'] = $members;
            }
        } else {
            $section['members'] = $members;
        }

        return array_merge([
            'section' => $section,
            'sectionIndex' => 1,
            'pageId' => 42,
            'mode' => 'public',
            'emitMarkers' => $markers,
            'emitFormMarkers' => false,
            'schema' => [],
            'theme' => [],
            'profile' => ['watermark_enabled' => false],
            'mediaById' => $defaultMediaById,
        ], $extra);
    }

    private function render(bool $markers = false, array $sectionOverrides = [], array $extra = []): string
    {
        return View::make('site.sections.team', $this->vars($markers, $sectionOverrides, $extra))->render();
    }

    public function test_renders_team_members_with_portrait_grid_and_name_role_bio(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('data-svc-variant="classic"', $html);
        $this->assertStringContainsString('site-shell-container', $html);
        $this->assertStringContainsString('site-section-spacing', $html);

        // Header copy
        $this->assertStringContainsString('Meet Our Leadership Team', $html);
        $this->assertStringContainsString('Our People', $html);
        $this->assertStringContainsString('Experienced professionals dedicated to exceptional craftsmanship.', $html);

        // Members copy
        $this->assertStringContainsString('Jane Doe', $html);
        $this->assertStringContainsString('Managing Director', $html);
        $this->assertStringContainsString('Over 15 years leading high-profile construction projects.', $html);

        $this->assertStringContainsString('John Smith', $html);
        $this->assertStringContainsString('Lead Architect', $html);
        $this->assertStringContainsString('RIBA chartered architect specialising in contemporary builds.', $html);

        $this->assertStringContainsString('Alice Johnson', $html);
        $this->assertStringContainsString('Project Manager', $html);
        $this->assertStringContainsString('Oversees daily on-site operations and quality standards.', $html);

        // Imagery has 20px radius styling
        $this->assertStringContainsString('border-radius: 20px', $html);
        $this->assertStringContainsString('https://example.test/media/jane.jpg?v=101', $html);
    }

    public function test_renders_pure_css_hover_alternate_image_with_no_scripts(): void
    {
        $html = $this->render();

        // John Smith has primary 102 and alternate 103
        $this->assertStringContainsString('https://example.test/media/john.jpg?v=102', $html);
        $this->assertStringContainsString('https://example.test/media/john-fun.jpg?v=103', $html);

        // Pure CSS hover swap classes
        $this->assertStringContainsString('group-hover:opacity-0', $html);
        $this->assertStringContainsString('opacity-0 group-hover:opacity-100', $html);

        // Binding rule: NO inline scripts
        $this->assertStringNotContainsString('<script', $html);
    }

    public function test_initials_placeholder_when_image_is_missing(): void
    {
        $html = $this->render(false, [
            'members' => [
                ['name' => 'Jane Doe', 'role' => 'Architect'],
                ['name' => 'Alice', 'role' => 'Engineer'],
                ['name' => 'Robert Bruce Banner', 'role' => 'Scientist'],
            ],
        ]);

        // Initials placeholders rendered
        $this->assertStringContainsString('>JD</span>', $html);
        $this->assertStringContainsString('>A</span>', $html);
        $this->assertStringContainsString('>RB</span>', $html);

        // No image tags when all members lack images
        $this->assertStringNotContainsString('<img', $html);
    }

    public function test_empty_members_array_renders_nothing(): void
    {
        $emptyMembers = $this->render(false, ['members' => []]);
        $this->assertSame('', trim($emptyMembers));

        $noMembersKey = $this->render(false, ['members' => null, 'items' => null]);
        $this->assertSame('', trim($noMembersKey));
    }

    public function test_grid_columns_option_2_3_4(): void
    {
        // Default 3 cols
        $defaultHtml = $this->render();
        $this->assertStringContainsString('lg:grid-cols-3', $defaultHtml);

        // 2 cols
        $twoColHtml = $this->render(false, ['__options' => ['grid_columns' => 2]]);
        $this->assertStringContainsString('sm:grid-cols-2', $twoColHtml);
        $this->assertStringNotContainsString('lg:grid-cols-3', $twoColHtml);
        $this->assertStringNotContainsString('lg:grid-cols-4', $twoColHtml);

        // 4 cols
        $fourColHtml = $this->render(false, ['__options' => ['grid_columns' => 4]]);
        $this->assertStringContainsString('lg:grid-cols-4', $fourColHtml);
    }

    public function test_surface_tokens_used(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('background-color: var(--color-surface)', $html);
        $this->assertStringContainsString('color: var(--color-text)', $html);
    }

    public function test_escaping_prevents_xss_in_all_fields(): void
    {
        $xssName = 'Jane <script>alert("xss")</script>';
        $xssRole = 'Director <img src=x onerror=alert(1)>';
        $xssBio = 'Bio <b>bold</b> & "quotes"';
        $xssTitle = 'Our <script>alert("title")</script> Team';
        $xssEyebrow = 'Eyebrow <img src=x>';
        $xssIntro = 'Intro <script>alert("intro")</script>';

        $html = $this->render(false, [
            'title' => $xssTitle,
            'eyebrow' => $xssEyebrow,
            'intro' => $xssIntro,
            'members' => [
                [
                    'name' => $xssName,
                    'role' => $xssRole,
                    'bio' => $xssBio,
                ],
            ],
        ]);

        $this->assertStringNotContainsString('<script>alert("xss")</script>', $html);
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        $this->assertStringNotContainsString('<b>bold</b>', $html);
        $this->assertStringNotContainsString('<script>alert("title")</script>', $html);
        $this->assertStringNotContainsString('<img src=x>', $html);
        $this->assertStringNotContainsString('<script>alert("intro")</script>', $html);

        $this->assertStringContainsString(e($xssName), $html);
        $this->assertStringContainsString(e($xssRole), $html);
        $this->assertStringContainsString(e($xssBio), $html);
        $this->assertStringContainsString(e($xssTitle), $html);
        $this->assertStringContainsString(e($xssEyebrow), $html);
        $this->assertStringContainsString(e($xssIntro), $html);
    }

    public function test_editor_markers_emitted_in_admin_edit_mode(): void
    {
        $html = $this->render(true);

        $this->assertStringContainsString('data-editable-field="eyebrow"', $html);
        $this->assertStringContainsString('data-editable-field="title"', $html);
        $this->assertStringContainsString('data-editable-field="intro"', $html);

        $this->assertStringContainsString('data-editable-field="members.0.name"', $html);
        $this->assertStringContainsString('data-editable-field="members.0.role"', $html);
        $this->assertStringContainsString('data-editable-field="members.0.bio"', $html);
        $this->assertStringContainsString('data-editable-field="members.0.image_id"', $html);

        $this->assertStringContainsString('data-editable-field="members.1.name"', $html);
        $this->assertStringContainsString('data-editable-field="members.2.name"', $html);
    }

    public function test_suppressed_eyebrow_emits_hidden_marker(): void
    {
        $html = $this->render(true, ['__suppress_eyebrow' => true]);

        $this->assertMatchesRegularExpression(
            '/<span class="hidden"[^>]*data-editable-field="eyebrow"/',
            $html,
        );
        $this->assertStringNotContainsString('Our People</span>', $html);
        $this->assertStringContainsString('data-editable-field="title"', $html);
    }

    public function test_fallback_support_for_items_key_in_section_data(): void
    {
        $html = $this->render(true, [
            'members' => null,
            'items' => [
                ['name' => 'Sam Alex', 'role' => 'Consultant', 'bio' => 'Consulting.'],
            ],
        ]);

        $this->assertStringContainsString('Sam Alex', $html);
        $this->assertStringContainsString('Consultant', $html);
        $this->assertStringContainsString('data-editable-field="items.0.name"', $html);
        $this->assertStringContainsString('data-editable-field="items.0.role"', $html);
        $this->assertStringContainsString('data-editable-field="items.0.bio"', $html);
    }

    public function test_cross_tenant_media_is_never_rendered_via_page_renderer(): void
    {
        // Site A has portrait media
        $siteA = Site::factory()->create();
        $mediaA = SiteMedia::factory()->for($siteA)->create([
            'url' => 'https://example.test/site-a/secret-portrait.jpg',
            'alt_text' => 'Secret Portrait',
        ]);

        // Site B has a team section referencing Site A's media id
        $siteB = Site::factory()->create();
        $pageB = GeneratedPage::factory()->for($siteB)->create(['page_type' => 'about']);
        $revB = PageRevision::factory()->for($pageB, 'page')->create([
            'content_data' => [
                'sections' => [
                    [
                        'type' => 'team',
                        'variant' => 'classic',
                        'title' => 'Our Team',
                        'members' => [
                            [
                                'name' => 'Foreign Staff',
                                'role' => 'Infiltrator',
                                'image_id' => $mediaA->id,
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $pageB->update(['published_revision_id' => $revB->id]);

        $versionB = SiteVersion::create([
            'site_id' => $siteB->id,
            'version' => 1,
            'composition' => ['nav' => ['items' => []], 'footer' => ['columns' => []]],
            'page_revisions' => [['page_id' => $pageB->id, 'revision_id' => $revB->id]],
            'published_at' => now(),
        ]);
        SiteVersionCurrent::create(['site_id' => $siteB->id, 'version_id' => $versionB->id, 'updated_at' => now()]);

        BusinessProfile::factory()->for($siteB)->create([
            'profile_data' => ['watermark_enabled' => false],
        ]);

        $renderer = app(PageRenderer::class);
        $renderedHtml = $renderer->render($siteB, $pageB->id, mode: 'public');

        // Site A's image URL MUST NOT appear on Site B
        $this->assertStringNotContainsString('secret-portrait.jpg', $renderedHtml);
        $this->assertStringNotContainsString('Secret Portrait', $renderedHtml);

        // Degrades gracefully to initials placeholder
        $this->assertStringContainsString('>FS</span>', $renderedHtml);
    }
}
