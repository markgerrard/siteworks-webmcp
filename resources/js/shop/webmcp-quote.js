// Storefront WebMCP tools for the quote form.
//
// A shopper's own browser agent can read the form and fill it in; the shopper
// reviews what was filled and presses the send button themselves. Nothing here
// talks to the server: the only side effect is the DOM state of the form.
//
// This file is inlined into the quote view (with the `export` keywords
// stripped) exactly like cart-drawer-logic.js, so it must stay free of
// imports and only use top-level `export function` / `export const`.

export const QUOTE_FORM_SELECTOR = 'form[data-webmcp-quote-form]';
export const QUOTE_HINT_SELECTOR = '[data-webmcp-quote-hint]';
export const QUOTE_TOOL_NAMES = Object.freeze({
    get: 'siteworks.get_quote_form',
    prefill: 'siteworks.prefill_quote',
});

const FILLED_ATTRIBUTE = 'data-agent-filled';
const HIGHLIGHT_MS = 2500;
const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/;

function quoteModelContextHost() {
    if (typeof document !== 'undefined' && document.modelContext?.registerTool) {
        return document.modelContext;
    }
    if (typeof navigator !== 'undefined' && navigator.modelContext?.registerTool) {
        return navigator.modelContext;
    }

    return null;
}

function quoteTextResult(payload) {
    return { content: [{ type: 'text', text: JSON.stringify(payload) }] };
}

function quoteLabelText(node) {
    if (! node) {
        return '';
    }
    // The visible caption is the first span of the label; a trailing "*" only marks
    // the field required and is reported separately.
    const caption = node.querySelector('span') ?? node;

    return String(caption.textContent ?? '').replace(/\*/g, '').replace(/\s+/g, ' ').trim();
}

function quoteFieldLabel(element) {
    if (element.type === 'radio') {
        return quoteLabelText(element.closest('fieldset')?.querySelector('legend'));
    }

    return quoteLabelText(element.labels?.[0] ?? element.closest('label'));
}

function quoteFieldType(element) {
    if (element.tagName === 'TEXTAREA') {
        return 'textarea';
    }
    if (element.type === 'radio') {
        return 'choice';
    }

    return element.type || 'text';
}

/**
 * The fields an agent may fill, in document order. Hidden inputs (token, csrf) and the
 * honeypot are never listed — they are not the shopper's to fill. Radio buttons sharing a
 * name collapse into one choice field carrying its options.
 */
export function quoteFormFields(form) {
    const fields = [];
    const seen = new Map();

    for (const element of Array.from(form.elements)) {
        const name = element.name;
        if (! name || name === '_token') {
            continue;
        }
        if (! ['INPUT', 'TEXTAREA', 'SELECT'].includes(element.tagName)) {
            continue;
        }
        if (['hidden', 'submit', 'button', 'reset', 'file', 'checkbox'].includes(element.type)) {
            continue;
        }
        if (element.getAttribute('aria-hidden') === 'true' || element.tabIndex === -1) {
            continue;
        }

        if (element.type === 'radio') {
            let field = seen.get(name);
            if (! field) {
                field = {
                    name,
                    label: quoteFieldLabel(element),
                    type: 'choice',
                    required: element.required,
                    value: '',
                    options: [],
                    elements: [],
                };
                seen.set(name, field);
                fields.push(field);
            }
            field.options.push({
                value: element.value,
                label: quoteLabelText(element.closest('label')?.querySelector('span') ?? element.closest('label')),
            });
            field.elements.push(element);
            if (element.checked) {
                field.value = element.value;
            }

            continue;
        }

        if (seen.has(name)) {
            continue;
        }
        const field = {
            name,
            label: quoteFieldLabel(element),
            type: quoteFieldType(element),
            required: element.required,
            value: element.value ?? '',
            elements: [element],
        };
        const maxLength = Number(element.getAttribute('maxlength'));
        if (Number.isInteger(maxLength) && maxLength > 0) {
            field.max_length = maxLength;
        }
        if (element.type === 'date' && element.min) {
            field.min = element.min;
        }
        if (element.type === 'number') {
            if (element.min !== '') {
                field.min = Number(element.min);
            }
            if (element.max !== '') {
                field.max = Number(element.max);
            }
        }
        seen.set(name, field);
        fields.push(field);
    }

    return fields;
}

function quotePublicField(field) {
    const { elements, ...rest } = field;

    return rest;
}

