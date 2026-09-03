<?php

use App\Enums\AgentRole;
use App\Enums\LogoSize;
use App\Enums\PageKind;
use App\Models\BusinessProfile;
use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteDraft;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Pest\Browser\ServerManager;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * @return array{0: User, 1: Site, 2: GeneratedPage}
 */
function seedOverlayScrollHome(): array
{
    $user = User::factory()->staff(AgentRole::Agent)->create([
        'last_login_at' => now(),
    ]);
    $site = Site::factory()->create([
        'created_by_user_id' => $user->id,
        'business_name' => 'Overlay Scroll Ltd',
        'theme' => 'trades-bold',
        'header_mode' => 'overlay',
        'header_bg' => '#ffffff',
    ]);
    BusinessProfile::factory()->for($site)->create([
        'profile_data' => [
            'top_bar_enabled' => true,
            'contact' => ['phones' => ['0161 123 4567']],
        ],
    ]);
    HeroVersion::factory()->for($site)->active()->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://cdn.example/hero-home.jpg',
    ]);
    $content = ['sections' => [
        ['type' => 'hero', 'title' => 'Overlay home'],
        ['type' => 'services', 'title' => 'Our Services', 'items' => [
            ['title' => 'A', 'body' => str_repeat('Tall content. ', 80)],
            ['title' => 'B', 'body' => str_repeat('More content. ', 80)],
        ]],
    ]];
    $page = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'kind' => PageKind::Core,
    ]);
    $revision = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => $content,
    ]);
    $page->update(['published_revision_id' => $revision->id]);

    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => [
                ['type' => 'page', 'label' => 'Home', 'href' => '/', 'page_id' => $page->id],
                ['type' => 'group', 'label' => 'Services', 'children' => [
                    ['type' => 'page', 'label' => 'Roofing', 'href' => '/roofing'],
                ]],
            ]],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
            'homepage_page_id' => $page->id,
        ],
        'page_revisions' => [['page_id' => $page->id, 'revision_id' => $revision->id]],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);
    // The editor preview (admin-edit) resolves nav from SiteDraft.composition,
    // not from the published SiteVersion — mirror it so the dropdown renders.
    SiteDraft::create(['site_id' => $site->id, 'composition' => $version->composition, 'updated_at' => now()]);

    return [$user, $site, $page];
}

function overlayScrollShellUrl(Site $site, GeneratedPage $page): string
{
    $host = config('domains.agent_domain');
    $serverBase = ServerManager::instance()->http()->rewrite('/');
    $port = parse_url($serverBase, PHP_URL_PORT);

    return 'http://'.$host.($port ? ':'.$port : '').route('site.editor-shell', [
        'site' => $site->id,
        'page' => $page->id,
    ], false);
}

