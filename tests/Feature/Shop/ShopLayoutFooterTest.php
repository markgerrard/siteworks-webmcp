<?php

test('shop and site layouts share the footer pin body style', function () {
    $bodyStyle = 'min-height: 100vh; display: flex; flex-direction: column;';
    $bodyAttribute = 'style="{{ config(\'site.layout_body_style\') }}"';

    expect(config('site.layout_body_style'))->toBe($bodyStyle)
        ->and(file_get_contents(resource_path('views/components/shop/layout.blade.php')))->toContain($bodyAttribute)
        ->and(file_get_contents(resource_path('views/site/page.blade.php')))->toContain($bodyAttribute);
});
