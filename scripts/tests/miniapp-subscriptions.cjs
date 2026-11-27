const assert = require('node:assert/strict');
const { createServer } = require('node:http');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const { chromium } = require('playwright');

const assets = join(__dirname, '../../app/assets');
const secret = 'https://panel.example.test/sub/test-key';
const name = 'customer-with-a-long-username-1234567890';
const subscription = { username: name, id_invoice: 'my-order', panel_user_id: '11', name_product: 'Stored plan', Service_location: 'پنل اصلی',
    Status: 'active', days_remaining: 4, remaining_bytes: 524288000, expires_at: 2000000000, has_queued_renewal: true };
const foreign = { ...subscription, username: 'somebody-else', id_invoice: 'other-order' };
const calls = [];
const server = createServer(async (request, response) => {
    const url = new URL(request.url, 'http://localhost');
    if (url.pathname === '/') {
        response.setHeader('Content-Type', 'text/html; charset=utf-8');
        response.end(`<!doctype html><html lang="fa" dir="rtl"><meta name="viewport" content="width=device-width,initial-scale=1">
            <link rel="stylesheet" href="/css/app.css"><div class="app-shell"><main id="view"></main></div>
            <script type="module">import {start} from '/v1.3.5/router.js';
            sessionStorage.setItem('faoxima.token', location.search.includes('admin') ? 'admin' : 'buyer'); start();</script>`);
        return;
    }
    if (url.pathname === '/api/miniapp.php') {
        const action = url.searchParams.get('actions');
        const admin = request.headers.authorization === 'Bearer admin';
        let raw = '';
        for await (const chunk of request) raw += chunk;
        const data = raw ? JSON.parse(raw) : Object.fromEntries(url.searchParams);
        calls.push({ action, method: request.method, search: url.search, data });
        let payload = {};
        if (action === 'invoices') {
            const items = data.scope === 'all' && admin ? [foreign] : [subscription];
            payload = { items, page: 1, total: items.length, total_pages: 1, can_search_all: admin };
        } else if (action === 'service') {
            payload = { ...subscription, display_username: name, status: 'active', product_name: 'Stored plan',
                panel_type: 'rebecca', total_traffic_gb: 1, remaining_traffic_gb: 0.5, order_id: 'my-order' };
        } else if (action === 'countries') {
            payload = [{ id: 'rb', name: 'ربکا', is_username: true, is_username_required: true }];
        } else if (action === 'categories' || action === 'time_ranges') {
            payload = [];
        } else if (action === 'services') {
            payload = [
                { id: 'offer', name: 'پیش‌فرض تست', traffic_gb: 20, time_days: 60, price: 50000, symbolic_limit_enabled: true, symbolic_limit_users: 2 },
                { id: 'unlimited', name: 'پلن داخلی', traffic_gb: 0, time_days: 0, price: 80000 },
            ];
        }
        response.setHeader('Content-Type', 'application/json');
        response.end(JSON.stringify({ status: true, obj: payload }));
        return;
    }
    try {
        response.setHeader('Content-Type', url.pathname.endsWith('.js') ? 'text/javascript' : 'text/css');
        response.end(readFileSync(join(assets, url.pathname)));
    } catch {
        response.writeHead(404).end();
    }
});

(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const origin = `http://127.0.0.1:${server.address().port}`;
    const browser = await chromium.launch(process.env.CHROMIUM_BIN ? { executablePath: process.env.CHROMIUM_BIN } : {});
    try {
        const page = await browser.newPage({ viewport: { width: 320, height: 750 } });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(`${origin}/#/`);
        const recent = page.locator('#home-services-list .list-item').first();
        await recent.locator('.li-title').getByText(name).waitFor();
        assert.equal(await recent.locator('.li-sub').innerText(), 'پنل اصلی');
        assert.doesNotMatch(await recent.innerText(), /Stored plan/);
        assert.ok(calls.some(call => call.action === 'invoices' && call.data.limit === '3'));
        await recent.click();
        await page.locator('#service-body').getByText('شناسهٔ اشتراک').waitFor();
        assert.ok(calls.some(call => call.action === 'service' && call.data.order_id === 'my-order'));

        await page.goto(`${origin}/#/services`);
        const card = page.locator('.service-preview').first();
        await card.getByText(name).waitFor();
        assert.equal(await page.locator('#subscriptions-scope').isVisible(), false);
        assert.match(await card.innerText(), /شناسهٔ اشتراک\s*11/);
        await card.getByText('رزرو اشتراک').waitFor();
        for (const width of [320, 360, 390, 430]) {
            await page.setViewportSize({ width, height: 750 });
            assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
            assert.ok(await card.evaluate(el => el.scrollWidth <= el.clientWidth));
        }
        await page.getByRole('combobox', { name: 'مرتب‌سازی' }).selectOption('remaining_bytes');
        await card.getByText(name).waitFor();
        assert.ok(calls.some(call => call.data.sort === 'remaining_bytes'));
        const field = page.getByRole('searchbox', { name: 'جستجوی اشتراک' });
        await field.fill(secret);
        await field.press('Enter');
        await card.getByText(name).waitFor();
        const lookup = calls.find(call => call.data.q === secret);
        assert.equal(lookup.method, 'POST');
        assert.equal(new URLSearchParams(lookup.search).has('q'), false);
        await card.click();
        await page.locator('#service-body').getByText('شناسهٔ اشتراک').waitFor();
        assert.ok(calls.some(call => call.action === 'service' && call.data.order_id === 'my-order'));

        await page.goto(`${origin}/?admin=1#/services`);
        await page.getByRole('checkbox', { name: 'جستجو در همهٔ اشتراک‌ها' }).check();
        await page.getByRole('searchbox').fill('11');
        await page.getByRole('searchbox').press('Enter');
        await page.getByText('somebody-else').waitFor();
        assert.ok(calls.some(call => call.data.q === '11' && call.data.scope === 'all'));

        await page.goto(`${origin}/#/buy`);
        const offer = page.locator('[data-id="offer"]');
        await offer.waitFor();
        assert.equal(await page.locator('#panel-list').count(), 0);
        assert.match(await page.locator('.buy-step-count').innerText(), /از\s+۴/);
        assert.equal(await offer.locator('.plan-title').innerText(), '۲۰ گیگ، ۶۰ روز');
        assert.equal(await offer.locator('.plan-title').getAttribute('dir'), 'rtl');
        assert.equal(await offer.locator('.plan-meta > span').count(), 3);
        assert.match(await offer.locator('.plan-meta').innerText(), /۲۰ گیگ/);
        assert.doesNotMatch(await offer.innerText(), /GB|پیش‌فرض/);
        assert.equal(await page.locator('[data-id="unlimited"] .plan-title').innerText(), 'حجم و مدت نامحدود');
        await offer.click();
        await page.getByRole('heading', { name: 'خلاصهٔ سفارش' }).waitFor();
        assert.match(await page.locator('#view').innerText(), /۲۰ گیگ/);
        assert.doesNotMatch(await page.locator('#view').innerText(), /GB|پیش‌فرض تست/);
        assert.deepEqual(errors, []);
        console.log('Mobile cards (320–430px), exact-order routes, private POST, admin scope and single-panel checkout passed.');
    } finally {
        await browser.close();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => { console.error(error); server.close(); process.exitCode = 1; });
