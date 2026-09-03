const GENERIC_CART_ERROR = "Couldn't update your cart";

export function applyMutationResult(state, res, data) {
    const preserved = {
        items: state.items,
        upsell: state.upsell,
        count: state.count,
        subtotalDisplay: state.subtotalDisplay,
        freeShipping: state.freeShipping,
    };

    if (! res || ! res.ok || ! data || typeof data !== 'object' || ! Array.isArray(data.items)) {
        const message = (data && data.error && typeof data.error.message === 'string' && data.error.message)
            ? data.error.message
            : GENERIC_CART_ERROR;

        return {
            ...preserved,
            error: message,
            message,
            announce: message,
        };
    }

    return {
        items: data.items,
        upsell: data.upsell || [],
        count: data.count ?? 0,
        subtotalDisplay: data.subtotal_display || '',
        freeShipping: data.free_shipping || null,
        error: '',
        message: '',
        announce: '',
    };
}

export function isVisibleFocusTarget(el) {
    if (! el) {
        return false;
    }
    if (el.offsetParent === null) {
        return false;
    }
    if (typeof el.getClientRects === 'function' && el.getClientRects().length === 0) {
        return false;
    }

    return true;
}

export function chooseFocusRestoreTarget(candidates) {
    const lastTrigger = candidates && candidates.lastTrigger;
    if (isVisibleFocusTarget(lastTrigger)) {
        return lastTrigger;
    }

    const headerControls = (candidates && candidates.headerControls) || [];
    for (let i = 0; i < headerControls.length; i++) {
        if (isVisibleFocusTarget(headerControls[i])) {
            return headerControls[i];
        }
    }

    const mobileToggle = candidates && candidates.mobileToggle;
    if (isVisibleFocusTarget(mobileToggle)) {
        return mobileToggle;
    }

    return null;
}
