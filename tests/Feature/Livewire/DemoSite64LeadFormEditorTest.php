<?php

use Livewire\Livewire;

it('lead-form-editor mounts for the demo user on site 64 and saves a title', function () {
    [$site, $user] = demoSite64();
    $page = demoSite64HomePage($site);
    $content = $page->content_data ?? [];
    $sections = $content['sections'] ?? [];
    if (! collect($sections)->contains(fn ($section) => ($section['type'] ?? null) === 'lead_form')) {
        $sections[] = [
            'type' => 'lead_form',
            'title' => 'Get in touch',
            'intro' => 'We reply the same day.',
            'benefits' => ['Handmade cakes', 'Same-day reply', 'Local pickup'],
            'submit_label' => 'Send',
            'extra_fields' => [],
        ];
        $content['sections'] = $sections;
        $page->update(['content_data' => $content]);
    }

    Livewire::actingAs($user)
        ->test('lead-form-editor', ['siteId' => $site->id, 'pageType' => 'home'])
        ->set('title', 'Ask about a cake')
        ->call('save')
        ->assertOk()
        ->assertSet('saved', true)
        ->assertSet('title', 'Ask about a cake');
});
