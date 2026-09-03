<?php

use App\Support\FormFieldDefinition;

test('a key is derived from the label', function () {
    expect(FormFieldDefinition::deriveKey('Job postcode', []))->toBe('job_postcode');
});

test('a duplicate label gets a numeric suffix, not a clobbered key', function () {
    expect(FormFieldDefinition::deriveKey('Job postcode', ['job_postcode']))->toBe('job_postcode_2')
        ->and(FormFieldDefinition::deriveKey('Job postcode', ['job_postcode', 'job_postcode_2']))->toBe('job_postcode_3');
});

test('a typed key is sanitised into something an input name can hold', function () {
    expect(FormFieldDefinition::normaliseKey('Job Postcode!'))->toBe('job_postcode');
});

test('duplicate explicit keys are suffixed without rewriting the first key', function () {
    $first = FormFieldDefinition::normalise([
        'name' => 'service',
        'label' => 'Service',
        'type' => 'text',
    ], []);
    $duplicate = FormFieldDefinition::normalise([
        'name' => 'service',
        'label' => 'Another service',
        'type' => 'text',
    ], [$first['name']]);

    expect($first['name'])->toBe('service')
        ->and($duplicate['name'])->toBe('service_2');
});

test('a key may not start with a digit', function () {
    // Payload keys are treated as identifiers downstream.
    expect(FormFieldDefinition::normaliseKey('2nd address'))->toBe('field_2nd_address');
});

test('every form flavour shares one field cap', function () {
    // Was 5 for lead_form and 8 for contact_form (decision D7). The split kept
    // drifting out of sync with the three places that enforce it, so it is now
    // one number that they all read.
    expect(FormFieldDefinition::capFor('lead_form'))->toBe(FormFieldDefinition::MAX_FIELDS)
        ->and(FormFieldDefinition::capFor('contact_form'))->toBe(FormFieldDefinition::MAX_FIELDS)
        ->and(FormFieldDefinition::MAX_FIELDS)->toBe(10);
});

test('only lead form Message is exempt from the field cap', function () {
    $fields = [
        ['name' => 'phone'],
        ['name' => 'message'],
    ];

    expect(FormFieldDefinition::countableFieldTotal('lead_form', $fields))->toBe(1)
        ->and(FormFieldDefinition::countableFieldTotal('contact_form', $fields))->toBe(2)
        ->and(FormFieldDefinition::countableFieldTotal('lead_form', [['name' => 'Message']]))->toBe(1);
});

test('options are trimmed and blanks dropped', function () {
    $field = FormFieldDefinition::normalise([
        'label' => 'Service',
        'type' => 'select',
        'options' => ['Boiler', '', '  ', 'Drains'],
    ], []);

    expect($field['options'])->toBe(['Boiler', 'Drains']);
});

test('a non-choice field carries no options key at all', function () {
    $field = FormFieldDefinition::normalise([
        'label' => 'Postcode',
        'type' => 'text',
        'options' => ['stray'],
    ], []);

    expect($field)->not->toHaveKey('options');
});
