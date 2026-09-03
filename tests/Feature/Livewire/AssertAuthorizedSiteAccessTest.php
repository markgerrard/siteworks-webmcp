<?php

use App\Enums\AgentRole;
use App\Livewire\Concerns\AuthorizesSiteAccess;
use App\Models\ProjectItem;
use App\Models\Site;
use App\Models\User;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * findAuthorizedSite() signals denial by returning null — call sites that
 * discard the result silently continue into the mutation (the shape of
 * the Sev-1 IDOR fixed in 8141493). assertAuthorizedSiteAccess() is the
 * fail-closed variant those call sites now use.
 */
function traitHarness(int $siteId): object
{
    return new class($siteId)
    {
        use AuthorizesSiteAccess;

        public function __construct(public int $siteId) {}

        /**
         * Test-only bridge: the trait method is protected (it must never
         * be a callable Livewire action), so the harness exposes it.
         */
        public function callAssertAuthorizedSiteAccess(): Site
        {
            return $this->assertAuthorizedSiteAccess();
        }
    };
}

it('aborts 403 when the user cannot access the site', function () {
    $owner = User::factory()->staff(AgentRole::Agent)->create();
    $outsider = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $owner->id]);

    $this->actingAs($outsider);

    expect(fn () => traitHarness($site->id)->callAssertAuthorizedSiteAccess())
        ->toThrow(HttpException::class);
});

it('returns the site for an authorized user', function () {
    $owner = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $owner->id]);

    $this->actingAs($owner);

    expect(traitHarness($site->id)->callAssertAuthorizedSiteAccess()->id)->toBe($site->id);
});

it('blocks project-item-card mount for an agent with no claim on the item’s site', function () {
    $owner = User::factory()->staff(AgentRole::Agent)->create();
    $outsider = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $owner->id]);
    $item = ProjectItem::factory()->create(['site_id' => $site->id]);

    Livewire::actingAs($outsider)
        ->test('project-item-card', ['itemId' => $item->id])
        ->assertStatus(403);
});
