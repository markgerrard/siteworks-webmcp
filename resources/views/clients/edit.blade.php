<x-layouts::app :title="$client->name" width="page">
    <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
        <div>
            <flux:heading size="xl">{{ $client->name }}</flux:heading>
            <flux:subheading>{{ __('Edit client details') }}</flux:subheading>
        </div>

        @if (session('success'))
            <flux:callout variant="success" icon="check-circle">
                {{ session('success') }}
            </flux:callout>
        @endif

        <div class="max-w-lg rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
            <form method="POST" action="{{ route('clients.update', $client) }}" class="space-y-4">
                @csrf
                @method('PUT')
                <div>
                    <flux:input label="Client Name" name="name" :value="old('name', $client->name)" required />
                    @error('name') <p class="text-sm text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <flux:input label="Legacy CID" name="cid" :value="old('cid', $client->cid)" type="number" placeholder="Foreign key to legacy system" />
                    @error('cid') <p class="text-sm text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>
                <div class="flex gap-3 pt-2">
                    <flux:button variant="primary" type="submit" icon="check">{{ __('Save Changes') }}</flux:button>
                    <flux:button variant="ghost" :href="route('clients.index')" icon="arrow-left">{{ __('Back') }}</flux:button>
                </div>
            </form>
        </div>

        {{-- Sites --}}
        <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
            <div class="flex items-center justify-between mb-4">
                <flux:heading size="lg">{{ __('Sites') }} ({{ $client->sites->count() }})</flux:heading>
                <flux:button variant="primary" size="sm" icon="plus" :href="route('sites.create', ['client_id' => $client->id])">
                    {{ __('New Site') }}
                </flux:button>
            </div>
        @if ($client->sites->isNotEmpty())
                <div class="space-y-2">
                    @foreach ($client->sites as $site)
                        <a href="{{ route('sites.show', $site) }}"
                           class="flex items-center justify-between p-3 rounded-lg hover:bg-zinc-50 dark:hover:bg-neutral-800 transition-colors">
                            <div>
                                <span class="text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ $site->business_name }}</span>
                                <span class="text-xs text-zinc-500 dark:text-zinc-400 ml-2">{{ $site->location }}</span>
                            </div>
                            <flux:badge size="sm" :color="match($site->status->value) {
                                'draft' => 'zinc',
                                'review' => 'amber',
                                'published' => 'green',
                                'failed' => 'red',
                                default => 'blue',
                            }">{{ ucfirst($site->status->value) }}</flux:badge>
                        </a>
                    @endforeach
                </div>
        @else
                <p class="text-sm text-zinc-500 dark:text-zinc-400">No sites yet.</p>
        @endif
            </div>


    </div>
</x-layouts::app>
