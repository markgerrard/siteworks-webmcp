<?php

use Illuminate\Support\Facades\Route;

// Editor shell + its mutation/preview-action routes — shared across the
// agents and customer surfaces. Authorization is delegated to SitePolicy
// (`view` / `update`) on each controller, which permits both staff (via
// isStaff/isAdmin etc.) and matching client users (via client_id match).
//
// No `Route::domain()` pin: each surface deploy serves these on its own
// host. On SURFACE=all the route matches both agents and customer hosts,
// and route('site.editor-shell', …) builds URLs against the current
// request's host.
//
// Names are kept under the `site.*` prefix that pre-dated the surface
// split, so existing Blade route() calls continue to resolve unchanged.

Route::middleware(['auth', 'verified'])->group(function () {
    if ((bool) config('editor.agent_approval.enabled')) {
        Route::get('/sites/{site}/agent-approvals', [\App\Http\Controllers\Site\Editor\AgentApprovalController::class, 'index'])
            ->name('site.editor.agent-approvals');
        Route::post('/sites/{site}/agent-approvals/grant', [\App\Http\Controllers\Site\Editor\AgentApprovalController::class, 'grant'])
            ->name('site.editor.agent-approvals.grant');
        Route::post('/sites/{site}/agent-approvals/grant/revoke', [\App\Http\Controllers\Site\Editor\AgentApprovalController::class, 'revokeGrant'])
            ->name('site.editor.agent-approvals.grant-revoke');
        Route::post('/sites/{site}/agent-approvals/{approval}/approve', [\App\Http\Controllers\Site\Editor\AgentApprovalController::class, 'approve'])
            ->name('site.editor.agent-approvals.approve');
        Route::post('/sites/{site}/agent-approvals/{approval}/deny', [\App\Http\Controllers\Site\Editor\AgentApprovalController::class, 'deny'])
            ->name('site.editor.agent-approvals.deny');
    }

    Route::get('/sites/{site}/pages/{page}/editor', \App\Http\Controllers\Site\EditorShellController::class)
        ->name('site.editor-shell');

    // Inline field-update endpoint (WYSIWYG editor)
    Route::post('/sites/{site}/pages/{page}/fields', \App\Http\Controllers\Site\PageFieldUpdateController::class)
        ->name('site.admin.field-update');

    Route::get('/sites/{site}/pages/{page}/form/{section}', \App\Http\Controllers\Site\FormDefinitionController::class)
        ->whereNumber('section')
        ->name('site.admin.form-definition');

    Route::post('/sites/{site}/pages/{page}/form/{section}', \App\Http\Controllers\Site\FormUpdateController::class)
        ->whereNumber('section')
        ->name('site.admin.form-update');

    // Publish / discard / summary (WYSIWYG editor toolbar)
    Route::get('/sites/{site}/publish-summary', [\App\Http\Controllers\Site\SitePublishController::class, 'summary'])
        ->name('site.admin.publish-summary');
    Route::post('/sites/{site}/publish', [\App\Http\Controllers\Site\SitePublishController::class, 'publish'])
        ->name('site.admin.publish');
    Route::post('/sites/{site}/discard-all', [\App\Http\Controllers\Site\SitePublishController::class, 'discardAll'])
        ->name('site.admin.discard-all');

    // Media upload (WYSIWYG image picker)
    Route::post('/sites/{site}/media', \App\Http\Controllers\Site\SiteMediaUploadController::class)
        ->name('site.admin.media-upload');

    Route::get('/sites/{site}/pages/{page}/preview-url', \App\Http\Controllers\Site\Editor\EditorOperationController::class)
        ->name('site.editor.preview-url');
    Route::get('/sites/{site}/pages/{page}/structure', \App\Http\Controllers\Site\Editor\EditorOperationController::class)
        ->name('site.editor.structure');
    Route::get('/sites/{site}/brand-context', \App\Http\Controllers\Site\Editor\EditorOperationController::class)
        ->name('site.editor.brand-context');
    Route::get('/sites/{site}/image-versions', \App\Http\Controllers\Site\Editor\EditorOperationController::class)
        ->name('site.editor.image-versions');
    Route::get('/sites/{site}/jobs/{ref}', \App\Http\Controllers\Site\Editor\EditorOperationController::class)
        ->name('site.editor.job-status');
    Route::post('/sites/{site}/operations/{operation}', [\App\Http\Controllers\Site\Editor\EditorOperationController::class, 'operation'])
        ->where('operation', '[A-Za-z0-9_]{1,64}')
        ->name('site.editor.operation');
    Route::post('/sites/{site}/pages/{page}/sections', \App\Http\Controllers\Site\Editor\EditorOperationController::class)
        ->name('site.editor.sections');
    Route::post('/sites/{site}/logo/select', \App\Http\Controllers\Site\Editor\EditorOperationController::class)
        ->name('site.editor.select-logo');
    Route::post('/sites/{site}/image-versions/restore', \App\Http\Controllers\Site\Editor\EditorOperationController::class)
        ->name('site.editor.restore-image-version');
    Route::post('/sites/{site}/pages/{page}/media/restore', \App\Http\Controllers\Site\Editor\EditorOperationController::class)
        ->name('site.editor.restore-media-version');

    // Admin handoff → public-host editor (mints a signed token, redirects
    // to public URL). Same controller works for clients — the mint just
    // proves the user can see the page.
    Route::get('/sites/{site}/pages/{page}/open-live-editor', \App\Http\Controllers\Site\OpenLiveEditorController::class)
        ->name('site.admin.open-live-editor');

    // Version history — preview a historical version or roll back to it.
    // Embedded in the History tab of both the agent sites/show page and
    // the customer-portal History section, so both surfaces resolve the
    // route names. Authorization via SitePolicy@view / @update.
    Route::get('/sites/{site}/versions/{version}/preview', \App\Http\Controllers\Site\SiteVersionPreviewController::class)
        ->name('site.version.preview');
    Route::post('/sites/{site}/versions/{version}/rollback', \App\Http\Controllers\Site\SiteVersionRollbackController::class)
        ->name('site.version.rollback');
});
