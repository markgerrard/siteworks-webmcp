export function shopSortMenu() {
    return {
        open: false,
        isDesktop: false,
        init() {
            if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
                return;
            }
            const media = window.matchMedia('(min-width: 768px)');
            const sync = () => {
                this.isDesktop = media.matches;
                if (! this.isDesktop) {
                    this.open = false;
                }
            };
            sync();
            if (typeof media.addEventListener === 'function') {
                media.addEventListener('change', sync);
            } else if (typeof media.addListener === 'function') {
                media.addListener(sync);
            }
        },
        items() {
            const root = this.$refs && this.$refs.menu;
            if (! root) {
                return [];
            }

            return Array.from(root.querySelectorAll('[role="menuitem"]'));
        },
        toggle() {
            if (this.open) {
                this.close();
                return;
            }
            this.openAt(0);
        },
        openAt(index) {
            this.open = true;
            this.$nextTick(() => this.focusAt(index));
        },
        close() {
            if (! this.open) {
                return;
            }
            this.open = false;
            const trigger = this.$el.querySelector('#shop-sort-button');
            this.$nextTick(() => trigger && trigger.focus && trigger.focus());
        },
        focusAt(index) {
            const items = this.items();
            if (! items.length) {
                return;
            }
            const next = index < 0 ? items.length - 1 : index % items.length;
            items[next].focus();
        },
        move(delta) {
            const items = this.items();
            if (! items.length) {
                return;
            }
            const current = items.indexOf(document.activeElement);
            const from = current === -1 ? (delta > 0 ? -1 : 0) : current;
            const next = (from + delta + items.length) % items.length;
            items[next].focus();
        },
    };
}
