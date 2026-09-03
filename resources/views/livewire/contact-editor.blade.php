<?php

use App\Livewire\Concerns\AuthorizesSiteAccess;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    use AuthorizesSiteAccess;
    #[Locked]
    public int $siteId;
    public string $companyName = '';
    public string $phone = '';
    public string $mobile = '';
    public string $email = '';
    public string $address = '';
    public string $postcode = '';
    public ?float $latitude = null;
    public ?float $longitude = null;
    public string $openingHoursText = '';
    public ?string $siteType = null;
    public ?string $region = null;
    public array $postcodeResults = [];
    public bool $saved = false;

    public function mount(int $siteId): void
    {
        $this->siteId = $siteId;
        $site = $this->findAuthorizedSite();
        $this->companyName = $site?->business_name ?? '';
        $this->siteType = $site?->site_type;
        $this->region = $site?->region;
        $contact = $site?->businessProfile?->profile_data['contact'] ?? [];
        $this->phone = ($contact['phones'][0] ?? '');
        $this->mobile = $contact['mobile'] ?? '';
        $this->email = ($contact['emails'][0] ?? '');
        $this->address = $contact['address'] ?? '';
        $geo = $site?->businessProfile?->profile_data['geo'] ?? [];
        $this->latitude = $geo['latitude'] ?? null;
        $this->longitude = $geo['longitude'] ?? null;
        $this->openingHoursText = implode("\n", array_map(fn ($r) => "{$r['day']}: {$r['hours']}", \App\Support\OpeningHours::rows($site?->businessProfile?->profile_data['opening_hours'] ?? null)));
    }

    public function lookupPostcode(): void
    {
        $this->postcodeResults = [];
        $pc = trim($this->postcode);
        if (strlen($pc) < 5) {
            return;
        }

        try {
            $response = Http::withHeaders([
                'X-API-Key' => config('services.postcode.key'),
            ])->timeout(10)->get(config('services.postcode.url').'/find/'.urlencode($pc), [
                'format' => true,
                'sort' => true,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $this->latitude = $data['latitude'] ?? $this->latitude;
                $this->longitude = $data['longitude'] ?? $this->longitude;
                $addresses = $data['addresses'] ?? [];
                $this->postcodeResults = array_map(function ($addr) {
                    $lines = array_filter($addr['formatted_address'] ?? []);

                    return implode(', ', $lines);
                }, array_slice($addresses, 0, 20));
            }
        } catch (\Throwable $e) {
            // Silently fail — the agent can type the address manually.
        }
    }

    public function selectAddress(int $index): void
    {
        if (isset($this->postcodeResults[$index])) {
            $this->address = $this->postcodeResults[$index];
            $this->postcodeResults = [];
        }
    }

    public function geocodeAddress(): void
    {
        $addr = trim($this->address);
        if ($addr === '') {
            return;
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'WebsiteGenerator/1.0',
            ])->timeout(10)->get('https://nominatim.openstreetmap.org/search', [
                'q' => $addr,
                'format' => 'json',
                'limit' => 1,
            ]);

            if ($response->successful()) {
                $results = $response->json();
                if (! empty($results[0])) {
                    $this->latitude = (float) $results[0]['lat'];
                    $this->longitude = (float) $results[0]['lon'];
                    session()->flash('contact-geo', 'Location found: '.($results[0]['display_name'] ?? $addr));
                } else {
                    session()->flash('contact-geo-err', 'Address not found — try adding more detail.');
                }
            }
        } catch (\Throwable $e) {
            session()->flash('contact-geo-err', 'Geocoding failed: '.$e->getMessage());
        }
    }

    public function save(): void
    {
        $site = $this->findAuthorizedSite();
        $bp = $site?->businessProfile;
        if (! $bp) {
            return;
        }

        $attrs = [
            'site_type' => $this->siteType !== null && $this->siteType !== '' ? $this->siteType : null,
            'region' => $this->region !== null && $this->region !== '' ? $this->region : null,
        ];
        if ($this->companyName !== '' && $this->companyName !== $site->business_name) {
            $attrs['business_name'] = $this->companyName;
        }
        $site->update($attrs);

        $profile = $bp->profile_data;
        $profile['contact'] = $profile['contact'] ?? [];
        $profile['contact']['phones'] = $this->phone !== '' ? [$this->phone] : [];
        $profile['contact']['mobile'] = $this->mobile !== '' ? $this->mobile : null;
        $profile['contact']['emails'] = $this->email !== '' ? [$this->email] : [];
        $profile['contact']['address'] = $this->address;

        $profile['geo'] = $profile['geo'] ?? [];
        if ($this->latitude !== null) {
            $profile['geo']['latitude'] = $this->latitude;
            $profile['geo']['longitude'] = $this->longitude;
        }

        $profile['opening_hours'] = \App\Support\OpeningHours::fromLines($this->openingHoursText);

        $bp->update(['profile_data' => $profile]);

        // Update the contact page's details section through PageService
        // so a draft PageRevision is created — the versioned renderer
        // pins revisions on publish and won't see a direct content_data
        // overwrite. Without this, the new phone/email/address never
        // reached the public site on the versioned path and the
        // unpublished-changes banner never surfaced.
        $contactPage = $site->generatedPages()
            ->where('page_type', 'contact')
            ->first();

        $contactPageDirty = false;

        if ($contactPage) {
            $content = $contactPage->content_data;
            $isListShape = isset($content['sections']) && is_array($content['sections']);

            if ($isListShape) {
                // Details section is an items-list: [{label: 'Phone', value: '...'}, ...].
                // Upsert each known field into the items array rather than adding
                // scalar sibling keys (which the renderer ignores).
                foreach ($content['sections'] as $i => $section) {
                    if (is_array($section) && ($section['type'] ?? null) === 'details') {
                        $items = $section['items'] ?? [];
                        $updates = [
                            'Phone' => $this->phone,
                            'Mobile' => $this->mobile,
                            'Email' => $this->email,
                            'Address' => $this->address,
                        ];
                        foreach ($updates as $label => $value) {
                            $itemIndex = null;
                            foreach ($items as $k => $it) {
                                if (($it['label'] ?? '') === $label) {
                                    $itemIndex = $k;
                                    break;
                                }
                            }
                            if ($value === '' || $value === null) {
                                if ($itemIndex !== null) {
                                    unset($items[$itemIndex]);
                                }
                            } elseif ($itemIndex !== null) {
                                $items[$itemIndex]['value'] = $value;
                            } else {
                                $items[] = ['label' => $label, 'value' => $value];
                            }
                        }
                        $content['sections'][$i]['items'] = array_values($items);
                        $contactPageDirty = true;
                        break;
                    }
                }
            } elseif (isset($content['details'])) {
                // Legacy map shape — ContentShapeTranslator reshapes to items-list
                // on the next write via PageService::replaceContent, so we can
                // just set direct scalar keys here and let the translator
                // convert.
                $content['details']['phone'] = $this->phone ?: null;
                $content['details']['mobile'] = $this->mobile ?: null;
                $content['details']['email'] = $this->email ?: null;
                $content['details']['address'] = $this->address ?: null;
                $contactPageDirty = true;
            }

            if ($contactPageDirty) {
                // Draft creation + admin_revision bump atomically —
                // same race avoidance as saveSection. Without the single
                // transaction, an auto-publish batch could land between
                // the draft commit and the bump commit and publish the
                // in-flight contact-edit before the admin reviews it.
                // applyAdminChange also calls PublicPageCache::invalidate(),
                // so the cache path is covered when the contact page is dirty.
                app(\App\Services\Site\CompositionService::class)->applyAdminChange(
                    $site,
                    function () use ($contactPage, $content) {
                        app(\App\Services\Site\PageService::class)->replaceContent(
                            $contactPage,
                            $content,
                            aiGenerated: false,
                            userId: auth()->id(),
                        );
                    },
                    userId: auth()->id(),
                );
                $this->dispatch('composition-dirty');
            } else {
                // Profile.contact.* changed but the contact page wasn't
                // dirty (no contact page, or content matched exactly).
                // The public renderer reads profile data live so PublicPageCache
                // must still be invalidated to avoid serving stale HTML.
                app(\App\Services\Site\PublicPageCache::class)->invalidate($site);
            }
        }

        // Stamp into the latest preview snapshot.
        $preview = $site->latestPreview;
        if ($preview) {
            app(\App\Services\PreviewSnapshotWriter::class)->mutate($preview, function (&$snapshot) use ($profile) {
                $snapshot['profile']['contact'] = $profile['contact'];
                $snapshot['profile']['geo'] = $profile['geo'];
                $snapshot['profile']['opening_hours'] = $profile['opening_hours'];

                // Update snapshot contact page details too.
                if (isset($snapshot['pages']['contact']['details'])) {
                    $snapshot['pages']['contact']['details']['phone'] = $this->phone ?: null;
                    $snapshot['pages']['contact']['details']['mobile'] = $this->mobile ?: null;
                    $snapshot['pages']['contact']['details']['email'] = $this->email ?: null;
                    $snapshot['pages']['contact']['details']['address'] = $this->address ?: null;
                }
            });
        }

        $this->saved = true;
    }
};
?>

