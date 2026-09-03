<?php

use Illuminate\Support\Facades\Blade;

it('renders the price component byte-identically to the pre-T13 markup when vat is explicit', function () {
    $html = Blade::render('<x-shop.price :amount="$amount" :vat="true" />', ['amount' => '£20.00']);

    expect($html)->toBe("<span class=\"tabular-nums whitespace-nowrap\" style=\"font-variant-numeric: tabular-nums\">£20.00 <span style=\"font-variant-caps: small-caps\">inc. VAT</span></span>\n");
});

it('derives the vat suffix from currency when vat is omitted', function () {
    expect(Blade::render('<x-shop.price :amount="$amount" currency="USD" />', ['amount' => '$20.00']))
        ->toBe("<span class=\"tabular-nums whitespace-nowrap\" style=\"font-variant-numeric: tabular-nums\">\$20.00</span>\n");
});

it('renders byte-identically to the pre-T13 default (no props) for a GBP-default caller', function () {
    expect(Blade::render('<x-shop.price :amount="$amount" />', ['amount' => '£20.00']))
        ->toBe("<span class=\"tabular-nums whitespace-nowrap\" style=\"font-variant-numeric: tabular-nums\">£20.00 <span style=\"font-variant-caps: small-caps\">inc. VAT</span></span>\n");
});

it('renders byte-identically to the pre-T13 markup when vat is explicitly false', function () {
    expect(Blade::render('<x-shop.price :amount="$amount" :vat="false" />', ['amount' => '£20.00']))
        ->toBe("<span class=\"tabular-nums whitespace-nowrap\" style=\"font-variant-numeric: tabular-nums\">£20.00</span>\n");
});
