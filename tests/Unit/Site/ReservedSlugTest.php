<?php

use App\Models\GeneratedPage;


test('GeneratedPage::RESERVED_SLUGS contains shop, news, admin, login, register and _edit', function () {
    expect(GeneratedPage::RESERVED_SLUGS)
        ->toContain('shop')
        ->toContain('news')
        ->toContain('admin')
        ->toContain('login')
        ->toContain('register')
        ->toContain('_edit');
});
