<?php

use App\Enums\AgentRole;
use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Pest\Browser\ServerManager;
use Tests\TestCase;

/*
 * Front 2 (WebMCP) browser coverage on the agents editor shell.
 *
 * ChatGPT's modelContext is faked in-page after load, then sync()
 * registers siteworks.* tools. Executes go through the parent
 * coordinator and the iframe prepare-external-write handshake.
 *
 * Seeding and the multi-domain URL follow EditorShellBridgeTest;
 * iframe DOM readback follows FormPanelTest / EditorPreviewXssTest
 * (withinFrame + script) because the preview is cross-origin —
 * parent script() cannot read contentDocument.
 */

uses(TestCase::class, RefreshDatabase::class);

/**
 * @return array{0: User, 1: Site, 2: GeneratedPage}
 */
function seedWebMcpEditorPage(): array
{
    $user = User::factory()->staff(AgentRole::Agent)->create([
        'last_login_at' => now(),
    ]);
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $body = [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'Original body']],
        ]],
    ];
    $content = ['sections' => [
        [
            'type' => 'hero',
            'title' => 'Original headline',
            'subtitle' => 'Original subtitle',
        ],
        [
            'type' => 'intro',
            'title' => 'About us',
            'body' => $body,
        ],
    ]];
    $page = GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'home',
        'content_data' => $content,
        'sort_order' => 1,
        'version' => 1,
        'status' => PageStatus::Published,
    ]);
    $revision = PageRevision::create([
        'page_id' => $page->id,
        'content_data' => $content,
        'ai_generated' => false,
        'created_at' => now(),
    ]);
    $page->update(['published_revision_id' => $revision->id]);

    return [$user, $site, $page];
}

function webMcpEditorUrl(Site $site, GeneratedPage $page): string
{
    $host = config('domains.agent_domain');
    $serverBase = ServerManager::instance()->http()->rewrite('/');
    $port = parse_url($serverBase, PHP_URL_PORT);

    return 'http://'.$host.($port ? ':'.$port : '').route('site.editor-shell', [
        'site' => $site->id,
        'page' => $page->id,
    ], false);
}

function webMcpWaitForHostScript(): string
{
    return <<<'JS'
        new Promise((resolve) => {
            const started = Date.now();
            const tick = () => {
                if (typeof window.__siteworks_webmcp__ === 'object' && window.__siteworks_webmcp__ !== null) {
                    resolve('object');
                    return;
                }
                if (Date.now() - started > 10000) {
                    resolve(typeof window.__siteworks_webmcp__);
                    return;
                }
                setTimeout(tick, 50);
            };
            tick();
        })
    JS;
}

function webMcpInstallFakeAndSyncScript(): string
{
    return <<<'JS'
        (async () => {
            window.__t = {};
            document.modelContext = {
                registerTool(def, {signal}) {
                    window.__t[def.name] = def;
                    signal?.addEventListener('abort', () => delete window.__t[def.name]);
                }
            };
            await window.__siteworks_webmcp__.sync();
            return Object.keys(window.__t);
        })()
    JS;
}

function webMcpExecuteEditFieldScript(int $pageId, int $storedIndex, string $fieldPath, string $value): string
{
    $input = json_encode([
        'page_id' => $pageId,
        'stored_index' => $storedIndex,
        'field_path' => $fieldPath,
        'value' => $value,
    ], JSON_THROW_ON_ERROR);

    return <<<JS
        (async () => {
            const tool = window.__t['siteworks.edit_field'];
            if (! tool) {
                return { ok: false, error: { code: 'missing_tool', tools: Object.keys(window.__t || {}) } };
            }
            try {
                const wrapped = await tool.execute({$input});
                if (wrapped?.content?.[0]?.text) {
                    return JSON.parse(wrapped.content[0].text);
                }
                return wrapped;
            } catch (error) {
                return { ok: false, error: { code: 'exception', message: String(error?.message || error) } };
            }
        })()
    JS;
}

