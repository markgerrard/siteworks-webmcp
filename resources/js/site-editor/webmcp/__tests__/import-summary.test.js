import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { IMPORT_SUMMARY_ID, dismissImportSummary, formatPence, noteLabel, showImportSummary } from '../import-summary.js';

const originalMatchMedia = window.matchMedia;

function committedImport(overrides = {}) {
    return {
        schema_version: 1,
        created: 2,
        failed: 1,
        new_revision: 4,
        results: [
            { source_row: 1, status: 'created', name: 'Almond Croissant', slug: 'almond-croissant', category: 'pastries', price_pence: 800, warnings: ['missing_description', 'duplicate_category'] },
            { source_row: 2, status: 'created', name: 'Pain au Chocolat', slug: 'pain-au-chocolat', category: 'pastries', price_pence: 900, warnings: [] },
            { source_row: 3, status: 'rejected', name: 'Baguette', errors: ['category_not_found'] },
        ],
        ...overrides,
    };
}

function panel() {
    return document.getElementById(IMPORT_SUMMARY_ID);
}

beforeEach(() => {
    document.body.replaceChildren();
    window.matchMedia = vi.fn(() => ({ matches: true }));
});

afterEach(() => {
    dismissImportSummary();
    document.body.replaceChildren();
    window.matchMedia = originalMatchMedia;
    delete window.Livewire;
});

test('renders one row per result with notes, and rejected rows carry their errors in red', () => {
    showImportSummary({ data: committedImport() });

    const root = panel();
    expect(root).not.toBeNull();
    expect(root.getAttribute('role')).toBe('dialog');
    expect(root.getAttribute('aria-modal')).toBe('false');
    expect(root.querySelector('h2').textContent).toBe('Imported 2 draft products');
    expect(root.textContent).toContain('Nothing has been published. Drafts stay hidden on the live shop until you publish.');

    const rows = [...root.querySelectorAll('[data-import-row]')];
    expect(rows).toHaveLength(3);
    expect(rows.map((row) => row.dataset.importRow)).toEqual(['created', 'created', 'rejected']);

    const cells = (row) => [...row.querySelectorAll('td')].map((cell) => cell.textContent);
    expect(cells(rows[0])).toEqual(['Almond Croissant', 'pastries', '8.00', 'Draft', 'No description; Duplicate category skipped']);
    expect(cells(rows[1])).toEqual(['Pain au Chocolat', 'pastries', '9.00', 'Draft', '—']);
    expect(cells(rows[2])).toEqual(['Baguette', '—', '—', 'Rejected', 'Category not found']);
    expect(rows[2].querySelector('[data-import-errors]')).not.toBeNull();
    expect(rows[0].querySelector('[data-import-errors]')).toBeNull();
});

test('a matched row is shown as Matched, counted in the header, and never as a draft', () => {
    showImportSummary({ data: committedImport({
        created: 1,
        matched: 1,
        failed: 0,
        results: [
            { source_row: 1, status: 'created', name: 'Pumpkin Spice Loaf', slug: 'pumpkin-spice-loaf', category: 'seasonal-fall', price_pence: 650, warnings: [] },
            { source_row: 2, status: 'matched', name: 'Fig & Walnut Tart', slug: 'fig-walnut-tart', product_id: 5, category: 'seasonal-fall', price_pence: 550, warnings: ['matches_existing'] },
        ],
    }) });

    const root = panel();
    expect(root.querySelector('h2').textContent).toBe('Imported 1 draft product');
    expect(root.querySelector('[data-import-matched]').textContent).toBe('1 row matched a product already in the catalogue and was left alone.');

    const rows = [...root.querySelectorAll('[data-import-row]')];
    expect(rows.map((row) => row.dataset.importRow)).toEqual(['created', 'matched']);
    const cells = [...rows[1].querySelectorAll('td')].map((cell) => cell.textContent);
    expect(cells).toEqual(['Fig & Walnut Tart', 'seasonal-fall', '5.50', 'Matched', 'Already in the catalogue']);
    expect(rows[1].querySelector('[data-import-errors]')).toBeNull();
});

test('a single created product reads in the singular', () => {
    showImportSummary({ data: committedImport({ created: 1, results: [{ source_row: 1, status: 'created', slug: 'one' }] }) });

    expect(panel().querySelector('h2').textContent).toBe('Imported 1 draft product');
    expect(panel().querySelector('[data-import-row] td').textContent).toBe('one');
});

test('a second import replaces the earlier panel instead of stacking', () => {
    showImportSummary({ data: committedImport() });
    showImportSummary({ data: committedImport({ created: 1, results: [committedImport().results[1]] }) });

    expect(document.querySelectorAll(`#${IMPORT_SUMMARY_ID}`)).toHaveLength(1);
    expect(panel().querySelectorAll('[data-import-row]')).toHaveLength(1);
});

test('Escape, the header close and the footer close all dismiss the panel', () => {
    showImportSummary({ data: committedImport() });
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    expect(panel()).toBeNull();

    showImportSummary({ data: committedImport() });
    panel().querySelector('[aria-label="Close import summary"]').click();
    expect(panel()).toBeNull();

    showImportSummary({ data: committedImport() });
    const footerClose = [...panel().querySelectorAll('button')].find((button) => button.textContent === 'Close');
    footerClose.click();
    expect(panel()).toBeNull();
});

test('review drafts asks the list for the draft filter through Livewire and closes', () => {
    window.Livewire = { dispatch: vi.fn() };
    showImportSummary({ data: committedImport() });

    panel().querySelector('[data-import-review]').click();

    expect(window.Livewire.dispatch).toHaveBeenCalledWith('shop-filter-drafts');
    expect(panel()).toBeNull();
});

test('review drafts still closes when Livewire is absent', () => {
    showImportSummary({ data: committedImport() });

    panel().querySelector('[data-import-review]').click();

    expect(panel()).toBeNull();
});

test('the slide is skipped under prefers-reduced-motion and used otherwise', () => {
    showImportSummary({ data: committedImport() });
    expect(panel().classList.contains('translate-x-full')).toBe(false);
    dismissImportSummary();
    expect(panel()).toBeNull();

    window.matchMedia = vi.fn(() => ({ matches: false }));
    showImportSummary({ data: committedImport() });
    expect(panel().classList.contains('translate-x-full')).toBe(true);
    dismissImportSummary();
    expect(panel().dataset.state).toBe('closed');
    expect(panel().classList.contains('translate-x-full')).toBe(true);
});

test('labels and prices degrade gracefully', () => {
    expect(noteLabel('missing_description')).toBe('No description');
    expect(noteLabel('price_missing')).toBe('No price — set a price before publishing');
    expect(noteLabel('matches_existing')).toBe('Already in the catalogue');
    expect(noteLabel('some_future_code')).toBe('some future code');
    expect(formatPence(1299)).toBe('12.99');
    expect(formatPence(null)).toBe('—');
});
