<x-layouts::app :title="__('New Client')" width="page">
    <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
        <div>
            <flux:heading size="xl">{{ __('New Client') }}</flux:heading>
            <flux:subheading>{{ __('Add a new client account') }}</flux:subheading>
        </div>

        <div class="max-w-lg rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
            <form method="POST" action="{{ route('clients.store') }}" class="space-y-4">
                @csrf
                <div>
                    <flux:input label="Client Name" name="name" :value="old('name')" required placeholder="Gibson Plumbing and Heating" />
                    @error('name') <p class="text-sm text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <flux:input label="Legacy CID" name="cid" :value="old('cid')" type="number" placeholder="Optional — foreign key to legacy system" />
                    @error('cid') <p class="text-sm text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>
                <div class="flex gap-3 pt-2">
                    <flux:button variant="primary" type="submit" icon="check">{{ __('Create Client') }}</flux:button>
                    <flux:button variant="ghost" :href="route('clients.index')" icon="arrow-left">{{ __('Cancel') }}</flux:button>
                </div>
            </form>
        </div>
    </div>
</x-layouts::app>
