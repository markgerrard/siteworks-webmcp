export function productMatches(f, filters, facets) {
    if (! f || typeof f !== 'object') {
        return true;
    }

    if (filters.cat && ! (Array.isArray(f.c) && f.c.indexOf(filters.cat) !== -1)) {
        return false;
    }

    if (filters.avail && f.a !== filters.avail) {
        return false;
    }

    if (filters.opt && ! (Array.isArray(f.o) && f.o.indexOf(filters.opt) !== -1)) {
        return false;
    }

    if (filters.price !== '' && filters.price !== null && filters.price !== undefined) {
        const buckets = (facets && Array.isArray(facets.price)) ? facets.price : [];
        const bucket = buckets.find((row) => String(row.id) === String(filters.price));
        if (bucket) {
            const price = Number(f.p);
            if (price < Number(bucket.min || 0)) {
                return false;
            }
            if (bucket.max !== null && bucket.max !== undefined && price >= Number(bucket.max)) {
                return false;
            }
        }
    }

    return true;
}

export function parseFilterQuery(search) {
    const raw = String(search || '');
    const params = new URLSearchParams(raw.charAt(0) === '?' ? raw.slice(1) : raw);

    return {
        cat: params.get('cat') || '',
        price: params.get('price') || '',
        avail: params.get('avail') || '',
        opt: params.get('opt') || '',
        sort: params.get('sort') || '',
    };
}

export function serializeFilterQuery(filters, search) {
    const raw = String(search || '');
    const params = new URLSearchParams(raw.charAt(0) === '?' ? raw.slice(1) : raw);
    const ordered = [
        ['avail', filters.avail || ''],
        ['cat', filters.cat || ''],
        ['opt', filters.opt || ''],
        ['price', filters.price === 0 || filters.price === '0' ? '0' : (filters.price || '')],
        ['sort', filters.sort || ''],
    ];
    ordered.forEach(([key, value]) => {
        if (value !== '') {
            params.set(key, String(value));
        } else {
            params.delete(key);
        }
    });
    const query = params.toString();

    return query ? '?' + query : '';
}

export function applyCardVisibility(cards, filters, facets) {
    let shown = 0;
    const list = Array.from(cards || []);
    list.forEach((el) => {
        let f = null;
        try {
            f = JSON.parse(el.getAttribute('data-f') || 'null');
        } catch (e) {
            f = null;
        }
        const match = productMatches(f, filters, facets);
        if (match) {
            el.removeAttribute('hidden');
            shown += 1;
        } else {
            el.setAttribute('hidden', '');
        }
    });

    return { shown, total: list.length };
}

function isVisibleFocusTarget(el) {
    return Boolean(el && (el.offsetParent !== null || (el.getClientRects && el.getClientRects().length > 0)));
}

export function shopFilters() {
    const reduceMotion = typeof window !== 'undefined'
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    return {
        open: false,
        facets: { category: [], price: [], availability: [], options: [] },
        filters: { cat: '', price: '', avail: '', opt: '', sort: '' },
        shown: 0,
        total: 0,
        lastTrigger: null,
        reduceMotion,
        init() {
            try {
                this.facets = JSON.parse(this.$el.getAttribute('data-facets') || '{}') || this.facets;
            } catch (e) {
                this.facets = { category: [], price: [], availability: [], options: [] };
            }
            this.filters = parseFilterQuery(typeof window !== 'undefined' ? window.location.search : '');
            const cards = document.querySelectorAll('.shop-product-card[data-f]');
            this.total = cards.length;
            this.shown = cards.length;
        },
        get countLabel() {
            return 'Showing ' + this.shown + ' of ' + this.total;
        },
        get isFiltered() {
            return Boolean(this.filters.cat || this.filters.price !== '' || this.filters.avail || this.filters.opt);
        },
        get backdropBind() {
            return this.reduceMotion ? {} : { ['x-transition.opacity.duration.200ms']: true };
        },
        toggleCat(slug) {
            this.filters.cat = this.filters.cat === slug ? '' : slug;
            this.apply({ syncUrl: true });
        },
        togglePrice(id) {
            const value = String(id);
            this.filters.price = this.filters.price === value ? '' : value;
            this.apply({ syncUrl: true });
        },
        toggleAvail(id) {
            this.filters.avail = this.filters.avail === id ? '' : id;
            this.apply({ syncUrl: true });
        },
        toggleOpt(id) {
            this.filters.opt = this.filters.opt === id ? '' : id;
            this.apply({ syncUrl: true });
        },
        clearAll() {
            this.filters = { cat: '', price: '', avail: '', opt: '', sort: this.filters.sort || '' };
            this.apply({ syncUrl: true });
        },
        apply(opts) {
            const cards = document.querySelectorAll('.shop-product-card[data-f]');
            const result = applyCardVisibility(cards, this.filters, this.facets);
            this.shown = result.shown;
            this.total = result.total;
            if (opts && opts.syncUrl && typeof history !== 'undefined' && history.replaceState) {
                const next = window.location.pathname + serializeFilterQuery(this.filters, window.location.search) + window.location.hash;
                history.replaceState(null, '', next);
            }
        },
        show(trigger) {
            this.lastTrigger = trigger || this.lastTrigger;
            if (this.open) {
                return;
            }
            this.open = true;
            document.body.style.overflow = 'hidden';
            this.setBackgroundInert(true);
            this.$nextTick(() => {
                const closeButton = document.querySelector('#shop-filters-modal-layer [aria-label="Close filters"]');
                if (closeButton && closeButton.focus) {
                    closeButton.focus();
                }
            });
        },
        close() {
            if (! this.open) {
                return;
            }
            this.open = false;
            document.body.style.overflow = '';
            this.setBackgroundInert(false);
            this.$nextTick(() => this.restoreFocus());
        },
        setBackgroundInert(on) {
            const modalLayer = document.getElementById('shop-filters-modal-layer');
            Array.from(document.body.children).forEach((el) => {
                if (el === modalLayer || (modalLayer && el.contains(modalLayer))) {
                    return;
                }
                if (on) {
                    if (! el.hasAttribute('inert')) {
                        el.setAttribute('inert', '');
                        el.setAttribute('data-shop-filters-inert', '');
                    }
                } else if (el.hasAttribute('data-shop-filters-inert')) {
                    el.removeAttribute('inert');
                    el.removeAttribute('data-shop-filters-inert');
                }
            });
        },
        restoreFocus() {
            const target = this.lastTrigger;
            if (target && target.focus) {
                target.focus();
                return;
            }
            const body = document.body;
            body.setAttribute('tabindex', '-1');
            body.focus();
            body.removeAttribute('tabindex');
        },
        trap(event) {
            if (! this.open) {
                return;
            }
            const root = document.getElementById('shop-filters-drawer');
            if (! root) {
                return;
            }
            const focusable = Array.from(root.querySelectorAll('a[href], button:not([disabled]), input, select, textarea, [tabindex]:not([tabindex="-1"])'))
                .filter((el) => isVisibleFocusTarget(el));
            if (! focusable.length) {
                return;
            }
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (! event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        },
    };
}