function quotePropertySchema(field) {
    const description = field.label ? `${field.label}.` : `${field.name}.`;

    switch (field.type) {
        case 'choice':
            return {
                type: 'string',
                enum: field.options.map((option) => option.value),
                description: `${description} One of the listed options.`,
            };
        case 'date':
            return {
                type: 'string',
                format: 'date',
                pattern: '^\\d{4}-\\d{2}-\\d{2}$',
                description: `${description} ISO date, YYYY-MM-DD${field.min ? `, on or after ${field.min}` : ''}.`,
            };
        case 'number':
            return {
                type: 'integer',
                ...(field.min !== undefined ? { minimum: field.min } : {}),
                ...(field.max !== undefined ? { maximum: field.max } : {}),
                description,
            };
        case 'email':
            return { type: 'string', format: 'email', maxLength: field.max_length, description };
        case 'textarea':
            return {
                type: 'string',
                maxLength: field.max_length,
                description: field.name === 'message'
                    ? `${description} Anything the form has no field of its own for (headcount, flavour, budget, wording to write on the order) goes here as short readable lines.`
                    : description,
            };
        default:
            return { type: 'string', maxLength: field.max_length, description };
    }
}

/**
 * The prefill input schema is derived from the form as rendered, so an agent can only
 * name fields the shopper can see. Every property is optional; unknown keys are rejected.
 */
export function quotePrefillSchema(fields) {
    const properties = {};
    for (const field of fields) {
        const schema = quotePropertySchema(field);
        if (schema.maxLength === undefined) {
            delete schema.maxLength;
        }
        properties[field.name] = schema;
    }

    return { type: 'object', properties, additionalProperties: false };
}

export function quoteFormSnapshot(form) {
    const fields = quoteFormFields(form);

    return {
        business_name: form.dataset.businessName ?? '',
        submit_label: form.querySelector('button[type="submit"]')?.textContent?.trim() ?? '',
        fields: fields.map(quotePublicField),
        required: fields.filter((field) => field.required).map((field) => field.name),
        fulfilment_method_options: fields.find((field) => field.name === 'fulfilment_method')?.options ?? [],
    };
}

function quoteValidateValue(field, value) {
    switch (field.type) {
        case 'choice':
            return field.options.some((option) => option.value === value)
                ? null
                : `must be one of: ${field.options.map((option) => option.value).join(', ')}`;
        case 'date':
            if (typeof value !== 'string' || ! ISO_DATE.test(value)) {
                return 'must be an ISO date (YYYY-MM-DD)';
            }
            if (field.min && value < field.min) {
                return `must be on or after ${field.min}`;
            }

            return null;
        case 'number':
            if (! Number.isInteger(value)) {
                return 'must be a whole number';
            }
            if (field.min !== undefined && value < field.min) {
                return `must be at least ${field.min}`;
            }
            if (field.max !== undefined && value > field.max) {
                return `must be at most ${field.max}`;
            }

            return null;
        default:
            return typeof value === 'string' ? null : 'must be a string';
    }
}