it('overlay header is fixed and transparent at top, solid after scroll, solid immediately on reload, and the mobile panel stays anchored', function () {
    [$user, $site, $page] = seedOverlayScrollHome();

    $this->actingAs($user);
    $shell = visit(overlayScrollShellUrl($site, $page));

    $atTop = null;
    $shell->withinFrame('#editor-preview-iframe', function ($iframe) use (&$atTop) {
        $atTop = $iframe->script(<<<'JS'
            new Promise((resolve) => {
                const read = () => {
                    const h = document.querySelector('header[data-header-mode="overlay"]') || document.querySelector('header');
                    if (!h) {
                        return { position: null, bg: null, mode: null };
                    }
                    const cs = getComputedStyle(h);
                    return { position: cs.position, bg: cs.backgroundColor, mode: h.getAttribute('data-header-mode') };
                };
                const start = performance.now();
                const tick = () => {
                    const s = read();
                    if (s.position === 'fixed' && (s.bg === 'rgba(0, 0, 0, 0)' || s.bg === 'transparent')) {
                        resolve(s);
                        return;
                    }
                    if (performance.now() - start > 4000) {
                        resolve(s);
                        return;
                    }
                    requestAnimationFrame(tick);
                };
                tick();
            })
        JS);
    });

    expect($atTop['position'] ?? null)->toBe('fixed')
        ->and(in_array($atTop['bg'] ?? '', ['rgba(0, 0, 0, 0)', 'transparent'], true))->toBeTrue()
        ->and($atTop['mode'] ?? null)->toBe('overlay');

    $afterScroll = null;
    $shell->withinFrame('#editor-preview-iframe', function ($iframe) use (&$afterScroll) {
        $afterScroll = $iframe->script(<<<'JS'
            new Promise((resolve) => {
                window.scrollTo(0, 300);
                const start = performance.now();
                const tick = () => {
                    const h = document.querySelector('header[data-header-mode="overlay"]') || document.querySelector('header');
                    if (!h) {
                        if (performance.now() - start > 4000) {
                            resolve({ position: null, bg: null, y: window.scrollY });
                            return;
                        }
                        requestAnimationFrame(tick);
                        return;
                    }
                    const cs = getComputedStyle(h);
                    if (window.scrollY > 10 && cs.position === 'fixed' && cs.backgroundColor !== 'rgba(0, 0, 0, 0)' && cs.backgroundColor !== 'transparent') {
                        resolve({ position: cs.position, bg: cs.backgroundColor, y: window.scrollY });
                        return;
                    }
                    if (performance.now() - start > 4000) {
                        resolve({ position: cs.position, bg: cs.backgroundColor, y: window.scrollY });
                        return;
                    }
                    requestAnimationFrame(tick);
                };
                requestAnimationFrame(tick);
            })
        JS);
    });

    expect($afterScroll['position'] ?? null)->toBe('fixed')
        ->and($afterScroll['bg'] ?? '')->not->toBe('rgba(0, 0, 0, 0)')
        ->and($afterScroll['bg'] ?? '')->not->toBe('transparent');

    // Restored-scroll solid paint: do not location.reload() inside iframe.script
    // (that rejects the evaluate). Replay x-init at scrollY > 10 — ready starts
    // false, scrolled is true — which is the post-reload first paint.
    $restored = null;
    $shell->withinFrame('#editor-preview-iframe', function ($iframe) use (&$restored) {
        $restored = $iframe->script(<<<'JS'
            new Promise((resolve) => {
                const go = async () => {
                    window.scrollTo(0, 300);
                    const h = document.querySelector('header[data-header-mode="overlay"]') || document.querySelector('header');
                    if (!h || !window.Alpine) {
                        resolve({ ok: false });
                        return;
                    }
                    const data = Alpine.$data(h);
                    data.scrolled = window.scrollY > 10;
                    data.ready = false;
                    await Alpine.nextTick();
                    const firstBg = getComputedStyle(h).backgroundColor;
                    const firstPosition = getComputedStyle(h).position;
                    data.ready = true;
                    await Alpine.nextTick();
                    resolve({
                        ok: true,
                        y: window.scrollY,
                        scrolled: data.scrolled,
                        firstBg,
                        firstPosition,
                        readyBg: getComputedStyle(h).backgroundColor,
                        staticClass: h.getAttribute('class'),
                        staticStyle: h.getAttribute('style'),
                    });
                };
                go();
            })
        JS);
    });

    expect($restored['ok'] ?? false)->toBeTrue()
        ->and($restored['y'] ?? 0)->toBeGreaterThan(10)
        ->and($restored['scrolled'] ?? false)->toBeTrue()
        ->and($restored['firstPosition'] ?? null)->toBe('fixed')
        ->and($restored['firstBg'] ?? '')->not->toBe('rgba(0, 0, 0, 0)')
        ->and($restored['firstBg'] ?? '')->not->toBe('transparent')
        ->and($restored['readyBg'] ?? '')->not->toBe('rgba(0, 0, 0, 0)')
        ->and($restored['readyBg'] ?? '')->not->toBe('transparent')
        ->and($restored['staticClass'] ?? '')->toContain('shadow-md')
        ->and($restored['staticStyle'] ?? '')->toContain('background-color:');

    // Reload the preview iframe from the parent (cross-origin: set iframe.src,
    // never touch contentWindow). Then restore scroll inside the frame and
    // assert the header is solid — this is the executable reload path.
    $reloaded = $shell->script(<<<'JS'
        new Promise((resolve) => {
            const iframe = document.getElementById('editor-preview-iframe');
            if (!iframe) {
                resolve('missing-iframe');
                return;
            }
            const done = () => setTimeout(() => resolve('reloaded'), 400);
            iframe.addEventListener('load', done, { once: true });
            iframe.src = iframe.src;
            setTimeout(() => resolve('timeout'), 8000);
        })
    JS);

    expect($reloaded)->toBe('reloaded');

    $afterReload = null;
    $shell->withinFrame('#editor-preview-iframe', function ($iframe) use (&$afterReload) {
        $afterReload = $iframe->script(<<<'JS'
            new Promise((resolve) => {
                window.scrollTo(0, 300);
                const start = performance.now();
                const tick = () => {
                    const h = document.querySelector('header[data-header-mode="overlay"]') || document.querySelector('header');
                    if (!h) {
                        if (performance.now() - start > 4000) {
                            resolve({ position: null, bg: null, y: window.scrollY });
                            return;
                        }
                        requestAnimationFrame(tick);
                        return;
                    }
                    const data = window.Alpine ? Alpine.$data(h) : null;
                    if (data) {
                        data.scrolled = window.scrollY > 10;
                        data.ready = true;
                    }
                    const cs = getComputedStyle(h);
                    if (window.scrollY > 10 && cs.position === 'fixed' && cs.backgroundColor !== 'rgba(0, 0, 0, 0)' && cs.backgroundColor !== 'transparent') {
                        resolve({ position: cs.position, bg: cs.backgroundColor, y: window.scrollY });
                        return;
                    }
                    if (performance.now() - start > 4000) {
                        resolve({ position: cs.position, bg: cs.backgroundColor, y: window.scrollY });
                        return;
                    }
                    requestAnimationFrame(tick);
                };
                tick();
            })
        JS);
    });

    expect($afterReload['position'] ?? null)->toBe('fixed')
        ->and($afterReload['y'] ?? 0)->toBeGreaterThan(10)
        ->and($afterReload['bg'] ?? '')->not->toBe('rgba(0, 0, 0, 0)')
        ->and($afterReload['bg'] ?? '')->not->toBe('transparent');

    $dropdown = null;
    $shell->withinFrame('#editor-preview-iframe', function ($iframe) use (&$dropdown) {
        $dropdown = $iframe->script(<<<'JS'
            new Promise((resolve) => {
                const go = async () => {
                    window.scrollTo(0, 0);
                    const h = document.querySelector('header');
                    if (h && window.Alpine) {
                        const data = Alpine.$data(h);
                        data.scrolled = false;
                        data.ready = true;
                        await Alpine.nextTick();
                    }
                    const trigger = document.querySelector('header .hidden.md\\:flex button');
                    if (!trigger) {
                        resolve({ triggerFound: false });
                        return;
                    }
                    trigger.click();
                    await Alpine.nextTick();
                    const panel = trigger.parentElement.querySelector('.absolute.top-full');
                    const openDisplay = panel ? getComputedStyle(panel).display : 'missing';
                    window.scrollTo(0, 300);
                    if (h && window.Alpine) {
                        const data = Alpine.$data(h);
                        data.scrolled = window.scrollY > 10;
                        data.ready = true;
                        await Alpine.nextTick();
                    }
                    // The solid state fades in over duration-700; read the
                    // settled colour, not the transition's first frame.
                    await new Promise((wait) => setTimeout(wait, 900));
                    const cs = h ? getComputedStyle(h) : null;
                    const triggerCs = getComputedStyle(trigger);
                    resolve({
                        triggerFound: true,
                        openDisplay,
                        afterDisplay: panel ? getComputedStyle(panel).display : 'missing',
                        headerBg: cs ? cs.backgroundColor : null,
                        headerPosition: cs ? cs.position : null,
                        triggerColor: triggerCs.color,
                        y: window.scrollY,
                    });
                };
                go();
            })
        JS);
    });

    expect($dropdown['triggerFound'] ?? false)->toBeTrue()
        ->and($dropdown['openDisplay'] ?? 'none')->not->toBe('none')
        ->and($dropdown['afterDisplay'] ?? 'none')->not->toBe('none')
        ->and($dropdown['headerPosition'] ?? null)->toBe('fixed')
        ->and($dropdown['headerBg'] ?? '')->not->toBe('rgba(0, 0, 0, 0)')
        ->and($dropdown['headerBg'] ?? '')->not->toBe('transparent')
        ->and($dropdown['triggerColor'] ?? '')->not->toBe('rgba(255, 255, 255, 0.85)')
        ->and($dropdown['y'] ?? 0)->toBeGreaterThan(10);

    $panel = null;
    $shell->resize(390, 844);
    $shell->withinFrame('#editor-preview-iframe', function ($iframe) use (&$panel) {
        $panel = $iframe->script(<<<'JS'
            new Promise((resolve) => {
                const burger = document.querySelector('button[aria-controls="mobile-nav-panel"]');
                const phone = document.querySelector('a[aria-label^="Call"]');
                const go = async () => {
                if (burger) {
                    burger.click();
                    if (window.Alpine) { await Alpine.nextTick(); }
                }
                requestAnimationFrame(() => {
                    const p = document.getElementById('mobile-nav-panel');
                    const h = document.querySelector('header');
                    if (!p || !h) {
                        resolve({ ok: false, burgerFound: !!burger });
                        return;
                    }
                    const before = p.getBoundingClientRect();
                    const headerBottom = h.getBoundingClientRect().bottom;
                    window.scrollTo(0, 300);
                    requestAnimationFrame(() => {
                        const after = p.getBoundingClientRect();
                        resolve({
                            ok: true,
                            burgerFound: !!burger,
                            position: getComputedStyle(h).position,
                            beforeTop: before.top,
                            afterTop: after.top,
                            headerBottom,
                            delta: Math.abs(after.top - before.top),
                            phoneColor: phone ? getComputedStyle(phone).color : null,
                            panelDisplay: getComputedStyle(p).display,
                            panelPosition: getComputedStyle(p).position,
                        });
                    });
                });
                };
                go();
            })
        JS);
    });

    expect($panel['burgerFound'] ?? false)->toBeTrue()
        ->and($panel['ok'] ?? false)->toBeTrue()
        ->and($panel['position'] ?? null)->toBe('fixed')
        ->and($panel['beforeTop'] ?? 0)->toBeGreaterThan(0)
        ->and($panel['delta'] ?? 99)->toBeLessThan(2)
        ->and($panel['phoneColor'] ?? null)->not->toBeNull();

    $assertCopyClearsHeader = function (int $width, int $height) use ($shell): void {
        $shell->resize($width, $height);
        $geometry = null;
        $shell->withinFrame('#editor-preview-iframe', function ($iframe) use (&$geometry) {
            $geometry = $iframe->script(<<<'JS'
                new Promise((resolve) => {
                    const go = async () => {
                        window.scrollTo(0, 0);
                        const h = document.querySelector('header[data-header-mode="overlay"]') || document.querySelector('header');
                        if (h && window.Alpine) {
                            const data = Alpine.$data(h);
                            data.scrolled = false;
                            data.ready = true;
                            await Alpine.nextTick();
                        }
                        await new Promise((frame) => requestAnimationFrame(() => requestAnimationFrame(frame)));
                        const header = document.querySelector('header[data-header-mode="overlay"]') || document.querySelector('header');
                        const copy = document.querySelector('.overlay-hero-copy');
                        if (!header || !copy) {
                            resolve({ ok: false, y: window.scrollY });
                            return;
                        }
                        const walker = document.createTreeWalker(copy, NodeFilter.SHOW_TEXT, {
                            acceptNode(node) {
                                if (!node.textContent || !node.textContent.trim()) {
                                    return NodeFilter.FILTER_REJECT;
                                }
                                const el = node.parentElement;
                                if (!el) {
                                    return NodeFilter.FILTER_REJECT;
                                }
                                const cs = getComputedStyle(el);
                                if (cs.display === 'none' || cs.visibility === 'hidden') {
                                    return NodeFilter.FILTER_REJECT;
                                }
                                const r = el.getBoundingClientRect();
                                if (r.width === 0 || r.height === 0) {
                                    return NodeFilter.FILTER_REJECT;
                                }
                                return NodeFilter.FILTER_ACCEPT;
                            },
                        });
                        const text = walker.nextNode();
                        const textEl = text ? text.parentElement : null;
                        const headerBottom = header.getBoundingClientRect().bottom;
                        const textTop = textEl ? textEl.getBoundingClientRect().top : null;
                        resolve({
                            ok: true,
                            y: window.scrollY,
                            headerBottom,
                            textTop,
                            clears: textTop !== null && textTop >= headerBottom,
                        });
                    };
                    go();
                })
            JS);
        });

        expect($geometry['ok'] ?? false)->toBeTrue()
            ->and($geometry['y'] ?? 1)->toBe(0)
            ->and($geometry['clears'] ?? false)->toBeTrue()
            ->and($geometry['textTop'] ?? 0)->toBeGreaterThanOrEqual($geometry['headerBottom'] ?? 9999);
    };

    $assertCopyClearsHeader(1440, 950);
    $assertCopyClearsHeader(390, 844);

    $site->update(['logo_size' => LogoSize::Large]);

    $reloadedLarge = $shell->script(<<<'JS'
        new Promise((resolve) => {
            const iframe = document.getElementById('editor-preview-iframe');
            if (!iframe) {
                resolve('missing-iframe');
                return;
            }
            const done = () => setTimeout(() => resolve('reloaded'), 400);
            iframe.addEventListener('load', done, { once: true });
            iframe.src = iframe.src;
            setTimeout(() => resolve('timeout'), 8000);
        })
    JS);

    expect($reloadedLarge)->toBe('reloaded');

    $assertCopyClearsHeader(1440, 950);
    $assertCopyClearsHeader(390, 844);
});

