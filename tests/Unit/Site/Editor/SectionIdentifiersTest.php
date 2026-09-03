<?php

use App\Services\Site\Editor\SectionIdentifiers;

/**
 * Oracle rules ("Tests must have an independent oracle"):
 * - Every expected value computed independently of SectionIdentifiers.
 * - Every assertion asserts equality on a fixture where a wrong implementation cannot coincide.
 * - Every test names the wrong implementation it catches.
 */

test('ensure() leaves existing ids alone, mints only where absent', function () {
    $svc = new SectionIdentifiers;

    $contentData = [
        'sections' => [
            ['type' => 'hero', 'id' => 'AAA'],
            ['type' => 'services', 'id' => 'BBB'],
            ['type' => 'cta'], // no id
        ],
    ];

    $result = $svc->ensure($contentData);

    // Expected independently: AAA and BBB unchanged, one new id minted
    $ids = array_column($result['sections'], 'id');
    expect($ids)->toHaveCount(3);
    expect($ids[0])->toBe('AAA');
    expect($ids[1])->toBe('BBB');
    // The third id is a ULID (26 chars, Crockford base32)
    expect($ids[2])->toBeString()->toHaveLength(26);
    expect($ids[2])->not->toBe('AAA');
    expect($ids[2])->not->toBe('BBB');

    // Catches: an implementation that re-mints everything (all ids change),
    // or one that uses position-based ids.
});

test('ensure() is idempotent', function () {
    $svc = new SectionIdentifiers;

    $contentData = [
        'sections' => [
            ['type' => 'hero'],
            ['type' => 'services', 'id' => 'BBB'],
            ['type' => 'cta'],
        ],
    ];

    $first = $svc->ensure($contentData);
    $second = $svc->ensure($first);

    // Every key and value at every level must be identical
    expect($second)->toEqual($first);
    // Use strict equality to ensure no new keys or re-ordered keys
    expect(json_encode($second))->toBe(json_encode($first));

    // Catches: an implementation that re-mints on every call (idempotence red).
});

test('ensure() is inert on legacy shape with no sections key', function () {
    $svc = new SectionIdentifiers;

    $legacy = [
        'hero' => ['heading' => 'Welcome'],
        'services' => ['heading' => 'Services'],
    ];

    $result = $svc->ensure($legacy);

    // Must be === identical — no 'sections' key created
    expect($result)->toEqual($legacy);
    expect(json_encode($result))->toBe(json_encode($legacy));

    // Catches: an implementation that creates ['sections' => []]
    // or adds a 'sections' key at all.
});

test('ensure() is inert when sections is not a list', function () {
    $svc = new SectionIdentifiers;

    $associativeMap = [
        'sections' => [
            'hero' => ['type' => 'hero'],
            'services' => ['type' => 'services'],
        ],
    ];

    $result = $svc->ensure($associativeMap);

    expect($result)->toEqual($associativeMap);
    expect(json_encode($result))->toBe(json_encode($associativeMap));

    // Catches: an implementation that treats associative arrays as lists.
});

test('ensure() leaves non-array sections alone', function () {
    $svc = new SectionIdentifiers;

    $contentData = [
        'sections' => [
            'not-an-array',
            ['type' => 'hero', 'id' => 'AAA'],
            null,
        ],
    ];

    $result = $svc->ensure($contentData);

    expect($result['sections'][0])->toBe('not-an-array');
    expect($result['sections'][1]['id'])->toBe('AAA');
    expect($result['sections'][2])->toBeNull();

    // Catches: an implementation that throws on non-array sections.
});

test('ensure() does not alter any value other than adding missing ids', function () {
    $svc = new SectionIdentifiers;

    $section = ['type' => 'hero', 'title' => 'Welcome', 'nested' => ['key' => 'val']];
    $contentData = ['sections' => [$section]];

    $result = $svc->ensure($contentData);

    // Remove the id to compare the rest
    $resultWithoutId = $result['sections'][0];
    unset($resultWithoutId['id']);

    expect($resultWithoutId)->toEqual($section);

    // Catches: an implementation that adds/removes keys, or alters values.
});

test('remint() replaces every id', function () {
    $svc = new SectionIdentifiers;

    $contentData = [
        'sections' => [
            ['type' => 'hero', 'id' => 'AAA'],
            ['type' => 'services', 'id' => 'BBB'],
            ['type' => 'cta', 'id' => 'CCC'],
        ],
    ];

    $result = $svc->remint($contentData);

    $ids = array_column($result['sections'], 'id');

    // Count unchanged (3 sections → 3 ids)
    expect($ids)->toHaveCount(3);

    // None of the original ids remain
    expect($ids)->not->toContain('AAA');
    expect($ids)->not->toContain('BBB');
    expect($ids)->not->toContain('CCC');

    // All are valid ULIDs
    foreach ($ids as $id) {
        expect($id)->toBeString()->toHaveLength(26);
    }

    // Sections are still 3, no new keys added
    expect($result['sections'])->toHaveCount(3);
    expect($result['sections'][0]['type'])->toBe('hero');
    expect($result['sections'][1]['type'])->toBe('services');
    expect($result['sections'][2]['type'])->toBe('cta');

    // Catches: remint() silently aliased to ensure() (old ids would remain).
});

