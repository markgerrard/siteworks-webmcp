import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import {
    QUOTE_TOOL_NAMES,
    installQuoteFormTools,
    prefillQuoteForm,
    quotePrefillSchema,
    quoteFormFields,
} from '../webmcp-quote.js';

const originalDocumentContext = Object.getOwnPropertyDescriptor(document, 'modelContext');
const originalNavigatorContext = Object.getOwnPropertyDescriptor(navigator, 'modelContext');

function fakeModelContext() {
    const tools = new Map();
    const aborted = [];

    return {
        tools,
        aborted,
        registerTool(def, options = {}) {
            tools.set(def.name, def);
            options.signal?.addEventListener('abort', () => {
                aborted.push(def.name);
                tools.delete(def.name);
            });
        },
    };
}

function defineContext(target, value) {
    Object.defineProperty(target, 'modelContext', { configurable: true, writable: true, value });
}

function restoreContext(target, descriptor) {
    if (descriptor) {
        Object.defineProperty(target, 'modelContext', descriptor);
    } else {
        delete target.modelContext;
    }
}

function quoteFormHtml({ marker = true, fulfilment = true } = {}) {
    return `
        <p data-webmcp-quote-hint hidden>Agent can fill this form</p>
        <form method="POST" action="/shop/quote" ${marker ? 'data-webmcp-quote-form' : ''} data-business-name="Camino Bakehouse">
            <input type="hidden" name="_token" value="csrf">
            <input type="hidden" name="quote_token" value="qt-1">
            <input type="text" name="hp_field" tabindex="-1" aria-hidden="true">
            <label><span>Name <span aria-hidden="true">*</span></span><input type="text" name="name" required maxlength="120"></label>
            <label><span>Email <span aria-hidden="true">*</span></span><input type="email" name="email" required maxlength="255"></label>
            <label><span>Phone</span><input type="tel" name="phone" maxlength="64"></label>
            <label><span>When do you need it?</span><input type="date" name="needed_by" min="2020-01-01"></label>
            <label><span>Number of people</span><input type="number" name="people_count" min="1" max="9999"></label>
            ${fulfilment ? `
            <fieldset>
                <legend>How should we get this to you?</legend>
                <label><input type="radio" name="fulfilment_method" value="delivery" checked><span>Delivery</span></label>
                <label><input type="radio" name="fulfilment_method" value="pickup"><span>Collect from the bakery</span></label>
                <label><span>Delivery postcode</span><input type="text" name="fulfilment_postcode" maxlength="16"></label>
            </fieldset>` : ''}
            <label><span>Message</span><textarea name="message" maxlength="1000"></textarea></label>
            <button type="submit">Request a quote</button>
        </form>
    `;
}

function parseResult(response) {
    expect(response).toEqual({ content: [{ type: 'text', text: expect.any(String) }] });

    return JSON.parse(response.content[0].text);
}

let handle;

beforeEach(() => {
    document.body.innerHTML = quoteFormHtml();
    handle = null;
});

afterEach(() => {
    handle?.unregister();
    restoreContext(document, originalDocumentContext);
    restoreContext(navigator, originalNavigatorContext);
    vi.useRealTimers();
});

test('registers nothing when the page has no quote form marker', () => {
    document.body.innerHTML = quoteFormHtml({ marker: false });
    const mc = fakeModelContext();
    defineContext(document, mc);

    handle = installQuoteFormTools();

    expect(handle).toBeNull();
    expect(mc.tools.size).toBe(0);
    expect(document.querySelector('[data-webmcp-quote-hint]').hidden).toBe(true);
});

test('registers nothing and keeps the hint hidden when the browser has no model context', () => {
    defineContext(document, undefined);
    defineContext(navigator, undefined);

    handle = installQuoteFormTools();

    expect(handle).toBeNull();
    expect(document.querySelector('[data-webmcp-quote-hint]').hidden).toBe(true);
});

test('registers both tools on the quote page and reveals the hint', () => {
    const mc = fakeModelContext();
    defineContext(document, mc);

    handle = installQuoteFormTools();

    expect([...mc.tools.keys()].sort()).toEqual([QUOTE_TOOL_NAMES.get, QUOTE_TOOL_NAMES.prefill].sort());
    expect(QUOTE_TOOL_NAMES.get).toBe('siteworks.get_quote_form');
    expect(QUOTE_TOOL_NAMES.prefill).toBe('siteworks.prefill_quote');
    expect(mc.tools.get(QUOTE_TOOL_NAMES.get).annotations.readOnlyHint).toBe(true);
    expect(mc.tools.get(QUOTE_TOOL_NAMES.prefill).annotations.readOnlyHint).toBe(false);
    expect(mc.tools.get(QUOTE_TOOL_NAMES.prefill).description).toMatch(/reviews the filled form and presses the send button/);
    expect(document.querySelector('[data-webmcp-quote-hint]').hidden).toBe(false);
});

