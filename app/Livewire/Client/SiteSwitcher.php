<?php

namespace App\Livewire\Client;

use Livewire\Attributes\Locked;
use Livewire\Component;

class SiteSwitcher extends Component
{
    #[Locked]
    public int $activeSiteId;

    public function mount(int $activeSiteId): void
    {
        $this->activeSiteId = $activeSiteId;
    }

    public function render()
    {
        // Strictly look up the active site within the user's accessible set.
        // Do NOT fall back to `Site::find($this->activeSiteId)` — Livewire
        // props are mutable client-side, and an unguarded find() would leak
        // another tenant's business_name / custom_domain / preview_domain
        // into the sidebar via prop tampering.
        $sites = auth()->user()->accessibleSites()->orderBy('business_name')->get();
        $active = $sites->firstWhere('id', $this->activeSiteId);

        return view('livewire.client.site-switcher', [
            'sites' => $sites,
            'active' => $active,
        ]);
    }
}
