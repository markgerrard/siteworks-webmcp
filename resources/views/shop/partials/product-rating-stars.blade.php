@php
    $avg = round((float) $avg, 1);
    $count = (int) $count;
    $aria = $aria ?? \App\Support\Shop\ProductReviews::ariaLabel($avg, $count);
    $showCount = $showCount ?? true;
    $wrapperClass = $wrapperClass ?? 'mt-1 flex items-center gap-1';
    $starPath = 'M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.562.562 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.562.562 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z';
@endphp
<div class="{{ $wrapperClass }}" aria-label="{{ $aria }}">
    <span class="inline-flex items-center" aria-hidden="true">
        @for ($i = 1; $i <= 5; $i++)
            @php $fill = max(0, min(1, $avg - ($i - 1))); @endphp
            <span class="relative inline-block" style="width: 1rem; height: 1rem;">
                <svg viewBox="0 0 24 24" class="absolute inset-0 h-full w-full" fill="none" stroke="var(--color-accent)" stroke-width="1.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $starPath }}" />
                </svg>
                @if ($fill > 0)
                    <span class="absolute inset-0 overflow-hidden" style="width: {{ round($fill * 100) }}%;">
                        <svg viewBox="0 0 24 24" class="h-full w-full" style="min-width: 1rem;" fill="var(--color-accent)" aria-hidden="true">
                            <path d="{{ $starPath }}" />
                        </svg>
                    </span>
                @endif
            </span>
        @endfor
    </span>
    @if ($showCount)
        <span class="text-sm" style="color: var(--color-text-muted);">{{ $count }}</span>
    @endif
</div>