test('a WebMCP edit_field through a fake modelContext advances the draft and updates the preview', function () {
    config(['editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true]);

    [$user, $site, $page] = seedWebMcpEditorPage();
    $published = $page->published_revision_id;

    $this->actingAs($user);
    $shell = visit(webMcpEditorUrl($site, $page));

    $kind = $shell->script(webMcpWaitForHostScript());
    expect($kind)->toBe('object');

    $names = $shell->script(webMcpInstallFakeAndSyncScript());
    expect($names)->toContain('siteworks.edit_field');

    $envelope = $shell->script(webMcpExecuteEditFieldScript($page->id, 0, 'title', 'Agent title'));

    expect($envelope['ok'])->toBeTrue()
        ->and($envelope['state']['draft_revision_id'])->toBeInt();

    $page->refresh();
    expect($page->draft_revision_id)->toBe($envelope['state']['draft_revision_id'])
        ->and($page->draft_revision_id)->not->toBeNull()
        ->and($page->draft_revision_id)->not->toBe($published);

    $heroTitle = '';
    $shell->withinFrame('#editor-preview-iframe', function ($iframe) use (&$heroTitle) {
        $heroTitle = $iframe->script(<<<'JS'
            document.querySelector('[data-editable-section-type="hero"][data-editable-field="title"]')?.textContent.trim() ?? ''
        JS);
    });
    expect($heroTitle)->toBe('Agent title');
});

test('the remove_section descriptor has destructiveHint true', function () {
    config(['editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true]);

    [$user, $site, $page] = seedWebMcpEditorPage();

    $this->actingAs($user);
    $shell = visit(webMcpEditorUrl($site, $page));

    expect($shell->script(webMcpWaitForHostScript()))->toBe('object');
    $shell->script(webMcpInstallFakeAndSyncScript());

    $hint = $shell->script(<<<'JS'
        window.__t['siteworks.remove_section']?.annotations?.destructiveHint ?? null
    JS);

    expect($hint)->toBeTrue();
});

test('the WebMCP host is undefined when agent tools are disabled', function () {
    config(['editor.agent_tools.enabled' => false]);

    [$user, $site, $page] = seedWebMcpEditorPage();

    $this->actingAs($user);
    $shell = visit(webMcpEditorUrl($site, $page));

    $kind = $shell->script(<<<'JS'
        new Promise((resolve) => {
            setTimeout(() => resolve(typeof window.__siteworks_webmcp__), 1500);
        })
    JS);

    expect($kind)->toBe('undefined');
});

test('a tool edit_field on another field commits a focused TipTap editor first', function () {
    config(['editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true]);

    [$user, $site, $page] = seedWebMcpEditorPage();

    $this->actingAs($user);
    $shell = visit(webMcpEditorUrl($site, $page));

    expect($shell->script(webMcpWaitForHostScript()))->toBe('object');
    $shell->script(webMcpInstallFakeAndSyncScript());

    $typed = '';
    $shell->withinFrame('#editor-preview-iframe', function ($iframe) use (&$typed) {
        $ready = $iframe->script(<<<'JS'
            new Promise((resolve) => {
                const started = Date.now();
                const tick = () => {
                    if (document.querySelector('[data-editable-type="rich"]')) {
                        resolve('ready');
                        return;
                    }
                    if (Date.now() - started > 10000) {
                        resolve('timeout');
                        return;
                    }
                    setTimeout(tick, 50);
                };
                tick();
            })
        JS);
        expect($ready)->toBe('ready');

        $iframe->click('[data-editable-type="rich"]');

        $typed = $iframe->script(<<<'JS'
            new Promise((resolve) => {
                const started = Date.now();
                const tick = () => {
                    const pm = document.querySelector('.ProseMirror');
                    if (pm) {
                        pm.focus();
                        document.execCommand('selectAll', false, null);
                        document.execCommand('insertText', false, 'Uncommitted rich');
                        resolve(pm.innerText.trim());
                        return;
                    }
                    if (Date.now() - started > 5000) {
                        resolve('timeout');
                        return;
                    }
                    setTimeout(tick, 50);
                };
                tick();
            })
        JS);
    });
    expect($typed)->toContain('Uncommitted rich');

    $envelope = $shell->script(webMcpExecuteEditFieldScript($page->id, 0, 'title', 'Agent title'));
    expect($envelope['ok'])->toBeTrue();

    $page->refresh();
    $draft = PageRevision::find($page->draft_revision_id);
    expect($draft)->not->toBeNull()
        ->and($draft->content_data['sections'][0]['title'])->toBe('Agent title')
        ->and(json_encode($draft->content_data['sections'][1]['body']))->toContain('Uncommitted rich');
});

test('a tool edit_field on another field commits a focused plain inline edit first', function () {
    config(['editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true]);

    [$user, $site, $page] = seedWebMcpEditorPage();

    $this->actingAs($user);
    $shell = visit(webMcpEditorUrl($site, $page));

    expect($shell->script(webMcpWaitForHostScript()))->toBe('object');
    $shell->script(webMcpInstallFakeAndSyncScript());

    $typed = '';
    $shell->withinFrame('#editor-preview-iframe', function ($iframe) use (&$typed) {
        $ready = $iframe->script(<<<'JS'
            new Promise((resolve) => {
                const started = Date.now();
                const tick = () => {
                    if (document.querySelector('[data-editable-section-type="hero"][data-editable-field="title"]')) {
                        resolve('ready');
                        return;
                    }
                    if (Date.now() - started > 10000) {
                        resolve('timeout');
                        return;
                    }
                    setTimeout(tick, 50);
                };
                tick();
            })
        JS);
        expect($ready)->toBe('ready');

        $iframe->click('[data-editable-section-type="hero"][data-editable-field="title"]');

        $typed = $iframe->script(<<<'JS'
            (() => {
                const el = document.querySelector('[data-editable-section-type="hero"][data-editable-field="title"]');
                if (! el) {
                    return 'missing';
                }
                el.focus();
                el.textContent = 'Uncommitted title';
                return el.textContent.trim();
            })()
        JS);
    });
    expect($typed)->toBe('Uncommitted title');

    $envelope = $shell->script(webMcpExecuteEditFieldScript($page->id, 0, 'subtitle', 'Agent subtitle'));
    expect($envelope['ok'])->toBeTrue();

    $page->refresh();
    $draft = PageRevision::find($page->draft_revision_id);
    expect($draft)->not->toBeNull()
        ->and($draft->content_data['sections'][0]['title'])->toBe('Uncommitted title')
        ->and($draft->content_data['sections'][0]['subtitle'])->toBe('Agent subtitle');
});
