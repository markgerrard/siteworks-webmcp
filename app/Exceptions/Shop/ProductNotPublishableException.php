<?php

namespace App\Exceptions\Shop;

use RuntimeException;

/**
 * A publish was refused because the product is not ready for the storefront.
 * The message is written for the person who clicked Publish.
 */
class ProductNotPublishableException extends RuntimeException
{
    public static function priceMissing(): self
    {
        return new self('Set a price before publishing');
    }
}
