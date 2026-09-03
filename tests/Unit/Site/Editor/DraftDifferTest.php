<?php

use App\Services\Site\Editor\DraftDiffer;

function draftDiffer(): DraftDiffer
{
    return new DraftDiffer;
}

function assertChangeShape(array $entry): void
{
    expect($entry)->toHaveKeys([
        'scope',
        'page_id',
        'stored_index',
        'field_path',
        'path',
        'before',
        'after',
        'kind',
        'truncated',
    ]);
    expect($entry['kind'])->toBeIn(['set', 'unset', 'insert', 'remove', 'move']);
    expect($entry['truncated'])->toBeBool();
}

it('reports a field set with stored_index, field_path, and the dotted path edit_field and get_page_structure share', function () {
    $entries = draftDiffer()->diffContent(
        ['sections' => [['type' => 'hero', 'title' => 'A']]],
        ['sections' => [['type' => 'hero', 'title' => 'B']]],
        41,
    );

    expect($entries)->toHaveCount(1);
    assertChangeShape($entries[0]);
    expect($entries[0])->toMatchArray([
        'scope' => 'page',
        'page_id' => 41,
        'stored_index' => 0,
        'field_path' => 'title',
        'path' => 'sections.0.title',
        'before' => 'A',
        'after' => 'B',
        'kind' => 'set',
        'truncated' => false,
    ]);
});

it('reports an unset field with a null after and keeps the edit_field address', function () {
    $entries = draftDiffer()->diffContent(
        ['sections' => [['type' => 'hero', 'title' => 'A', 'subtitle' => 'Removed']]],
        ['sections' => [['type' => 'hero', 'title' => 'A']]],
        7,
    );

    expect($entries)->toHaveCount(1);
    expect($entries[0])->toMatchArray([
        'scope' => 'page',
        'page_id' => 7,
        'stored_index' => 0,
        'field_path' => 'subtitle',
        'path' => 'sections.0.subtitle',
        'before' => 'Removed',
        'after' => null,
        'kind' => 'unset',
        'truncated' => false,
    ]);
});

it('reports a newly added scalar field as set with a null before', function () {
    $entries = draftDiffer()->diffContent(
        ['sections' => [['type' => 'hero', 'title' => 'A']]],
        ['sections' => [['type' => 'hero', 'title' => 'A', 'subtitle' => 'Hi']]],
        1,
    );

    expect($entries)->toHaveCount(1);
    expect($entries[0])->toMatchArray([
        'stored_index' => 0,
        'field_path' => 'subtitle',
        'path' => 'sections.0.subtitle',
        'before' => null,
        'after' => 'Hi',
        'kind' => 'set',
    ]);
});

it('reports a nested field_path the way get_page_structure enumerates repeatable items', function () {
    $entries = draftDiffer()->diffContent(
        ['sections' => [['type' => 'services', 'items' => [['title' => 'Old']]]]],
        ['sections' => [['type' => 'services', 'items' => [['title' => 'New']]]]],
        3,
    );

    expect($entries)->toHaveCount(1);
    expect($entries[0])->toMatchArray([
        'stored_index' => 0,
        'field_path' => 'items.0.title',
        'path' => 'sections.0.items.0.title',
        'before' => 'Old',
        'after' => 'New',
        'kind' => 'set',
    ]);
});

it('inserts a whole section with null field_path and summarises the array value', function () {
    $entries = draftDiffer()->diffContent(
        ['sections' => [['type' => 'hero', 'title' => 'A']]],
        ['sections' => [
            ['type' => 'hero', 'title' => 'A'],
            ['type' => 'cta', 'title' => 'Call', 'body' => 'Now'],
        ]],
        9,
    );

    expect($entries)->toHaveCount(1);
    expect($entries[0])->toMatchArray([
        'scope' => 'page',
        'page_id' => 9,
        'stored_index' => 1,
        'field_path' => null,
        'path' => 'sections.1',
        'before' => null,
        'after' => ['__count' => 3],
        'kind' => 'insert',
        'truncated' => false,
    ]);
});

