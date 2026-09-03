const wireIdOf = (el) => {
    const wireEl = el?.closest('[wire\\:id]') ?? el?.querySelector?.('[wire\\:id]') ?? null;

    return wireEl?.getAttribute('wire:id') ?? null;
};

const markedForm = (el) => {
    if (! el) {
        return null;
    }

    if (el.tagName === 'FORM') {
        return el;
    }

    return el.closest('form') ?? el.querySelector('form');
};

const createSaveBarStore = () => ({
    dirty: false,
    el: null,
    mark(el) {
        this.el = el;
        this.dirty = true;
    },
    save() {
        if (! this.el) {
            return;
        }

        const method = this.el.getAttribute('data-save-bar');
        const wireId = wireIdOf(this.el);

        if (method && wireId && window.Livewire) {
            // Do NOT clear here: the commit hook below clears only when the server round-trip
            // succeeds, so a failed/validating save keeps the bar (and the unsaved edits) visible.
            window.Livewire.find(wireId).call(method);

            return;
        }

        const form = markedForm(this.el);
        if (form) {
            form.requestSubmit();
        }

        this.dirty = false;
    },
    discard() {
        if (! this.el) {
            this.dirty = false;

            return;
        }

        const form = markedForm(this.el);
        if (form) {
            form.reset();
        }

        const wireId = wireIdOf(this.el);
        if (wireId && window.Livewire) {
            window.Livewire.find(wireId).$refresh();
        }

        this.dirty = false;
    },
});

const registerSaveBar = () => {
    if (! window.Alpine || window.Alpine.store('saveBar')) {
        return;
    }

    window.Alpine.store('saveBar', createSaveBarStore());
};

if (window.Alpine) {
    registerSaveBar();
} else {
    document.addEventListener('alpine:init', registerSaveBar);
}

const markFromEvent = (event) => {
    const marked = event.target.closest('[data-save-bar]');
    if (! marked || ! window.Alpine) {
        return;
    }

    window.Alpine.store('saveBar').mark(marked);
};

document.addEventListener('input', markFromEvent);
document.addEventListener('change', markFromEvent);

window.addEventListener('beforeunload', (event) => {
    if (window.Alpine?.store('saveBar')?.dirty) {
        event.preventDefault();
        event.returnValue = '';
    }
});

document.addEventListener('livewire:navigate', (event) => {
    const store = window.Alpine?.store('saveBar');
    if (! store?.dirty) {
        return;
    }

    if (! window.confirm('You have unsaved changes. Leave this page?')) {
        event.preventDefault();

        return;
    }

    // Confirmed leave: the marked element is about to be swapped out of the DOM, so the
    // store must not stay dirty (it would re-prompt on beforeunload and on the next navigate).
    store.dirty = false;
    store.el = null;
});

// A marked form submitted directly (its own Save button) is a save: clear the flag so the
// beforeunload guard does not fire on the resulting full-page navigation.
document.addEventListener('submit', (event) => {
    const marked = event.target.closest?.('[data-save-bar]');
    const store = window.Alpine?.store('saveBar');
    if (marked && store) {
        store.dirty = false;
        store.el = null;
    }
});

// Clear the flag only when a commit for the MARKED component succeeds — covers the bar's own
// Save, the component's own Save button (wire:click), and any other successful action on it.
document.addEventListener('livewire:init', () => {
    window.Livewire?.hook('commit', ({ component, succeed }) => {
        succeed(() => {
            const store = window.Alpine?.store('saveBar');
            if (! store?.dirty || ! store.el) {
                return;
            }

            if (wireIdOf(store.el) !== component.id) {
                return;
            }

            // Instant catalogue commits (variant/media) re-render the marked
            // component while top-level fields may still differ from the
            // persisted row. Keep the navigation guard when the editor says so.
            const stillDirty = Boolean(
                store.el.matches?.('[data-has-unsaved-changes="1"]')
                || store.el.querySelector?.('[data-has-unsaved-changes="1"]')
            );
            if (stillDirty) {
                return;
            }

            store.dirty = false;
            store.el = null;
        });
    });
});
