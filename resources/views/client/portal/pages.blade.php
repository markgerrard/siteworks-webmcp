<x-layouts::client :title="__('Pages').' — '.$site->business_name" :site="$site" width="full">
    <x-slot:help>
        <div
            x-data="{
                page: (new URLSearchParams(window.location.search)).get('page') || 'home',
                get category() {
                    if (this.page === 'home') return 'home';
                    if (this.page === 'about') return 'about';
                    if (this.page === 'contact') return 'contact';
                    if (this.page === 'projects') return 'projects';
                    if (this.page && this.page.startsWith('services-')) return 'services';
                    return 'custom';
                },
            }"
            x-on:pages-help-page-changed.window="page = $event.detail"
            class="space-y-4"
        >
            {{-- Home --}}
            <template x-if="category === 'home'">
                <div class="space-y-4">
                    <x-help.section icon="home" title="Home page">
                        The first thing visitors see. Hero, intro, services
                        overview, FAQs and call-to-actions all live here.
                    </x-help.section>
                    <div class="space-y-3">
                        <x-help.action icon="photo" label="Hero">
                            The headline and big image at the top. Click to
                            re-word it or regenerate the image.
                        </x-help.action>
                        <x-help.action icon="squares-2x2" label="Sections">
                            Intro, services snapshot, trust signals, FAQs, CTAs.
                            Click any section to edit; drag to reorder.
                        </x-help.action>
                    </div>
                </div>
            </template>

            {{-- About --}}
            <template x-if="category === 'about'">
                <div class="space-y-4">
                    <x-help.section icon="user-group" title="About page">
                        Your story — who you are, how long you've been at it,
                        and why customers should trust you.
                    </x-help.section>
                    <div class="space-y-3">
                        <x-help.action icon="pencil-square" label="Story & values">
                            Click any paragraph to rewrite it. Keep it
                            personal — visitors are checking who they're hiring.
                        </x-help.action>
                        <x-help.action icon="photo" label="Hero">
                            Swap the header image or change the heading.
                        </x-help.action>
                    </div>
                </div>
            </template>

            {{-- Services pages --}}
            <template x-if="category === 'services'">
                <div class="space-y-4">
                    <x-help.section icon="wrench-screwdriver" title="Service page">
                        One page per service you offer. Sells the specific job
                        and converts the visitor into an enquiry.
                    </x-help.section>
                    <div class="space-y-3">
                        <x-help.action icon="photo" label="Hero">
                            Service-specific image and headline.
                        </x-help.action>
                        <x-help.action icon="check-circle" label="What's included">
                            List the work, materials, timeframes — the things a
                            buyer wants to confirm before calling.
                        </x-help.action>
                        <x-help.action icon="question-mark-circle" label="FAQs">
                            Pre-empt the questions you get on every quote call.
                        </x-help.action>
                        <x-help.action icon="phone" label="Lead form / CTA">
                            The conversion point. Keep it short.
                        </x-help.action>
                    </div>
                </div>
            </template>

            {{-- Projects --}}
            <template x-if="category === 'projects'">
                <div class="space-y-4">
                    <x-help.section icon="rectangle-stack" title="Projects page">
                        Your portfolio — recent jobs, photos and write-ups.
                        Use the pills above (<strong>Settings</strong>,
                        <strong>Gallery</strong>, <strong>Case Studies</strong>)
                        to switch between the three editors.
                    </x-help.section>
                    <div class="space-y-3">
                        <x-help.action icon="cog-6-tooth" label="Settings">
                            Page heading, intro paragraph and the CTA strip at
                            the bottom.
                        </x-help.action>
                        <x-help.action icon="photo" label="Gallery">
                            Tiles — one image plus a short title and caption.
                            Best for quick visual proof. <strong>+ Add tile</strong>, drag
                            to reorder (best work first), or archive to hide.
                        </x-help.action>
                        <x-help.action icon="document-text" label="Case studies">
                            Longer write-ups for flagship jobs — hero image,
                            brief, what you did, outcome. Use where the gallery
                            isn't enough to close the sale.
                        </x-help.action>
                    </div>
                    <x-help.tip>
                        <em>Gallery</em> for breadth (lots of jobs at a glance),
                        <em>Case studies</em> for depth (flagship jobs told properly).
                    </x-help.tip>
                </div>
            </template>

            {{-- Contact --}}
            <template x-if="category === 'contact'">
                <div class="space-y-4">
                    <x-help.section icon="envelope" title="Contact page">
                        Where enquiries come in. Form, phone, hours, and the
                        areas you cover.
                    </x-help.section>
                    <div class="space-y-3">
                        <x-help.action icon="document-text" label="Lead form">
                            Toggle on or off and customise fields.
                        </x-help.action>
                        <x-help.action icon="map-pin" label="Areas covered">
                            List the suburbs or regions you'll travel to.
                        </x-help.action>
                    </div>
                </div>
            </template>

            {{-- Custom / fallback --}}
            <template x-if="category === 'custom'">
                <div class="space-y-4">
                    <x-help.section icon="document-text" title="This page">
                        A custom page on your site. Click any section to edit;
                        drag to reorder.
                    </x-help.section>
                </div>
            </template>

            {{-- Always-on tips --}}
            <x-help.section icon="squares-plus" title="Managing pages">
                Use the tabs above to switch pages. Add a new page from the
                <strong>+</strong> tab, or hide one from the editor.
                Nav order is set in <strong>Navigation</strong>.
            </x-help.section>

            <x-help.tip>
                Changes save as a draft. Hit <strong>Publish</strong> in the
                editor when you're ready to go live.
            </x-help.tip>
        </div>
    </x-slot:help>

    <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl p-6">
        <flux:heading size="xl">{{ __('Pages') }}</flux:heading>
        <livewire:site.unpublished-changes-banner :site-id="$site->id" />
        <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
            <livewire:page-manager :siteId="$site->id" />
        </div>
    </div>
</x-layouts::client>
