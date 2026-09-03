{{-- Shared review-moderation markup, included by both the agent-CP
     review-moderation-panel and the client-portal client.review-moderation
     components. Host component must provide $reviews (paginator),
     $statusFilter, $statusMessage, $statusMessageVariant and the
     approve/reject actions. --}}
@if ($statusMessage)
    @if (($statusMessageVariant ?? 'success') === 'warning')
        <flux:callout variant="warning" icon="exclamation-triangle">{{ $statusMessage }}</flux:callout>
    @else
        <flux:callout variant="success" icon="check-circle">{{ $statusMessage }}</flux:callout>
    @endif
@endif

<div class="flex flex-wrap gap-3">
    @foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $key => $label)
        <button type="button" wire:click="$set('statusFilter', '{{ $key }}')"
                class="text-xs font-semibold px-4 py-1.5 rounded-full border transition-colors cursor-pointer
                       {{ $statusFilter === $key
                           ? 'bg-amber-500 text-zinc-900 border-amber-500 shadow-sm'
                           : 'bg-transparent text-zinc-500 dark:text-zinc-400 border-zinc-300 dark:border-zinc-700 hover:bg-zinc-100 dark:hover:bg-zinc-800 hover:text-zinc-800 dark:hover:text-zinc-100' }}">
            {{ $label }}
        </button>
    @endforeach
</div>

@if ($reviews->isEmpty())
    <p class="text-sm text-zinc-500 dark:text-zinc-400">No {{ $statusFilter === 'all' ? '' : $statusFilter.' ' }}reviews.</p>
@else
    <div class="space-y-3">
        @foreach ($reviews as $review)
            <div wire:key="review-{{ $review->id }}"
                 class="rounded-lg border border-zinc-200 dark:border-neutral-700 p-4 flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-sm font-semibold text-zinc-800 dark:text-zinc-100">{{ $review->author_name }}</span>
                        @php $starCount = min(5, max(0, (int) $review->rating)); @endphp
                        <span class="text-xs text-amber-500" aria-label="{{ $starCount }} out of 5 stars">
                            {{ str_repeat('★', $starCount) }}{{ str_repeat('☆', 5 - $starCount) }}
                        </span>
                        <span class="text-xs px-2 py-0.5 rounded-full border
                            {{ match ($review->status) {
                                \App\Enums\SiteReviewStatus::Approved => 'border-green-500 text-green-600 dark:text-green-400',
                                \App\Enums\SiteReviewStatus::Rejected => 'border-red-500 text-red-600 dark:text-red-400',
                                default => 'border-zinc-400 text-zinc-500 dark:text-zinc-400',
                            } }}">
                            {{ ucfirst($review->status->value) }}
                        </span>
                    </div>
                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300 whitespace-pre-line">{{ $review->text }}</p>
                    <p class="mt-2 text-xs text-zinc-400 dark:text-zinc-500">Submitted {{ $review->created_at->toDayDateTimeString() }}</p>
                </div>
                @if ($review->status === \App\Enums\SiteReviewStatus::Pending)
                    <div class="flex shrink-0 gap-2">
                        <flux:button size="sm" variant="primary" wire:click="approve({{ $review->id }})">Approve</flux:button>
                        <flux:button size="sm" variant="danger" wire:click="reject({{ $review->id }})">Reject</flux:button>
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    {{ $reviews->links() }}
@endif
