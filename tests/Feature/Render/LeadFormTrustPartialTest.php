<?php

it('renders each trust style with editor markers, and nothing for empty benefits', function () {
    $editor = fn (string $f, string $t) => ' data-editable="x.'.$f.'"';
    foreach (['tick-list', 'chips-under-button', 'inline-piped', 'pill-badges', 'icon-box'] as $style) {
        $html = view('site.partials.lead-form-trust', ['style' => $style, 'benefits' => ['One', 'Two'], 'editor' => $editor, 'onDark' => true])->render();
        expect($html)->toContain('data-editable="x.benefits.0"')->toContain('data-editable="x.benefits.1"')->toContain('One')->toContain('Two');
        expect(view('site.partials.lead-form-trust', ['style' => $style, 'benefits' => [], 'editor' => $editor, 'onDark' => true])->render())->toBe('');
    }
    expect(view('site.partials.lead-form-trust', ['style' => 'tick-list', 'benefits' => ['A'], 'editor' => $editor, 'onDark' => true])->render())->toContain('space-y-3 max-w-md')->toContain('data-trust-style="tick-list"');
});

it('inline-piped onDark trust copy is full white', function () {
    $html = view('site.partials.lead-form-trust', [
        'style' => 'inline-piped',
        'benefits' => ['Insured'],
        'editor' => fn () => '',
        'onDark' => true,
    ])->render();

    expect($html)->toContain('text-white')
        ->and($html)->not->toContain('text-white/70');
});
