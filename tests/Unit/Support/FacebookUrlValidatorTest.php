<?php

use App\Support\FacebookUrlValidator;

it('accepts importable page urls and normalises them', function () {
    expect(FacebookUrlValidator::isImportablePageUrl('https://www.facebook.com/BallymenaBespokeTiling'))->toBeTrue()
        ->and(FacebookUrlValidator::isImportablePageUrl('http://facebook.com/BallymenaBespokeTiling'))->toBeFalse()
        ->and(FacebookUrlValidator::normalisePageUrl('https://facebook.com/Ballymena.Bespoke-Tiling_2024/?ref=site#top'))
        ->toBe('https://www.facebook.com/Ballymena.Bespoke-Tiling_2024');
});

it('rejects reserved facebook paths and non page urls', function (string $url) {
    expect(FacebookUrlValidator::isImportablePageUrl($url))->toBeFalse();
})->with([
    'login' => 'https://www.facebook.com/login',
    'share' => 'https://www.facebook.com/share/abc123',
    'photo' => 'https://www.facebook.com/photo/?fbid=123',
    'photos' => 'https://www.facebook.com/photos/example',
    'groups' => 'https://www.facebook.com/groups/example',
    'events' => 'https://www.facebook.com/events/example',
    'pages' => 'https://www.facebook.com/pages/example',
    'pg' => 'https://www.facebook.com/pg/example',
    'junk' => 'not-a-url',
    'other host' => 'https://example.com/facebook.com/ValidHandle',
]);

it('extracts the first importable facebook page url from scrape data', function () {
    $url = FacebookUrlValidator::extractFromScrapeData([
        'markdown' => 'Follow us at https://www.facebook.com/photo/?fbid=123 or https://facebook.com/BallymenaBespokeTiling?locale=en_GB.',
        'links' => [
            ['href' => 'https://www.facebook.com/AnotherValidHandle'],
        ],
    ]);

    expect($url)->toBe('https://www.facebook.com/BallymenaBespokeTiling');
});

it('returns null when scrape data has no importable facebook url', function () {
    expect(FacebookUrlValidator::extractFromScrapeData([
        'html' => '<a href="https://www.facebook.com/login">Facebook</a>',
        'markdown' => 'No public page link here.',
    ]))->toBeNull();
});
