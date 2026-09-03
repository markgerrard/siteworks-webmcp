<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-linear-to-b dark:from-neutral-950 dark:to-neutral-900">
        <div class="bg-background flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div class="flex w-full max-w-sm flex-col gap-2">
                {{-- Login shows the full SiteWorks lockup (hex mark + wordmark)
                     centered above the form. Anchor goes to AuthLanding::for(null)
                     which routes back to the customer-domain root, since this
                     layout is reachable from both customer and agent surfaces. --}}
                <a href="{{ \App\Support\AuthLanding::for(null) }}"
                   class="mb-2 flex items-center justify-center gap-3 font-medium">
                    <img src="/images/sw-mark.png"
                         alt="{{ config('app.name') }}"
                         class="h-12 w-auto block dark:hidden">
                    <img src="/images/sw-mark-dark.png"
                         alt="{{ config('app.name') }}"
                         class="h-12 w-auto hidden dark:block">
                    <span class="font-display text-3xl font-extrabold tracking-tight text-zinc-900 dark:text-white">
                        SiteWorks
                    </span>
                </a>
                <div class="flex flex-col gap-6">
                    {{ $slot }}
                </div>
            </div>
        </div>
        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts(['nonce' => \Illuminate\Support\Facades\Vite::cspNonce()])
    </body>
</html>
