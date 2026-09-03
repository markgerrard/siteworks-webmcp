@php
    $members = array_values($members ?? (is_array($section['members'] ?? null) ? $section['members'] : (is_array($section['items'] ?? null) ? $section['items'] : [])));
@endphp

@if (! empty($members))
    @php
        $listKey = isset($section['members']) ? 'members' : (isset($section['items']) ? 'items' : 'members');

        $wrapperBg = 'var(--color-surface)';
        $textOnWrapper = 'var(--color-text)';
        $mutedOnWrapper = 'var(--color-text-muted)';
        $accentOnWrapper = 'var(--brand-accent-text)';

        $eyebrow = $section['eyebrow'] ?? 'Our Team';
        $titleMatchesEyebrow = ! empty($section['title']) && strcasecmp(trim((string) $section['title']), (string) $eyebrow) === 0;
        $hideEyebrow = $titleMatchesEyebrow || ! empty($section['__suppress_eyebrow']);

        $gridColumns = (int) ($section['__options']['grid_columns'] ?? $section['options']['grid_columns'] ?? 3);
        $gridColumns = in_array($gridColumns, [2, 3, 4], true) ? $gridColumns : 3;
        $gridColsClass = match ($gridColumns) {
            2 => 'grid grid-cols-1 sm:grid-cols-2 gap-8 lg:gap-10',
            4 => 'grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-8 lg:gap-10',
            default => 'grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-8 lg:gap-10',
        };

        $getInitials = function (?string $name): string {
            if (! is_string($name) || trim($name) === '') {
                return '?';
            }
            $initials = (string) \Illuminate\Support\Str::of($name)
                ->explode(' ')
                ->filter()
                ->take(2)
                ->map(fn ($word) => mb_strtoupper(\Illuminate\Support\Str::substr($word, 0, 1)))
                ->implode('');

            return $initials !== '' ? $initials : '?';
        };
    @endphp

    <div data-svc-variant="classic" class="site-section-spacing" style="background-color: {{ $wrapperBg }};">
        <div class="site-shell-container px-4 sm:px-6 lg:px-8">
            @if (! empty($section['title']) || ! empty($section['intro']) || (! $hideEyebrow && ! empty($eyebrow)))
                <div class="text-center mb-14 md:mb-16">
                    @unless ($hideEyebrow)
                        <span class="text-sm font-bold tracking-widest uppercase mb-3 block"
                              style="color: {{ $accentOnWrapper }};" {!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</span>
                    @elseif ($emitMarkers)
                        <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
                    @endunless

                    @if (! empty($section['title']))
@if (! empty($section['__options']['split_heading_reveal']))
@php /* column-0 directives: indented @if emits its leading whitespace and
would break the unstamped path's byte identity */ @endphp
                        @include('site.sections._split_heading', [
                            'section' => $section,
                            'sectionIndex' => $sectionIndex ?? ($section['__stored_index'] ?? 0),
                            'splitHeadingReveal' => true,
                            'class' => 'text-3xl md:text-5xl font-extrabold tracking-tight mb-4',
                            'style' => 'color: '.$textOnWrapper.'; font-family: var(--font-display); letter-spacing: var(--heading-letter-spacing);',
                            'attrs' => $editor('title', 'plain'),
                        ])
@else
                        <h2 class="text-3xl md:text-5xl font-extrabold tracking-tight mb-4"
                            style="color: {{ $textOnWrapper }}; font-family: var(--font-display); letter-spacing: var(--heading-letter-spacing);"
                            {!! $editor('title', 'plain') !!}>{{ $section['title'] }}</h2>
@endif
                    @elseif ($emitMarkers)
                        <span class="hidden"{!! $editor('title', 'plain') !!}></span>
                    @endif

                    @if (! empty($section['intro']))
                        <p class="text-lg max-w-2xl mx-auto"
                           style="color: {{ $mutedOnWrapper }};"
                           {!! $editor('intro', 'plain') !!}>{{ $section['intro'] }}</p>
                    @elseif ($emitMarkers)
                        <span class="hidden"{!! $editor('intro', 'plain') !!}></span>
                    @endif
                </div>
            @else
                @if ($emitMarkers)
                    <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
                    <span class="hidden"{!! $editor('title', 'plain') !!}></span>
                    <span class="hidden"{!! $editor('intro', 'plain') !!}></span>
                @endif
            @endif

            <div class="{{ $gridColsClass }}">
                @foreach ($members as $i => $member)
                    @php
                        $name = $member['name'] ?? $member['title'] ?? '';
                        $role = $member['role'] ?? '';
                        $bio = $member['bio'] ?? $member['body'] ?? '';

                        // Primary image
                        $primaryId = $member['image_id'] ?? (is_numeric($member['image'] ?? null) ? $member['image'] : null);
                        $primaryMedia = $primaryId !== null ? ($mediaById ?? collect())->get((int) $primaryId) : null;

                        $primaryUrl = null;
                        $primaryAlt = (string) $name;
                        if ($primaryMedia) {
                            $primaryUrl = $primaryMedia->url . ($primaryMedia->id ? '?v='.$primaryMedia->id : '');
                            $primaryAlt = $primaryMedia->alt_text ?: (string) $name;
                        }
                        // SiteMedia-only per spec §A: no raw-URL escape
                        // hatches — arbitrary external hosts would breach
                        // the no-new-egress posture line.

                        // Hover / alternate image
                        $hoverId = $member['hover_image_id'] ?? $member['alternate_image_id'] ?? (is_numeric($member['alternate_image'] ?? null) ? $member['alternate_image'] : null);
                        $hoverMedia = $hoverId !== null ? ($mediaById ?? collect())->get((int) $hoverId) : null;

                        $hoverUrl = null;
                        $hoverAlt = '';
                        if ($hoverMedia) {
                            $hoverUrl = $hoverMedia->url . ($hoverMedia->id ? '?v='.$hoverMedia->id : '');
                            $hoverAlt = '';
                        }

                        if (! $primaryUrl && $hoverUrl) {
                            // Promoted to the ONLY visible portrait: restore a
                            // descriptive alt (decorative '' applies just to the
                            // hover duplicate).
                            $primaryUrl = $hoverUrl;
                            $primaryAlt = (string) $name;
                            $hoverUrl = null;
                        }

                        $initials = $getInitials($name);
                    @endphp
                    <div class="team-member group flex flex-col items-center text-center">
                        <figure class="relative w-full aspect-[4/5] overflow-hidden mb-5"
                                style="border-radius: 20px; background-color: var(--color-surface-alt);">
                            @if ($primaryUrl)
                                <img src="{{ $primaryUrl }}"
                                     alt="{{ $primaryAlt }}"
                                     loading="lazy"
                                     class="w-full h-full object-cover{{ $hoverUrl ? ' transition-opacity duration-300 group-hover:opacity-0' : '' }}"
                                     style="border-radius: 20px;">
                                @if ($hoverUrl)
                                    <img src="{{ $hoverUrl }}"
                                         alt="{{ $hoverAlt }}"
                                         loading="lazy"
                                         class="absolute inset-0 w-full h-full object-cover transition-opacity duration-300 opacity-0 group-hover:opacity-100"
                                         style="border-radius: 20px;">
                                @endif
                            @else
                                <div class="w-full h-full flex items-center justify-center font-bold text-3xl select-none"
                                     style="background-color: var(--color-surface-alt); color: var(--color-text-muted); border-radius: 20px;">
                                    <span>{{ $initials }}</span>
                                </div>
                            @endif
                        </figure>

                        <h3 class="text-xl font-bold mb-1"
                            style="color: {{ $textOnWrapper }};"
                            {!! $editor("{$listKey}.{$i}.name", 'plain') !!}>{{ $name }}</h3>

                        @if (! empty($role))
                            <p class="text-sm font-medium mb-2"
                               style="color: {{ $accentOnWrapper }};"
                               {!! $editor("{$listKey}.{$i}.role", 'plain') !!}>{{ $role }}</p>
                        @elseif ($emitMarkers)
                            <span class="hidden"{!! $editor("{$listKey}.{$i}.role", 'plain') !!}></span>
                        @endif

                        @if (! empty($bio))
                            <p class="text-sm leading-relaxed mt-1"
                               style="color: {{ $mutedOnWrapper }};"
                               {!! $editor("{$listKey}.{$i}.bio", 'plain') !!}>{{ $bio }}</p>
                        @elseif ($emitMarkers)
                            <span class="hidden"{!! $editor("{$listKey}.{$i}.bio", 'plain') !!}></span>
                        @endif

                        @if ($emitMarkers)
                            <button type="button" class="hidden"{!! $editor("{$listKey}.{$i}.image_id", 'image') !!}></button>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endif
