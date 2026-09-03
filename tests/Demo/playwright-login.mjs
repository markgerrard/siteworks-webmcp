import { chromium } from 'playwright';

const portal = process.env.DEMO_PORTAL_URL ?? 'http://app.localhost:8090';
const email = process.env.DEMO_USER_EMAIL ?? 'demo@camino.example';
const password = process.env.DEMO_USER_PASSWORD ?? 'webmcp-demo';

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage();

try {
    await page.goto(`${portal}/login`, { waitUntil: 'domcontentloaded' });
    await page.locator('input[name="email"]').fill(email);
    await page.locator('input[name="password"]').fill(password);
    await Promise.all([
        page.waitForURL(/\/sites\/\d+/, { timeout: 30000 }),
        page.locator('[data-test="login-button"]').click({ noWaitAfter: true }),
    ]);
    await page.goto(`${portal}/sites/64/design`, { waitUntil: 'domcontentloaded' });
    const body = await page.content();
    if (! /design/i.test(body)) {
        throw new Error('design page did not render');
    }
    console.log('PLAYWRIGHT_OK', page.url());
} finally {
    await browser.close();
}
