<div class="py-20 lg:py-24 bg-white">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
        @if (!empty($data['heading']))
            <div class="text-center mb-12">
                <span class="text-sm font-bold tracking-widest uppercase mb-3 block" style="color: var(--brand-accent);">Contact Form</span>
                <h2 class="text-3xl md:text-4xl font-extrabold text-gray-900">
                    {{ $data['heading'] }}
                </h2>
            </div>
        @endif

        <div class="bg-white rounded-lg shadow-md p-8 md:p-10 border-t-4" style="border-top-color: var(--brand-primary);"
             x-data="{ submitted: false }">

            {{-- Thank you state --}}
            <div x-show="submitted" x-cloak class="text-center py-12">
                <div class="w-16 h-16 rounded-full mx-auto mb-6 flex items-center justify-center text-white" style="background-color: var(--brand-primary);">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                </div>
                <h3 class="text-2xl font-bold text-gray-900 mb-2">Thank You!</h3>
                <p class="text-gray-600">We've received your message and will get back to you shortly.</p>
            </div>

            {{-- Form --}}
            <form x-show="!submitted" action="#" @submit.prevent="submitted = true" class="space-y-6">
                @if (!empty($data['fields']))
                    @foreach ($data['fields'] as $field)
                        <div>
                            <label for="field-{{ $field['name'] }}" class="block text-sm font-semibold text-gray-700 mb-1.5">
                                {{ $field['label'] ?? ucfirst($field['name']) }}
                                @if (!empty($field['required']))
                                    <span class="text-red-500">*</span>
                                @endif
                            </label>

                            @if (($field['type'] ?? 'text') === 'textarea')
                                <textarea
                                    id="field-{{ $field['name'] }}"
                                    name="{{ $field['name'] }}"
                                    rows="4"
                                    placeholder="{{ $field['placeholder'] ?? '' }}"
                                    {{ !empty($field['required']) ? 'required' : '' }}
                                    class="w-full rounded-md border border-gray-300 px-4 py-2.5 text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:border-transparent transition-shadow"
                                ></textarea>
                            @elseif (($field['type'] ?? 'text') === 'select')
                                <select
                                    id="field-{{ $field['name'] }}"
                                    name="{{ $field['name'] }}"
                                    {{ !empty($field['required']) ? 'required' : '' }}
                                    class="w-full rounded-md border border-gray-300 px-4 py-2.5 text-gray-900 focus:outline-none focus:ring-2 focus:border-transparent transition-shadow bg-white"
                                >
                                    <option value="">Select an option...</option>
                                    @if (!empty($field['options']))
                                        @foreach ($field['options'] as $option)
                                            <option value="{{ $option }}">{{ $option }}</option>
                                        @endforeach
                                    @endif
                                </select>
                            @else
                                <input
                                    type="{{ $field['type'] ?? 'text' }}"
                                    id="field-{{ $field['name'] }}"
                                    name="{{ $field['name'] }}"
                                    placeholder="{{ $field['placeholder'] ?? '' }}"
                                    {{ !empty($field['required']) ? 'required' : '' }}
                                    class="w-full rounded-md border border-gray-300 px-4 py-2.5 text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:border-transparent transition-shadow"
                                />
                            @endif
                        </div>
                    @endforeach
                @endif

                <div>
                    <button type="submit"
                            class="w-full px-6 py-3 rounded-md font-bold text-white text-sm shadow-md transition-all hover:shadow-lg hover:brightness-110"
                            style="background-color: var(--brand-accent);">
                        {{ $data['submit_label'] ?? 'Send Message' }}
                    </button>
                </div>

                @if (!empty($data['privacy_note']))
                    <p class="text-xs text-gray-400 text-center mt-4">
                        <svg class="w-3.5 h-3.5 inline-block mr-1 -mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                        {{ $data['privacy_note'] }}
                    </p>
                @endif
            </form>
        </div>
    </div>
</div>
