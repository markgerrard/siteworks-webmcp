<?php

namespace App\Exceptions\Shop;

use RuntimeException;

class ProductRevisionConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This product was changed elsewhere — reload to see the latest.');
    }
}
