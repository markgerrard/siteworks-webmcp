import { expect, test, vi, beforeEach, afterEach } from 'vitest';
import {
    applyCardVisibility,
    parseFilterQuery,
    productMatches,
    serializeFilterQuery,
    shopFilters,
} from '../filters.js';

const cake = { c: ['cakes', 'wedding-cakes'], p: 4500, a: 'in', o: ['8in'] };
const tart = { c: ['patisserie'], p: 950, a: 'in', o: [] };
const wedding = { c: ['cakes', 'wedding-cakes'], p: 8000, a: 'mto', o: ['8in', '10in'] };

const facets = {
    price: [
        { id: 0, min: 0, max: 2000 },
        { id: 1, min: 2000, max: 4000 },
        { id: 2, min: 4000, max: null },
    ],
};

const none = { cat: '', price: '', avail: '', opt: '', sort: '' };

test('productMatches keeps every card when no filters are set', () => {
    expect(productMatches(cake, none, facets)).toBe(true);
    expect(productMatches(tart, none, facets)).toBe(true);
    expect(productMatches(null, none, facets)).toBe(true);
});

test('productMatches requires the category slug on the product including ancestors', () => {
    expect(productMatches(cake, { ...none, cat: 'cakes' }, facets)).toBe(true);
    expect(productMatches(cake, { ...none, cat: 'wedding-cakes' }, facets)).toBe(true);
    expect(productMatches(tart, { ...none, cat: 'cakes' }, facets)).toBe(false);
});

test('productMatches applies price buckets and availability', () => {
    expect(productMatches(tart, { ...none, price: '0' }, facets)).toBe(true);
    expect(productMatches(cake, { ...none, price: '0' }, facets)).toBe(false);
    expect(productMatches(cake, { ...none, price: '2' }, facets)).toBe(true);
    expect(productMatches(cake, { ...none, avail: 'in' }, facets)).toBe(true);
    expect(productMatches(wedding, { ...none, avail: 'in' }, facets)).toBe(false);
    expect(productMatches(wedding, { ...none, avail: 'mto' }, facets)).toBe(true);
});

test('productMatches requires the selected option id', () => {
    expect(productMatches(cake, { ...none, opt: '8in' }, facets)).toBe(true);
    expect(productMatches(tart, { ...none, opt: '8in' }, facets)).toBe(false);
});

test('parseFilterQuery and serializeFilterQuery round-trip the shareable params', () => {
    const parsed = parseFilterQuery('?price=2&avail=in&opt=8in&sort=featured');
    expect(parsed).toEqual({ cat: '', price: '2', avail: 'in', opt: '8in', sort: 'featured' });
    expect(serializeFilterQuery(parsed)).toBe('?avail=in&opt=8in&price=2&sort=featured');
    expect(serializeFilterQuery(none)).toBe('');
    expect(parseFilterQuery('')).toEqual(none);
});

test('serializeFilterQuery preserves unrelated query params', () => {
    const withUtm = '?utm_source=x&price=2';
    expect(serializeFilterQuery(parseFilterQuery(withUtm), withUtm)).toBe('?utm_source=x&price=2');
    expect(serializeFilterQuery({ ...none, avail: 'in' }, '?utm_source=x')).toBe('?utm_source=x&avail=in');
    expect(serializeFilterQuery(none, '?utm_source=x&gclid=abc&price=2')).toBe('?utm_source=x&gclid=abc');
    expect(serializeFilterQuery(none, '?utm_source=x')).toBe('?utm_source=x');
});

test('applyCardVisibility hides non-matching cards with the hidden attribute', () => {
    document.body.innerHTML = `
        <div class="shop-product-card" data-f='{"c":["cakes"],"p":4500,"a":"in","o":["8in"]}'></div>
        <div class="shop-product-card" data-f='{"c":["patisserie"],"p":950,"a":"in","o":[]}'></div>
    `;
    const cards = document.querySelectorAll('.shop-product-card[data-f]');
    const result = applyCardVisibility(cards, { ...none, cat: 'cakes' }, facets);

    expect(result).toEqual({ shown: 1, total: 2 });
    expect(cards[0].hasAttribute('hidden')).toBe(false);
    expect(cards[1].hasAttribute('hidden')).toBe(true);
});