function quoteReducedMotion() {
    return typeof window !== 'undefined'
        && typeof window.matchMedia === 'function'
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function quoteDispatchFilled(element) {
    element.dispatchEvent(new Event('input', { bubbles: true }));
    element.dispatchEvent(new Event('change', { bubbles: true }));
}

function quoteHighlight(elements) {
    for (const element of elements) {
        element.setAttribute(FILLED_ATTRIBUTE, '');
    }
    setTimeout(() => {
        for (const element of elements) {
            element.removeAttribute(FILLED_ATTRIBUTE);
        }
    }, HIGHLIGHT_MS);
}

/**
 * Writes the given values into the form and reports what was filled and which required
 * fields are still empty. Rejects the whole call, filling nothing, when a key is not a
 * form field or a value does not fit its field. Never submits.
 */
export function prefillQuoteForm(form, input) {
    if (input === null || typeof input !== 'object' || Array.isArray(input)) {
        return { ok: false, error: { code: 'invalid_input', message: 'Input must be an object of field values.' } };
    }

    const fields = quoteFormFields(form);
    const byName = new Map(fields.map((field) => [field.name, field]));
    const unknown = Object.keys(input).filter((key) => ! byName.has(key));
    if (unknown.length > 0) {
        return {
            ok: false,
            error: {
                code: 'unknown_field',
                message: `Not a field on this form: ${unknown.join(', ')}. Call ${QUOTE_TOOL_NAMES.get} for the field list.`,
                unknown,
            },
        };
    }

    const invalid = [];
    for (const [name, value] of Object.entries(input)) {
        if (value === null || value === undefined) {
            continue;
        }
        const problem = quoteValidateValue(byName.get(name), value);
        if (problem !== null) {
            invalid.push({ field: name, problem });
        }
    }
    if (invalid.length > 0) {
        return { ok: false, error: { code: 'invalid_value', message: 'Some values do not fit their field.', invalid } };
    }

    const filled = [];
    const touched = [];
    for (const field of fields) {
        if (! (field.name in input) || input[field.name] === null || input[field.name] === undefined) {
            continue;
        }
        const value = input[field.name];
        if (field.type === 'choice') {
            for (const radio of field.elements) {
                const wasChecked = radio.checked;
                radio.checked = radio.value === value;
                if (radio.checked && ! wasChecked) {
                    quoteDispatchFilled(radio);
                }
                if (radio.checked) {
                    touched.push(radio);
                }
            }
        } else {
            const element = field.elements[0];
            element.value = String(value);
            quoteDispatchFilled(element);
            touched.push(element);
        }
        filled.push(field.name);
    }

    if (touched.length > 0) {
        const first = touched[0];
        if (typeof first.scrollIntoView === 'function') {
            first.scrollIntoView({ behavior: quoteReducedMotion() ? 'auto' : 'smooth', block: 'center' });
        }
        quoteHighlight(touched);
    }

    const missingRequired = quoteFormFields(form)
        .filter((field) => field.required && String(field.value ?? '').trim() === '')
        .map((field) => field.name);

    return { ok: true, filled, missing_required: missingRequired };
}

function quoteToolDefinitions(form) {
    const fields = quoteFormFields(form);
    const business = form.dataset.businessName ? ` for ${form.dataset.businessName}` : '';

    return [
        {
            name: QUOTE_TOOL_NAMES.get,
            description: `Reads the quote request form${business}: each field's name, label, type, current value and options, plus which fields are required. Reads nothing from the server and changes nothing.`,
            inputSchema: { type: 'object', properties: {}, additionalProperties: false },
            annotations: { readOnlyHint: true, destructiveHint: false },
            execute: async () => quoteTextResult(quoteFormSnapshot(form)),
        },
        {
            name: QUOTE_TOOL_NAMES.prefill,
            description: `Fills in the quote request form${business} on the page with the values given; every field is optional and anything not passed is left as it is. Details the form has no field for go into message as short readable lines. This only edits the form in the browser: nothing is sent, and the shopper reviews the filled form and presses the send button themselves. Returns which fields were filled and which required fields are still empty.`,
            inputSchema: quotePrefillSchema(fields),
            annotations: { readOnlyHint: false, destructiveHint: false },
            execute: async (input) => quoteTextResult(prefillQuoteForm(form, input ?? {})),
        },
    ];
}

/**
 * Registers the two quote tools on the page's model context and returns a handle that
 * removes them. Returns null when the page has no quote form or the browser has no
 * model context, in which case nothing is registered and the hint stays hidden.
 */
export function installQuoteFormTools({ root = document } = {}) {
    const form = root.querySelector(QUOTE_FORM_SELECTOR);
    const host = quoteModelContextHost();
    if (! form || ! host) {
        return null;
    }

    const controllers = new Map();
    const register = () => {
        for (const definition of quoteToolDefinitions(form)) {
            controllers.get(definition.name)?.abort();
            const controller = new AbortController();
            controllers.set(definition.name, controller);
            host.registerTool(definition, { signal: controller.signal });
        }
    };
    const unregister = () => {
        for (const controller of controllers.values()) {
            controller.abort();
        }
        controllers.clear();
    };

    register();

    const hint = root.querySelector(QUOTE_HINT_SELECTOR);
    if (hint) {
        hint.hidden = false;
    }

    // Tools must not outlive the page: drop them when it is hidden, and put them back
    // when the browser restores the page from its back-forward cache.
    const onPageHide = () => unregister();
    const onPageShow = (event) => {
        if (event.persisted) {
            register();
        }
    };
    window.addEventListener('pagehide', onPageHide);
    window.addEventListener('pageshow', onPageShow);

    return {
        toolNames: Object.values(QUOTE_TOOL_NAMES),
        unregister() {
            unregister();
            window.removeEventListener('pagehide', onPageHide);
            window.removeEventListener('pageshow', onPageShow);
        },
    };
}

export function bootQuoteFormTools() {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => installQuoteFormTools(), { once: true });
    } else {
        installQuoteFormTools();
    }
}