it('removes a whole section with null field_path and summarises the array value', function () {
    $entries = draftDiffer()->diffContent(
        ['sections' => [
            ['type' => 'hero', 'title' => 'A'],
            ['type' => 'cta', 'title' => 'Call'],
        ]],
        ['sections' => [['type' => 'hero', 'title' => 'A']]],
        2,
    );

    expect($entries)->toHaveCount(1);
    expect($entries[0])->toMatchArray([
        'stored_index' => 1,
        'field_path' => null,
        'path' => 'sections.1',
        'before' => ['__count' => 2],
        'after' => null,
        'kind' => 'remove',
    ]);
});

it('reports a type-preserving reorder as move, not as a remove-plus-insert pair', function () {
    $entries = draftDiffer()->diffContent(
        ['sections' => [
            ['type' => 'hero', 'title' => 'A'],
            ['type' => 'cta', 'title' => 'B'],
        ]],
        ['sections' => [
            ['type' => 'cta', 'title' => 'B'],
            ['type' => 'hero', 'title' => 'A'],
        ]],
        5,
    );

    $kinds = array_column($entries, 'kind');
    expect($kinds)->not->toContain('insert')
        ->and($kinds)->not->toContain('remove')
        ->and($kinds)->toBe(['move', 'move']);

    expect($entries[0])->toMatchArray([
        'stored_index' => 0,
        'field_path' => null,
        'path' => 'sections.0',
        'before' => 1,
        'after' => 0,
        'kind' => 'move',
    ]);
    expect($entries[1])->toMatchArray([
        'stored_index' => 1,
        'field_path' => null,
        'path' => 'sections.1',
        'before' => 0,
        'after' => 1,
        'kind' => 'move',
    ]);
});

it('addresses field changes on a moved section at the destination stored_index', function () {
    $entries = draftDiffer()->diffContent(
        ['sections' => [
            ['type' => 'hero', 'title' => 'A'],
            ['type' => 'cta', 'title' => 'B'],
        ]],
        ['sections' => [
            ['type' => 'cta', 'title' => 'B'],
            ['type' => 'hero', 'title' => 'Z'],
        ]],
        5,
    );

    $sets = array_values(array_filter($entries, fn (array $entry): bool => $entry['kind'] === 'set'));
    expect($sets)->toHaveCount(1);
    expect($sets[0])->toMatchArray([
        'stored_index' => 1,
        'field_path' => 'title',
        'path' => 'sections.1.title',
        'before' => 'A',
        'after' => 'Z',
        'kind' => 'set',
    ]);
});

it('treats a type replacement at the same index as remove plus insert', function () {
    $entries = draftDiffer()->diffContent(
        ['sections' => [['type' => 'hero', 'title' => 'A']]],
        ['sections' => [['type' => 'cta', 'title' => 'B']]],
        1,
    );

    expect(array_column($entries, 'kind'))->toEqualCanonicalizing(['insert', 'remove']);
    foreach ($entries as $entry) {
        expect($entry['stored_index'])->toBe(0)
            ->and($entry['field_path'])->toBeNull()
            ->and($entry['path'])->toBe('sections.0');
    }
});

it('truncates scalar values at 512 bytes and sets truncated true', function () {
    $long = str_repeat('a!', 300);
    $entries = draftDiffer()->diffContent(
        ['sections' => [['type' => 'hero', 'title' => 'short']]],
        ['sections' => [['type' => 'hero', 'title' => $long]]],
        1,
    );

    expect($entries)->toHaveCount(1);
    expect($entries[0]['truncated'])->toBeTrue();
    expect($entries[0]['after'])->toBe(str_repeat('a!', 256));
    expect(strlen((string) $entries[0]['after']))->toBe(512);
});

it('summarises an array value as a count rather than inlining it', function () {
    $entries = draftDiffer()->diffContent(
        ['sections' => [['type' => 'services', 'items' => []]]],
        ['sections' => [['type' => 'services', 'items' => [
            ['title' => 'One', 'body' => 'a'],
            ['title' => 'Two', 'body' => 'b'],
        ]]]],
        1,
    );

    $inserts = array_values(array_filter($entries, fn (array $entry): bool => $entry['kind'] === 'insert'));
    expect($inserts)->not->toBeEmpty();
    foreach ($inserts as $entry) {
        expect($entry['after'])->toBeArray()
            ->and($entry['after'])->toHaveKey('__count');
    }

    $json = json_encode($entries);
    expect($json)->not->toContain('One')
        ->and($json)->not->toContain('Two');
});

