<?php

use App\Models\BusinessProfile;


it('produces stable sha1 from profile_data', function () {
    $a = new BusinessProfile(['profile_data' => ['x' => 1, 'y' => 2]]);
    $b = new BusinessProfile(['profile_data' => ['x' => 1, 'y' => 2]]);

    expect($a->fingerprint())->toBe($b->fingerprint());
    expect($a->fingerprint())->toMatch('/^[a-f0-9]{40}$/');
});

it('changes when profile_data changes', function () {
    $a = new BusinessProfile(['profile_data' => ['x' => 1]]);
    $b = new BusinessProfile(['profile_data' => ['x' => 2]]);

    expect($a->fingerprint())->not->toBe($b->fingerprint());
});

it('returns sha1 of empty array when profile_data is null', function () {
    $a = new BusinessProfile(['profile_data' => null]);

    expect($a->fingerprint())->toBe(sha1(json_encode([])));
});
