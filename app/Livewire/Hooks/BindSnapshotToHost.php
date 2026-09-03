<?php

namespace App\Livewire\Hooks;

use Livewire\ComponentHook;

/**
 * Bind every Livewire snapshot to the host it was minted on.
 *
 * The memo records the minting host and hydration rejects a mismatch, so a snapshot
 * is only ever replayed on the host that issued it, independent of cookie scoping.
 * A snapshot with no host memo is rejected too: the field is inside the checksum, so
 * a legitimate snapshot always carries it.
 */
class BindSnapshotToHost extends ComponentHook
{
    public function dehydrate($context)
    {
        $context->addMemo('host', request()->getHost());
    }

    public function hydrate($memo)
    {
        abort_unless(($memo['host'] ?? null) === request()->getHost(), 403);
    }
}
