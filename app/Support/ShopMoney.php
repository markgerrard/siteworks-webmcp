<?php

namespace App\Support;

final class ShopMoney
{
    public static function symbol(string $currency = 'GBP'): string
    {
        return match (strtoupper($currency)) {
            'USD' => '$',
            'AUD' => 'A$',
            'NZD' => 'NZ$',
            'EUR' => '€',
            default => '£',
        };
    }

    public static function format(int $cents, string $currency = 'GBP'): string
    {
        $symbol = match (strtoupper($currency)) {
            'USD' => '$',
            'AUD' => 'A$',
            'NZD' => 'NZ$',
            'EUR' => '€',
            default => '£',
        };

        $formatted = number_format($cents / 100, 2);
        if (str_ends_with($symbol, '$') && str_ends_with($formatted, '.00')) {
            $formatted = substr($formatted, 0, -3);
        }

        return $symbol.$formatted;
    }

    public static function display(int $cents, string $currency = 'GBP', bool $priceFrom = false): string
    {
        $formatted = self::format($cents, $currency);

        return $priceFrom ? 'from '.$formatted : $formatted;
    }

    public static function includesVat(string $currency): bool
    {
        return strtoupper($currency) === 'GBP';
    }

    public static function formatWithVat(int $cents, string $currency = 'GBP'): string
    {
        $formatted = self::format($cents, $currency);

        return self::includesVat($currency) ? $formatted.' inc. VAT' : $formatted;
    }

    public static function displayWithVat(int $cents, string $currency = 'GBP', bool $priceFrom = false): string
    {
        $formatted = self::display($cents, $currency, $priceFrom);

        return self::includesVat($currency) ? $formatted.' inc. VAT' : $formatted;
    }
}
