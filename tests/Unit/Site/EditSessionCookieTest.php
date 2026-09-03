<?php

use App\Services\Site\EditSessionCookie;

beforeEach(function () {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    $this->cookie = new EditSessionCookie;
});

test('make and validate round-trip succeeds', function () {
    $payload = ['user_id' => 1, 'site_id' => 2, 'page_id' => 3, 'expires_at' => time() + 1800];
    $cookie = $this->cookie->make($payload, 'example.com');

    $result = $this->cookie->validate($cookie->getValue());

    expect($result)->not->toBeNull()
        ->and($result['user_id'])->toBe(1)
        ->and($result['site_id'])->toBe(2);
});

test('tampered cookie value returns null', function () {
    $payload = ['user_id' => 1, 'site_id' => 2, 'page_id' => 3, 'expires_at' => time() + 1800];
    $cookie = $this->cookie->make($payload, 'example.com');

    expect($this->cookie->validate($cookie->getValue().'tampered'))->toBeNull();
});

test('expired cookie value returns null', function () {
    $payload = ['user_id' => 1, 'site_id' => 2, 'page_id' => 3, 'expires_at' => time() - 1];
    $cookie = $this->cookie->make($payload, 'example.com');

    expect($this->cookie->validate($cookie->getValue()))->toBeNull();
});

test('make throws RuntimeException when APP_KEY is empty', function () {
    config(['app.key' => '']);
    $cookie = new EditSessionCookie;

    $payload = ['user_id' => 1, 'site_id' => 2, 'page_id' => 3, 'expires_at' => time() + 1800];

    expect(fn () => $cookie->make($payload, 'example.com'))->toThrow(\RuntimeException::class);
});

test('validate throws RuntimeException when APP_KEY is empty', function () {
    // Make a valid cookie first with a proper key
    $payload = ['user_id' => 1, 'site_id' => 2, 'page_id' => 3, 'expires_at' => time() + 1800];
    $cookie = $this->cookie->make($payload, 'example.com');
    $value = $cookie->getValue();

    config(['app.key' => '']);
    $other = new EditSessionCookie;

    expect(fn () => $other->validate($value))->toThrow(\RuntimeException::class);
});