// Redaction is decidable-properties only: data: prefix, invalid UTF-8, disallowed control bytes, and
// canonical base64 at or above DraftDiffer's 64-character floor (48 decoded bytes). Below the floor a
// canonical string is too small to be a media payload and is echoed - see the accident test below.
it('never echoes a base64 or binary value', function (string $payload) {
    $entries = draftDiffer()->diffContent(
        ['sections' => [['type' => 'hero', 'title' => 'A', 'background_image' => null]]],
        ['sections' => [['type' => 'hero', 'title' => 'A', 'background_image' => $payload]]],
        1,
    );

    expect($entries)->toHaveCount(1);
    expect($entries[0]['truncated'])->toBeTrue();
    expect($entries[0]['after'])->toBeNull();
    expect(json_encode($entries))->not->toContain($payload);
})->with([
    'png base64' => 'iVBORw0KGgoAAAANSUhEUgAAAAQAAAAEAQMAAACTPww9AAAAIGNIUk0AAHomAACAhAAA+gAAAIDoAAB1MAAA6mAAADqYAAAXcJy6UTwAAAAGUExURf8AAP///0EdNBEAAAABYktHRAH/Ai3eAAAAB3RJTUUH6ggcBTkHx5/rUAAAAAtJREFUCNdjYIAAAAAIAAEvIN0xAAAAAElFTkSuQmCC',
    'data uri' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAQAAAAEAQMAAACTPww9AAAAIGNIUk0AAHomAACAhAAA+gAAAIDoAAB1MAAA6mAAADqYAAAXcJy6UTwAAAAGUExURf8AAP///0EdNBEAAAABYktHRAH/Ai3eAAAAB3RJTUUH6ggcBTkHx5/rUAAAAAtJREFUCNdjYIAAAAAIAAEvIN0xAAAAAElFTkSuQmCC',
    'text base64' => base64_encode(str_repeat('The quick brown fox jumps over the lazy dog. ', 4)),
    'control-byte base64' => base64_encode(str_repeat("\x01", 100)),
    'raw control byte' => "headline\x01payload",
    'pdf header' => "%PDF-1.4\n%\x01\x01\x01\x01\n1 0 obj\n<<>>\nendobj\n",
    'all-A canonical' => str_repeat('A', 64),
    'url-safe base64' => strtr(base64_encode(str_repeat("\xfb\xff\xbf", 22)), '+/', '-_'),
    'raw DEL' => "headline\x7fpayload",
    'invalid utf8' => "\xC3\x28",
]);

it('redacts a SHA-256 hex digest and a long numeric reference code', function (string $payload) {
    // Accepted cost of the absolute "no base64 is ever echoed" contract.
    // These are legitimate field values; provenance-based redaction (text vs
    // asset/blob) is the backlogged fix so the differ can redact by type
    // rather than guessing from characters.
    $entries = draftDiffer()->diffContent(
        ['sections' => [['type' => 'hero', 'title' => 'A', 'background_image' => null]]],
        ['sections' => [['type' => 'hero', 'title' => 'A', 'background_image' => $payload]]],
        1,
    );

    expect($entries)->toHaveCount(1);
    expect($entries[0]['truncated'])->toBeTrue();
    expect($entries[0]['after'])->toBeNull();
    expect(json_encode($entries))->not->toContain($payload);
})->with([
    'sha-256 hex digest' => hash('sha256', 'test'),
    'long numeric reference' => str_repeat('9', 80),
]);

