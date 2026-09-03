<?php

use App\Services\Site\EditSessionToken;

beforeEach(function () {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    $this->tokens = new EditSessionToken;
});

test('mint and validate round-trip succeeds', function () {
    $token = $this->tokens->mint(1, 2, 3, 1800);

    $payload = $this->tokens->validate($token);

    expect($payload)->not->toBeNull()
        ->and($payload['site_id'])->toBe(1)
        ->and($payload['user_id'])->toBe(2)
        ->and($payload['page_id'])->toBe(3);
});

test('tampered payload returns null', function () {
    $token = $this->tokens->mint(1, 2, 3, 1800);

    // Corrupt the payload portion
    [$payload, $sig] = explode('.', $token, 2);
    $decoded = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
    $decoded['user_id'] = 9999;
    $tampered = strtr(base64_encode(json_encode($decoded)), '+/', '-_');
    $tamperedToken = "{$tampered}.{$sig}";

    expect($this->tokens->validate($tamperedToken))->toBeNull();
});

test('expired token returns null', function () {
    $token = $this->tokens->mint(1, 2, 3, -1); // already expired

    expect($this->tokens->validate($token))->toBeNull();
});

test('validateForPage returns null when site_id mismatches', function () {
    $token = $this->tokens->mint(1, 2, 3, 1800);

    expect($this->tokens->validateForPage($token, 99, 3))->toBeNull();
});

test('validateForPage returns null when page_id mismatches', function () {
    $token = $this->tokens->mint(1, 2, 3, 1800);

    expect($this->tokens->validateForPage($token, 1, 99))->toBeNull();
});

test('validateForPage succeeds when site_id and page_id match', function () {
    $token = $this->tokens->mint(5, 7, 11, 1800);

    $payload = $this->tokens->validateForPage($token, 5, 11);

    expect($payload)->not->toBeNull()
        ->and($payload['user_id'])->toBe(7);
});

test('wrong APP_KEY returns null', function () {
    $token = $this->tokens->mint(1, 2, 3, 1800);

    // Change the key
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    $other = new EditSessionToken;

    expect($other->validate($token))->toBeNull();
});

test('malformed token returns null', function () {
    expect($this->tokens->validate('not-a-valid-token'))->toBeNull();
    expect($this->tokens->validate(''))->toBeNull();
    expect($this->tokens->validate('a.b.c'))->toBeNull();
});

test('mint throws RuntimeException when APP_KEY is empty', function () {
    config(['app.key' => '']);
    $tokens = new EditSessionToken;

    expect(fn () => $tokens->mint(1, 2, 3))->toThrow(\RuntimeException::class);
});

test('validate throws RuntimeException when APP_KEY is empty', function () {
    // Mint with a valid key first, then clear it before validating
    $validToken = $this->tokens->mint(1, 2, 3, 1800);

    config(['app.key' => '']);
    $tokens = new EditSessionToken;

    expect(fn () => $tokens->validate($validToken))->toThrow(\RuntimeException::class);
});
