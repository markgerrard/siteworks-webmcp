<div class="py-16 lg:py-20 bg-gray-50">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        @if (!empty($data['heading']))
            <div class="text-center mb-12">
                <span class="text-sm font-bold tracking-widest uppercase mb-3 block" style="color: var(--brand-accent);">FAQ</span>
                <h2 class="text-3xl md:text-4xl font-extrabold text-gray-900">
                    {{ $data['heading'] }}
                </h2>
            </div>
        @endif
        @if (!empty($data['items']))
            <div class="space-y-4" x-data="{ open: null }">
                @foreach ($data['items'] as $idx => $item)
                    <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                        <button type="button"
                                x-on:click="open = open === {{ $idx }} ? null : {{ $idx }}"
                                class="w-full flex items-center justify-between px-6 py-5 text-left cursor-pointer">
                            <span class="text-base font-semibold text-gray-900">{{ $item['question'] ?? '' }}</span>
                            <svg class="w-5 h-5 flex-shrink-0 text-gray-500 transition-transform" x-bind:class="open === {{ $idx }} ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </button>
                        <div x-show="open === {{ $idx }}" x-cloak x-collapse class="px-6 pb-5">
                            <p class="text-gray-600 leading-relaxed">{{ $item['answer'] ?? '' }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
