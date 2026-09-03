<?php

use App\Support\ContactLabelIcon;

it('maps contact-detail labels to icon keys', function (string $label, string $key) {
    expect(ContactLabelIcon::key($label))->toBe($key);
})->with([
    ['Hours', 'hours'], ['Opening hours', 'hours'], ['Service area', 'coverage'], ['Coverage', 'coverage'],
    ['Address', 'address'], ['Phone', 'phone'], ['Mobile', 'mobile'], ['Email', 'email'], ['WhatsApp', 'whatsapp'],
    ['', 'address'], ['Something else', 'something else'],
]);
