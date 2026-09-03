import { expect, test } from 'vitest';
import { applyMutationResult, chooseFocusRestoreTarget } from '../cart-drawer-logic.js';

const lastValid = {
    items: [{ id: 7, name: 'Damson Jam', qty: 1 }],
    upsell: [{ slug: 'tart' }],
    count: 1,
    subtotalDisplay: '£20.00',
    freeShipping: { progress_pct: 40 },
    announce: '',
};

test('applyMutationResult replaces cart state on a 2xx payload', () => {
    const next = applyMutationResult(lastValid, { ok: true }, {
        items: [{ id: 7, name: 'Damson Jam', qty: 2 }],
        upsell: [],
        count: 2,
        subtotal_display: '£40.00',
        free_shipping: { progress_pct: 80 },
    });

    expect(next.items).toEqual([{ id: 7, name: 'Damson Jam', qty: 2 }]);
    expect(next.count).toBe(2);
    expect(next.subtotalDisplay).toBe('£40.00');
    expect(next.upsell).toEqual([]);
    expect(next.freeShipping).toEqual({ progress_pct: 80 });
    expect(next.error).toBe('');
    expect(next.message).toBe('');
});

test('applyMutationResult keeps last-valid items on a 422 with an error message', () => {
    const next = applyMutationResult(lastValid, { ok: false, status: 422 }, {
        error: { code: 'insufficient_stock', message: 'Not enough stock available.' },
        cart: { items: [], count: 0, subtotal_display: '', upsell: [], free_shipping: null },
    });

    expect(next.items).toEqual(lastValid.items);
    expect(next.count).toBe(1);
    expect(next.subtotalDisplay).toBe('£20.00');
    expect(next.upsell).toEqual(lastValid.upsell);
    expect(next.freeShipping).toEqual(lastValid.freeShipping);
    expect(next.error).toBe('Not enough stock available.');
    expect(next.message).toBe('Not enough stock available.');
});

test('applyMutationResult uses a generic message when the response is not JSON', () => {
    const next = applyMutationResult(lastValid, { ok: false, status: 500 }, null);

    expect(next.items).toEqual(lastValid.items);
    expect(next.count).toBe(1);
    expect(next.error).toBe("Couldn't update your cart");
    expect(next.message).toBe("Couldn't update your cart");
});

test('applyMutationResult uses a generic message on a network failure', () => {
    const next = applyMutationResult(lastValid, null, null);

    expect(next.items).toEqual(lastValid.items);
    expect(next.count).toBe(1);
    expect(next.error).toBe("Couldn't update your cart");
    expect(next.message).toBe("Couldn't update your cart");
});

function candidate({ visible, rects, id }) {
    return {
        id,
        offsetParent: visible === false ? null : {},
        getClientRects: () => {
            if (rects) {
                return rects;
            }

            return visible === false ? [] : [{ width: 44, height: 44 }];
        },
    };
}

test('chooseFocusRestoreTarget keeps a visible lastTrigger', () => {
    const lastTrigger = candidate({ visible: true, id: 'last' });

    expect(chooseFocusRestoreTarget({
        lastTrigger,
        headerControls: [candidate({ visible: true, id: 'header' })],
        mobileToggle: candidate({ visible: true, id: 'toggle' }),
    })).toBe(lastTrigger);
});

test('chooseFocusRestoreTarget prefers a visible header cart control when lastTrigger is hidden', () => {
    const visibleHeader = candidate({ visible: true, id: 'header' });

    expect(chooseFocusRestoreTarget({
        lastTrigger: candidate({ visible: false, id: 'mobile-cart' }),
        headerControls: [candidate({ visible: false, id: 'hidden-header' }), visibleHeader],
        mobileToggle: candidate({ visible: true, id: 'toggle' }),
    })).toBe(visibleHeader);
});

test('chooseFocusRestoreTarget falls back to the mobile menu toggle', () => {
    const toggle = candidate({ visible: true, id: 'toggle' });

    expect(chooseFocusRestoreTarget({
        lastTrigger: candidate({ visible: false, id: 'mobile-cart' }),
        headerControls: [candidate({ visible: false, id: 'header' })],
        mobileToggle: toggle,
    })).toBe(toggle);
});

test('chooseFocusRestoreTarget returns null when every candidate is hidden', () => {
    expect(chooseFocusRestoreTarget({
        lastTrigger: candidate({ visible: false, id: 'mobile-cart' }),
        headerControls: [candidate({ visible: false, id: 'header' })],
        mobileToggle: candidate({ visible: false, id: 'toggle' }),
    })).toBeNull();
});

test('chooseFocusRestoreTarget treats a zero-rect lastTrigger as hidden', () => {
    const toggle = candidate({ visible: true, id: 'toggle' });

    expect(chooseFocusRestoreTarget({
        lastTrigger: candidate({ visible: true, rects: [], id: 'clipped' }),
        headerControls: [],
        mobileToggle: toggle,
    })).toBe(toggle);
});