it('overlay header stays transparent 50ms after scroll, is solid by 400ms, snaps back, and is solid on restored-scroll first paint', function () {
    [$user, $site, $page] = seedOverlayScrollHome();

    $this->actingAs($user);
    $shell = visit(overlayScrollShellUrl($site, $page));

    $timing = null;
    $shell->withinFrame('#editor-preview-iframe', function ($iframe) use (&$timing) {
        $timing = $iframe->script(<<<'JS'
            new Promise((resolve) => {
                const go = async () => {
                    const headerEl = () => document.querySelector('header[data-header-mode="overlay"]') || document.querySelector('header');
                    const readBg = () => {
                        const h = headerEl();
                        return h ? getComputedStyle(h).backgroundColor : null;
                    };
                    const isTransparent = (c) => c === 'rgba(0, 0, 0, 0)' || c === 'transparent';
                    const start = performance.now();
                    while (performance.now() - start < 4000) {
                        const h = headerEl();
                        if (h && window.Alpine) {
                            const data = Alpine.$data(h);
                            data.ready = true;
                            data.scrolled = false;
                            data.pending = false;
                            await Alpine.nextTick();
                            if (window.scrollY <= 10 && isTransparent(readBg())) {
                                break;
                            }
                        }
                        await new Promise((frame) => requestAnimationFrame(frame));
                    }

                    window.scrollTo(0, 300);
                    await new Promise((wait) => setTimeout(wait, 50));
                    const at50 = readBg();
                    const scrolledAt50 = headerEl() && window.Alpine ? Alpine.$data(headerEl()).scrolled : null;

                    await new Promise((wait) => setTimeout(wait, 350));
                    const at400 = readBg();
                    const scrolledAt400 = headerEl() && window.Alpine ? Alpine.$data(headerEl()).scrolled : null;

                    window.scrollTo(0, 0);
                    await new Promise((frame) => requestAnimationFrame(frame));
                    const backBg = readBg();
                    const backScrolled = headerEl() && window.Alpine ? Alpine.$data(headerEl()).scrolled : null;
                    // The solid→transparent fade runs over duration-700; the
                    // state flips at once, the colour settles within ~1s.
                    await new Promise((wait) => setTimeout(wait, 1000));
                    const backBgSettled = readBg();

                    window.scrollTo(0, 300);
                    const restoredHeader = headerEl();
                    if (!restoredHeader || !window.Alpine) {
                        resolve({ ok: false });
                        return;
                    }
                    const restoredData = Alpine.$data(restoredHeader);
                    restoredData.scrolled = window.scrollY > 10;
                    restoredData.pending = false;
                    restoredData.ready = false;
                    await Alpine.nextTick();
                    const restoredBg = getComputedStyle(restoredHeader).backgroundColor;

                    resolve({
                        ok: true,
                        at50,
                        at400,
                        backBg,
                        backBgSettled,
                        restoredBg,
                        scrolledAt50,
                        scrolledAt400,
                        backScrolled,
                        y: window.scrollY,
                    });
                };
                go();
            })
        JS);
    });

    expect($timing['ok'] ?? false)->toBeTrue()
        ->and(in_array($timing['at50'] ?? '', ['rgba(0, 0, 0, 0)', 'transparent'], true))->toBeTrue()
        ->and($timing['scrolledAt50'] ?? true)->toBeFalse()
        ->and($timing['at400'] ?? '')->not->toBe('rgba(0, 0, 0, 0)')
        ->and($timing['at400'] ?? '')->not->toBe('transparent')
        ->and($timing['scrolledAt400'] ?? false)->toBeTrue()
        ->and($timing['backScrolled'] ?? true)->toBeFalse()
        ->and(in_array($timing['backBgSettled'] ?? '', ['rgba(0, 0, 0, 0)', 'transparent'], true))->toBeTrue()
        ->and($timing['y'] ?? 0)->toBeGreaterThan(10)
        ->and($timing['restoredBg'] ?? '')->not->toBe('rgba(0, 0, 0, 0)')
        ->and($timing['restoredBg'] ?? '')->not->toBe('transparent');
});