function mountShopFilters({ search = '', pathname = '/collections/cakes' } = {}) {
    document.body.innerHTML = `
        <header id="page-header"><a href="/">Home</a></header>
        <main id="page-main">
            <div class="shop-product-card" data-f='{"c":["cakes"],"p":4500,"a":"in","o":["8in"]}'></div>
            <div class="shop-product-card" data-f='{"c":["cakes"],"p":8000,"a":"mto","o":["8in"]}'></div>
            <div class="shop-product-card" data-f='{"c":["patisserie"],"p":950,"a":"in","o":[]}'></div>
            <div id="shop-filters" data-facets='{"price":[{"id":0,"min":0,"max":2000},{"id":2,"min":4000,"max":null}]}'>
                <button type="button" data-filter-trigger>Filter</button>
            </div>
        </main>
        <div id="shop-filters-modal-layer">
            <aside id="shop-filters-drawer">
                <button type="button" data-close aria-label="Close filters">Close</button>
            </aside>
        </div>
    `;
    window.history.replaceState(null, '', pathname + search);
    const root = document.getElementById('shop-filters');
    const component = shopFilters();
    component.$el = root;
    component.$root = root;
    component.$refs = { closeBtn: document.querySelector('[data-close]') };
    component.$nextTick = (fn) => fn();
    component.init();

    return component;
}

beforeEach(() => {
    window.matchMedia = vi.fn().mockReturnValue({
        matches: false,
        addEventListener() {},
        removeEventListener() {},
        addListener() {},
        removeListener() {},
    });
});

afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
    document.body.removeAttribute('inert');
    document.body.style.overflow = '';
});

test('shopFilters ANDs across facets and keeps a single value per facet', () => {
    const ui = mountShopFilters();
    const cards = document.querySelectorAll('.shop-product-card[data-f]');

    ui.togglePrice('2');
    ui.toggleAvail('in');

    expect(ui.filters).toEqual({ cat: '', price: '2', avail: 'in', opt: '', sort: '' });
    expect(cards[0].hasAttribute('hidden')).toBe(false);
    expect(cards[1].hasAttribute('hidden')).toBe(true);
    expect(cards[2].hasAttribute('hidden')).toBe(true);

    ui.toggleCat('cakes');
    ui.toggleCat('patisserie');

    expect(ui.filters.cat).toBe('patisserie');
    expect(cards[0].hasAttribute('hidden')).toBe(true);
    expect(cards[2].hasAttribute('hidden')).toBe(true);
});

test('shopFilters mirrors state with replaceState and Clear all', () => {
    const replaceState = vi.spyOn(history, 'replaceState');
    const ui = mountShopFilters({ search: '?utm_source=x' });
    replaceState.mockClear();

    ui.toggleAvail('in');

    const mirrored = String(replaceState.mock.calls.at(-1)[2]);
    expect(mirrored).toContain('/collections/cakes');
    expect(mirrored).toContain('utm_source=x');
    expect(mirrored).toContain('avail=in');

    ui.clearAll();

    const cleared = String(replaceState.mock.calls.at(-1)[2]);
    expect(ui.filters).toEqual(none);
    expect(cleared).toContain('utm_source=x');
    expect(cleared).not.toContain('avail=');
    expect(document.querySelectorAll('.shop-product-card[data-f][hidden]')).toHaveLength(0);
});

test('shopFilters hydrates pressed state from the URL', () => {
    const ui = mountShopFilters({ search: '?price=2&avail=in' });
    const cards = document.querySelectorAll('.shop-product-card[data-f]');

    expect(ui.filters.price).toBe('2');
    expect(ui.filters.avail).toBe('in');
    expect(ui.isFiltered).toBe(true);
    expect(cards[0].hasAttribute('hidden')).toBe(false);
    expect(cards[1].hasAttribute('hidden')).toBe(false);
    expect(cards[2].hasAttribute('hidden')).toBe(false);
});

test('shopFilters slide-over traps focus and inerts the page background', () => {
    const ui = mountShopFilters();
    const trigger = document.querySelector('[data-filter-trigger]');
    const closeBtn = document.querySelector('[data-close]');
    const header = document.getElementById('page-header');
    const main = document.getElementById('page-main');
    const root = document.getElementById('shop-filters');
    const layer = document.getElementById('shop-filters-modal-layer');

    trigger.focus();
    ui.show(trigger);

    expect(ui.open).toBe(true);
    expect(document.activeElement).toBe(closeBtn);
    expect(header.hasAttribute('inert')).toBe(true);
    expect(main.hasAttribute('inert')).toBe(true);
    expect(root.hasAttribute('inert')).toBe(false);
    expect(layer.hasAttribute('inert')).toBe(false);

    ui.close();

    expect(ui.open).toBe(false);
    expect(document.activeElement).toBe(trigger);
    expect(header.hasAttribute('inert')).toBe(false);
    expect(main.hasAttribute('inert')).toBe(false);
});
