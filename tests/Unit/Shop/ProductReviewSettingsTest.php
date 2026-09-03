<?php

use App\Support\Shop\ProductReviewSettings;

test('null and partial payloads merge onto the disabled-by-default shape', function () {
    $fromNull = ProductReviewSettings::from(null);
    $fromPartial = ProductReviewSettings::from(['enabled' => true, 'label' => 'Customer reviews']);

    expect($fromNull->toArray())->toBe([
        'enabled' => false,
        'label' => 'Reviews',
        'public_form' => false,
        'moderate' => true,
        'show_on_cards' => true,
        'min_reviews_for_card' => 1,
    ])->and($fromPartial->enabled)->toBeTrue()
        ->and($fromPartial->label)->toBe('Customer reviews')
        ->and($fromPartial->publicForm)->toBeFalse();
});

test('validate accepts the documented knob set and clamps the label', function () {
    $settings = ProductReviewSettings::validate([
        'enabled' => true,
        'label' => '  Feedback  ',
        'public_form' => true,
        'moderate' => false,
        'show_on_cards' => false,
        'min_reviews_for_card' => 3,
    ]);

    expect($settings->toArray())->toBe([
        'enabled' => true,
        'label' => 'Feedback',
        'public_form' => true,
        'moderate' => false,
        'show_on_cards' => false,
        'min_reviews_for_card' => 3,
    ]);
});