it('echoes ordinary short values that are canonical base64 by accident', function (string $value) {
    // The regression this test exists to prevent. Without the 64-character floor every four-letter
    // word is canonical base64 of three bytes and was silently blanked - including 'Home', 'hero',
    // 'type', 'body', 'dark', and the section types 'Services' and 'Projects'. Blanking real content
    // in the diff an agent reads to decide its next call is worse than echoing under 48 bytes that
    // cannot be a media payload.
    $entries = draftDiffer()->diffContent(
        ['sections' => [['type' => 'hero', 'title' => 'A']]],
        ['sections' => [['type' => 'hero', 'title' => $value]]],
        1,
    );

    expect($entries)->toHaveCount(1);
    expect($entries[0]['truncated'])->toBeFalse();
    expect($entries[0]['after'])->toBe($value);
})->with([
    'four-letter word' => 'Home',
    'section type hero' => 'hero',
    'section type Services' => 'Services',
    'section type Projects' => 'Projects',
    'theme token' => 'dark',
    'short canonical base64' => 'aGVsbG8gd29ybGQ=',
    // The floor's lower edge, pinned literally: 63 characters echo, 64 redact (the redaction
    // test's all-A case). Without both sides the floor could move and the suite stay green.
    '63 chars, one below the floor' => str_repeat('A', 63),
]);

it('does not redact ordinary long prose, a long URL, or minified JSON', function (string $value) {
    $entries = draftDiffer()->diffContent(
        ['sections' => [['type' => 'hero', 'title' => 'A']]],
        ['sections' => [['type' => 'hero', 'title' => $value]]],
        1,
    );

    expect($entries)->toHaveCount(1);
    expect($entries[0]['truncated'])->toBeFalse();
    expect($entries[0]['after'])->toBe($value);
})->with([
    'long prose' => str_repeat('Reliable local roofing for homes and businesses in Kent. ', 4),
    'long url' => 'https://example.com/preview/kent-roofing/services/flat-roof-repair?utm_source=agent&utm_medium=preview&ref='.str_repeat('n', 80).'#contact',
    'minified json' => '{"business":"Kent Roofing","areas":["Maidstone","Ashford","Canterbury"],"phone":"+441234567890","cta":"Get a quote"}',
]);

it('orders entries by page id, stored index, then path so two runs are byte-identical', function () {
    $before = ['sections' => [[
        'type' => 'hero',
        'subtitle' => 'old-sub',
        'title' => 'old-title',
        'eyebrow' => 'old-eye',
    ]]];
    $after = ['sections' => [[
        'type' => 'hero',
        'subtitle' => 'new-sub',
        'title' => 'new-title',
        'eyebrow' => 'new-eye',
    ]]];

    $first = draftDiffer()->diffContent($before, $after, 12);
    $second = draftDiffer()->diffContent($after, $after, 12);
    $again = draftDiffer()->diffContent($before, $after, 12);

    expect(json_encode($first))->toBe(json_encode($again));
    expect($second)->toBe([]);
    expect(array_column($first, 'path'))->toBe([
        'sections.0.eyebrow',
        'sections.0.subtitle',
        'sections.0.title',
    ]);
});

it('returns no entries when page content is unchanged', function () {
    $content = ['sections' => [['type' => 'hero', 'title' => 'A'], ['type' => 'cta', 'title' => 'B']]];

    expect(draftDiffer()->diffContent($content, $content, 1))->toBe([]);
});

it('diffs non-section page keys without inventing a stored_index', function () {
    $entries = draftDiffer()->diffContent(
        ['sections' => [['type' => 'hero', 'title' => 'A']], 'meta' => ['seo' => ['title' => 'Old']]],
        ['sections' => [['type' => 'hero', 'title' => 'A']], 'meta' => ['seo' => ['title' => 'New']]],
        8,
    );

    expect($entries)->toHaveCount(1);
    expect($entries[0])->toMatchArray([
        'scope' => 'page',
        'page_id' => 8,
        'stored_index' => null,
        'field_path' => 'meta.seo.title',
        'path' => 'meta.seo.title',
        'before' => 'Old',
        'after' => 'New',
        'kind' => 'set',
    ]);
});

