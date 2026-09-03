@php
    $announcementMessages = \App\Support\ChromeKnobs::announcementMessages($site);
@endphp
@if (\App\Support\ChromeKnobs::announcementEnabled($site) && $announcementMessages !== [])
@php
    $announcementBg = \App\Support\ChromeKnobs::announcementBg($site);
    $announcementStyle = $announcementBg === null
        ? 'background-color: var(--color-accent); color: var(--color-accent-text);'
        : 'background-color: '.$announcementBg.'; color: '.(\App\Support\ChromeKnobs::announcementIsDark($site) ? '#ffffff' : '#111111').';';
    $announcementCount = count($announcementMessages);
    $announcementTokens = is_array($renderTokens ?? null) ? $renderTokens : (is_array($tokens ?? null) ? $tokens : []);
    $announcementPad = $announcementTokens['nav_padding_class'] ?? 'px-4 sm:px-6 lg:px-8';
@endphp
<div data-announcement-strip role="region" aria-label="Announcement" style="{{ $announcementStyle }}"@if ($announcementCount > 1) x-data="{ i: 0, n: {{ $announcementCount }} }"@endif>
    <div class="site-shell-container {{ $announcementPad }}">
        <div class="relative flex items-center justify-center text-center text-sm" style="min-height: 2.75rem;">
            @if ($announcementCount > 1)
                <button type="button" class="absolute left-0 inline-flex h-8 w-8 items-center justify-center" aria-label="Previous announcement" @click="i = (i - 1 + n) % n">
                    <span aria-hidden="true">‹</span>
                </button>
            @endif
            <div class="{{ $announcementCount > 1 ? 'px-10' : '' }}"@if ($announcementCount > 1) aria-live="polite"@endif>
                @foreach ($announcementMessages as $idx => $message)
                    <p
                        @if ($announcementCount > 1)
                            x-show="i === {{ $idx }}"
                            @if ($idx !== 0)
                                style="display: none;"
                            @endif
                        @endif
                    >
                        @if (! empty($message['url']))
                            <a href="{{ $message['url'] }}" style="color: inherit; text-decoration: underline;">{{ $message['text'] }}</a>
                        @else
                            {{ $message['text'] }}
                        @endif
                    </p>
                @endforeach
            </div>
            @if ($announcementCount > 1)
                <button type="button" class="absolute right-0 inline-flex h-8 w-8 items-center justify-center" aria-label="Next announcement" @click="i = (i + 1) % n">
                    <span aria-hidden="true">›</span>
                </button>
            @endif
        </div>
    </div>
</div>
@endif