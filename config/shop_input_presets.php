<?php

return [

    'max_inputs' => 3,

    'max_chars' => 500,

    'max_help' => 120,

    'max_options' => 12,

    'max_files' => 3,

    'image_max_bytes' => 8 * 1024 * 1024,

    'image_max_dimension' => 6000,

    'image_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'],

    'signed_url_ttl_seconds' => 300,

    'mail_signed_url_ttl_days' => 7,

    'orphan_days' => 14,

    'payload_max_bytes' => 65536,

    'patterns' => [
        'no-emoji' => [
            'label' => 'No emoji',
            'reject' => '/\p{Extended_Pictographic}/u',
        ],
        'letters-digits-spaces' => [
            'label' => 'Letters, digits and spaces',
            'allow' => '/^[\p{L}\p{N} ]+$/u',
        ],
    ],

    'presets' => [
        [
            'key' => 'short-text',
            'label' => 'Short text',
            'definition' => [
                'slug' => 'note',
                'label' => 'Note',
                'kind' => 'text',
                'required' => false,
                'max_chars' => 80,
                'pattern' => null,
                'help' => '',
            ],
        ],
        [
            'key' => 'long-text',
            'label' => 'Long text',
            'definition' => [
                'slug' => 'message',
                'label' => 'Message',
                'kind' => 'textarea',
                'required' => false,
                'max_chars' => 250,
                'pattern' => null,
                'help' => '',
            ],
        ],
        [
            'key' => 'choice',
            'label' => 'Choice',
            'definition' => [
                'slug' => 'option',
                'label' => 'Option',
                'kind' => 'choice',
                'required' => true,
                'options' => ['One', 'Two'],
                'help' => '',
            ],
        ],
        [
            'key' => 'image',
            'label' => 'Image upload',
            'definition' => [
                'slug' => 'photo',
                'label' => 'Photo',
                'kind' => 'image',
                'required' => false,
                'max_files' => 1,
                'help' => '',
            ],
        ],
        [
            'key' => 'pattern-text',
            'label' => 'Text with pattern',
            'definition' => [
                'slug' => 'marking',
                'label' => 'Marking',
                'kind' => 'text',
                'required' => false,
                'max_chars' => 40,
                'pattern' => 'letters-digits-spaces',
                'help' => '',
            ],
        ],
    ],

];