it('diffs composition with site scope and dotted composition paths', function () {
    $entries = draftDiffer()->diffComposition(
        ['theme' => ['accent_override' => '#111111'], 'nav' => ['items' => [
            ['label' => 'Home'],
            ['label' => 'About'],
            ['label' => 'Old'],
        ]]],
        ['theme' => ['accent_override' => '#ff6600'], 'nav' => ['items' => [
            ['label' => 'Home'],
            ['label' => 'About'],
            ['label' => 'Contact'],
        ]]],
    );

    expect($entries)->toHaveCount(2);
    foreach ($entries as $entry) {
        assertChangeShape($entry);
        expect($entry['scope'])->toBe('site')
            ->and($entry['page_id'])->toBeNull()
            ->and($entry['stored_index'])->toBeNull()
            ->and($entry['path'])->toStartWith('composition.');
    }

    expect(array_column($entries, 'path'))->toBe([
        'composition.nav.items.2.label',
        'composition.theme.accent_override',
    ]);
    expect($entries[0])->toMatchArray([
        'field_path' => null,
        'before' => 'Old',
        'after' => 'Contact',
        'kind' => 'set',
    ]);
    expect($entries[1])->toMatchArray([
        'before' => '#111111',
        'after' => '#ff6600',
        'kind' => 'set',
    ]);
});

it('diffs drafted asset selections so a selection-only write still names what changed', function () {
    $entries = draftDiffer()->diffSelections(
        [
            ['family' => 'hero', 'page_type' => 'home', 'slot' => 'hero', 'version_id' => 10],
            ['family' => 'logo', 'page_type' => '', 'slot' => '', 'version_id' => 3],
        ],
        [
            ['family' => 'hero', 'page_type' => 'home', 'slot' => 'hero', 'version_id' => 22],
            ['family' => 'logo', 'page_type' => '', 'slot' => '', 'version_id' => 3],
        ],
    );

    expect($entries)->toHaveCount(1);
    assertChangeShape($entries[0]);
    expect($entries[0])->toMatchArray([
        'scope' => 'site',
        'page_id' => null,
        'stored_index' => null,
        'field_path' => null,
        'path' => 'asset_selection.hero.home.hero.version_id',
        'before' => 10,
        'after' => 22,
        'kind' => 'set',
        'truncated' => false,
    ]);
});

it('inserts and removes selection keys at the spec path prefix', function () {
    $inserted = draftDiffer()->diffSelections(
        [],
        [['family' => 'hero', 'page_type' => 'home', 'slot' => 'hero', 'version_id' => 4]],
    );
    $removed = draftDiffer()->diffSelections(
        [['family' => 'logo', 'page_type' => '', 'slot' => '', 'version_id' => 9]],
        [],
    );

    expect($inserted)->toHaveCount(1);
    expect($inserted[0])->toMatchArray([
        'scope' => 'site',
        'path' => 'asset_selection.hero.home.hero.version_id',
        'before' => null,
        'after' => 4,
        'kind' => 'insert',
    ]);

    expect($removed)->toHaveCount(1);
    expect($removed[0])->toMatchArray([
        'path' => 'asset_selection.logo.version_id',
        'before' => 9,
        'after' => null,
        'kind' => 'remove',
    ]);
});

it('orders selection paths lexicographically even when rows and fields arrive unsorted', function () {
    $before = [
        ['family' => 'logo', 'page_type' => '', 'slot' => '', 'version_id' => 1, 'mode' => 'fit'],
        ['family' => 'hero', 'page_type' => 'services', 'slot' => 'hero', 'version_id' => 10, 'alt' => 'old'],
        ['family' => 'hero', 'page_type' => 'home', 'slot' => 'hero', 'version_id' => 3, 'mode' => 'on'],
        ['family' => 'cta', 'page_type' => 'home', 'slot' => 'banner', 'version_id' => 7, 'mode' => 'dark'],
    ];
    $after = [
        ['family' => 'logo', 'page_type' => '', 'slot' => '', 'version_id' => 2, 'mode' => 'fill'],
        ['family' => 'hero', 'page_type' => 'services', 'slot' => 'hero', 'version_id' => 11, 'alt' => 'new'],
        ['family' => 'hero', 'page_type' => 'home', 'slot' => 'hero', 'version_id' => 4, 'mode' => 'off'],
        ['family' => 'cta', 'page_type' => 'home', 'slot' => 'banner', 'version_id' => 8, 'mode' => 'light'],
    ];

    expect(array_column(draftDiffer()->diffSelections($before, $after), 'path'))->toBe([
        'asset_selection.cta.home.banner.mode',
        'asset_selection.cta.home.banner.version_id',
        'asset_selection.hero.home.hero.mode',
        'asset_selection.hero.home.hero.version_id',
        'asset_selection.hero.services.hero.alt',
        'asset_selection.hero.services.hero.version_id',
        'asset_selection.logo.mode',
        'asset_selection.logo.version_id',
    ]);
});

