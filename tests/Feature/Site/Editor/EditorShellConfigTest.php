<?php

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteDraft;
use App\Models\User;

beforeEach(function () {
    $this->withoutVite();
});

/**
 * @return array<string, mixed>
 */
function editorShellConfig(string $html): array
{
    preg_match("/window\\.__siteworks_editor_shell_config__ = JSON\\.parse\\('(.*)'\\);/", $html, $matches);

    expect($matches)->toHaveKey(1);

    $json = json_decode('"'.$matches[1].'"', true, 512, JSON_THROW_ON_ERROR);

    return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
}

it('seeds editor revisions epochs capabilities catalog and operation urls', function () {
    config([
        'editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true,
        'editor.agent_tools.roles' => ['staff'],
    ]);

    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $publishedPage = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $publishedRevision = PageRevision::factory()->for($publishedPage, 'page')->create();
    $publishedPage->forceFill([
        'published_revision_id' => $publishedRevision->id,
        'structure_epoch' => 3,
    ])->save();

    $draftPage = GeneratedPage::factory()->for($site)->create(['page_type' => 'about']);
    $draftPublishedRevision = PageRevision::factory()->for($draftPage, 'page')->create();
    $draftRevision = PageRevision::factory()->for($draftPage, 'page')->create();
    $draftPage->forceFill([
        'published_revision_id' => $draftPublishedRevision->id,
        'draft_revision_id' => $draftRevision->id,
        'structure_epoch' => 7,
    ])->save();

    $archivedPage = GeneratedPage::factory()->for($site)->create(['archived_at' => now()]);
    $archivedRevision = PageRevision::factory()->for($archivedPage, 'page')->create();
    $archivedPage->forceFill(['published_revision_id' => $archivedRevision->id])->save();

    SiteDraft::query()->create([
        'site_id' => $site->id,
        'composition' => [],
        'updated_by_user_id' => $user->id,
        'updated_at' => now(),
        'admin_revision' => 11,
    ]);

    $response = $this->actingAs($user)->get(route('site.editor-shell', [$site, $publishedPage]));
    $response->assertOk();
    $config = editorShellConfig($response->getContent());

    expect($config['currentRevisionIds'])->toBe([
        (string) $publishedPage->id => $publishedRevision->id,
        (string) $draftPage->id => $draftRevision->id,
    ])->and($config['structureEpochs'])->toBe([
        (string) $publishedPage->id => 3,
        (string) $draftPage->id => 7,
    ])->and($config['compositionRevision'])->toBe(11)
        ->and($config['compositionRevision'])->toBeInt()
        ->and($config['capabilities'])->toBe(['edit', 'publish', 'media', 'agent_tools', 'editor_ui'])
        ->and($config['sectionCatalog']['hero'])->toBe([
            'label' => 'Hero',
            'page_types' => ['*'],
            'singleton' => true,
        ]);

    foreach ($config['sectionCatalog'] as $definition) {
        expect(array_keys($definition))->toBe(['label', 'page_types', 'singleton']);
    }

    expect($config['formDefinitionUrl'])->toContain("/sites/{$site->id}/pages/0/form/0")
        ->and($config['formUpdateUrl'])->toContain("/sites/{$site->id}/pages/0/form/0")
        ->and($config['operationUrl'])->toBe("/sites/{$site->id}/operations/__operation__")
        ->and($config['previewUrlUrl'])->toContain("/sites/{$site->id}/pages/0/preview-url")
        ->and($config['structureUrl'])->toContain("/sites/{$site->id}/pages/0/structure")
        ->and($config['sectionsUrl'])->toContain("/sites/{$site->id}/pages/0/sections")
        ->and($config['brandContextUrl'])->toBe("/sites/{$site->id}/brand-context")
        ->and($config['imageVersionsUrl'])->toBe("/sites/{$site->id}/image-versions")
        ->and($config['jobStatusUrl'])->toBe("/sites/{$site->id}/jobs/0")
        ->and($config['generateImageUrl'])->toBe("/sites/{$site->id}/generate/image")
        ->and($config['generateHeroUrl'])->toBe("/sites/{$site->id}/generate/hero")
        ->and($config['generateLogoUrl'])->toBe("/sites/{$site->id}/generate/logo")
        ->and($config['selectLogoUrl'])->toBe("/sites/{$site->id}/logo/select")
        ->and($config['restoreImageVersionUrl'])->toBe("/sites/{$site->id}/image-versions/restore")
        ->and($config['restoreMediaVersionUrl'])->toBe("/sites/{$site->id}/pages/0/media/restore");

    // Flags are independent: turning AGENTS off leaves the human editor layer seeded.
    config(['editor.agent_tools.enabled' => false, 'editor.operations.enabled' => true]);

    $agentsOffConfig = editorShellConfig(
        $this->actingAs($user)
            ->get(route('site.editor-shell', [$site, $publishedPage]))
            ->assertOk()
            ->getContent(),
    );

    expect($agentsOffConfig['capabilities'])->toBe(['edit', 'publish', 'media', 'editor_ui']);

    // …and turning HUMANS off leaves agent tools seeded.
    config(['editor.agent_tools.enabled' => true, 'editor.operations.enabled' => false]);

    $humansOffConfig = editorShellConfig(
        $this->actingAs($user)
            ->get(route('site.editor-shell', [$site, $publishedPage]))
            ->assertOk()
            ->getContent(),
    );

    expect($humansOffConfig['capabilities'])->toBe(['edit', 'publish', 'media', 'agent_tools']);

    // Only with both off does the shell fall back to the base capabilities.
    config(['editor.agent_tools.enabled' => false, 'editor.operations.enabled' => false]);

    $disabledConfig = editorShellConfig(
        $this->actingAs($user)
            ->get(route('site.editor-shell', [$site, $publishedPage]))
            ->assertOk()
            ->getContent(),
    );

    expect($disabledConfig['capabilities'])->toBe(['edit', 'publish', 'media']);
});
