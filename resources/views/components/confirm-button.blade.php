@props([
    /** Required. Unique name used to wire the trigger to the modal. */
    'name',
    'title',
    'description' => '',
    'confirmLabel' => 'Confirm',
    'cancelLabel' => 'Cancel',

    /**
     * Variant for the confirm button — typically 'danger' for destructive
     * actions, 'primary' for benign confirmations (e.g. "Send"). Use the
     * Flux variant grammar.
     */
    'confirmVariant' => 'primary',

    /**
     * Default trigger button props — only used when no `trigger` slot is
     * supplied. For non-flux triggers (raw <button>, link, icon-only, etc.)
     * pass markup via the `trigger` slot instead.
     */
    'icon' => null,
    'size' => 'sm',
    'triggerVariant' => 'ghost',
    'triggerLabel' => '',
])

{{--
    Reusable confirmation modal that wraps a Flux modal trigger + body so
    callsites can replace native browser wire:confirm dialogs with a styled
    in-app prompt. The wire:click (and any other extra attributes) are
    forwarded to the inner Confirm button — the outer flux:modal.close
    wrapper around it makes the click both fire the action AND close the
    modal in one tap, no extra dispatch wiring needed.

    Default trigger is a flux:button; pass a `trigger` slot to drop in
    custom markup (raw <button>, link, icon-only span, etc.). The slot
    contents are wrapped in <flux:modal.trigger> automatically — DON'T
    add another one inside the slot.

    Usage (default trigger):
        <x-confirm-button
            name="delete-thing-{{ $id }}"
            icon="trash"
            size="xs"
            triggerVariant="ghost"
            title="Delete this thing?"
            description="This cannot be undone."
            confirmLabel="Delete"
            confirmVariant="danger"
            wire:click="deleteThing({{ $id }})"
        />

    Usage (custom trigger):
        <x-confirm-button name="..." title="..." confirmVariant="danger" wire:click="...">
            <x-slot:trigger>
                <button type="button" class="text-red-500">✕</button>
            </x-slot:trigger>
        </x-confirm-button>
--}}

<flux:modal.trigger name="{{ $name }}">
    @isset($trigger)
        {{ $trigger }}
    @else
        <flux:button icon="{{ $icon }}" size="{{ $size }}" variant="{{ $triggerVariant }}">
            {{ $triggerLabel }}
        </flux:button>
    @endisset
</flux:modal.trigger>

<flux:modal name="{{ $name }}" class="max-w-md">
    <div class="space-y-4">
        <div>
            <flux:heading size="lg">{{ $title }}</flux:heading>
            @if ($description)
                <flux:subheading>{{ $description }}</flux:subheading>
            @endif
        </div>

        <div class="flex justify-end gap-2">
            <flux:modal.close>
                <flux:button variant="ghost">{{ $cancelLabel }}</flux:button>
            </flux:modal.close>

            <flux:modal.close>
                <flux:button variant="{{ $confirmVariant }}" {{ $attributes }}>
                    {{ $confirmLabel }}
                </flux:button>
            </flux:modal.close>
        </div>
    </div>
</flux:modal>