it('returns no entries when two selection snapshots are identical', function () {
    $snapshot = [
        ['family' => 'hero', 'page_type' => 'home', 'slot' => 'hero', 'version_id' => 1, 'mode' => 'on'],
        ['family' => 'logo', 'page_type' => '', 'slot' => '', 'version_id' => 3],
    ];

    expect(draftDiffer()->diffSelections($snapshot, $snapshot))->toBe([]);
});

it('reports a same-type id-keyed swap as two moves and no field-level sets', function () {
    // Named wrong implementation: the current position-and-type matcher. A
    // reorder never changes a type bucket's cardinality, so the remove/insert
    // loops never run; first-of-type is paired with first-of-type and the
    // differ emits a spray of title/body set entries under sections.* instead
    // of two moves.
    $before = ['sections' => [
        ['type' => 'hero', 'id' => 'AAA', 'title' => 'Alpha title', 'body' => 'Alpha body'],
        ['type' => 'hero', 'id' => 'BBB', 'title' => 'Beta title', 'body' => 'Beta body'],
    ]];
    $after = ['sections' => [
        ['type' => 'hero', 'id' => 'BBB', 'title' => 'Beta title', 'body' => 'Beta body'],
        ['type' => 'hero', 'id' => 'AAA', 'title' => 'Alpha title', 'body' => 'Alpha body'],
    ]];

    $entries = draftDiffer()->diffContent($before, $after, 17);

    expect(array_column($entries, 'kind'))->toBe(['move', 'move']);
    expect($entries[0])->toMatchArray([
        'scope' => 'page',
        'page_id' => 17,
        'stored_index' => 0,
        'section_id' => 'BBB',
        'field_path' => null,
        'path' => 'sections.0',
        'before' => 1,
        'after' => 0,
        'kind' => 'move',
        'truncated' => false,
    ]);
    expect($entries[1])->toMatchArray([
        'scope' => 'page',
        'page_id' => 17,
        'stored_index' => 1,
        'section_id' => 'AAA',
        'field_path' => null,
        'path' => 'sections.1',
        'before' => 0,
        'after' => 1,
        'kind' => 'move',
        'truncated' => false,
    ]);

    $setsUnderSections = array_values(array_filter(
        $entries,
        fn (array $entry): bool => $entry['kind'] === 'set' && str_starts_with((string) $entry['path'], 'sections.'),
    ));
    expect($setsUnderSections)->toBe([]);
});

it('does not emit id as a changed field when the after snapshot gains section ids', function () {
    // Named wrong implementation: adding section_id to the entry but leaving
    // `id` inside the diffed section body, which emits sections.N.id: null → id
    // on every pre-backfill published revision vs post-backfill draft.
    $before = ['sections' => [
        ['type' => 'hero', 'title' => 'Old title', 'body' => 'Same body'],
    ]];
    $after = ['sections' => [
        ['type' => 'hero', 'id' => 'SECID001', 'title' => 'New title', 'body' => 'Same body'],
    ]];

    $entries = draftDiffer()->diffContent($before, $after, 4);

    $idFieldEntries = array_values(array_filter(
        $entries,
        fn (array $entry): bool => $entry['field_path'] === 'id',
    ));
    expect($idFieldEntries)->toBe([]);

    $sets = array_values(array_filter($entries, fn (array $entry): bool => $entry['kind'] === 'set'));
    expect($sets)->toHaveCount(1);
    expect($sets[0])->toMatchArray([
        'stored_index' => 0,
        'section_id' => 'SECID001',
        'field_path' => 'title',
        'path' => 'sections.0.title',
        'before' => 'Old title',
        'after' => 'New title',
        'kind' => 'set',
    ]);
});

