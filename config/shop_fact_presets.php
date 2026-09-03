<?php

/**
 * Store-level product fact group presets. Labels here are the only
 * place vertical nouns belong; applying a preset writes these groups
 * onto the site and never touches product values.
 *
 * @return array<string, array{label: string, groups: list<array{slug: string, label: string, kind: 'pairs'|'text', show_on_card: bool, schema: null|string}>}>
 */
return [
    'bakery' => [
        'label' => 'Bakery',
        'groups' => [
            [
                'slug' => 'allergens',
                'label' => 'Allergens',
                'kind' => 'text',
                'show_on_card' => false,
                'schema' => null,
            ],
            [
                'slug' => 'ingredients',
                'label' => 'Ingredients',
                'kind' => 'text',
                'show_on_card' => false,
                'schema' => 'ingredients',
            ],
            [
                'slug' => 'nutrition',
                'label' => 'Nutrition',
                'kind' => 'pairs',
                'show_on_card' => false,
                'schema' => 'nutrition',
            ],
            [
                'slug' => 'serves',
                'label' => 'Serves',
                'kind' => 'pairs',
                'show_on_card' => true,
                'schema' => null,
            ],
        ],
    ],
    'florist' => [
        'label' => 'Florist',
        'groups' => [
            [
                'slug' => 'whats-included',
                'label' => "What's included",
                'kind' => 'text',
                'show_on_card' => false,
                'schema' => null,
            ],
            [
                'slug' => 'care',
                'label' => 'Care',
                'kind' => 'text',
                'show_on_card' => false,
                'schema' => null,
            ],
            [
                'slug' => 'delivery-notes',
                'label' => 'Delivery notes',
                'kind' => 'text',
                'show_on_card' => false,
                'schema' => null,
            ],
        ],
    ],
    'furniture' => [
        'label' => 'Furniture',
        'groups' => [
            [
                'slug' => 'dimensions',
                'label' => 'Dimensions',
                'kind' => 'pairs',
                'show_on_card' => false,
                'schema' => 'size',
            ],
            [
                'slug' => 'materials',
                'label' => 'Materials',
                'kind' => 'text',
                'show_on_card' => false,
                'schema' => 'material',
            ],
            [
                'slug' => 'care',
                'label' => 'Care',
                'kind' => 'text',
                'show_on_card' => false,
                'schema' => null,
            ],
        ],
    ],
    'apparel' => [
        'label' => 'Apparel',
        'groups' => [
            [
                'slug' => 'size-and-fit',
                'label' => 'Size & fit',
                'kind' => 'text',
                'show_on_card' => false,
                'schema' => 'size',
            ],
            [
                'slug' => 'materials',
                'label' => 'Materials',
                'kind' => 'text',
                'show_on_card' => false,
                'schema' => 'material',
            ],
            [
                'slug' => 'care',
                'label' => 'Care',
                'kind' => 'text',
                'show_on_card' => false,
                'schema' => null,
            ],
        ],
    ],
    'cosmetics' => [
        'label' => 'Cosmetics',
        'groups' => [
            [
                'slug' => 'ingredients',
                'label' => 'Ingredients',
                'kind' => 'text',
                'show_on_card' => false,
                'schema' => 'ingredients',
            ],
            [
                'slug' => 'how-to-use',
                'label' => 'How to use',
                'kind' => 'text',
                'show_on_card' => false,
                'schema' => null,
            ],
            [
                'slug' => 'details',
                'label' => 'Details',
                'kind' => 'pairs',
                'show_on_card' => false,
                'schema' => null,
            ],
        ],
    ],
    'generic-specifications' => [
        'label' => 'Generic — Specifications',
        'groups' => [
            [
                'slug' => 'specifications',
                'label' => 'Specifications',
                'kind' => 'pairs',
                'show_on_card' => false,
                'schema' => null,
            ],
            [
                'slug' => 'details',
                'label' => 'Details',
                'kind' => 'text',
                'show_on_card' => false,
                'schema' => null,
            ],
        ],
    ],
];
