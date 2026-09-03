<?php

use App\Support\ShopMoney;

test('GBP formats with a pound sign and two decimal places', function () {
    expect(ShopMoney::format(1250, 'GBP'))->toBe('£12.50')
        ->and(ShopMoney::format(4500, 'GBP'))->toBe('£45.00')
        ->and(ShopMoney::format(0, 'GBP'))->toBe('£0.00');
});

test('USD formats with a dollar sign and two decimal places', function () {
    expect(ShopMoney::format(1250, 'USD'))->toBe('$12.50')
        ->and(ShopMoney::format(8550, 'USD'))->toBe('$85.50');
});

test('USD drops trailing .00 on whole-dollar amounts while GBP keeps two decimals', function () {
    expect(ShopMoney::format(8500, 'USD'))->toBe('$85')
        ->and(ShopMoney::format(0, 'USD'))->toBe('$0')
        ->and(ShopMoney::format(8500, 'GBP'))->toBe('£85.00')
        ->and(ShopMoney::display(8500, 'USD', true))->toBe('from $85');
});

test('from-prices prefix the formatted amount with lowercase from and one space', function () {
    expect(ShopMoney::display(8500, 'USD', true))->toBe('from $85')
        ->and(ShopMoney::display(8500, 'GBP', true))->toBe('from £85.00')
        ->and(ShopMoney::display(8500, 'USD', false))->toBe('$85');
});

test('GBP shopper prices append inc. VAT and non-GBP prices do not', function () {
    expect(ShopMoney::includesVat('GBP'))->toBeTrue()
        ->and(ShopMoney::includesVat('gbp'))->toBeTrue()
        ->and(ShopMoney::includesVat('USD'))->toBeFalse()
        ->and(ShopMoney::formatWithVat(2000, 'GBP'))->toBe('£20.00 inc. VAT')
        ->and(ShopMoney::formatWithVat(4000, 'GBP'))->toBe('£40.00 inc. VAT')
        ->and(ShopMoney::formatWithVat(2000, 'USD'))->toBe(ShopMoney::format(2000, 'USD'))
        ->and(ShopMoney::formatWithVat(2000, 'USD'))->not->toContain('inc. VAT');
});