it('matches id-less before sections to id-bearing after sections instead of remove-plus-insert', function () {
    // Named wrong implementation: an id-only matcher. A before snapshot taken
    // from a pre-backfill published revision has no ids; id-only matching
    // reports every section as a remove plus an insert. get_draft_diff diffs
    // published → draft, so that is the normal state of every site at ship.
    $before = ['sections' => [
        ['type' => 'hero', 'title' => 'Old title', 'body' => 'Same body'],
    ]];
    $after = ['sections' => [
        ['type' => 'hero', 'id' => 'SECID001', 'title' => 'New title', 'body' => 'Same body'],
    ]];

    $entries = draftDiffer()->diffContent($before, $after, 4);
    $kinds = array_column($entries, 'kind');

    expect($kinds)->not->toContain('insert')
        ->and($kinds)->not->toContain('remove');
});

it('reports mismatched non-empty ids as remove and insert even when content is identical', function () {
    // Named wrong implementation: the type-bucket ordinal fallback pairing
    // two heroes that both carry ids. AAA is not in $afterById, so both land
    // in the hero bucket unpaired; min(1,1)=1 pairs them and identical
    // content yields an empty diff instead of remove+insert.
    $before = ['sections' => [
        ['type' => 'hero', 'id' => 'AAA', 'title' => 'Same title', 'body' => 'Same body'],
    ]];
    $after = ['sections' => [
        ['type' => 'hero', 'id' => 'BBB', 'title' => 'Same title', 'body' => 'Same body'],
    ]];

    $entries = draftDiffer()->diffContent($before, $after, 6);

    $sets = array_values(array_filter($entries, fn (array $entry): bool => $entry['kind'] === 'set'));
    expect($sets)->toBe([]);

    $removes = array_values(array_filter($entries, fn (array $entry): bool => $entry['kind'] === 'remove'));
    $inserts = array_values(array_filter($entries, fn (array $entry): bool => $entry['kind'] === 'insert'));
    expect($removes)->toHaveCount(1);
    expect($inserts)->toHaveCount(1);
    expect($removes[0]['section_id'])->toBe('AAA');
    expect($inserts[0]['section_id'])->toBe('BBB');
});

it('still pairs a type-bucket candidate when only one side carries an id', function () {
    // Named wrong implementation: excluding every id-bearing section from
    // the type-bucket fallback. A before snapshot with no ids and an after
    // snapshot with ids is the ship-day get_draft_diff path; refusing to
    // pair those mixed candidates reports every section as remove+insert
    // instead of a field-level set.
    $before = ['sections' => [
        ['type' => 'hero', 'title' => 'Old title', 'body' => 'Same body'],
        ['type' => 'cta', 'title' => 'Call now'],
    ]];
    $after = ['sections' => [
        ['type' => 'hero', 'id' => 'SECID001', 'title' => 'New title', 'body' => 'Same body'],
        ['type' => 'cta', 'id' => 'SECID002', 'title' => 'Call now'],
    ]];

    $entries = draftDiffer()->diffContent($before, $after, 4);
    $kinds = array_column($entries, 'kind');

    expect($kinds)->not->toContain('insert')
        ->and($kinds)->not->toContain('remove');

    $sets = array_values(array_filter($entries, fn (array $entry): bool => $entry['kind'] === 'set'));
    expect($sets)->toHaveCount(1);
    expect($sets[0])->toMatchArray([
        'stored_index' => 0,
        'section_id' => 'SECID001',
        'field_path' => 'title',
        'path' => 'sections.0.title',
        'before' => 'Old title',
        'after' => 'New title',
        'kind' => 'set',
    ]);
});

