<?php

namespace App\Livewire;

use Livewire\Component;

/**
 * Empty stand-in for Livewire components stripped from the public demo
 * (agents-only media picker, AI studios, managed content).
 * Registered by name in AppServiceProvider when demo mode is on so
 * portal pages that still reference those tags do not 500.
 */
class DemoMissingComponent extends Component
{
    public mixed $siteId = null;

    public mixed $pageId = null;

    public mixed $slot = null;

    public mixed $page = null;

    public mixed $kind = null;

    public mixed $productId = null;

    public mixed $orderId = null;

    public mixed $model = null;

    public mixed $kinds = null;

    public mixed $aspect = null;

    public mixed $slotLabel = null;

    public function render(): string
    {
        return '<div data-demo-missing-component></div>';
    }
}
