<?php

use App\Models\ProjectsPageDraft;
use App\Models\Site;
use Illuminate\Support\Facades\DB;


it('prunes projects_page_drafts rows older than the threshold', function () {
    $site = Site::factory()->create();

    $old = ProjectsPageDraft::create([
        'site_id' => $site->id,
        'content_hash' => str_repeat('a', 40),
        'response' => ['stub' => true],
    ]);
    // Bypass Eloquent timestamps — model->update touches updated_at
    // and won't reliably write a back-dated created_at.
    DB::table('projects_page_drafts')
        ->where('id', $old->id)
        ->update(['created_at' => now()->subDays(5)]);

    $fresh = ProjectsPageDraft::create([
        'site_id' => $site->id,
        'content_hash' => str_repeat('b', 40),
        'response' => ['stub' => true],
    ]);

    $this->artisan('site:prune-projects-page-drafts', ['--days' => 2])
        ->expectsOutputToContain('Pruned 1')
        ->assertSuccessful();

    expect(ProjectsPageDraft::find($old->id))->toBeNull();
    expect(ProjectsPageDraft::find($fresh->id))->not->toBeNull();
});
