<?php

namespace App\Support\Shop;

/**
 * Extra bakery quote fields rendered on /shop/quote in addition to the
 * core name, email, phone, needed_by, and message inputs.
 */
final class QuoteFormFields
{
    /**
     * @return list<array{name: string, label: string, type: string}>
     */
    public static function extra(): array
    {
        return [
            ['name' => 'occasion', 'label' => "Occasion / what it's for", 'type' => 'text'],
            ['name' => 'people_count', 'label' => 'Number of people', 'type' => 'number'],
            ['name' => 'flavour', 'label' => 'Flavour', 'type' => 'text'],
            ['name' => 'pickup_date', 'label' => 'Pickup date', 'type' => 'date'],
            ['name' => 'budget', 'label' => 'Budget', 'type' => 'text'],
            ['name' => 'message_on_top', 'label' => 'Message on top', 'type' => 'text'],
            ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea'],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        $rules = [];
        foreach (self::extra() as $field) {
            $rules[$field['name']] = match ($field['type']) {
                'number' => ['nullable', 'integer', 'min:1', 'max:9999'],
                'date' => ['nullable', 'date', 'after_or_equal:today'],
                'textarea' => ['nullable', 'string', 'max:1000'],
                default => ['nullable', 'string', 'max:120'],
            };
        }

        return $rules;
    }
}
