<?php

use App\Jobs\Shop\BuildCustomerDataExport;
use App\Models\Shop\Order;
use App\Services\Shop\CustomerAuthService;
use Livewire\Component;

new class extends Component
{
    public string $newPassword = '';

    public bool $marketingConsent = false;

    public function mount(): void
    {
        $c = auth('customer')->user();
        abort_unless($c, 401);
        $this->marketingConsent = (bool) $c->marketing_consent_at;
    }

    public function savePassword(CustomerAuthService $svc): void
    {
        $this->validate(['newPassword' => 'required|string|min:8']);
        $svc->setPassword(auth('customer')->user(), $this->newPassword);
        $this->newPassword = '';
    }

    public function saveConsent(): void
    {
        auth('customer')->user()->update([
            'marketing_consent_at' => $this->marketingConsent ? now() : null,
        ]);
    }

    public function requestExport(): void
    {
        BuildCustomerDataExport::dispatch(auth('customer')->user()->id);
    }

    public function deleteAccount(): void
    {
        $c = auth('customer')->user();
        Order::where('customer_id', $c->id)->update(['customer_id' => null]);
        $c->delete();
        auth('customer')->logout();
    }
}; ?>

@php
    $field = 'width: 100%; min-height: 44px; padding: 0.5rem 0.75rem; color: var(--color-text); border: 1px solid var(--color-border); border-radius: var(--radius-button); background-color: var(--color-surface); outline-color: var(--color-accent);';
    $button = 'min-height: 44px; min-width: 44px; padding: 0.5rem 1rem; background-color: var(--color-primary); color: var(--color-text-on-primary); border-radius: var(--radius-button); outline-color: var(--color-accent);';
    $quiet = 'min-height: 44px; min-width: 44px; padding: 0.5rem 1rem; color: var(--color-text); border: 1px solid var(--color-border); border-radius: var(--radius-button); background-color: transparent; outline-color: var(--color-accent);';
@endphp
<div class="space-y-8 max-w-md">
    <section>
        <h2 class="font-semibold mb-2">Set a password (optional)</h2>
        <input type="password" wire:model="newPassword" placeholder="New password" class="w-full p-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="{{ $field }}">
        @error('newPassword')
            <p class="mt-1 text-sm" role="alert" style="color: var(--color-accent-text)">{{ $message }}</p>
        @enderror
        <button type="button" wire:click="savePassword" class="mt-3 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="{{ $button }}">Save password</button>
    </section>
    <section>
        <h2 class="font-semibold mb-2">Marketing emails</h2>
        <label class="inline-flex items-center gap-2">
            <input type="checkbox" wire:model="marketingConsent">
            <span>Opt in</span>
        </label>
        <button type="button" wire:click="saveConsent" class="ml-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="{{ $quiet }}">Save</button>
    </section>
    <section>
        <h2 class="font-semibold mb-2">Data export</h2>
        <p class="text-sm mb-3" style="color: var(--color-text-muted)">We'll email a signed download link. It expires after seven days — there isn't a separate download page.</p>
        <button type="button" wire:click="requestExport" class="focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="{{ $quiet }}">Email my data export</button>
    </section>
    <section>
        <h2 class="font-semibold mb-2">Delete account</h2>
        <p class="text-sm mb-3" style="color: var(--color-text-muted)">Your order history stays with the merchant for tax records; your personal account record is removed.</p>
        <button type="button" wire:click="deleteAccount" wire:confirm="Delete your account?" class="focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="{{ $quiet }}">Delete my account</button>
    </section>
</div>