test('remint() is inert on legacy shape', function () {
    $svc = new SectionIdentifiers;

    $legacy = ['hero' => ['heading' => 'Welcome']];

    $result = $svc->remint($legacy);

    expect($result)->toEqual($legacy);
    expect(json_encode($result))->toBe(json_encode($legacy));
});

test('ensure() returns a new array (does not mutate the input)', function () {
    $svc = new SectionIdentifiers;

    $contentData = [
        'sections' => [
            ['type' => 'hero'],
        ],
    ];

    $original = json_encode($contentData);
    $svc->ensure($contentData);

    // Input must be unmodified
    expect(json_encode($contentData))->toBe($original);

    // Catches: an implementation that mutates the input array in place.
});
test('ensure() treats empty string id as absent and mints a fresh ULID', function () {
    $svc = new SectionIdentifiers;

    $contentData = [
        'sections' => [
            ['type' => 'hero', 'id' => ''],
        ],
    ];

    $result = $svc->ensure($contentData);

    $ids = array_column($result['sections'], 'id');
    expect($ids)->toHaveCount(1);
    // Empty string must NOT survive as an id
    expect($ids[0])->toBeString()->toHaveLength(26);
    expect($ids[0])->not->toBe('');

    // Catches: an implementation that treats '' as a valid id (isset alone
    // returns true for ''; a looser predicate like !isset passes it through).
});

test('ensure() treats null id as absent and mints a fresh ULID', function () {
    $svc = new SectionIdentifiers;

    $contentData = [
        'sections' => [
            ['type' => 'hero', 'id' => null],
        ],
    ];

    $result = $svc->ensure($contentData);

    $ids = array_column($result['sections'], 'id');
    expect($ids)->toHaveCount(1);
    // Null must NOT survive — a fresh string ULID must be minted
    expect($ids[0])->toBeString()->toHaveLength(26);

    // Catches: an implementation that leaves 'id' => null in place
    // (isset returns false for null, so the current code passes this by
    // accident. The tightened predicate MUST also pass it.)
});

test('ensure() treats non-string id (int) as absent and mints a fresh ULID', function () {
    $svc = new SectionIdentifiers;

    $contentData = [
        'sections' => [
            ['type' => 'hero', 'id' => 123],
        ],
    ];

    $result = $svc->ensure($contentData);

    $ids = array_column($result['sections'], 'id');
    expect($ids)->toHaveCount(1);
    // Integer 123 must NOT survive — a fresh string ULID must be minted
    expect($ids[0])->toBeString()->toHaveLength(26);
    expect($ids[0])->not->toBe(123);

    // Catches: an implementation that uses !isset which would allow
    // integer 123 to pass through as a valid id.
});

test('ensure() keeps a real ULID byte-for-byte unchanged', function () {
    $svc = new SectionIdentifiers;

    $realUlid = '01JHG7KX3MNQRSTVWXYZ012345';
    $contentData = [
        'sections' => [
            ['type' => 'hero', 'id' => $realUlid],
        ],
    ];

    $result = $svc->ensure($contentData);

    expect($result['sections'][0]['id'])->toBe($realUlid);

    // Catches: an implementation that re-mints even when a valid string id exists.
});

test('carryForward() copies ids by type at matching positions and leaves extras for ensure()', function () {
    $svc = new SectionIdentifiers;

    $current = [
        'sections' => [
            ['type' => 'hero', 'id' => 'AAA', 'title' => 'Old'],
            ['type' => 'cta', 'id' => 'BBB', 'title' => 'Same'],
        ],
        'meta' => ['keep' => true],
    ];
    $incoming = [
        'sections' => [
            ['type' => 'hero', 'title' => 'New'],
            ['type' => 'cta', 'title' => 'Same'],
            ['type' => 'faqs', 'title' => 'Added'],
        ],
        'meta' => ['keep' => false],
    ];

    $result = $svc->carryForward($current, $incoming);

    expect($result['sections'][0]['id'])->toBe('AAA');
    expect($result['sections'][1]['id'])->toBe('BBB');
    expect($result['sections'][2])->not->toHaveKey('id');
    expect($result['meta']['keep'])->toBeFalse();

    // Catches: an implementation that remints, or that copies by index ignoring type.
});

test('carryForward() does not copy when types at a position disagree', function () {
    $svc = new SectionIdentifiers;

    $result = $svc->carryForward(
        ['sections' => [['type' => 'hero', 'id' => 'AAA']]],
        ['sections' => [['type' => 'cta', 'title' => 'New']]],
    );

    expect($result['sections'][0])->not->toHaveKey('id');

    // Catches: an implementation that copies ids by position regardless of type.
});

test('carryForward() never manufactures a null or empty id', function () {
    $svc = new SectionIdentifiers;

    $result = $svc->carryForward(
        ['sections' => [['type' => 'hero', 'id' => ''], ['type' => 'cta', 'id' => null]]],
        ['sections' => [['type' => 'hero', 'title' => 'A'], ['type' => 'cta', 'title' => 'B']]],
    );

    expect($result['sections'][0])->not->toHaveKey('id');
    expect($result['sections'][1])->not->toHaveKey('id');

    // Catches: an implementation that writes id => null / id => '' and so
    // blocks ensure() from minting.
});