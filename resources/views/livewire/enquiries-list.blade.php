{{-- Shared enquiries-list markup, included by both the agent-CP
     enquiries-inbox and the client-portal client.enquiries-inbox
     components. Host component must provide $enquiries (paginator).
     Read-only — no internal/agent-only fields beyond friendly labels. --}}
@if ($enquiries->isEmpty())
    <p class="text-sm text-zinc-500 dark:text-zinc-400">No enquiries yet.</p>
@else
    <div class="space-y-3">
        @foreach ($enquiries as $enquiry)
            @php
                // Only scalar payload values are renderable — a nested
                // array/object from an import or legacy writer must not
                // 500 the whole inbox via htmlspecialchars().
                $payload = collect($enquiry->payload ?? [])->filter(fn ($value) => is_scalar($value));
                // Render-side caps: the submit controller bounds values at
                // 2000 chars, but legacy/imported rows aren't guaranteed to
                // honour that — never render unbounded text.
                $phone = $payload->has('phone') ? \Illuminate\Support\Str::limit((string) $payload->get('phone'), 200) : null;
                $service = $payload->has('service') ? \Illuminate\Support\Str::limit((string) $payload->get('service'), 200) : null;
                $message = $payload->has('message') ? \Illuminate\Support\Str::limit((string) $payload->get('message'), 2000) : null;
                $extraFields = $payload->except(['phone', 'service', 'message', 'kind'])->take(10);
                $labelFor = function (string $key) use ($enquiry) {
                    // field_labels is null on every pre-snapshot row; index
                    // it only after coalescing so PHP 8 does not warn.
                    return ($enquiry->field_labels ?? [])[$key]
                        ?? \App\Support\EnquiryFieldLabels::humanise($key);
                };
            @endphp
            <div wire:key="enquiry-{{ $enquiry->id }}"
                 class="rounded-lg border border-zinc-200 dark:border-neutral-700 p-4">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <div class="flex flex-wrap items-center gap-2 min-w-0">
                        <span class="text-sm font-semibold text-zinc-800 dark:text-zinc-100">{{ $enquiry->name }}</span>
                        <a href="mailto:{{ $enquiry->email }}" class="text-xs text-accent hover:underline">{{ $enquiry->email }}</a>
                        @if ($phone)
                            <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $phone }}</span>
                        @endif
                    </div>
                    <span class="text-xs text-zinc-400 dark:text-zinc-500 shrink-0">{{ $enquiry->created_at->toDayDateTimeString() }}</span>
                </div>

                <div class="mt-2 flex flex-wrap gap-2">
                    @if ($service)
                        <span class="text-xs px-2 py-0.5 rounded-full border border-zinc-300 dark:border-zinc-600 text-zinc-600 dark:text-zinc-300">{{ $service }}</span>
                    @endif
                    @if ($enquiry->page_type)
                        <span class="text-xs px-2 py-0.5 rounded-full border border-zinc-300 dark:border-zinc-600 text-zinc-500 dark:text-zinc-400">from {{ $enquiry->page_type }} page</span>
                    @endif
                </div>

                @if (($enquiry->payload['kind'] ?? null) === 'quote')
                    @include('shop.partials.quote-enquiry-lines', [
                        'enquiry' => $enquiry,
                        'listClass' => 'mt-2 space-y-1 text-sm text-zinc-600 dark:text-zinc-300',
                    ])
                    @include('shop.partials.quote-fulfilment', ['enquiry' => $enquiry])
                @endif

                @if ($message)
                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300 whitespace-pre-line">{{ $message }}</p>
                @endif

                @if ($extraFields->isNotEmpty())
                    <dl class="mt-2 space-y-1">
                        @foreach ($extraFields as $field => $value)
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                <dt class="inline font-medium">{{ $labelFor($field) }}:</dt>
                                <dd class="inline">{{ \Illuminate\Support\Str::limit((string) $value, 200) }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
            </div>
        @endforeach
    </div>

    {{ $enquiries->links() }}
@endif
