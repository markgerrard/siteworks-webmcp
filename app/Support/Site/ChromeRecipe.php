<?php

namespace App\Support\Site;

final class ChromeRecipe
{
    public const SCHEMA_VERSION = 1;

    /** @var list<string> */
    public const KEYS = [
        'label',
        'description',
        'schema_version',
        'layout',
        'top_bar',
        'nav_row',
        'nav_case',
        'nav_container_style',
        'nav_container_fill',
        'logo_height',
        'hero_frame',
        'hero_corners',
        'hero_backdrop',
        'brand_pattern',
        'nav_row_pattern',
        'store_controls',
        'store_control_style',
        'store_controls_slot',
        'shop_nav_style',
        'sticky_shrink',
    ];

    /**
     * @var array<string, list<string>>
     */
    public const ENUMS = [
        'layout' => ['standard', 'centred'],
        'top_bar' => ['auto', 'off'],
        'nav_row' => ['inline', 'beneath'],
        'nav_case' => ['default', 'caps'],
        'nav_container_style' => \App\Support\ChromeKnobs::NAV_CONTAINER_STYLES,
        'nav_container_fill' => \App\Support\ChromeKnobs::NAV_CONTAINER_FILLS,
        'logo_height' => ['sm', 'md', 'lg', 'xl'],
        'store_controls' => ['icons', 'icons+labels'],
        'store_control_style' => ['plain', 'pill'],
        'store_controls_slot' => ['inline', 'right'],
        'shop_nav_style' => ['link', 'dropdown', 'mega'],
        'sticky_shrink' => ['on', 'off'],
        'hero_frame' => ['full', 'boxed'],
        'hero_corners' => ['card', 'square'],
        'hero_backdrop' => ['page', 'white', 'surface-alt', 'primary'],
        'brand_pattern' => ['none', 'swirl', 'dots', 'image'],
        'nav_row_pattern' => ['none', 'swirl', 'dots', 'image'],
    ];

    /**
     * @param  array<string, mixed>  $recipe
     * @return list<string>
     */
    public static function errors(array $recipe): array
    {
        $errors = [];

        if (! array_key_exists('layout', $recipe) || ! is_string($recipe['layout']) || $recipe['layout'] === '') {
            $errors[] = 'recipe.layout must be standard or centred';
        } elseif (! in_array($recipe['layout'], self::ENUMS['layout'], true)) {
            $errors[] = 'recipe.layout must be standard or centred';
        }

        foreach ($recipe as $key => $value) {
            $label = is_scalar($key) ? (string) $key : gettype($key);
            if (! is_string($key) || ! in_array($key, self::KEYS, true)) {
                $errors[] = "recipe.{$label} is not a known chrome option";

                continue;
            }

            if ($key === 'layout') {
                continue;
            }

            if ($key === 'schema_version') {
                if (! is_int($value)) {
                    $errors[] = 'recipe.schema_version must be an integer';
                } elseif ($value !== self::SCHEMA_VERSION) {
                    $errors[] = 'recipe.schema_version is not a supported schema version';
                }

                continue;
            }

            if ($key === 'label') {
                if (! is_string($value)) {
                    $errors[] = 'recipe.label must be a string';
                }

                continue;
            }

            if ($key === 'description') {
                if ($value !== null && ! is_string($value)) {
                    $errors[] = 'recipe.description must be a string or null';
                }

                continue;
            }

            if (! in_array($value, self::ENUMS[$key], true)) {
                $errors[] = "recipe.{$key} has an invalid value";
            }
        }

        if (($recipe['layout'] ?? null) === 'centred'
            && array_key_exists('top_bar', $recipe)
            && $recipe['top_bar'] !== 'off') {
            $errors[] = 'recipe.top_bar must be off when layout is centred';
        }

        return array_values(array_unique($errors));
    }
}
