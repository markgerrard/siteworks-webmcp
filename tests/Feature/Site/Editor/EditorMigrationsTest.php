<?php

use Illuminate\Support\Facades\Schema;

it('creates the editor tables and columns', function () {
    expect(Schema::hasTable('editor_operation_log'))->toBeTrue()
        ->and(Schema::hasColumns('editor_operation_log', ['site_id', 'page_id', 'actor_user_id', 'actor_channel', 'operation', 'result_code', 'duration_ms', 'created_at']))->toBeTrue()
        ->and(Schema::hasColumn('site_media', 'actor_channel'))->toBeTrue()
        ->and(Schema::hasTable('site_draft_asset_selections'))->toBeTrue()
        ->and(Schema::hasColumns('site_draft_asset_selections', ['site_id', 'family', 'page_type', 'slot', 'version_id', 'mode', 'placement', 'created_by_user_id']))->toBeTrue()
        ->and(Schema::hasColumn('generated_pages', 'structure_epoch'))->toBeTrue();
});
