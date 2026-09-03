<x-shop.layout :site="$site">
    <div class="px-4 sm:px-6 lg:px-8 py-6 max-w-full">
        <h1 class="text-2xl font-bold mb-4">Sign in</h1>

        @if (session('status'))
            <div
                role="status"
                class="mb-4 max-w-sm rounded p-3"
                style="color: var(--color-text); border: 1px solid var(--color-border); background-color: var(--color-surface-alt); border-radius: var(--radius-card);"
            >{{ session('status') }}</div>
        @endif

        <form method="POST" action="{{ route('shop.account.login') }}" class="space-y-3 max-w-sm">
            @csrf
            @if (! empty($return))
                <input type="hidden" name="return" value="{{ $return }}">
            @endif
            <input
                name="email"
                type="email"
                value="{{ old('email') }}"
                placeholder="Email"
                required
                class="w-full p-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                style="min-height: 44px; color: var(--color-text); border: 1px solid var(--color-border); border-radius: var(--radius-button); background-color: var(--color-surface); outline-color: var(--color-accent);"
            >
            <details>
                <summary class="text-sm cursor-pointer" style="color: var(--color-text-muted)">Have a password? Sign in with that instead</summary>
                <input
                    name="password"
                    type="password"
                    placeholder="Password"
                    class="mt-2 w-full p-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                    style="min-height: 44px; color: var(--color-text); border: 1px solid var(--color-border); border-radius: var(--radius-button); background-color: var(--color-surface); outline-color: var(--color-accent);"
                >
            </details>
            <button
                class="px-4 py-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                style="min-height: 44px; background-color: var(--color-primary); color: var(--color-text-on-primary); border-radius: var(--radius-button); outline-color: var(--color-accent);"
            >Email me a sign-in link</button>
        </form>
    </div>
</x-shop.layout>