test('falls back to navigator.modelContext when document.modelContext is absent', () => {
    const mc = fakeModelContext();
    defineContext(document, undefined);
    defineContext(navigator, mc);

    handle = installQuoteFormTools();

    expect(mc.tools.has(QUOTE_TOOL_NAMES.prefill)).toBe(true);
});

test('get_quote_form lists the visible fields, the fulfilment options and the business name', async () => {
    const mc = fakeModelContext();
    defineContext(document, mc);
    handle = installQuoteFormTools();

    const snapshot = parseResult(await mc.tools.get(QUOTE_TOOL_NAMES.get).execute({}));

    expect(snapshot.business_name).toBe('Camino Bakehouse');
    expect(snapshot.submit_label).toBe('Request a quote');
    expect(snapshot.required).toEqual(['name', 'email']);
    expect(snapshot.fields.map((field) => field.name)).toEqual([
        'name', 'email', 'phone', 'needed_by', 'people_count', 'fulfilment_method', 'fulfilment_postcode', 'message',
    ]);
    expect(snapshot.fields.find((field) => field.name === 'name')).toMatchObject({ label: 'Name', type: 'text', required: true, value: '', max_length: 120 });
    expect(snapshot.fields.find((field) => field.name === 'needed_by')).toMatchObject({ type: 'date', min: '2020-01-01' });
    expect(snapshot.fields.find((field) => field.name === 'fulfilment_method')).toMatchObject({
        label: 'How should we get this to you?',
        type: 'choice',
        value: 'delivery',
        options: [{ value: 'delivery', label: 'Delivery' }, { value: 'pickup', label: 'Collect from the bakery' }],
    });
    expect(snapshot.fulfilment_method_options.map((option) => option.value)).toEqual(['delivery', 'pickup']);
    expect(snapshot.fields.some((field) => ['_token', 'quote_token', 'hp_field'].includes(field.name))).toBe(false);
    expect(JSON.stringify(snapshot)).not.toContain('elements');
});

test('the prefill input schema is built from the form and rejects unknown keys', () => {
    const schema = quotePrefillSchema(quoteFormFields(document.querySelector('form')));

    expect(schema.type).toBe('object');
    expect(schema.additionalProperties).toBe(false);
    expect(schema.required).toBeUndefined();
    expect(Object.keys(schema.properties)).toEqual([
        'name', 'email', 'phone', 'needed_by', 'people_count', 'fulfilment_method', 'fulfilment_postcode', 'message',
    ]);
    expect(schema.properties.fulfilment_method).toMatchObject({ type: 'string', enum: ['delivery', 'pickup'] });
    expect(schema.properties.needed_by).toMatchObject({ type: 'string', format: 'date' });
    expect(schema.properties.people_count).toMatchObject({ type: 'integer', minimum: 1, maximum: 9999 });
    expect(schema.properties.email).toMatchObject({ type: 'string', format: 'email', maxLength: 255 });
    expect(schema.properties.message.description).toMatch(/short readable lines/);
    expect(schema.properties.name.maxLength).toBe(120);
});

