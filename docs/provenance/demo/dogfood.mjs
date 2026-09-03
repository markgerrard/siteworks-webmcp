import { chromium } from 'playwright';

const portal = process.env.DEMO_PORTAL_URL ?? 'http://app.localhost:8090';
const storefront = process.env.DEMO_STOREFRONT_URL ?? 'http://localhost:8090';
const email = process.env.DEMO_USER_EMAIL ?? 'demo@camino.example';
const password = process.env.DEMO_USER_PASSWORD ?? 'webmcp-demo';
const subtitle = process.env.DEMO_HERO_SUBTITLE ?? 'Dogfood hero subtitle';

function waitForWebmcpScript() {
    return `
        new Promise((resolve) => {
            const started = Date.now();
            const tick = () => {
                if (typeof window.__siteworks_webmcp__ === 'object' && window.__siteworks_webmcp__ !== null) {
                    resolve('object');
                    return;
                }
                if (Date.now() - started > 15000) {
                    resolve(typeof window.__siteworks_webmcp__);
                    return;
                }
                setTimeout(tick, 50);
            };
            tick();
        })
    `;
}

function installFakeAndSyncScript() {
    return `
        (async () => {
            window.__t = {};
            document.modelContext = {
                registerTool(def, {signal} = {}) {
                    window.__t[def.name] = def;
                    signal?.addEventListener('abort', () => delete window.__t[def.name]);
                }
            };
            await window.__siteworks_webmcp__.sync();
            return Object.keys(window.__t).sort();
        })()
    `;
}

async function executeTool(page, name, input) {
    const payload = JSON.stringify(input);
    return page.evaluate(async ({ name, payload }) => {
        const tool = window.__t[name];
        if (! tool) {
            return { ok: false, error: { code: 'missing_tool', tools: Object.keys(window.__t || {}) } };
        }
        try {
            const wrapped = await tool.execute(JSON.parse(payload));
            if (wrapped?.content?.[0]?.text) {
                return JSON.parse(wrapped.content[0].text);
            }
            return wrapped;
        } catch (error) {
            return { ok: false, error: { code: 'exception', message: String(error?.message || error) } };
        }
    }, { name, payload });
}

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext();
const page = await context.newPage();
const transcript = [];

function log(label, value) {
    const line = typeof value === 'string' ? `${label}: ${value}` : `${label}: ${JSON.stringify(value, null, 2)}`;
    transcript.push(line);
    console.log(line);
}

try {
    await page.goto(`${portal}/login`, { waitUntil: 'domcontentloaded' });
    await page.locator('input[name="email"]').fill(email);
    await page.locator('input[name="password"]').fill(password);
    await Promise.all([
        page.waitForURL(/\/sites\/\d+/, { timeout: 30000 }),
        page.locator('[data-test="login-button"]').click({ noWaitAfter: true }),
    ]);
    log('login', page.url());

    await page.goto(`${portal}/sites/64`, { waitUntil: 'domcontentloaded' });
    const pagesKind = await page.evaluate(waitForWebmcpScript());
    log('pages.__siteworks_webmcp__', pagesKind);
    const pagesTools = pagesKind === 'object'
        ? await page.evaluate(installFakeAndSyncScript())
        : [];
    log('pages.tools', pagesTools);

    const editHref = await page.locator('[data-cp-edit-site]').getAttribute('href');
    if (! editHref) {
        throw new Error('Edit site link missing on /sites/64');
    }
    const editorUrl = new URL(editHref, portal).toString();
    log('editor.url', editorUrl);

    await page.goto(editorUrl, { waitUntil: 'domcontentloaded' });
    const editorKind = await page.evaluate(waitForWebmcpScript());
    log('editor.__siteworks_webmcp__', editorKind);
    if (editorKind !== 'object') {
        throw new Error('WebMCP host did not install on the editor shell');
    }
    const editorTools = await page.evaluate(installFakeAndSyncScript());
    log('editor.tools', editorTools);

    await page.waitForSelector('#editor-preview-iframe');
    await new Promise((resolve) => setTimeout(resolve, 2500));

    const brand = await executeTool(page, 'siteworks.get_brand_context', {});
    log('get_brand_context', brand);

    const pageId = await page.evaluate(() => window.__siteworks_editor_shell_config__?.pageId ?? null);
    let edit = { ok: false, error: { code: 'not_attempted' } };
    for (let attempt = 1; attempt <= 8; attempt++) {
        edit = await executeTool(page, 'siteworks.edit_field', {
            page_id: pageId,
            stored_index: 0,
            field_path: 'subtitle',
            value: subtitle,
        });
        log(`edit_field.attempt_${attempt}`, { ok: edit?.ok === true, code: edit?.error?.code ?? null });
        if (edit?.ok === true || edit?.error?.code !== 'editor_busy') {
            break;
        }
        await new Promise((resolve) => setTimeout(resolve, 1000));
    }
    log('edit_field', edit);
    if (edit?.ok !== true) {
        throw new Error('edit_field did not succeed');
    }

    const published = await page.evaluate(async () => {
        const config = window.__siteworks_editor_shell_config__ || {};
        const res = await fetch(config.publishUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': config.csrfToken,
            },
            body: JSON.stringify({}),
        });
        return { status: res.status, body: await res.json().catch(() => ({})) };
    });
    log('publish', published);

    const store = await context.newPage();
    await store.goto(storefront + '/', { waitUntil: 'domcontentloaded' });
    const html = await store.content();
    const reflected = html.includes(subtitle);
    log('storefront.url', store.url());
    log('storefront.reflects_subtitle', reflected);
    if (! reflected) {
        throw new Error('storefront did not contain the edited hero subtitle');
    }

    console.log('PLAYWRIGHT_OK');
} finally {
    await browser.close();
}
