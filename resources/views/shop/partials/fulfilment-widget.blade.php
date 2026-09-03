@php
    $widget = $widget ?? null;
@endphp
@if (is_array($widget))
    <div
        class="mt-4"
        data-testid="fulfilment-widget"
        x-data="fulfilmentWidget({{ $widget['checked'] ? 'true' : 'false' }})"
        data-check-url="{{ $widget['check_url'] }}"
    >
        <div data-testid="fulfilment-widget-result" x-show="checked" @unless($widget['checked']) x-cloak @endunless>
            <p class="text-sm font-medium m-0" data-testid="fulfilment-postcode" x-show="! display">{{ $widget['display'] }}</p>
            <p class="text-sm font-medium m-0" data-testid="fulfilment-postcode" x-show="display" x-text="display" x-cloak></p>
            @if ($widget['miss'])
                <p class="text-sm mt-1 m-0" data-testid="fulfilment-miss">{{ $widget['miss'] }}</p>
            @endif
            <p class="text-sm mt-1 m-0" data-testid="fulfilment-miss" x-show="miss" x-text="miss" x-cloak></p>
            @foreach ($widget['lines'] as $line)
                <p class="text-sm mt-1 m-0" data-testid="fulfilment-line-{{ $line['method'] }}">{{ $line['text'] }}</p>
            @endforeach
            <template x-for="line in lines" :key="line.method">
                <p class="text-sm mt-1 m-0" :data-testid="'fulfilment-line-' + line.method" x-text="line.text"></p>
            </template>
            <p class="mt-2 m-0">
                <a
                    href="{{ $widget['check_url'] }}?change=1"
                    data-testid="fulfilment-change"
                    @click.prevent="change()"
                    class="text-sm underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                    style="color: var(--color-text); outline-color: var(--color-accent);"
                >Change</a>
            </p>
        </div>

        <form
            method="GET"
            action="{{ $widget['check_url'] }}"
            data-testid="fulfilment-widget-form"
            x-show="! checked"
            @if ($widget['checked']) x-cloak @endif
            @submit.prevent="check($event)"
            class="space-y-2"
        >
            <label class="block">
                <span class="text-sm font-medium">{{ $widget['prompt'] }}</span>
                <input
                    type="text"
                    name="postcode"
                    value="{{ old('postcode') }}"
                    autocomplete="postal-code"
                    maxlength="16"
                    class="mt-1 w-full max-w-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                    style="min-height: 44px; padding: 0.5rem 0.75rem; color: var(--color-text); border: 1px solid var(--color-border); border-radius: var(--radius-button); background-color: var(--color-surface); outline-color: var(--color-accent);"
                >
            </label>
            @error('postcode')
                <p class="text-sm" role="alert" data-testid="fulfilment-error">{{ $message }}</p>
            @enderror
            <p class="text-sm" role="alert" data-testid="fulfilment-error" x-show="error" x-text="error" x-cloak></p>
            <button
                type="submit"
                class="focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                style="min-height: 44px; padding: 0.5rem 1rem; background-color: var(--color-primary); color: var(--color-text-on-primary); border-radius: var(--radius-button); outline-color: var(--color-accent);"
            >Check</button>
        </form>
    </div>
    <script>
        function fulfilmentWidget(initialChecked) {
            return {
                checkUrl: '',
                checked: initialChecked,
                display: '',
                miss: '',
                lines: [],
                error: '',
                init() {
                    this.checkUrl = this.$el.dataset.checkUrl || '/shop/fulfilment/check';
                },
                async check(event) {
                    const form = event.target;
                    const postcode = (form.postcode && form.postcode.value) ? form.postcode.value : '';
                    this.error = '';
                    try {
                        const res = await fetch(this.checkUrl + '?postcode=' + encodeURIComponent(postcode), {
                            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        });
                        const data = await res.json();
                        if (! data.valid) {
                            this.error = data.error || 'Enter a valid postcode.';
                            return;
                        }
                        this.checked = true;
                        this.display = data.display || postcode;
                        this.miss = data.miss || '';
                        this.lines = data.lines || [];
                    } catch (e) {
                        form.removeAttribute('x-on:submit.prevent');
                        form.submit();
                    }
                },
                async change() {
                    try {
                        await fetch(this.checkUrl + '?change=1', {
                            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        });
                    } catch (e) {}
                    this.checked = false;
                    this.display = '';
                    this.miss = '';
                    this.lines = [];
                    this.error = '';
                },
            };
        }
    </script>
@endif