it('names the fixture section id on insert, remove, and field-set entries', function () {
    $inserted = draftDiffer()->diffContent(
        ['sections' => [['type' => 'hero', 'id' => 'HERO1', 'title' => 'A']]],
        ['sections' => [
            ['type' => 'hero', 'id' => 'HERO1', 'title' => 'A'],
            ['type' => 'cta', 'id' => 'CTA9', 'title' => 'Call', 'body' => 'Now'],
        ]],
        9,
    );
    expect($inserted)->toHaveCount(1);
    expect($inserted[0])->toMatchArray([
        'kind' => 'insert',
        'stored_index' => 1,
        'section_id' => 'CTA9',
        'field_path' => null,
        'path' => 'sections.1',
    ]);

    $removed = draftDiffer()->diffContent(
        ['sections' => [
            ['type' => 'hero', 'id' => 'HERO1', 'title' => 'A'],
            ['type' => 'cta', 'id' => 'CTA9', 'title' => 'Call'],
        ]],
        ['sections' => [['type' => 'hero', 'id' => 'HERO1', 'title' => 'A']]],
        2,
    );
    expect($removed)->toHaveCount(1);
    expect($removed[0])->toMatchArray([
        'kind' => 'remove',
        'stored_index' => 1,
        'section_id' => 'CTA9',
        'field_path' => null,
        'path' => 'sections.1',
    ]);

    $sets = draftDiffer()->diffContent(
        ['sections' => [['type' => 'hero', 'id' => 'HERO1', 'title' => 'Old']]],
        ['sections' => [['type' => 'hero', 'id' => 'HERO1', 'title' => 'New']]],
        41,
    );
    expect($sets)->toHaveCount(1);
    expect($sets[0])->toMatchArray([
        'kind' => 'set',
        'stored_index' => 0,
        'section_id' => 'HERO1',
        'field_path' => 'title',
        'path' => 'sections.0.title',
        'before' => 'Old',
        'after' => 'New',
    ]);

    $idLess = draftDiffer()->diffContent(
        ['sections' => [['type' => 'hero', 'title' => 'A']]],
        ['sections' => [['type' => 'hero', 'title' => 'B']]],
        41,
    );
    expect($idLess)->toHaveCount(1);
    expect($idLess[0]['section_id'])->toBeNull();
});

it('orders entries by stored_index not section id', function () {
    // Named wrong implementation: re-sorting changed[] by section_id. Ids
    // ZZZ then AAA sort the other way from indexes 0 then 1; a frozen path
    // list is the oracle (comparing two implementation-produced lists is
    // vacuous).
    $before = ['sections' => [
        ['type' => 'hero', 'id' => 'ZZZ', 'eyebrow' => 'old-z-eye', 'title' => 'old-z'],
        ['type' => 'cta', 'id' => 'AAA', 'title' => 'old-a'],
    ]];
    $after = ['sections' => [
        ['type' => 'hero', 'id' => 'ZZZ', 'eyebrow' => 'new-z-eye', 'title' => 'new-z'],
        ['type' => 'cta', 'id' => 'AAA', 'title' => 'new-a'],
    ]];

    $entries = draftDiffer()->diffContent($before, $after, 12);

    expect(array_map(fn (array $entry): array => [
        'stored_index' => $entry['stored_index'],
        'section_id' => $entry['section_id'],
        'path' => $entry['path'],
    ], $entries))->toBe([
        ['stored_index' => 0, 'section_id' => 'ZZZ', 'path' => 'sections.0.eyebrow'],
        ['stored_index' => 0, 'section_id' => 'ZZZ', 'path' => 'sections.0.title'],
        ['stored_index' => 1, 'section_id' => 'AAA', 'path' => 'sections.1.title'],
    ]);
});

it('puts a null section_id on site-scope composition and selection entries', function () {
    $composition = draftDiffer()->diffComposition(
        ['theme' => ['accent_override' => '#111111']],
        ['theme' => ['accent_override' => '#ff6600']],
    );
    expect($composition)->toHaveCount(1);
    expect($composition[0]['scope'])->toBe('site');
    expect($composition[0]['section_id'])->toBeNull();

    $selections = draftDiffer()->diffSelections(
        [['family' => 'hero', 'page_type' => 'home', 'slot' => 'hero', 'version_id' => 10]],
        [['family' => 'hero', 'page_type' => 'home', 'slot' => 'hero', 'version_id' => 22]],
    );
    expect($selections)->toHaveCount(1);
    expect($selections[0]['scope'])->toBe('site');
    expect($selections[0]['section_id'])->toBeNull();
});
