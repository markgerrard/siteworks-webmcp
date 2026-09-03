<x-layouts::auth :title="__('Staff Sign In')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Staff Sign In')" :description="__('Sign in with your Microsoft work account')" />

        @if ($errors->has('auth'))
            <flux:callout variant="danger" icon="exclamation-triangle">
                <flux:callout.heading>{{ __('Sign in failed') }}</flux:callout.heading>
                <flux:callout.text>{{ $errors->first('auth') }}</flux:callout.text>
            </flux:callout>
        @endif

        <div class="flex flex-col gap-4">
            <flux:button
                variant="primary"
                href="{{ route('agent.sso.redirect') }}"
                class="w-full"
                data-test="sso-button"
            >
                <span class="flex items-center justify-center gap-3">
                    {{-- Microsoft four-square logo (inline SVG) --}}
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 21 21" aria-hidden="true" focusable="false">
                        <rect x="1" y="1" width="9" height="9" fill="#f25022"/>
                        <rect x="11" y="1" width="9" height="9" fill="#7fba00"/>
                        <rect x="1" y="11" width="9" height="9" fill="#00a4ef"/>
                        <rect x="11" y="11" width="9" height="9" fill="#ffb900"/>
                    </svg>
                    {{ __('Sign in with Microsoft') }}
                </span>
            </flux:button>
        </div>
    </div>
</x-layouts::auth>
