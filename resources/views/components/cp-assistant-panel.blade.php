@props([
    'toggle' => false,
])

@if ($toggle)
    {{-- Icon-only in the topbar: the tooltip + aria-label carry the name. --}}
    <flux:tooltip :content="__('Assistant')" position="bottom">
    <flux:button
        type="button"
        variant="ghost"
        icon="sparkles"
        data-icon="sparkles"
        data-cp-assistant-toggle
        :aria-label="__('Assistant')"
        x-data="{
            open: false,
            init() {
                try {
                    this.open = localStorage.getItem('siteworks.assistant.open') === '1';
                } catch (e) {}
            },
        }"
        x-bind:aria-expanded="open"
        x-on:cp-assistant-changed.window="open = $event.detail.open"
        x-on:click="$dispatch('cp-assistant-toggle', { toggle: $el })"
        class="cursor-pointer"
    />
    </flux:tooltip>
@else
    <div
        x-data="{
            open: false,
            lastToggle: null,
            init() {
                try {
                    this.open = localStorage.getItem('siteworks.assistant.open') === '1';
                } catch (e) {}
                this.$watch('open', value => {
                    try {
                        localStorage.setItem('siteworks.assistant.open', value ? '1' : '0');
                    } catch (e) {}
                    this.$dispatch('cp-assistant-changed', { open: value });
                    if (value) {
                        this.$nextTick(() => this.$refs.assistantPanel?.focus({ preventScroll: true }));
                    } else {
                        this.$nextTick(() => {
                            const toggle = (this.lastToggle && document.contains(this.lastToggle))
                                ? this.lastToggle
                                : document.querySelector('[data-cp-assistant-toggle]');
                            toggle?.focus({ preventScroll: true });
                        });
                    }
                });
            },
            toggle(el) {
                if (el) this.lastToggle = el;
                this.open = ! this.open;
            },
            close() { this.open = false; },
        }"
        x-on:cp-assistant-toggle.window="toggle($event.detail && $event.detail.toggle)"
        x-on:keydown.escape.window="if (open) close()"
        class="contents"
    >
        <div
            data-cp-assistant-backdrop
            x-show="open"
            x-cloak
            x-on:click="close()"
            x-transition:enter="transition ease-out duration-200 motion-reduce:transition-none"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-out duration-200 motion-reduce:transition-none"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="xl:hidden fixed inset-x-0 bottom-0 top-[var(--cp-topbar)] z-40 bg-black/40"
        ></div>

        <aside
            data-cp-assistant-panel
            x-ref="assistantPanel"
            x-show="open"
            x-cloak
            x-transition:enter="transition ease-out duration-200 motion-reduce:transition-none"
            x-transition:enter-start="translate-x-full opacity-0"
            x-transition:enter-end="translate-x-0 opacity-100"
            x-transition:leave="transition ease-out duration-200 motion-reduce:transition-none"
            x-transition:leave-start="translate-x-0 opacity-100"
            x-transition:leave-end="translate-x-full opacity-0"
            tabindex="-1"
            role="complementary"
            aria-label="Assistant"
            class="fixed top-[var(--cp-topbar)] right-0 bottom-0 z-50 flex w-[360px] max-w-[100vw] flex-col border-s border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 xl:sticky xl:top-[var(--cp-topbar)] xl:bottom-auto xl:z-auto xl:h-[calc(100dvh-var(--cp-topbar))] xl:[grid-area:aside]"
            style="width: 360px"
        >
            <div class="flex items-center justify-between gap-3 border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                <flux:heading size="sm">{{ __('Assistant') }}</flux:heading>
                <flux:button
                    type="button"
                    variant="ghost"
                    icon="x-mark"
                    size="sm"
                    x-on:click="close()"
                    :aria-label="__('Close assistant')"
                    class="cursor-pointer"
                />
            </div>

            <div class="space-y-3 border-b border-zinc-200 px-4 py-3 text-sm dark:border-zinc-700">
                <p class="text-zinc-600 dark:text-zinc-300">{{ __('Help') }}</p>
                <ul class="space-y-1">
                    <li>
                        <a href="#" class="text-accent hover:underline">{{ __('Docs') }}</a>
                    </li>
                    <li>
                        <a href="#" class="text-accent hover:underline">{{ __('Contact support') }}</a>
                    </li>
                </ul>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto px-4 py-3 text-sm text-zinc-500 dark:text-zinc-400">
                {{-- Empty transcript. --}}
            </div>

            <div class="border-t border-zinc-200 p-3 dark:border-zinc-700">
                <textarea
                    disabled
                    rows="2"
                    class="w-full resize-none rounded-md border border-zinc-200 bg-zinc-100 px-3 py-2 text-sm text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400"
                    placeholder="{{ __('Assistant coming soon') }}"
                ></textarea>
            </div>
        </aside>
    </div>
@endif
