<?php

namespace App\Services\Shop;

use RuntimeException;

final class CategoryTreeException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function cycle(): self
    {
        return new self('cycle', 'A category cannot be moved under itself or one of its descendants.');
    }

    public static function depth(): self
    {
        return new self('depth', 'Categories cannot nest deeper than 3 levels.');
    }

    public static function slugTaken(): self
    {
        return new self('slug_taken', 'A category with that slug already exists on this site.');
    }

    public static function notFound(): self
    {
        return new self('not_found', 'Category not found.');
    }

    public static function reservedSlug(): self
    {
        return new self('validation', 'That slug is reserved for a storefront page.');
    }
}
