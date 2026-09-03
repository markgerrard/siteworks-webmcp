<x-layouts::client :title="__('Chatbot').' — '.$site->business_name" :site="$site" width="page">
    <x-slot:help>
        <x-help.section icon="chat-bubble-left-right" title="Chatbot">
            An always-on assistant for your visitors. Answers common
            questions and hands off to an enquiry form when they're ready.
        </x-help.section>

        <div class="space-y-3">
            <x-help.action icon="book-open" label="Knowledge">
                Reads your pages + business info. The more you fill in, the
                better it answers.
            </x-help.action>
            <x-help.action icon="sparkles" label="Welcome pills">
                Tappable suggestions that appear above the chat input — things
                like <em>"Get a quote"</em> or <em>"Areas you cover"</em>. Tapping one
                sends it as the visitor's first message, so it's the fastest way
                to nudge people into a conversation.
                Add up to 6, keep them short (3–5 words), and lead with whatever
                you most want visitors to ask.
            </x-help.action>
            <x-help.action icon="speaker-wave" label="Tone">
                Friendly, professional, or casual — pick what suits you.
            </x-help.action>
            <x-help.action icon="inbox-arrow-down" label="Lead capture">
                Visitor details land in your enquiries when they're ready
                to book.
            </x-help.action>
        </div>

        <x-help.tip>
            Re-train after big content changes so it picks up new services
            or pricing.
        </x-help.tip>
    </x-slot:help>

    <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl p-6">
        <flux:heading size="xl">{{ __('Chatbot') }}</flux:heading>
        <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
            <livewire:chatbot-manager :siteId="$site->id" />
        </div>
    </div>
</x-layouts::client>
