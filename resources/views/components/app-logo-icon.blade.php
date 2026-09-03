{{-- Mark-only treatment (no wordmark). Used where the layout already
     renders the product name elsewhere or space is constrained. Light +
     dark variants swap on the .dark root so the hex panels stay visible
     against either surface. --}}
<img src="/images/sw-mark.png"
     alt="{{ config('app.name') }}"
     {{ $attributes->merge(['class' => 'h-9 w-auto object-contain block dark:hidden']) }}>
<img src="/images/sw-mark-dark.png"
     alt="{{ config('app.name') }}"
     {{ $attributes->merge(['class' => 'h-9 w-auto object-contain hidden dark:block']) }}>