test('prefill fills the fields, fires input and change, highlights them and never submits', async () => {
    vi.useFakeTimers();
    const mc = fakeModelContext();
    defineContext(document, mc);
    handle = installQuoteFormTools();

    const form = document.querySelector('form');
    const submitted = vi.fn((event) => event.preventDefault());
    form.addEventListener('submit', submitted);
    form.requestSubmit = vi.fn();
    form.submit = vi.fn();
    const events = [];
    form.addEventListener('input', (event) => events.push(['input', event.target.name]));
    form.addEventListener('change', (event) => events.push(['change', event.target.name]));
    const scrolled = vi.fn();
    form.elements.name.scrollIntoView = scrolled;

    const message = 'Birthday cake for 30 people\nChocolate\nBudget about $150\nOn top: Happy 40th, Maya';
    const result = parseResult(await mc.tools.get(QUOTE_TOOL_NAMES.prefill).execute({
        name: 'Maya Chen',
        fulfilment_method: 'pickup',
        needed_by: '2030-06-15',
        people_count: 30,
        message,
    }));

    expect(result).toEqual({
        ok: true,
        filled: ['name', 'needed_by', 'people_count', 'fulfilment_method', 'message'],
        missing_required: ['email'],
    });
    expect(form.elements.name.value).toBe('Maya Chen');
    expect(form.elements.needed_by.value).toBe('2030-06-15');
    expect(form.elements.people_count.value).toBe('30');
    expect(form.elements.message.value).toBe(message);
    expect(form.querySelector('input[value="pickup"]').checked).toBe(true);
    expect(form.querySelector('input[value="delivery"]').checked).toBe(false);
    expect(form.elements.email.value).toBe('');
    expect(form.elements.phone.value).toBe('');
    expect(events).toContainEqual(['input', 'name']);
    expect(events).toContainEqual(['change', 'name']);
    expect(events).toContainEqual(['change', 'fulfilment_method']);
    expect(events).toContainEqual(['input', 'message']);
    expect(scrolled).toHaveBeenCalledTimes(1);
    expect(form.elements.name.hasAttribute('data-agent-filled')).toBe(true);
    expect(form.elements.email.hasAttribute('data-agent-filled')).toBe(false);

    vi.runAllTimers();
    expect(form.elements.name.hasAttribute('data-agent-filled')).toBe(false);

    expect(submitted).not.toHaveBeenCalled();
    expect(form.requestSubmit).not.toHaveBeenCalled();
    expect(form.submit).not.toHaveBeenCalled();
});

test('prefill reports no missing required fields once they are all filled', () => {
    const result = prefillQuoteForm(document.querySelector('form'), { name: 'Maya Chen', email: 'maya@example.com' });

    expect(result).toEqual({ ok: true, filled: ['name', 'email'], missing_required: [] });
});

test('prefill rejects unknown keys and fills nothing', async () => {
    const mc = fakeModelContext();
    defineContext(document, mc);
    handle = installQuoteFormTools();
    const form = document.querySelector('form');

    const result = parseResult(await mc.tools.get(QUOTE_TOOL_NAMES.prefill).execute({ name: 'Maya', flavour: 'chocolate' }));

    expect(result.ok).toBe(false);
    expect(result.error.code).toBe('unknown_field');
    expect(result.error.unknown).toEqual(['flavour']);
    expect(result.error.message).toContain('siteworks.get_quote_form');
    expect(form.elements.name.value).toBe('');
});

test('prefill rejects values that do not fit their field and fills nothing', () => {
    const form = document.querySelector('form');

    const badChoice = prefillQuoteForm(form, { name: 'Maya', fulfilment_method: 'courier' });
    const badDate = prefillQuoteForm(form, { needed_by: 'Saturday' });
    const badCount = prefillQuoteForm(form, { people_count: 'thirty' });
    const badInput = prefillQuoteForm(form, 'name=Maya');

    expect(badChoice.ok).toBe(false);
    expect(badChoice.error.code).toBe('invalid_value');
    expect(badChoice.error.invalid).toEqual([{ field: 'fulfilment_method', problem: 'must be one of: delivery, pickup' }]);
    expect(badDate.error.invalid[0].problem).toMatch(/ISO date/);
    expect(badCount.error.invalid[0].problem).toMatch(/whole number/);
    expect(badInput.error.code).toBe('invalid_input');
    expect(form.elements.name.value).toBe('');
    expect(form.querySelector('input[value="delivery"]').checked).toBe(true);
});

test('prefill reads the form without a fulfilment fieldset', () => {
    document.body.innerHTML = quoteFormHtml({ fulfilment: false });
    const form = document.querySelector('form');

    const fields = quoteFormFields(form).map((field) => field.name);
    const result = prefillQuoteForm(form, { fulfilment_method: 'pickup' });

    expect(fields).not.toContain('fulfilment_method');
    expect(result.error.code).toBe('unknown_field');
});

test('removes the tools on pagehide and restores them when the page is shown from cache', () => {
    const mc = fakeModelContext();
    defineContext(document, mc);
    handle = installQuoteFormTools();

    window.dispatchEvent(new Event('pagehide'));

    expect(mc.tools.size).toBe(0);
    expect(mc.aborted.sort()).toEqual([QUOTE_TOOL_NAMES.get, QUOTE_TOOL_NAMES.prefill].sort());

    const restored = new Event('pageshow');
    Object.defineProperty(restored, 'persisted', { value: true });
    window.dispatchEvent(restored);

    expect(mc.tools.size).toBe(2);

    handle.unregister();
    handle = null;
    expect(mc.tools.size).toBe(0);
});