<div class="space-y-4">
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <flux:select wire:model="siteType" label="Type">
            <flux:select.option value="">Unclassified</flux:select.option>
            @foreach (config('site_types') as $slug => $label)
                <flux:select.option :value="$slug">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model="region" label="Region">
            <flux:select.option value="">Unclassified</flux:select.option>
            @foreach (config('regions') as $slug => $label)
                <flux:select.option :value="$slug">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div>
            <label for="contact-editor-company" class="text-sm font-medium text-zinc-500 dark:text-zinc-400 block mb-1">Company Name</label>
            <input id="contact-editor-company" type="text" wire:model.blur="companyName"
                   class="w-full text-sm rounded-md border border-zinc-200 bg-white px-3 py-2 dark:bg-neutral-900 dark:border-neutral-700"
                   placeholder="Business Name Ltd" />
        </div>
        <div>
            <label for="contact-editor-phone" class="text-sm font-medium text-zinc-500 dark:text-zinc-400 block mb-1">Phone</label>
            <input id="contact-editor-phone" type="text" wire:model.blur="phone"
                   class="w-full text-sm rounded-md border border-zinc-200 bg-white px-3 py-2 dark:bg-neutral-900 dark:border-neutral-700"
                   placeholder="01234 567890" />
        </div>
        <div>
            <label for="contact-editor-email" class="text-sm font-medium text-zinc-500 dark:text-zinc-400 block mb-1">Email</label>
            <input id="contact-editor-email" type="email" wire:model.blur="email"
                   class="w-full text-sm rounded-md border border-zinc-200 bg-white px-3 py-2 dark:bg-neutral-900 dark:border-neutral-700"
                   placeholder="info@example.com" />
        </div>
        <div>
            <label for="contact-editor-mobile" class="text-sm font-medium text-zinc-500 dark:text-zinc-400 block mb-1">Mobile <span class="font-normal text-zinc-400">(optional)</span></label>
            <input id="contact-editor-mobile" type="text" wire:model.blur="mobile"
                   class="w-full text-sm rounded-md border border-zinc-200 bg-white px-3 py-2 dark:bg-neutral-900 dark:border-neutral-700"
                   placeholder="07700 900000" />
        </div>
    </div>

    {{-- Postcode lookup + Address --}}
    <div class="flex items-end gap-3">
        <div class="w-48 flex-shrink-0">
            <label for="contact-editor-postcode" class="text-sm font-medium text-zinc-500 dark:text-zinc-400 block mb-1">Postcode</label>
            <input id="contact-editor-postcode" type="text" wire:model="postcode"
                   wire:keydown.enter="lookupPostcode"
                   class="w-full text-sm rounded-md border border-zinc-200 bg-white px-3 py-2 uppercase dark:bg-neutral-900 dark:border-neutral-700"
                   placeholder="WN1 1EB" />
        </div>
        <flux:button size="sm" variant="ghost" wire:click="lookupPostcode" icon="magnifying-glass"
                     wire:loading.attr="disabled" wire:target="lookupPostcode">
            <span wire:loading.remove wire:target="lookupPostcode">Find</span>
            <span wire:loading wire:target="lookupPostcode">Searching…</span>
        </flux:button>
        <div class="flex-1">
            <label for="contact-editor-address" class="text-sm font-medium text-zinc-500 dark:text-zinc-400 block mb-1">Address</label>
            <div class="flex items-center gap-2">
                <input id="contact-editor-address" type="text" wire:model.blur="address"
                       class="flex-1 text-sm rounded-md border border-zinc-200 bg-white px-3 py-2 dark:bg-neutral-900 dark:border-neutral-700"
                       placeholder="123 High Street, Wigan" />
                <flux:button size="sm" variant="ghost" wire:click="geocodeAddress" icon="map-pin"
                             wire:loading.attr="disabled" wire:target="geocodeAddress"
                             title="Geocode this address">
                    <span wire:loading.remove wire:target="geocodeAddress">Locate</span>
                    <span wire:loading wire:target="geocodeAddress">…</span>
                </flux:button>
            </div>
        </div>
    </div>

    @if (count($postcodeResults) > 0)
        <div class="max-h-48 overflow-y-auto rounded-md border border-zinc-200 bg-white dark:bg-neutral-900 dark:border-neutral-700">
            @foreach ($postcodeResults as $idx => $addr)
                <button type="button"
                        wire:click="selectAddress({{ $idx }})"
                        class="w-full text-left px-3 py-2 text-sm hover:bg-zinc-100 dark:hover:bg-neutral-800 cursor-pointer border-b border-zinc-100 dark:border-neutral-700 last:border-b-0 transition-colors">
                    {{ $addr }}
                </button>
            @endforeach
        </div>
    @endif

    @if (session('contact-geo'))
        <p class="text-xs text-green-600 dark:text-green-400">{{ session('contact-geo') }}</p>
    @endif
    @if (session('contact-geo-err'))
        <p class="text-xs text-red-500">{{ session('contact-geo-err') }}</p>
    @endif

    @if ($latitude && $longitude)
        <p class="text-xs text-zinc-400">
            📍 {{ number_format($latitude, 6) }}, {{ number_format($longitude, 6) }}
            — map will render on the preview contact page.
        </p>
    @endif

    <div>
        <label for="contact-editor-hours" class="text-sm font-medium text-zinc-500 dark:text-zinc-400 block mb-1">Opening hours</label>
        <textarea id="contact-editor-hours" wire:model="openingHoursText" rows="4" aria-label="Opening hours"
                  class="w-full text-sm rounded-md border border-zinc-200 bg-white px-3 py-2 dark:bg-neutral-900 dark:border-neutral-700"></textarea>
        <p class="text-xs text-zinc-400 mt-1">One line per entry, e.g. Mon–Fri: 8:00–17:00</p>
    </div>

    <div class="flex items-center gap-3">
        <flux:button variant="primary" size="sm" wire:click="save" icon="check">
            Save Contact Details
        </flux:button>
        @if ($saved)
            <span class="text-sm text-green-600 dark:text-green-400">Saved — preview updated.</span>
        @endif
    </div>
</div>
