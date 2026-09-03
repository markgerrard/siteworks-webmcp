<?php

use App\Services\Site\PageLayoutRegistry;

function formPackRecipe(array $variants, array $options = []): array
{
    return [
        'label' => 'T', 'description' => 'T', 'schema_version' => 1,
        'variants' => $variants, 'options' => $options,
        'eyebrow_policy' => 'all', 'eyebrow_sections' => [],
    ];
}

it('allows lead_form in home and service recipes and rejects it in about', function () {
    $r = app(PageLayoutRegistry::class);
    expect($r->hardErrors(formPackRecipe(['lead_form' => 'centered'], ['form_input_style' => 'boxed']), 'home'))->toBe([])
        ->and($r->hardErrors(formPackRecipe(['lead_form' => 'centered'], ['form_input_style' => 'boxed']), 'service'))->toBe([])
        ->and($r->hardErrors(formPackRecipe(['lead_form' => 'centered']), 'about'))->toContain('recipe.variants key [lead_form] is not an allowed family for kind [about]');
});

it('validates the five form_* options as enums', function () {
    $r = app(PageLayoutRegistry::class);
    $errors = $r->hardErrors(formPackRecipe(['lead_form' => 'phone-ledger'], [
        'form_input_style' => 'boxed', 'form_surface' => 'card-on-dark', 'form_trust_style' => 'icon-box',
        'form_radio_style' => 'tiles', 'form_submit_style' => 'auto-arrow',
    ]), 'service');
    expect($errors)->toBe([]);
    expect($r->hardErrors(formPackRecipe(['lead_form' => 'phone-ledger'], ['form_surface' => 'glass']), 'service'))
        ->toContain('recipe.options.form_surface has an invalid value');
});

it('enforces the composition compatibility table', function () {
    $r = app(PageLayoutRegistry::class);
    expect($r->hardErrors(formPackRecipe(['lead_form' => 'inline-editorial'], ['form_input_style' => 'boxed']), 'service'))
        ->toContain('recipe.options.form_input_style [boxed] is not compatible with lead_form variant [inline-editorial]');
    expect($r->hardErrors(formPackRecipe(['lead_form' => 'centered'], ['form_input_style' => 'boxed', 'form_trust_style' => 'icon-box']), 'service'))
        ->toContain('recipe.options.form_trust_style [icon-box] is not compatible with lead_form variant [centered]');
});

it('requires an explicit input style where the site default could be incompatible', function () {
    $r = app(PageLayoutRegistry::class);
    foreach (['centered', 'split-screen', 'image-backed', 'inline-editorial', 'inline-band'] as $v) {
        expect($r->hardErrors(formPackRecipe(['lead_form' => $v]), 'service'))
            ->toContain("recipe.options.form_input_style is required for lead_form variant [{$v}]");
    }
    expect($r->hardErrors(formPackRecipe(['lead_form' => 'phone-ledger']), 'service'))->toBe([]);
});

it('rejects the 20-character token the brief used', function () {
    expect(app(PageLayoutRegistry::class)->hardErrors(formPackRecipe(['lead_form' => 'split-flipped-ledger']), 'service'))
        ->toContain('recipe.variants.lead_form is not a valid variant name (must match /^[a-z0-9-]{1,16}$/)');
});

it('produces errors without throwing when variants is a non-array value', function () {
    $r = app(PageLayoutRegistry::class);
    $recipe = [
        'label' => 'T', 'description' => 'T', 'schema_version' => 1,
        'variants' => 'nope', 'options' => [],
        'eyebrow_policy' => 'all', 'eyebrow_sections' => [],
    ];

    expect(fn () => $r->hardErrors($recipe, 'service'))->not->toThrow(\Throwable::class);
    expect($r->hardErrors($recipe, 'service'))->not->toBeEmpty();
});

it('rejects full-width submit on inline-band', function () {
    expect(app(PageLayoutRegistry::class)->hardErrors(formPackRecipe(['lead_form' => 'inline-band'], ['form_input_style' => 'boxed', 'form_submit_style' => 'full-width']), 'service'))
        ->toContain('recipe.options.form_submit_style [full-width] is not compatible with lead_form variant [inline-band]');
});

it('rejects full-width submit on inline-editorial', function () {
    expect(app(PageLayoutRegistry::class)->hardErrors(formPackRecipe(['lead_form' => 'inline-editorial'], ['form_input_style' => 'underline', 'form_submit_style' => 'full-width']), 'service'))
        ->toContain('recipe.options.form_submit_style [full-width] is not compatible with lead_form variant [inline-editorial]');
});

it('accepts editorial-ledger with underline, flat-cream and auto, rejects boxed and trust, and requires input style', function () {
    $r = app(PageLayoutRegistry::class);
    expect($r->hardErrors(formPackRecipe(['lead_form' => 'editorial-ledger'], [
        'form_input_style' => 'underline', 'form_surface' => 'flat-cream', 'form_submit_style' => 'auto',
    ]), 'service'))->toBe([]);
    expect($r->hardErrors(formPackRecipe(['lead_form' => 'editorial-ledger'], ['form_input_style' => 'boxed']), 'service'))
        ->toContain('recipe.options.form_input_style [boxed] is not compatible with lead_form variant [editorial-ledger]');
    expect($r->hardErrors(formPackRecipe(['lead_form' => 'editorial-ledger'], ['form_input_style' => 'underline', 'form_trust_style' => 'tick-list']), 'service'))
        ->toContain('recipe.options.form_trust_style [tick-list] is not compatible with lead_form variant [editorial-ledger]');
    expect($r->hardErrors(formPackRecipe(['lead_form' => 'editorial-ledger']), 'service'))
        ->toContain('recipe.options.form_input_style is required for lead_form variant [editorial-ledger]');
});

it('does not require form_input_style when about names lead_form', function () {
    $errors = app(PageLayoutRegistry::class)->hardErrors(
        formPackRecipe(['lead_form' => 'centered']),
        'about',
    );

    expect(implode("\n", $errors))->not->toContain('form_input_style is required');
});
