<x-layouts::app :title="__('Clients')" width="full">
    <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
        <div class="flex items-center justify-between">
            <div>
                <flux:heading size="xl">{{ __('Clients') }}</flux:heading>
                <flux:subheading>{{ __('Manage client accounts') }}</flux:subheading>
            </div>
            <flux:button variant="primary" :href="route('clients.create')" icon="plus">
                {{ __('New Client') }}
            </flux:button>
        </div>

        @if (session('success'))
            <flux:callout variant="success" icon="check-circle">
                {{ session('success') }}
            </flux:callout>
        @endif

        @if($clients->isEmpty())
            <div class="flex flex-col items-center justify-center rounded-xl border border-neutral-200 p-12 dark:border-neutral-700">
                <flux:icon.building-office class="size-12 text-zinc-400" />
                <flux:heading size="lg" class="mt-4">{{ __('No clients yet') }}</flux:heading>
                <flux:subheading class="mt-1">{{ __('Add your first client to get started.') }}</flux:subheading>
                <flux:button variant="primary" :href="route('clients.create')" icon="plus" class="mt-4">
                    {{ __('Add Client') }}
                </flux:button>
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Name') }}</flux:table.column>
                    <flux:table.column>{{ __('CID') }}</flux:table.column>
                    <flux:table.column>{{ __('Sites') }}</flux:table.column>
                    <flux:table.column>{{ __('Users') }}</flux:table.column>
                    <flux:table.column>{{ __('Created') }}</flux:table.column>
                    <flux:table.column />
                </flux:table.columns>

                <flux:table.rows>
                    @foreach($clients as $client)
                        <flux:table.row>
                            <flux:table.cell class="font-medium">{{ $client->name }}</flux:table.cell>
                            <flux:table.cell class="font-mono text-zinc-500">{{ $client->cid ?? '—' }}</flux:table.cell>
                            <flux:table.cell>{{ $client->sites_count }}</flux:table.cell>
                            <flux:table.cell>{{ $client->users_count }}</flux:table.cell>
                            <flux:table.cell>{{ $client->created_at->diffForHumans() }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:button variant="ghost" size="sm" :href="route('clients.edit', $client)" icon-trailing="chevron-right">
                                    {{ __('Edit') }}
                                </flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>

            <div>{{ $clients->links() }}</div>
        @endif
    </div>
</x-layouts::app>
