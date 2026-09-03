@if (! app()->runningUnitTests())
    @vite(['resources/js/save-bar.js'], 'build-agents')
@endif

<div
    x-data
    x-cloak
    x-show="$store.saveBar?.dirty"
    class="sticky top-0 z-50 flex w-full items-center justify-center gap-4 border-b border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100"
    data-save-bar-chrome
>
    <span>{{ __('Unsaved changes') }}</span>
    <span aria-hidden="true">—</span>
    <button type="button" class="underline underline-offset-2 cursor-pointer" x-on:click="$store.saveBar.discard()">
        {{ __('Discard') }}
    </button>
    <button type="button" class="rounded-md bg-amber-600 px-3 py-1 font-medium text-white cursor-pointer" x-on:click="$store.saveBar.save()">
        {{ __('Save') }}
    </button>
</div>
