<?php

use App\Enums\HeroVersionSource;
use App\Livewire\Concerns\AuthorizesSiteAccess;
use App\Livewire\Concerns\DemoUnavailable;
use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\Site;
use App\Services\Site\FormFieldsWriter;
use App\Services\Site\HeroVersionService;
use App\Services\Site\RepeatableSectionEntries;
use App\Services\Site\SectionSchema;
use App\Support\FormFieldDefinition;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use AuthorizesSiteAccess, DemoUnavailable, WithFileUploads;
    #[Locked]
    public int $siteId;

    /**
     * Upload buffer for hero / intro / band user-upload flow. Caller picks
     * which (pageType, slot) the bytes land at via uploadHero() args;
     * one buffer is enough since uploads are serialized by user click.
     */
    public $heroUpload = null;

    #[Url(as: 'page')]
    public string $activeTab = 'home';
    public array $heroModels = [];
    public array $heroPrompts = [];
    public array $introPrompts = [];
    /** Per-page POV override, keyed by page_type. 'auto' = no override. */
    public array $heroCompositions = [];
    public string $promptBrief = '';
    public string $promptBriefPage = '';
    public bool $contactFormEnabled = true;
    public bool $showAddPage = false;
    public array $newPageServices = [];
    public string $newPageCustom = '';
    public bool $newPageHero = true;

    /** Currently-expanded section key — "{pageType}.{sectionName}.{storedIndex}" or null. */
    public ?string $editing = null;

    /** Server-side selected image slot; at most one image-slot-picker mounts. */
    #[Locked]
    public string $imageSlot = 'hero';

    /** @var list<string> */
    private const IMAGE_SLOTS = ['hero', 'intro', 'band', 'band_2', 'band_3'];

    /** Flat form fields bound to the expanded section's editable data. */
    public string $editHeading = '';
    public string $editSubheading = '';
    /**
     * Section body as a TipTap doc for the flyout WYSIWYG. Seeded by edit()
     * (legacy string bodies are lifted via docFromPlainText) and written
     * back verbatim by saveSection() — body is never demoted to a string.
     */
    public ?array $editBodyDoc = null;
    public string $editCtaLabel = '';
    public string $editPhone = '';
    public string $editEmail = '';
    public string $editCoverage = '';
    public string $editSubmitLabel = '';
    public string $editPrivacyNote = '';
    public array $editItems = [];

    /** Schema-backed entry list currently being edited, such as team.members. */
    public string $editEntryList = '';

    /** @var list<array<string, mixed>> */
    public array $editEntries = [];

    /** Native key schema of the section's items: title_body | label_value | question_answer. */
    public string $editItemsSchema = 'title_body';
    public array $editFormFields = [];
    /**
     * Flat string list for the suburb_list ("Areas Covered") section —
     * editable in-place so agents can add/remove/rename coverage
     * locations without regenerating content. Populated from
     * $section['areas'] in edit(); written back in saveSection().
     *
     * @var array<int, string>
     */
    public array $editAreas = [];

    /**
     * POV / composition options surfaced in the regen UI. Keys map
     * 1:1 to VerticalStrategy::compositionClass values; 'auto' means
     * "use the vertical's default — no override".
     */
    public const AVAILABLE_HERO_COMPOSITIONS = [
        'auto' => 'Auto (vertical default)',
        'detail_close_up' => 'Detail · close-up (hands/macro)',
        'detail_mid_close' => 'Detail · mid-close (3/4 body, hands at work)',
        'wide_no_contact' => 'Wide · no-contact (body + tools, no contact moment)',
        'wide_environmental' => 'Wide · environmental (full setting, person in context)',
    ];

    public const DEFAULT_REGEN_MODEL = '';

    public array $introModels = [];

    /**
     * Re-render trigger fired by the home-hero-scene-studio child component
     * whenever its persist() runs (toggle on/off, slide added/removed,
     * overlay copy edited). The body is intentionally empty — Livewire
     * already re-runs render() after any action method completes, which
     * re-evaluates $heroSceneActiveForPage and shows/hides the legacy
     * Hero card in Content Sections without a page refresh.
     */
    #[On('hero-scene-changed')]
    public function refreshAfterSceneChange(): void {}

    public function mount(int $siteId): void
    {
        $this->siteId = $siteId;
        foreach (['home', 'about', 'contact'] as $page) {
            $this->heroModels[$page] = self::DEFAULT_REGEN_MODEL;
            $this->heroCompositions[$page] = 'auto';
            $this->introModels[$page] = self::DEFAULT_REGEN_MODEL;
            $this->introPrompts[$page] = '';
        }
        $site = $this->findAuthorizedSite();
        $preview = $site?->latestPreview;
        // Prefer BusinessProfile (versioned source of truth); fall back to
        // the legacy snapshot for sites generated before the flags moved.
        $profile = $site?->businessProfile?->profile_data ?? [];
        $this->contactFormEnabled = (bool) ($profile['contact_form_enabled'] ?? $preview?->snapshot['contact_form_enabled'] ?? true);

        $heroImages = $preview?->snapshot['hero_images'] ?? [];
        foreach ($heroImages as $page => $data) {
            if (is_array($data) && ! empty($data['prompt'])) {
                $this->heroPrompts[$page] = $data['prompt'];
            }
        }

        if ($site) {
            foreach ($site->heroVersions()->where('slot', 'intro')->where('is_active', true)->get() as $intro) {
                if (! empty($intro->prompt)) {
                    $this->introPrompts[$intro->page_type] = $intro->prompt;
                }
            }
        }
    }

    public function switchTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->editing = null;
        $this->imageSlot = 'hero';
    }

    public function selectImageSlot(string $slot): void
    {
        abort_unless(in_array($slot, self::IMAGE_SLOTS, true), 404);
        $this->imageSlot = $slot;
    }

    public function regenerateHero(string $pageType): void
    {
        $this->demoUnavailable('hero generation');
    }

    protected function demoNoticeChannel(): string
    {
        return 'page-mgr-msg';
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private static function parseHeroModelKey(string $modelKey): array
    {
        if (! str_contains($modelKey, ':')) {
            return [$modelKey, null];
        }

        [$base, $suffix] = explode(':', $modelKey, 2);

        return [$base, in_array($suffix, ['low', 'medium', 'high'], true) ? $suffix : null];
    }

    /**
     * Agent uploads a custom hero / intro / band image, bypassing the AI
     * pipeline. Bytes go straight to S3, a HeroVersion row is created
     * with source=user_upload, and the upload is marked active for
     * its (pageType, slot) — same activation semantics as
     * activateHeroVersion. Surfaces in the picker with a "User"
     * badge alongside AI-generated rows.
     *
     * Validation: jpeg/png/webp, ≤8MB, ≥600px on the long side. The
     * dimension check is conservative — anything smaller will look
     * blurry in the hero band at common viewport sizes.
     *
     * No watermark applied; agent-uploaded bytes are presumed to be
     * the final intended image. No prompt / model / placement
     * recorded — those are AI-pipeline metadata.
     */
    public function uploadHero(string $pageType, string $slot = 'hero'): void
    {
        $site = $this->findAuthorizedSite();
        if (! $site) {
            return;
        }

        if (! $this->isValidPageType($pageType)) {
            return;
        }

        if (! in_array($slot, ['hero', 'intro', 'band'], true)) {
            session()->flash('page-mgr-err', 'Unknown image slot.');

            return;
        }

        if (! RateLimiter::attempt("hero-upload:{$this->siteId}", 10, fn () => true, 300)) {
            session()->flash('page-mgr-err', 'Upload rate limit reached — please wait a few minutes.');

            return;
        }

        $this->validate([
            'heroUpload' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);

        // Dimension floor — avoid pixelated heroes in the band.
        $tmpPath = $this->heroUpload->getRealPath();
        $info = @getimagesizefromstring(file_get_contents($tmpPath));
        if ($info === false || max($info[0], $info[1]) < 600) {
            session()->flash('page-mgr-err', 'Image too small — long edge must be at least 600px.');
            $this->reset('heroUpload');

            return;
        }

        // Service pages with hero_source=shared reuse the home hero —
        // no per-page hero exists, so don't allow uploads there
        // (mirror of regenerateHero / generateVariations).
        $page = $site->generatedPages()->where('page_type', $pageType)->first();
        if ($slot === 'hero' && $page && ! $page->isCorePage() && $page->hero_source !== 'dedicated') {
            session()->flash('page-mgr-err', 'Switch this page to Dedicated before uploading a page-specific hero.');
            $this->reset('heroUpload');

            return;
        }

        $root = rtrim(config('services.storage.preview_root', 'previews'), '/');
        $ts = now()->format('Ymd-His');
        $ext = strtolower($this->heroUpload->getClientOriginalExtension() ?: 'png');
        $path = sprintf(
            '%s/%d/%s-%s-userupload-%s.%s',
            $root, $site->id, $slot, $pageType, $ts, $ext,
        );

        Storage::disk('s3')->put($path, file_get_contents($tmpPath), 'public');
        $url = Storage::disk('s3')->url($path);

        // Routed through HeroVersionService::activate so this upload
        // shares the same advisory lock as generation + manual
        // selection against the partial unique index
        // hero_versions_one_active_per_slot.
        app(HeroVersionService::class)->activate(
            $site->id,
            $pageType,
            [
                'url' => $url,
                'watermark_url' => null,
                'prompt' => null,
                'model' => null,
                'placement' => null,
                'upgrade_candidate' => false,
                'source' => HeroVersionSource::UserUpload,
            ],
            $slot,
        );

        // Re-stamp the preview snapshot so the rendered page picks up
        // the new image without rerunning the heavy content pipeline.
        $preview = $site->latestPreview;
        if ($preview) {
            app(\App\Services\PreviewSnapshotWriter::class)->mutate($preview, function (&$snapshot) use ($pageType, $url, $slot) {
                $key = match ($slot) {
                    'intro' => 'intro_images',
                    'band' => 'band_images',
                    default => 'hero_images',
                };
                $snapshot[$key][$pageType] = [
                    'url' => $url,
                    'watermark_url' => null,
                    'source' => 'user_upload',
                ];
            });
        }

        // PublicPageCache holds rendered HTML keyed against the live
        // SiteVersion. The hero URL has changed, so invalidate or the
        // public preview keeps serving the stale HTML.
        app(\App\Services\Site\PublicPageCache::class)->invalidate($site);

        $this->reset('heroUpload');
        $slotLabel = match ($slot) {
            'intro' => 'intro',
            'band' => 'band',
            default => 'hero',
        };
        session()->flash(
            'page-mgr-msg',
            "Uploaded — {$slotLabel} image set for {$pageType}."
        );
    }

    /**
     * Fire-and-select fan-out — dispatches GenerateHeroVariationsJob
     * which fans out N RegenerateHeroImageJob children (one per
     * composition class) with preserveOnly=true. Variations NEVER
     * auto-activate; the agent picks via the carousel after they
     * complete.
     */
    public function generateVariations(string $pageType): void
    {
        $this->demoUnavailable('hero variations');
    }

    public function generatePromptFromBrief(): void
    {
        $this->demoUnavailable('hero prompt');
    }

    /** Toggle a section open for editing, populating the form fields from the current data. */
    public function edit(string $pageType, string $sectionName, ?int $storedIndex = null): void
    {
        if (! $this->isValidPageType($pageType)) {
            return;
        }

        $site = $this->assertAuthorizedSiteAccess();
        $gp = $site->generatedPages()->where('page_type', $pageType)->first();

        // Two content_data shapes in the wild:
        //   Legacy (dict):  {hero: {...}, services: {...}, ...}
        //   Current (list): {sections: [{type: hero, ...}, ...], meta: {...}}
        // Look up the section by name under either shape.
        //
        // Source priority matches with() and saveSection(): draftRevision
        // (pending unpublished edits) → publishedRevision (authoritative
        // SiteVersion content) → GeneratedPage.content_data (legacy) →
        // empty. Draft-first mirrors PageService::currentEditableContent —
        // seeding from published while a draft exists made a second edit
        // session silently discard the first unpublished save.
        $content = $gp?->draftRevision?->content_data
            ?? $gp?->publishedRevision?->content_data
            ?? $gp?->content_data
            ?? [];
        if (isset($content['sections']) && is_array($content['sections'])) {
            if ($storedIndex === null) {
                foreach ($content['sections'] as $i => $s) {
                    if (is_array($s) && ($s['type'] ?? null) === $sectionName) {
                        $storedIndex = $i;
                        break;
                    }
                }
            }
            $candidate = $storedIndex !== null ? ($content['sections'][$storedIndex] ?? null) : null;
            $data = (is_array($candidate) && ($candidate['type'] ?? null) === $sectionName)
                ? $candidate
                : [];
        } else {
            $data = $content[$sectionName] ?? [];
            if ($storedIndex === null) {
                $legacyNames = array_keys($content);
                $legacyPos = array_search($sectionName, $legacyNames, true);
                $storedIndex = $legacyPos === false ? 0 : $legacyPos;
            }
        }

        $key = $this->editingKey($pageType, $sectionName, $storedIndex);
        if ($this->editing === $key) {
            $this->editing = null;

            return;
        }

        // Flatten a ProseMirror doc to plain text — concatenates all "text"
        // nodes, joining block-level nodes with newlines so paragraph
        // breaks survive the round-trip to a <textarea>.
        $flattenDoc = function ($node) use (&$flattenDoc): string {
            if (is_string($node)) {
                return $node;
            }
            if (! is_array($node)) {
                return '';
            }
            if (isset($node['text']) && is_string($node['text'])) {
                return $node['text'];
            }
            $out = [];
            foreach (($node['content'] ?? []) as $child) {
                $out[] = $flattenDoc($child);
            }

            return implode(($node['type'] ?? null) === 'doc' ? "\n\n" : '', array_filter($out, fn ($s) => $s !== ''));
        };

        // Pick the first populated value from candidate keys. String values
        // pass through; ProseMirror docs (arrays) are flattened to plain
        // text so the flyout <textarea> displays something the user
        // recognises. Structured formatting is preserved on save unless
        // the displayed text is actually edited — see saveSection().
        $pickStr = function (array $d, array $keys) use ($flattenDoc): string {
            foreach ($keys as $k) {
                if (! array_key_exists($k, $d)) {
                    continue;
                }
                if (is_string($d[$k])) {
                    return $d[$k];
                }
                if (is_array($d[$k])) {
                    $flat = trim($flattenDoc($d[$k]));
                    if ($flat !== '') {
                        return $flat;
                    }
                }
            }

            return '';
        };

        // Field key drift between shapes — accept either:
        //   heading ↔ title        subheading ↔ subtitle ↔ intro
        //   cta_label ↔ button_label
        $this->editHeading = $pickStr($data, ['heading', 'title']);
        $this->editSubheading = $pickStr($data, ['subheading', 'subtitle', 'intro']);

        // Body edits happen in a TipTap WYSIWYG, so seed a doc: pass
        // structured bodies through untouched and lift legacy strings.
        $this->editBodyDoc = null;
        if (array_key_exists('body', $data)) {
            $body = $data['body'];
            $renderer = app(\App\Services\Site\RichTextRenderer::class);
            $this->editBodyDoc = is_array($body)
                ? $body
                : $renderer->docFromPlainText(is_string($body) ? $body : '');
        }
        $this->editCtaLabel = $pickStr($data, ['cta_label', 'button_label']);
        $this->editPhone = $pickStr($data, ['phone']);
        $this->editEmail = $pickStr($data, ['email']);
        $this->editCoverage = $pickStr($data, ['coverage']);

        $this->editItems = [];
        $isFaq = $sectionName === 'faqs';
        // Detect the section's NATIVE item schema so save() writes back the
        // keys the templates actually render. details items are label/value;
        // FAQs question/answer; everything else icon/title/body. Reading
        // title/body from label/value items used to show empty rows AND
        // saving then destroyed the label/value data.
        $items = array_values(array_filter($data['items'] ?? [], 'is_array'));
        $usesLabelValue = collect($items)->contains(
            fn ($it) => array_key_exists('label', $it) || array_key_exists('value', $it)
        );
        $this->editItemsSchema = $isFaq ? 'question_answer' : ($usesLabelValue ? 'label_value' : 'title_body');
        foreach ($items as $origIdx => $item) {
            $rawTitle = match ($this->editItemsSchema) {
                'question_answer' => $item['question'] ?? '',
                'label_value' => $item['label'] ?? '',
                default => $item['title'] ?? '',
            };
            $rawBody = match ($this->editItemsSchema) {
                'question_answer' => $item['answer'] ?? '',
                'label_value' => $item['value'] ?? '',
                default => $item['body'] ?? '',
            };
            // Flatten ProseMirror bodies the same way as intro/subtitle —
            // save() will preserve the structured doc if the text echoes
            // back unchanged. 'orig' pins the row to the item it was seeded
            // from so that preservation survives remove/reorder; matching
            // originals by row position flattened every displaced rich body.
            $this->editItems[] = [
                'icon' => is_string($item['icon'] ?? null) ? $item['icon'] : '',
                'title' => is_string($rawTitle) ? $rawTitle : (is_array($rawTitle) ? trim($flattenDoc($rawTitle)) : ''),
                'body' => is_string($rawBody) ? $rawBody : (is_array($rawBody) ? trim($flattenDoc($rawBody)) : ''),
                'orig' => $origIdx,
            ];
        }

        $this->editEntryList = '';
        $this->editEntries = [];
        $repeatableSchema = app(SectionSchema::class);
        if ($repeatableSchema->isKnownSectionType($sectionName)
            && in_array('members', $repeatableSchema->repeatableLists($sectionName), true)) {
            $this->editEntryList = 'members';
        }
        if ($this->editEntryList !== '') {
            $this->editEntries = array_values(array_filter(
                $data[$this->editEntryList] ?? [],
                fn ($entry): bool => is_array($entry),
            ));
        }

        $this->editSubmitLabel = $data['submit_label'] ?? '';
        $this->editPrivacyNote = $data['privacy_note'] ?? '';

        $this->editFormFields = [];
        foreach (($data['fields'] ?? []) as $field) {
            $this->editFormFields[] = [
                'name' => $field['name'] ?? '',
                'label' => $field['label'] ?? '',
                'type' => $field['type'] ?? 'text',
                'required' => (bool) ($field['required'] ?? false),
                'placeholder' => $field['placeholder'] ?? '',
                // A list, not a comma-joined string: commas were structural,
                // so an option containing one silently became two.
                'options' => array_values(array_map(
                    fn ($o) => (string) $o,
                    is_array($field['options'] ?? null) ? $field['options'] : []
                )),
            ];
        }

        // Flat string list — currently only suburb_list uses this shape.
        // Keeps strings only; filters out any non-scalar entries the
        // content generator might have accidentally written.
        $this->editAreas = [];
        foreach (($data['areas'] ?? []) as $area) {
            if (is_string($area) && trim($area) !== '') {
                $this->editAreas[] = trim($area);
            }
        }

        $this->editing = $key;
    }

    private function editingKey(string $pageType, string $sectionName, ?int $storedIndex): string
    {
        return $storedIndex === null
            ? "{$pageType}.{$sectionName}"
            : "{$pageType}.{$sectionName}.{$storedIndex}";
    }

    /**
     * @return array{0: string, 1: string, 2: int|null}
     */
    private function parseEditingKey(string $key): array
    {
        $parts = explode('.', $key, 3);
        $storedIndex = isset($parts[2]) && is_numeric($parts[2]) ? (int) $parts[2] : null;

        return [$parts[0] ?? '', $parts[1] ?? '', $storedIndex];
    }

    /**
     * Add a field to a contact form.
     *
     * Until this existed the editor could only change fields the AI generator
     * had already written, so a client could never add one -- half of "all the
     * forms should be able to be changed".
     *
     * Eight is a bound, not a design limit: the enquiry payload caps at 40
     * keys, and a contact form longer than this stops being a contact form.
     */
    public function addFormField(): void
    {
        if (count($this->editFormFields) >= 8) {
            return;
        }

        $this->editFormFields[] = [
            'name' => '',
            'label' => '',
            'type' => 'text',
            'required' => false,
            'placeholder' => '',
            'options' => [],
        ];
    }

    public function removeFormField(int $index): void
    {
        if (! isset($this->editFormFields[$index])) {
            return;
        }

        unset($this->editFormFields[$index]);
        $this->editFormFields = array_values($this->editFormFields);
    }

    public function moveFormFieldUp(int $index): void
    {
        if ($index <= 0 || ! isset($this->editFormFields[$index])) {
            return;
        }

        [$this->editFormFields[$index - 1], $this->editFormFields[$index]]
            = [$this->editFormFields[$index], $this->editFormFields[$index - 1]];
    }

    public function moveFormFieldDown(int $index): void
    {
        if (! isset($this->editFormFields[$index + 1])) {
            return;
        }

        [$this->editFormFields[$index + 1], $this->editFormFields[$index]]
            = [$this->editFormFields[$index], $this->editFormFields[$index + 1]];
    }

    /**
     * Append a blank option row on a contact-form field.
     *
     * Mirrors lead-form-editor::addOption(). Kept as its own pair of methods
     * rather than shared, because the two editors hold their field state in
     * differently shaped properties.
     */
    public function addFieldOption(int $fieldIndex): void
    {
        if (! isset($this->editFormFields[$fieldIndex])) {
            return;
        }

        if (! is_array($this->editFormFields[$fieldIndex]['options'] ?? null)) {
            $this->editFormFields[$fieldIndex]['options'] = [];
        }

        if (count($this->editFormFields[$fieldIndex]['options']) >= 10) {
            return;
        }

        $this->editFormFields[$fieldIndex]['options'][] = '';
    }

    public function removeFieldOption(int $fieldIndex, int $optionIndex): void
    {
        if (! isset($this->editFormFields[$fieldIndex]['options'][$optionIndex])) {
            return;
        }

        unset($this->editFormFields[$fieldIndex]['options'][$optionIndex]);

        // Re-index, or the list serialises as a JSON object and the renderer's
        // @foreach emits keys where values belong.
        $this->editFormFields[$fieldIndex]['options'] = array_values(
            $this->editFormFields[$fieldIndex]['options']
        );
    }

    /** Persist the form fields back to GeneratedPage.content_data + Preview.snapshot. */
    public function saveSection(): void
    {
        if (! $this->editing) {
            return;
        }

        [$pageType, $sectionName, $storedIndex] = $this->parseEditingKey($this->editing);

        $site = $this->assertAuthorizedSiteAccess();
        $gp = $site->generatedPages()->where('page_type', $pageType)->first();
        if (! $gp) {
            return;
        }

        // Base the new draft on the CURRENT draft when one exists —
        // otherwise consecutive unpublished saves rebuild from published
        // and discard each other's changes. Falls back to publishedRevision
        // (composer-hydrated fields like suburb_list.areas stay intact:
        // every draft ultimately derives from a hydrated published base),
        // then content_data for sites without a published revision.
        $content = $gp->draftRevision?->content_data
            ?? $gp->publishedRevision?->content_data
            ?? $gp->content_data;

        // Locate the section under either shape so we can mutate it in place.
        $isListShape = isset($content['sections']) && is_array($content['sections']);
        if ($isListShape) {
            $sectionIndex = null;
            if ($storedIndex !== null
                && is_array($content['sections'][$storedIndex] ?? null)
                && ($content['sections'][$storedIndex]['type'] ?? null) === $sectionName) {
                $sectionIndex = $storedIndex;
            } else {
                foreach ($content['sections'] as $i => $s) {
                    if (is_array($s) && ($s['type'] ?? null) === $sectionName) {
                        $sectionIndex = $i;
                        break;
                    }
                }
            }
            $section = $sectionIndex !== null ? $content['sections'][$sectionIndex] : [];
        } else {
            $section = $content[$sectionName] ?? [];
        }

        // Re-flatten helper (same logic as edit()) so we can compare what
        // the flyout currently shows vs the original structured doc.
        $flattenDoc = function ($node) use (&$flattenDoc): string {
            if (is_string($node)) {
                return $node;
            }
            if (! is_array($node)) {
                return '';
            }
            if (isset($node['text']) && is_string($node['text'])) {
                return $node['text'];
            }
            $out = [];
            foreach (($node['content'] ?? []) as $child) {
                $out[] = $flattenDoc($child);
            }

            return implode(($node['type'] ?? null) === 'doc' ? "\n\n" : '', array_filter($out, fn ($s) => $s !== ''));
        };

        // Write $value into the first matching field key whose current
        // value is writable from a scalar. String values are replaced
        // directly. ProseMirror docs (arrays) are preserved ONLY when
        // $value matches their flattened plain text (user didn't edit);
        // if the user changed the text, the structured doc gets
        // overwritten with the new scalar (expected trade-off — rich
        // formatting is lost and the user should edit via the inline
        // WYSIWYG for anything beyond plain text).
        $writeIfString = function (array &$section, string $key, string $value) use ($flattenDoc): bool {
            if (! array_key_exists($key, $section)) {
                return false;
            }
            $current = $section[$key];
            if (is_string($current)) {
                $section[$key] = $value;

                return true;
            }
            if (is_array($current)) {
                $flat = trim($flattenDoc($current));
                if (trim($value) === $flat) {
                    // Unchanged — keep the original structured doc intact.
                    return true;
                }
                // User typed over the rich content → demote to plain string.
                $section[$key] = $value;

                return true;
            }

            return false;
        };

        // Write scalar fields back. Preserve whichever field name was already
        // present so we don't create duplicates across schema variants.
        if ($this->editHeading !== '') {
            if (! $writeIfString($section, 'title', $this->editHeading)) {
                // No title key (or title was structured) — fall through to
                // the legacy 'heading' slot; create it if missing.
                if (! $writeIfString($section, 'heading', $this->editHeading)) {
                    $section['heading'] = $this->editHeading;
                }
            }
        }
        $writeIfString($section, 'subheading', $this->editSubheading)
            || $writeIfString($section, 'subtitle', $this->editSubheading)
            || $writeIfString($section, 'intro', $this->editSubheading);

        // Body comes back from the WYSIWYG as a TipTap doc and is stored as
        // one — never demoted to a string. A malformed shape (JS glitch,
        // tampered payload) is dropped rather than persisted; the renderer
        // whitelists node types at display time, this guards the envelope.
        if (array_key_exists('body', $section)
            && is_array($this->editBodyDoc)
            && ($this->editBodyDoc['type'] ?? null) === 'doc'
            && is_array($this->editBodyDoc['content'] ?? null)) {
            $section['body'] = $this->editBodyDoc;
        }
        $writeIfString($section, 'button_label', $this->editCtaLabel)
            || $writeIfString($section, 'cta_label', $this->editCtaLabel);
        if (array_key_exists('phone', $section)) {
            $section['phone'] = $this->editPhone;
        }
        if (array_key_exists('email', $section)) {
            $section['email'] = $this->editEmail;
        }
        if (array_key_exists('coverage', $section)) {
            $section['coverage'] = $this->editCoverage;
        }
        if (array_key_exists('submit_label', $section)) {
            $section['submit_label'] = $this->editSubmitLabel;
        }
        if (array_key_exists('privacy_note', $section)) {
            $section['privacy_note'] = $this->editPrivacyNote;
        }

        if ($this->editEntryList !== '') {
            $section[$this->editEntryList] = app(RepeatableSectionEntries::class)->validated(
                $sectionName,
                $this->editEntryList,
                $this->editEntries,
                $site->id,
            );
        }

        // Write items back — FAQ items use question/answer keys. Preserve
        // an original ProseMirror body unless the flyout-edited plain text
        // differs from the original's flattened text (same round-trip rule
        // as scalar fields above).
        if (! empty($this->editItems) && array_key_exists('items', $section)) {
            $bodyKey = match ($this->editItemsSchema) {
                'question_answer' => 'answer',
                'label_value' => 'value',
                default => 'body',
            };
            // Same filter + reindex as edit() so 'orig' markers line up with
            // the array the rows were seeded from.
            $originalItems = array_values(array_filter($section['items'] ?? [], 'is_array'));
            $section['items'] = [];
            foreach ($this->editItems as $i) {
                // Look the original up via the row's 'orig' marker — NOT by
                // row position: after removeItem()/reorderItems() positions
                // no longer correspond and a positional lookup flattened
                // every displaced rich-text body to plain text.
                $orig = $i['orig'] ?? null;
                $origBody = is_numeric($orig) ? ($originalItems[(int) $orig][$bodyKey] ?? null) : null;
                $editedBody = $i['body'] ?? '';
                if (is_array($origBody) && trim($editedBody) === trim($flattenDoc($origBody))) {
                    $bodyValue = $origBody;
                } else {
                    $bodyValue = $editedBody;
                }

                $written = match ($this->editItemsSchema) {
                    'question_answer' => ['question' => $i['title'] ?? '', 'answer' => $bodyValue],
                    'label_value' => ['label' => $i['title'] ?? '', 'value' => $bodyValue],
                    default => ['icon' => $i['icon'] ?? '', 'title' => $i['title'] ?? '', 'body' => $bodyValue],
                };

                // Merge over the ORIGINAL item rather than rebuilding it, so
                // keys the flyout never surfaces survive the round-trip:
                // process 'step', services 'source_service', the featured /
                // contact_cta card flags, and the whole of feature_showcase
                // (url/name/tagline/screenshot_url — items with no title or
                // body at all, which a rebuild blanked outright). Rows added
                // in the flyout have no original and keep the plain shape.
                $original = is_numeric($orig) && is_array($originalItems[(int) $orig] ?? null)
                    ? $originalItems[(int) $orig]
                    : null;
                if ($original === null) {
                    $section['items'][] = $written;

                    continue;
                }
                foreach ($written as $key => $value) {
                    // An empty flyout field means "no such key here" for
                    // schemas the flyout can't represent — only write it when
                    // the original already had it (so a cleared title still
                    // clears) or the agent actually typed something.
                    if ($value === '' && ! array_key_exists($key, $original)) {
                        continue;
                    }
                    $original[$key] = $value;
                }
                $section['items'][] = $original;
            }
        }

        // Write contact form fields back.
        //
        // Keyed off the section TYPE, not off the fields key already existing.
        // The old condition meant a section without one -- which is every site
        // in the estate -- silently discarded anything the client added, so
        // "add a field" would have appeared to work and saved nothing.
        //
        // An untouched contact form (no fields key and not migrated) that is
        // saved without adding custom fields (e.g. title-only edit) must NOT
        // write `fields: []` or stamp `fields_migrated = true`, which would
        // silently disarm the implicit Phone + Message fields.
        $contactHadFieldsOriginally = array_key_exists('fields', $section) || ! empty($section['fields_migrated']);
        $contactHasCustomFields = ! empty($this->editFormFields);
        $shouldWriteContactFields = ($section['type'] ?? null) === 'contact_form'
            && ($contactHadFieldsOriginally || $contactHasCustomFields);

        if ($shouldWriteContactFields) {
            $section['fields'] = array_values(array_map(fn ($f) => [
                'name' => FormFieldDefinition::normaliseKey((string) ($f['name'] ?? '')),
                'label' => $f['label'] ?? '',
                'type' => $f['type'] ?? 'text',
                'required' => (bool) ($f['required'] ?? false),
                'placeholder' => $f['placeholder'] ?? '',
                // `radio` was missing here, so a radio field lost its options
                // on every save. Blank rows are dropped: addFieldOption()
                // appends an empty one, and contact_form.blade.php now renders
                // these, so an empty option would ship as a blank choice.
                ...(in_array($f['type'] ?? '', FormFieldDefinition::CHOICE_TYPES, true)
                    ? ['options' => array_values(array_filter(
                        array_map('trim', (array) ($f['options'] ?? [])),
                        fn ($o) => $o !== ''
                    ))]
                    : []),
            ], $this->editFormFields));

            // A field with no key would render an input that submits nothing,
            // so it can never collect an answer. Dropped rather than stored.
            $section['fields'] = array_values(array_filter(
                $section['fields'],
                fn ($f) => $f['name'] !== ''
            ));
        }

        // Write the areas list back for suburb_list. Always rewrite the
        // key (even if the list was previously unset) so admins can type
        // a list into a stub section and it persists. Empty list ⇒ unset
        // the key so the template's render-gate (count >= 3) kicks in
        // again rather than showing a half-empty chip row.
        if ($sectionName === 'suburb_list') {
            $cleaned = array_values(array_filter(
                array_map(fn ($s) => is_string($s) ? trim($s) : '', $this->editAreas),
                fn ($s) => $s !== '',
            ));
            if ($cleaned === []) {
                unset($section['areas']);
            } else {
                $section['areas'] = $cleaned;
            }
        }

        if ($isListShape) {
            if ($sectionIndex !== null) {
                $content['sections'][$sectionIndex] = $section;
            } else {
                // Section didn't exist in the list — append it. Rare path
                // (editing a section that was missing from the revision).
                $content['sections'][] = array_merge(['type' => $sectionName], $section);
            }
        } else {
            $content[$sectionName] = $section;
        }

        // Contact-form field writes go through FormFieldsWriter so the
        // draft revision and the preview snapshot cannot drift. Other
        // sections keep the existing persist path.
        if ($shouldWriteContactFields && $isListShape && $sectionIndex !== null) {
            $extras = $section;
            unset($extras['fields']);

            app(FormFieldsWriter::class)->write(
                $gp,
                $sectionIndex,
                $section['fields'] ?? [],
                userId: auth()->id(),
                expectedBaseRevisionId: null,
                sectionExtras: $extras,
            );
        } else {
            // Create a draft PageRevision + bump admin_revision atomically.
            // The single transaction closes the race window where a concurrent
            // AutoPublishCoordinator::finalizeAfterBatch could read the old
            // admin_revision between the draft commit and the bump commit,
            // decide no admin intent was expressed, and auto-publish the
            // in-flight draft.
            app(\App\Services\Site\CompositionService::class)->applyAdminChange(
                $site,
                function () use ($gp, $content) {
                    app(\App\Services\Site\PageService::class)->replaceContent(
                        $gp,
                        $content,
                        aiGenerated: false,
                        userId: auth()->id(),
                    );
                },
                userId: auth()->id(),
            );

            // Back-compat mirror: stamp the snapshot too so the legacy
            // shareable /preview/{slug} path reflects the edit immediately.
            // Source of truth for the versioned renderer is the PageRevision
            // created above.
            $preview = $site->latestPreview;
            if ($preview) {
                app(\App\Services\PreviewSnapshotWriter::class)->mutate($preview, function (&$snapshot) use ($pageType, $sectionName, $section) {
                    $snapshot['pages'][$pageType][$sectionName] = $section;
                });
            }
        }

        $this->editing = null;
        $this->dispatch('composition-dirty');
        session()->flash('page-mgr-msg', ucfirst($sectionName).' section saved.');
    }

    public function addItem(): void
    {
        $this->editItems[] = ['icon' => '', 'title' => '', 'body' => '', 'orig' => null];
    }

    public function removeItem(int $index): void
    {
        unset($this->editItems[$index]);
        $this->editItems = array_values($this->editItems);
    }

    public function addEntry(): void
    {
        if (! $this->editing || $this->editEntryList === '') {
            return;
        }

        [, $sectionName] = $this->parseEditingKey($this->editing);
        $entry = [];
        foreach (app(SectionSchema::class)->repeatableFieldRules($sectionName, $this->editEntryList) as $field => $rules) {
            if (($rules['type'] ?? null) === 'plain') {
                $entry[$field] = '';
            }
        }
        $this->editEntries[] = $entry;
    }

    public function removeEntry(int $index): void
    {
        if (! isset($this->editEntries[$index])) {
            return;
        }

        unset($this->editEntries[$index]);
        $this->editEntries = array_values($this->editEntries);
    }

    public function reorderEntries(int $from, int $to): void
    {
        if ($from === $to || ! isset($this->editEntries[$from]) || $to < 0 || $to >= count($this->editEntries)) {
            return;
        }

        $entry = $this->editEntries[$from];
        array_splice($this->editEntries, $from, 1);
        array_splice($this->editEntries, $to, 0, [$entry]);
    }

    public function clearEntryMedia(int $index, string $field): void
    {
        if (! in_array($field, ['image_id', 'alternate_image_id', 'hover_image_id'], true)
            || ! isset($this->editEntries[$index])) {
            return;
        }

        unset($this->editEntries[$index][$field]);
    }

    public function addArea(): void
    {
        $this->editAreas[] = '';
    }

    public function removeArea(int $index): void
    {
        unset($this->editAreas[$index]);
        $this->editAreas = array_values($this->editAreas);
    }

    public function openAddPage(): void
    {
        $this->showAddPage = true;
        $this->newPageServices = [];
        $this->newPageCustom = '';
        $this->newPageHero = true;
    }

    public function toggleService(string $title): void
    {
        if (in_array($title, $this->newPageServices, true)) {
            $this->newPageServices = array_values(array_diff($this->newPageServices, [$title]));
        } else {
            $this->newPageServices[] = $title;
        }
    }

    /**
     * Slug for a service page — location suffix only makes sense for local/regional
     * businesses. A national business would get ugly slugs like
     * "ai-preview-generator-united-kingdom" otherwise.
     */
    private function servicePageSlug(string $serviceName, string $location, string $scope): string
    {
        if ($scope === 'national' || trim($location) === '') {
            return Str::slug($serviceName);
        }

        return Str::slug($serviceName.'-'.$location);
    }

    /**
     * Trigger projects/portfolio page generation on demand. Build-time
     * dispatch is gated behind PREVIEW_PROJECTS_PAGE_AT_BUILD (default
     * off) to keep per-preview cost down; staff hit this from the
     * "Add Projects Page" button when they want to add a portfolio page
     * for the portfolio page.
     *
     * Refuses if a projects page already exists, if rate limited, or
     * if the site is over its monthly cost cap. Archetype recommendation
     * is shown as a hint in the UI but NOT enforced here — staff can
     * trigger on any vertical for testing / customer requests.
     */
    public function createProjectsPage(): void
    {
        $this->demoUnavailable('projects page');

        return;

        $site = $this->findAuthorizedSite();
        if (! $site) {
            return;
        }

        // Idempotency: if the page already exists, no-op with a clear
        // message. The page-manager re-renders to surface the existing
        // page; staff can edit / regen its content from the projects
        // pill-tabs surface instead of re-generating from scratch.
        $exists = $site->generatedPages()->where('page_type', 'projects')->exists();
        if ($exists) {
            session()->flash('page-mgr-msg', 'This site already has a Projects page — refreshing.');

            return;
        }

        if (! \Illuminate\Support\Facades\RateLimiter::attempt("projects-create:{$this->siteId}", 2, fn () => true, 600)) {
            session()->flash('page-mgr-err', 'Rate limit reached — please wait a few minutes.');

            return;
        }

        if (! $site->canIncurCostFor(auth()->user())) {
            session()->flash('page-mgr-err', 'Monthly credit limit reached for this site. Please contact your agent to top up.');

            return;
        }

        try {
            null->dispatch(null);
            cache()->increment("site:{$this->siteId}:pending_jobs");
            session()->flash('page-mgr-msg', 'Projects page generating — refresh in a minute or two.');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('createProjectsPage dispatch failed', [
                'site_id' => $this->siteId,
                'error' => $e->getMessage(),
            ]);
            session()->flash('page-mgr-err', 'Projects page generation could not be queued. Please try again shortly.');
        }
    }

    public function addServicePages(): void
    {
        $this->demoUnavailable('service pages');
    }

    /**
     * Click-to-add from the "Suggested service pages" card fed by the
     * GenerateServicePagesJob deferred list. Removes the entry from
     * admin_suggestions.pending_services before dispatching so the chip
     * disappears immediately (dispatch-or-fail — retry is a re-run of the
     * job, not a resurface). Shape governed by Contract 1.
     */
    public function addSuggestedService(int $index): void
    {
        $this->demoUnavailable('service pages');
    }

    /**
     * Change a page's publish status (Published / Draft / Archived). The
     * change is explicit admin intent so it bumps admin_revision via
     * MutationSource::Admin — auto-publish will skip if a batch finishes
     * after this call. Rejects disallowed transitions (no self-transitions,
     * enforces the PageStatus state machine).
     */
    public function updatePageStatus(string $pageType, string $newStatus): void
    {
        $site = $this->findAuthorizedSite();
        if (! $site) {
            return;
        }

        $target = \App\Enums\PageStatus::tryFrom($newStatus);
        if ($target === null) {
            session()->flash('page-mgr-err', 'Unknown page status: '.$newStatus);

            return;
        }

        $gp = $site->generatedPages()->where('page_type', $pageType)->first();
        if (! $gp) {
            return;
        }

        /** @var \App\Enums\PageStatus $current */
        $current = $gp->status;
        if ($current === $target) {
            return; // no-op
        }

        if (! $current->canTransitionTo($target)) {
            session()->flash('page-mgr-err', "Cannot change status from {$current->label()} to {$target->label()}.");

            return;
        }

        // Atomic: status change + admin_revision bump in one transaction
        // with a lock on the draft. If these two writes split, a batch's
        // AutoPublishCoordinator::finalize could read the draft between
        // them, see admin_revision unchanged, and auto-publish the
        // admin's in-flight status change — exactly what the guard is
        // supposed to prevent. (observer handles archived_at lifecycle)
        app(\App\Services\Site\CompositionService::class)->applyAdminChange(
            $site,
            fn () => $gp->update(['status' => $target]),
            userId: auth()->id(),
        );

        // Poke the unpublished-changes banner so it refreshes its count
        // without the admin having to reload the page.
        $this->dispatch('composition-dirty');

        session()->flash('page-mgr-msg', ucfirst($pageType).' page set to '.$target->label().'.');
    }

    public function updateHeroSource(string $pageType, string $heroSource): void
    {
        $site = $this->findAuthorizedSite();
        if (! $site) {
            return;
        }

        if (! in_array($heroSource, ['shared', 'dedicated'], true)) {
            session()->flash('page-mgr-err', 'Unknown hero source: '.$heroSource);

            return;
        }

        $page = $site->generatedPages()->where('page_type', $pageType)->first();
        if (! $page || $page->isCorePage() || $page->hero_source === $heroSource) {
            return;
        }

        app(\App\Services\Site\CompositionService::class)->applyAdminChange(
            $site,
            fn () => $page->update(['hero_source' => $heroSource]),
            userId: auth()->id(),
        );

        $this->dispatch('composition-dirty');
        session()->flash('page-mgr-msg', ucfirst(str_replace('-', ' ', $pageType)).' hero source set to '.$heroSource.'.');
    }

    public function updateNavLabel(string $pageType, string $label): void
    {
        $site = $this->findAuthorizedSite();
        $gp = $site?->generatedPages()->where('page_type', $pageType)->first();
        if (! $gp) {
            return;
        }
        $gp->update(['nav_label' => \Illuminate\Support\Str::limit(trim($label), 25, '')]);

        // Stamp into snapshot (legacy compat)
        $preview = $site->latestPreview;
        if ($preview) {
            app(\App\Services\PreviewSnapshotWriter::class)->mutate($preview, function (&$snapshot) use ($pageType, $gp) {
                $snapshot['nav_labels'][$pageType] = $gp->nav_label;
            });
        }

        app(\App\Services\Site\CompositionService::class)
            ->bumpAdminRevision($site, auth()->id());
        $this->dispatch('composition-dirty');
    }

    public function reorderPages(string $fromKey, string $toKey): void
    {
        $site = $this->findAuthorizedSite();
        $locked = ['home', 'contact'];
        if (! $site || $fromKey === $toKey || in_array($fromKey, $locked) || in_array($toKey, $locked)) {
            return;
        }

        // Use the same ordering the UI tabs use
        $preview = $site->latestPreview;
        $snapshot = $preview?->snapshot ?? [];
        $allPageKeys = array_keys($snapshot['pages'] ?? []);
        $corePages = ['home', 'about', 'contact'];
        $servicePageKeys = array_diff($allPageKeys, $corePages);
        $orderedKeys = array_merge(
            array_intersect($corePages, $allPageKeys),
            array_values($servicePageKeys),
        );

        $from = array_search($fromKey, $orderedKeys);
        $to = array_search($toKey, $orderedKeys);
        if ($from === false || $to === false) {
            return;
        }

        $moved = array_splice($orderedKeys, $from, 1);
        array_splice($orderedKeys, $to, 0, $moved);

        // Update sort_order on GeneratedPage rows
        foreach ($orderedKeys as $i => $pageType) {
            $site->generatedPages()->where('page_type', $pageType)->update(['sort_order' => $i]);
        }

        // Rebuild snapshot page ordering
        if ($preview) {
            app(\App\Services\PreviewSnapshotWriter::class)->mutate($preview, function (&$snapshot) use ($orderedKeys) {
                $oldPages = $snapshot['pages'] ?? [];
                $reordered = [];
                foreach ($orderedKeys as $pageType) {
                    if (isset($oldPages[$pageType])) {
                        $reordered[$pageType] = $oldPages[$pageType];
                    }
                }
                $snapshot['pages'] = $reordered;
            });
        }

        app(\App\Services\Site\CompositionService::class)
            ->bumpAdminRevision($site, auth()->id());
        $this->dispatch('composition-dirty');
    }

    public function deleteServicePage(string $pageType): void
    {
        if (! $this->isValidPageType($pageType)) {
            return;
        }

        $site = $this->findAuthorizedSite();
        if (! $site) {
            return;
        }

        $gp = $site->generatedPages()->where('page_type', $pageType)->first();
        if (! $gp || $gp->isCorePage()) {
            return;
        }

        $gp->delete();

        // Remove from snapshot
        $preview = $site->latestPreview;
        if ($preview) {
            app(\App\Services\PreviewSnapshotWriter::class)->mutate($preview, function (&$snapshot) use ($pageType) {
                unset($snapshot['pages'][$pageType]);
                unset($snapshot['hero_images'][$pageType]);
            });
        }

        $this->activeTab = 'home';
        session()->flash('page-mgr-msg', ucwords(str_replace('-', ' ', $pageType)).' page deleted.');
    }

    public function setTextZone(string $pageType, string $zone): void
    {
        if (! $this->isValidPageType($pageType)) {
            return;
        }

        $site = $this->findAuthorizedSite();
        if (! $site) {
            return;
        }

        // Derive overlay_direction from zone (same rule as before).
        [$row, $col] = explode('-', $zone);
        $overlayDir = match ($row) {
            'top' => 'to-b',
            'bottom' => 'to-t',
            default => match ($col) {
                'right' => 'to-l',
                default => 'to-r',
            },
        };

        $this->mutateActiveHeroPlacement($site, $pageType, [
            'text_zone' => $zone,
            'overlay_direction' => $overlayDir,
        ]);
    }

    public function setHeroCropY(string $pageType, int $percent): void
    {
        if (! $this->isValidPageType($pageType)) {
            return;
        }

        $site = $this->findAuthorizedSite();
        if (! $site) {
            return;
        }

        $this->mutateActiveHeroPlacement($site, $pageType, [
            'bg_position_y' => max(0, min(100, $percent)),
        ]);
    }

    /**
     * Merge placement patches into the active HeroVersion for this page_type
     * (versioned renderer source of truth) AND mirror the same change into
     * Preview.snapshot for legacy compat. Bumps admin_revision and
     * nudges the banner so changes surface as an unpublished edit.
     *
     * Without the HeroVersion update these changes never reached the
     * versioned renderer — only the legacy /preview/{slug} path was
     * seeing text-zone / crop-Y adjustments.
     */
    protected function mutateActiveHeroPlacement(\App\Models\Site $site, string $pageType, array $patch): void
    {
        // IMPORTANT: scope to slot='hero'. Without this filter the query
        // could return the active intro row for the same page_type and
        // write the hero's crop_y / text_zone patch to the intro row's
        // placement column — silent data loss. Crop slider "not saving"
        // was this: patches landed on the intro row, the hero thumbnail
        // re-read bg_position_y from the hero row (still null) and
        // showed no change. Same helper is used by setTextZone and
        // resetTextZone, so this fix covers all three.
        $active = $site->heroVersions()
            ->where('page_type', $pageType)
            ->where('slot', 'hero')
            ->where('is_active', true)
            ->first();

        // When the page doesn't have its own active hero (typical when
        // PREVIEW_INNER_PAGES_USE_SHARED_HERO=true or the page hard-failed
        // QA at build time), the live renderer falls back to the
        // __shared_service_hero row. Crop / text-zone edits intended for
        // this page must therefore land on the shared row. Bonus: that
        // change propagates to *every* page sharing the same fallback,
        // which is exactly the right behaviour for a shared hero.
        $writingToShared = false;
        if (! $active) {
            $active = $site->heroVersions()
                ->where('page_type', '__shared_service_hero')
                ->where('slot', 'hero')
                ->where('is_active', true)
                ->first();
            $writingToShared = $active !== null;
        }

        if ($active) {
            $placement = $active->placement ?? [];
            $placement = array_replace($placement, $patch);
            $active->update(['placement' => $placement]);
        }

        // Snapshot mirroring: when writing to the shared row, the snapshot
        // entry to update is __shared_service_hero (so all fallback
        // consumers re-read the patched placement on next render).
        $snapshotKey = $writingToShared ? '__shared_service_hero' : $pageType;

        $preview = $site->latestPreview;
        if ($preview) {
            // When the snapshot doesn't already have an entry for this
            // page (service pages, projects page, anything not seeded by
            // BuildPreviewJob), we must seed it with url + watermark from
            // the active HeroVersion before applying the patch. Otherwise
            // the next render's $heroImages map sees an entry without a
            // url, the page-manager admin thumbnail goes blank, and the
            // text-zone picker disappears (because tab['hero_url'] is null).
            $seedFromHero = ! $active ? null : [
                'url' => $active->url,
                'watermark_url' => $active->watermark_url,
                'prompt' => $active->prompt ?? '',
                'model' => $active->model ?? '',
            ];

            app(\App\Services\PreviewSnapshotWriter::class)->mutate($preview, function (&$snapshot) use ($snapshotKey, $patch, $seedFromHero) {
                $snapshot['hero_images'] = $snapshot['hero_images'] ?? [];
                $existing = $snapshot['hero_images'][$snapshotKey] ?? null;

                if ($existing === null && $seedFromHero !== null) {
                    // Brand-new snapshot entry — seed from active HeroVersion
                    // so url + watermark survive the patch.
                    $existing = $seedFromHero;
                } elseif ($existing === null) {
                    $existing = [];
                }

                // Patch keys (text_zone, bg_position_y, overlay_*, text_color)
                // mirror HeroVersion.placement and must be written nested under
                // ['placement'] — that's where BuildPreviewJob writes them and
                // where the public hero.blade reads them. Earlier this merged
                // into the top level, so CP edits were silently shadowed by
                // the snapshot's stale nested placement
                // (placement-roundtrip bug).
                $existing['placement'] = is_array($existing['placement'] ?? null) ? $existing['placement'] : [];
                $existing['placement'] = array_replace($existing['placement'], $patch);
                $snapshot['hero_images'][$snapshotKey] = $existing;
            });
        }

        app(\App\Services\Site\CompositionService::class)
            ->bumpAdminRevision($site, auth()->id());

        $this->dispatch('composition-dirty');
    }

    public function activateHeroVersion(int $versionId): void
    {
        $site = $this->findAuthorizedSite();
        $version = $site?->heroVersions()->find($versionId);
        // Slot guard: Livewire actions are public request surfaces — a
        // band/intro row id here would deactivate every hero row and
        // mirror the wrong URL into snapshot.hero_images. Mirrors the
        // intro/band actions' guards.
        if (! $version || $version->slot !== 'hero') {
            return;
        }

        // Routed through HeroVersionService so this shares the
        // activate() advisory lock. Slot-scoped deactivate lives
        // inside the service (intro/band siblings stay untouched).
        $heroVersions = app(HeroVersionService::class);
        $heroVersions->activateExistingAndRecord($version, $site, auth()->id());
        $this->dispatch('composition-dirty');

        session()->flash('page-mgr-msg', 'Hero image reverted.');
    }

    public function activateIntroVersion(int $versionId): void
    {
        $site = $this->findAuthorizedSite();
        $version = $site?->heroVersions()->find($versionId);
        if (! $version || $version->slot !== 'intro') {
            return;
        }

        $heroVersions = app(HeroVersionService::class);
        // Intro images are NOT mirrored into preview.snapshot.hero_images —
        // PageRenderer reads active slot='intro' rows from hero_versions
        // directly (see app/Services/Site/PageRenderer.php). recordActivation
        // skips the mirror for non-hero slots and still bumps + invalidates.
        $heroVersions->activateExistingAndRecord($version, $site, auth()->id());
        $this->dispatch('composition-dirty');

        session()->flash('page-mgr-msg', 'Intro image reverted.');
    }

    public function activateBandVersion(int $versionId): void
    {
        $site = $this->findAuthorizedSite();
        $version = $site?->heroVersions()->find($versionId);
        if (! $version || $version->slot !== 'band') {
            return;
        }

        $heroVersions = app(HeroVersionService::class);
        // Band images are not mirrored into preview.snapshot.hero_images —
        // PageRenderer reads active slot='band' rows from hero_versions
        // directly. recordActivation skips the mirror for non-hero slots
        // and still bumps + invalidates.
        $heroVersions->activateExistingAndRecord($version, $site, auth()->id());
        $this->dispatch('composition-dirty');

        session()->flash('page-mgr-msg', 'Band image reverted.');
    }

    public function regenerateIntro(string $pageType): void
    {
        $this->demoUnavailable('intro generation');

        return;

        $site = $this->findAuthorizedSite();
        if (! $site) {
            return;
        }

        if (! $this->isValidPageType($pageType)) {
            return;
        }

        // Projects page renders no intro band — guard explicitly so a
        // direct call (e.g. legacy URL or manual JS) can't generate one.
        if ($pageType === 'projects') {
            session()->flash('page-mgr-err', 'Projects page has no intro image — only the hero is rendered.');

            return;
        }

        if (! RateLimiter::attempt("intro-regen:{$this->siteId}", 3, fn () => true, 300)) {
            session()->flash('page-mgr-err', 'Rate limit reached — please wait a few minutes.');

            return;
        }

        if (! $site->canIncurCostFor(auth()->user())) {
            session()->flash('page-mgr-err', 'Monthly credit limit reached for this site. Please contact your agent to top up.');

            return;
        }

        // Intro regen uses the per-page model picker (mirrors hero regen).
        $modelKey = $this->introModels[$pageType] ?? self::DEFAULT_REGEN_MODEL;
        [$model, $qualityOverride] = self::parseHeroModelKey($modelKey);
        $prompt = trim($this->introPrompts[$pageType] ?? '') ?: null;
        if ($prompt && strlen($prompt) > 2000) {
            session()->flash('page-mgr-err', 'Prompt too long — max 2000 characters.');

            return;
        }

        try {
            null->dispatch(null(
                site: $site,
                pageType: $pageType,
                imageModel: $model,
                customPrompt: $prompt,
                regenTarget: 'intro',
                qualityOverride: $qualityOverride,
                initiatedByUserId: auth()->id(),
            ));
            cache()->increment("site:{$this->siteId}:pending_jobs");
            session()->flash('page-mgr-msg', ucwords(str_replace('-', ' ', $pageType)).' intro regenerating — refresh in a moment.');
        } catch (\Throwable $e) {
            Log::warning('regenerateIntro dispatch failed', [
                'site_id' => $this->siteId,
                'page_type' => $pageType,
                'error' => $e->getMessage(),
            ]);
            session()->flash('page-mgr-err', 'Intro regeneration could not be queued. Please try again shortly.');
        }
    }

    public function resetTextZone(string $pageType): void
    {
        $this->demoUnavailable('text zone');

        return;

        $site = $this->findAuthorizedSite();
        if (! $site) {
            return;
        }

        // Prefer the active HeroVersion's url over the snapshot — HeroVersion
        // is the versioned renderer's source of truth for the current hero.
        $active = $site->heroVersions()
            ->where('page_type', $pageType)
            ->where('is_active', true)
            ->first();

        $heroUrl = $active?->url ?? ($site->latestPreview?->snapshot['hero_images'][$pageType]['url'] ?? null);
        if (! $heroUrl) {
            return;
        }

        try {
            $ai = null;
            $resp = $ai->analysePlacement($heroUrl);
            $placementData = $resp['data'] ?? [];
            if (! empty($placementData) && is_array($placementData)) {
                $this->mutateActiveHeroPlacement($site, $pageType, $placementData);
            }
            session()->flash('page-mgr-msg', 'Text position reset to AI default.');
        } catch (\Throwable $e) {
            session()->flash('page-mgr-err', 'Reset failed: '.$e->getMessage());
        }
    }

    public function toggleContactForm(): void
    {
        $this->contactFormEnabled = ! $this->contactFormEnabled;

        $site = $this->findAuthorizedSite();
        if (! $site) {
            return;
        }

        // Source of truth: BusinessProfile. page.blade honours this flag
        // to skip rendering contact_form sections on the versioned path.
        if ($site->businessProfile) {
            $profile = $site->businessProfile->profile_data ?? [];
            $profile['contact_form_enabled'] = $this->contactFormEnabled;
            $site->businessProfile->update(['profile_data' => $profile]);
        }

        $preview = $site->latestPreview;
        if ($preview) {
            app(\App\Services\PreviewSnapshotWriter::class)->mutate($preview, function (&$snap) {
                $snap['contact_form_enabled'] = $this->contactFormEnabled;
            });
        }

        app(\App\Services\Site\CompositionService::class)
            ->bumpAdminRevision($site, auth()->id());
        $this->dispatch('composition-dirty');

        session()->flash('page-mgr-msg', 'Contact form '.($this->contactFormEnabled ? 'enabled' : 'disabled').'.');
    }

    public function regenerateContent(string $pageType): void
    {
        $this->demoUnavailable('page content');

        return;

        if (! $this->isValidPageType($pageType)) {
            return;
        }

        $site = $this->findAuthorizedSite();
        if (! $site) {
            return;
        }

        if (! RateLimiter::attempt("content-regen:{$this->siteId}", 5, fn () => true, 300)) {
            session()->flash('page-mgr-err', 'Rate limit reached — please wait a few minutes.');
            return;
        }

        if (! $site->canIncurCostFor(auth()->user())) {
            session()->flash('page-mgr-err', 'Monthly credit limit reached for this site. Please contact your agent to top up.');
            return;
        }

        try {
            return;
            cache()->increment("site:{$this->siteId}:pending_jobs");
            $this->editing = null;
            session()->flash('page-mgr-msg', ucfirst($pageType).' content regenerating — refresh in a moment.');
        } catch (\Throwable $e) {
            session()->flash('page-mgr-err', 'Content regen failed: '.$e->getMessage());
        }
    }

    /** Rewrite a single item without saving. Not available in this demo. */
    public function regenerateItem(int $index): void
    {
        $this->demoUnavailable('item copy');
    }

    public function reorderItems(int $from, int $to): void
    {
        if ($from === $to || ! isset($this->editItems[$from])) {
            return;
        }
        $item = $this->editItems[$from];
        array_splice($this->editItems, $from, 1);
        array_splice($this->editItems, $to, 0, [$item]);
    }

    private function isValidPageType(string $pageType): bool
    {
        return preg_match('/^[a-z0-9-]+(?:\/[a-z0-9-]+){0,3}$/', $pageType) === 1
            && strlen($pageType) <= 200;
    }

    /**
     * Protected computed property instead of a public with() method:
     * with() is a remotely callable Livewire action whose return value
     * (Site/model rows included) would be JSON-encoded into the
     * response. #[Computed] + protected keeps it render-only; the
     * template extract()s it so variable names are unchanged.
     */
    #[Computed]
    protected function viewData(): array
    {
        $site = $this->findAuthorizedSite();
        $preview = $site?->latestPreview;
        $snapshot = $preview?->snapshot ?? [];
        $heroImages = $snapshot['hero_images'] ?? [];

        // Page sections read from each GeneratedPage's draft revision
        // when one is pending, else the published revision — NOT from
        // the snapshot. Snapshot is baked by BuildPreviewJob at pipeline
        // completion and doesn't auto-update when content_data or
        // revisions change — admin writes update both paths explicitly
        // but composer hydration / back-office backfills / future
        // pipeline steps can drift. Draft-first keeps the section list,
        // the edit() flyout, and saveSection()'s base all consistent:
        // the admin sees what the NEXT publish will ship (divergence
        // from the live site is what the unpublished-changes banner
        // signals). Falls through to GeneratedPage content_data for
        // rows without any revision (shouldn't happen post-migration
        // but belt-and-braces) and finally to the snapshot for legacy
        // sites whose sections never made it into a revision.
        $allPages = [];
        $generatedPagesByType = [];
        if ($site !== null) {
            // Eager-load publishedRevision + re-key by page_type: the loop
            // below reads $gp->publishedRevision for every page (was
            // lazy-loaded, O(N) queries), and the tabs loop further down
            // previously ran generatedPages()->where('page_type', ...)->first()
            // per page (another O(N) burst). One eager-loaded fetch covers
            // both.
            $generatedPages = $site->generatedPages()
                ->with(['draftRevision:id,content_data', 'publishedRevision:id,content_data'])
                ->get();
            foreach ($generatedPages as $gp) {
                if (! is_string($gp->page_type) || $gp->page_type === '') {
                    continue;
                }
                $generatedPagesByType[$gp->page_type] = $gp;
                // Draft-first so the section list matches what edit() seeds
                // and what the next publish will actually ship.
                $pageContent = $gp->draftRevision?->content_data
                    ?? $gp->publishedRevision?->content_data
                    ?? $gp->content_data
                    ?? ($snapshot['pages'][$gp->page_type] ?? []);
                $allPages[$gp->page_type] = $pageContent;
            }
        }
        if ($allPages === []) {
            $allPages = $snapshot['pages'] ?? [];
        }

        // The preview snapshot's hero_images map is only written for CORE
        // pages (home/about/contact) by BuildPreviewJob — service-page
        // heroes live in the hero_versions table and never round-trip
        // through the snapshot. So the admin panel was showing "no hero"
        // for every service page even though the public renderer served
        // the dedicated hero just fine (PageRenderer reads hero_versions
        // as its source of truth). Mirror that here: merge active
        // slot='hero' rows into $heroImages so the admin thumbnail,
        // hero-source dropdown, and text-zone control all see the real
        // URL the live site is serving.
        if ($site !== null) {
            $activeHeroes = \App\Models\HeroVersion::where('site_id', $site->id)
                ->where('slot', 'hero')
                ->where('is_active', true)
                ->get(['page_type', 'url', 'watermark_url', 'placement', 'prompt', 'model']);
            foreach ($activeHeroes as $hv) {
                if (! is_string($hv->page_type) || $hv->page_type === '') {
                    continue;
                }
                // If the snapshot already has a complete entry (with url),
                // honour it — agent edits live there. But if it has a
                // partial stub (eg. crop_y patch with no url), or no entry
                // at all, hydrate from the HeroVersion row. Without this
                // fallback the partial-stub case nukes the admin thumb +
                // text-zone picker visibility on the very next render.
                $existing = $heroImages[$hv->page_type] ?? null;
                if (is_array($existing) && ! empty($existing['url'])) {
                    continue;
                }
                // Mirror the shape BuildPreviewJob writes to
                // snapshot.hero_images for core pages: placement keys
                // (bg_position_y, text_zone, text_color,
                // overlay_direction, overlay_strength, …) live at the
                // TOP level, not nested under 'placement'. The admin
                // thumb reads $hero['bg_position_y'] / ['text_zone']
                // directly, so we have to flatten here or service-page
                // crop + text-zone edits never render in the UI.
                $placement = is_array($hv->placement ?? null) ? $hv->placement : [];
                $heroImages[$hv->page_type] = array_replace([
                    'url' => $hv->url,
                    'watermark_url' => $hv->watermark_url,
                    'prompt' => $hv->prompt ?? '',
                    'model' => $hv->model ?? '',
                    ...$placement,
                ], is_array($existing) ? $existing : []);
            }
        }

        // Home first, then about, service pages, contact last.
        $corePages = ['home', 'about', 'contact'];
        $allPageKeys = array_keys($allPages);
        $servicePageKeys = array_diff($allPageKeys, $corePages);
        $orderedKeys = array_merge(
            array_intersect(['home', 'about'], $allPageKeys),
            array_values($servicePageKeys),
            in_array('contact', $allPageKeys) ? ['contact'] : [],
        );

        $tabs = [];
        foreach ($orderedKeys as $page) {
            $gpRow = $generatedPagesByType[$page] ?? null;
            $heroSource = $gpRow?->hero_source ?? 'shared';
            // Match PageRenderer's hero-source resolution exactly so the
            // admin UI thumbnail reflects what the public renderer will
            // actually serve. Without this, a service page toggled to
            // 'shared' but carrying a leftover dedicated HeroVersion row
            // would show the dedicated thumb in the page-manager while
            // the live renderer serves the shared hero (WYSIWYG mismatch).
            // Core pages (home, about, contact) always have their own hero
            // image baked into hero_images[page_type] — regardless of
            // hero_source, which is a service-page concept. The previous
            // logic mistakenly funnelled core pages whose hero_source
            // defaulted to 'shared' into the __shared_service_hero
            // fallback, which only exists for service pages, leaving
            // about/contact with no thumbnail in the page-manager.
            //
            // When a core page (home/about/contact) has no
            // own-page hero, fall through to the __shared_service_hero so
            // staff see what the live renderer serves rather than a blank.
            // hero_is_fallback=true flags this so the template can show a
            // "Using site-wide hero" badge + a one-click "Regenerate" button
            // to create a dedicated hero for the page.
            $heroIsFallback = false;
            if (in_array($page, $corePages, true)) {
                $ownHero = $heroImages[$page] ?? null;
                if ($ownHero !== null) {
                    $hero = $ownHero;
                } else {
                    $hero = $heroImages['__shared_service_hero'] ?? null;
                    $heroIsFallback = $hero !== null;
                }
            } elseif ($heroSource === 'dedicated' && isset($heroImages[$page])) {
                $hero = $heroImages[$page];
            } else {
                $hero = $heroImages['__shared_service_hero'] ?? null;
            }
            $content = $allPages[$page] ?? [];

            // Two content shapes:
            //   Legacy: {hero: {...}, services: {...}, ...} — keyed by section name
            //   Current: {sections: [{type: hero, ...}, ...], meta: {...}}
            // Normalise to [{name, data}, ...] for the template.
            $sections = [];
            if (isset($content['sections']) && is_array($content['sections'])) {
                foreach ($content['sections'] as $i => $sectionData) {
                    if (! is_array($sectionData)) {
                        continue;
                    }
                    $sections[$i] = [
                        'name' => $sectionData['type'] ?? '',
                        'data' => $sectionData,
                        '__stored_index' => $i,
                    ];
                }
            } else {
                foreach ($content as $sectionName => $sectionData) {
                    $sections[] = [
                        'name' => $sectionName,
                        'data' => $sectionData,
                    ];
                }
            }

            // Filter to slot='hero' — the hero_versions table also carries
            // the intro-image row (same page_type, slot='intro'), and
            // without the filter both appear in the hero version-history
            // grid. Clicking the intro thumbnail then tries to activate it
            // as the hero, producing a broken / missing hero image.
            $versions = $site?->heroVersions()
                ->where('page_type', $page)
                ->where('slot', 'hero')
                ->orderByDesc('id')
                ->get() ?? collect();

            // Intro slot — separate carousel + regen path. Active row
            // is the one currently rendered in the service-page about
            // section; others are prior versions the admin can revert to.
            $introVersions = $site?->heroVersions()
                ->where('page_type', $page)
                ->where('slot', 'intro')
                ->orderByDesc('id')
                ->get() ?? collect();
            $activeIntro = $introVersions->firstWhere('is_active', true);

            $bandVersions = $site?->heroVersions()
                ->where('page_type', $page)
                ->where('slot', 'band')
                ->orderByDesc('id')
                ->get() ?? collect();
            $activeBand = $bandVersions->firstWhere('is_active', true);

            // Inactive versions split into "recent" (uniform-size selection
            // grid) and "older" (disclosure-hidden, with filter chips for
            // revert / preserved-only browsing).
            //
            // Sort by id DESC (recency proxy — id is monotonic) so the
            // newest fan-out outputs always surface first, even if their
            // quality score is lower than a pre-personalise relic from
            // an earlier era. Score-based sorting was a footgun: it hid
            // recent face-aware variants behind older un-faced rows.
            //
            // Active row is rendered by the surrounding hero block — it
            // doesn't appear in these lists.
            $inactive = $versions
                ->where('is_active', false)
                ->sortByDesc('id')
                ->values();

            $recentVariants = $inactive->take(6);
            $olderPreserved = $inactive->slice(6)->values();

            // Placement keys (text_zone, bg_position_y, overlay_*, text_color)
            // live under hero_images[page][placement] when the snapshot was
            // freshly built from BuildPreviewJob (matches HeroVersion.placement
            // shape). Read nested first; fall back to top-level for legacy
            // entries where mutateActiveHeroPlacement merged into the wrong
            // depth.
            $heroPlacement = is_array($hero) ? (is_array($hero['placement'] ?? null) ? $hero['placement'] : []) : [];

            $tabs[$page] = [
                'hero_url' => is_array($hero) ? ($hero['url'] ?? null) : $hero,
                'hero_model' => is_array($hero) ? ($hero['model'] ?? null) : null,
                'hero_prompt' => is_array($hero) ? ($hero['prompt'] ?? null) : null,
                'bg_position_y' => $heroPlacement['bg_position_y'] ?? (is_array($hero) ? ($hero['bg_position_y'] ?? null) : null),
                'hero_versions' => $versions,
                'hero_recent_variants' => $recentVariants,
                'hero_older_preserved' => $olderPreserved,
                'intro_url' => $activeIntro?->url,
                'intro_versions' => $introVersions,
                'band_url' => $activeBand?->url,
                'band_versions' => $bandVersions,
                // Page status drives the per-page dropdown. Falls back to
                // Published if the row hasn't been backfilled (shouldn't happen
                // post-migration, but belt-and-braces).
                'page_status' => $gpRow?->status?->value ?? 'published',
                'hero_source' => $heroSource,
                'sections' => $sections,
                'seo' => $content['seo'] ?? [],
                'geo' => $content['geo'] ?? [],
                // 'projects' is its own archetype, not a service page.
                // Service pages are auto-generated per service offering;
                // 'projects' is the dedicated portfolio page.
                'is_service' => ! in_array($page, $corePages, true) && $page !== 'projects',
                'nav_label' => $gpRow?->nav_label ?? ucwords(str_replace('-', ' ', $page)),
                'page_id' => $gpRow?->id,
                'layout_preset_key' => $gpRow?->layout_preset_key,
                'page_archived' => $gpRow?->archived_at !== null,
                'revision_id' => $gpRow?->draft_revision_id ?? $gpRow?->published_revision_id,
                // True when the displayed hero is the __shared_service_hero
                // fallback (core page lacks its own HeroVersion). Staff see a badge
                // + a one-click regen button.
                'hero_is_fallback' => $heroIsFallback,
            ];
        }

        // Available services from the home page's services items — for the "add page" modal
        $scope = $site?->businessProfile?->profile_data['geo']['scope'] ?? 'local';
        $availableServices = [];
        foreach (($allPages['home']['services']['items'] ?? []) as $item) {
            $title = $item['title'] ?? '';
            if ($title !== '') {
                $slug = $this->servicePageSlug($title, $site?->location ?? '', $scope);
                // A service counts as existing if any reasonable slug variant is already a page.
                $variants = [$slug, Str::slug($title.'-'.($site?->location ?? '')), Str::slug($title)];
                $exists = (bool) array_filter($variants, fn ($v) => array_key_exists($v, $allPages));
                $availableServices[] = ['title' => $title, 'slug' => $slug, 'exists' => $exists];
            }
        }

        $heroSizes = $snapshot['hero_sizes'] ?? [];

        $heroSizes = $snapshot['hero_sizes'] ?? [];
        $pendingJobs = cache()->get("site:{$this->siteId}:pending_jobs", 0);

        // Deferred services from the pipeline's service-page cap (Contract 1).
        // Already sorted by confidence at write time; template reads in order.
        $pendingServices = is_array($site?->admin_suggestions['pending_services'] ?? null)
            ? $site->admin_suggestions['pending_services']
            : [];

        // Projects page state for the "Add Projects Page" affordance.
        // The button shows whenever the page is missing — archetype
        // recommendation is a hint, not a hard gate, so staff can
        // trigger on any vertical for testing / per-customer requests.
        $hasProjectsPage = $site?->generatedPages()->where('page_type', 'projects')->exists() ?? false;
        $projectsRecommended = $site
            ? app(\App\Services\Site\ArchetypeComposer::class)->includesProjectsPage($site)
            : false;

        return [
            'tabs' => $tabs,
            'heroImages' => $heroImages,
            'heroSizes' => $heroSizes,
            'availableServices' => $availableServices,
            'availableModels' => [],
            'availableCompositions' => self::AVAILABLE_HERO_COMPOSITIONS,
            'pendingJobs' => $pendingJobs,
            'pendingServices' => $pendingServices,
            'site' => $site,
            'hasProjectsPage' => $hasProjectsPage,
            'projectsRecommended' => $projectsRecommended,
        ];
    }
};
?>

@php
    /** @see viewData() — with()-replacement, extracted to keep the original template variable names. */
    $__viewData = $this->viewData;
    extract($__viewData);
@endphp

<div @if ($pendingJobs > 0) wire:poll.5s @endif
     x-data="{ activePage: '{{ $activeTab }}' }"
     x-init="
        $watch('activePage', v => {
            window.dispatchEvent(new CustomEvent('pages-help-page-changed', { detail: v }));
        });
        window.dispatchEvent(new CustomEvent('pages-help-page-changed', { detail: activePage }));
     "
>
    @if ($pendingJobs > 0)
        <div class="sticky top-0 z-30 flex items-center gap-3 mb-4 p-3 rounded-lg border-2 border-amber-300 bg-amber-50 shadow-sm dark:border-amber-600 dark:bg-amber-900/30 animate-pulse">
            <span class="relative flex h-3 w-3">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
                <span class="relative inline-flex rounded-full h-3 w-3 bg-amber-500"></span>
            </span>
            <svg class="w-5 h-5 text-amber-600 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
            </svg>
            <span class="text-sm font-medium text-amber-800 dark:text-amber-200">
                {{ $pendingJobs }} {{ \Illuminate\Support\Str::plural('job', $pendingJobs) }} running — this page auto-refreshes every 5s.
            </span>
        </div>
    @endif
    @if (session('page-mgr-msg'))
        <flux:callout variant="success" icon="check-circle" class="mb-4">
            {{ session('page-mgr-msg') }}
        </flux:callout>
    @endif
    @if (session('page-mgr-err'))
        <flux:callout variant="danger" icon="exclamation-triangle" class="mb-4">
            {{ session('page-mgr-err') }}
        </flux:callout>
    @endif

    @if (! empty($pendingServices))
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-700 dark:bg-amber-900/20">
            <div class="flex items-start justify-between gap-3 mb-3">
                <div>
                    <div class="text-sm font-semibold text-amber-900 dark:text-amber-200">
                        Suggested service pages
                    </div>
                    <p class="text-xs text-amber-800/80 dark:text-amber-300 mt-1">
                        The profiler flagged these services but the pipeline capped the initial build. Click one to generate its page now.
                    </p>
                </div>
                <span class="inline-flex shrink-0 items-center gap-1 rounded-md bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-800 dark:bg-amber-900/60 dark:text-amber-200">
                    {{ count($pendingServices) }} pending
                </span>
            </div>
            <div class="flex flex-wrap gap-2">
                @foreach ($pendingServices as $i => $suggestion)
                    @php
                        // Pre-compute to dodge nested-quote hell in the
                        // component attribute (title= would have to wrap a
                        // value containing both " and ' otherwise).
                        $suggestionName = $suggestion['name'] ?? 'this service';
                        $suggestionTitle = 'Generate "'.$suggestionName.'" page?';
                    @endphp
                    @unless ($demo)
                    <x-confirm-button
                        name="generate-service-{{ $i }}"
                        :title="$suggestionTitle"
                        description="A new service page will be generated and added to your site — this counts against your AI quota."
                        confirmLabel="Generate"
                        confirmVariant="primary"
                        wire:click="addSuggestedService({{ $i }})">
                        <x-slot:trigger>
                            <button type="button"
                                    class="group inline-flex items-center gap-2 rounded-full border border-amber-300 bg-white px-3 py-1.5 text-xs font-medium text-amber-900 shadow-sm transition-all hover:bg-amber-100 hover:border-amber-400 dark:border-amber-600 dark:bg-neutral-900 dark:text-amber-200 dark:hover:bg-amber-900/40"
                                    title="Confidence: {{ number_format((float) ($suggestion['confidence'] ?? 0), 2) }}">
                                <svg class="w-3.5 h-3.5 opacity-70 group-hover:opacity-100" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                                </svg>
                                {{ $suggestion['name'] ?? 'Unnamed service' }}
                            </button>
                        </x-slot:trigger>
                    </x-confirm-button>
                    @endunless
                @endforeach
            </div>
        </div>
    @endif

    {{-- Watermark toggle moved to Site Details (it's a site-wide
         setting; was confusing as a per-page tab control). See
         resources/views/livewire/site/watermark-toggle.blade.php --}}



    {{-- Page selector: Alpine x-show for instant paint; switchTab commits activeTab --}}
    <div class="flex items-center gap-3 mb-6">
        <select x-model="activePage"
                wire:change="switchTab($event.target.value)"
                class="text-sm rounded-md border border-zinc-200 bg-white pl-3 pr-8 py-2 dark:bg-neutral-900 dark:border-neutral-700 min-w-[220px]">
            @foreach ($tabs as $tabKey => $tabData)
                <option value="{{ $tabKey }}">
                    {{ $tabData['nav_label'] ?? ucwords(str_replace('-', ' ', $tabKey)) }}
                    {{ ($tabData['is_service'] ?? false) ? '(service)' : '' }}
                </option>
            @endforeach
        </select>
        <flux:button size="sm" variant="ghost" wire:click="openAddPage" icon="plus">
            Add Page
        </flux:button>

        {{-- Add Projects Page — appears when the site has no projects/portfolio
             page yet. Shown as a "Recommended" pill when the archetype
             composer agrees the vertical typically has portfolio work
             (trades, builders, designers) and as a plain "Add" when it
             doesn't (helpful for staff who want to test the surface on
             a vertical that wouldn't normally include one). Build-time
             auto-dispatch is gated behind PREVIEW_PROJECTS_PAGE_AT_BUILD
             so this button is the canonical trigger. --}}
        @if (! $hasProjectsPage)
            @unless ($demo)
            <flux:button
                size="sm"
                variant="ghost"
                icon="folder-plus"
                wire:click="createProjectsPage"
                wire:loading.attr="disabled"
                wire:target="createProjectsPage"
                title="{{ $projectsRecommended ? 'Recommended for this vertical — adds a portfolio page' : 'Adds a portfolio page. Not typical for this vertical but allowed.' }}">
                <span wire:loading.remove wire:target="createProjectsPage">
                    Add Projects Page
                    @if ($projectsRecommended)
                        <span class="ml-1 inline-flex items-center rounded-full bg-amber-100 dark:bg-amber-900/40 px-1.5 py-0.5 text-[10px] font-semibold text-amber-900 dark:text-amber-200">Recommended</span>
                    @endif
                </span>
                <span wire:loading wire:target="createProjectsPage">Generating…</span>
            </flux:button>
            @endunless
        @endif
    </div>

    {{-- Add page modal --}}
    @if ($showAddPage)
        <div class="mb-6 rounded-xl border-2 border-dashed border-zinc-300 dark:border-neutral-600 p-6 bg-zinc-50/50 dark:bg-neutral-800/50">
            <h4 class="text-sm font-semibold text-zinc-700 dark:text-zinc-200 mb-4">Add Service Page</h4>

            @if (count($availableServices) > 0)
                <div class="mb-4">
                    <label class="text-xs font-medium text-zinc-500 dark:text-zinc-400 block mb-2">From your services</label>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($availableServices as $svc)
                            <button type="button"
                                    wire:click="toggleService('{{ $svc['title'] }}')"
                                    {{ $svc['exists'] ? 'disabled' : '' }}
                                    class="text-xs px-3 py-1.5 rounded-full border transition-colors cursor-pointer
                                           {{ $svc['exists'] ? 'bg-zinc-100 text-zinc-400 border-zinc-200 cursor-not-allowed dark:bg-neutral-800 dark:text-zinc-500 dark:border-neutral-700' : (in_array($svc['title'], $newPageServices) ? 'bg-zinc-900 text-white border-zinc-900 dark:bg-white dark:text-zinc-900' : 'bg-white text-zinc-700 border-zinc-300 hover:border-zinc-500 dark:bg-neutral-900 dark:text-zinc-300 dark:border-neutral-600') }}">
                                {{ $svc['title'] }}
                                @if ($svc['exists'])
                                    ✓
                                @endif
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="mb-4">
                <label class="text-xs font-medium text-zinc-500 dark:text-zinc-400 block mb-1">Or enter a custom service</label>
                <input type="text" wire:model="newPageCustom"
                       class="w-full max-w-sm text-sm rounded-md border border-zinc-200 bg-white px-3 py-2 dark:bg-neutral-900 dark:border-neutral-700"
                       placeholder="e.g. Emergency Plumbing" />
            </div>

            @unless ($demo)
            <div class="flex items-center gap-4 mb-4">
                <label class="flex items-center gap-2 text-xs text-zinc-600 cursor-pointer">
                    <input type="checkbox" wire:model="newPageHero" class="rounded border-zinc-300" />
                    Generate hero image
                </label>
            </div>
            @endunless

            <div class="flex items-center gap-3">
                @unless ($demo)
                <flux:button size="sm" variant="primary" wire:click="addServicePages" icon="plus"
                             wire:loading.attr="disabled" wire:target="addServicePages">
                    <span wire:loading.remove wire:target="addServicePages">Add {{ count($newPageServices) + (trim($newPageCustom) !== '' ? 1 : 0) }} service page(s) live</span>
                    <span wire:loading wire:target="addServicePages">Queuing…</span>
                </flux:button>
                @endunless
                <flux:button size="sm" variant="ghost" wire:click="$set('showAddPage', false)">
                    Cancel
                </flux:button>
            </div>
        </div>
    @endif

    {{-- Active tab content --}}
    @foreach ($tabs as $page => $tab)
        <div x-show="activePage === '{{ $page }}'" x-cloak>
            {{-- Page header with nav label edit --}}
            <div class="flex items-center justify-between mb-4 gap-4">
                <span class="text-xs font-medium text-zinc-400 uppercase tracking-wide">
                    {{ ($tab['is_service'] ?? false) ? 'Service Page' : 'Core Page' }} · /{{ $page }}
                </span>
                <div class="flex items-center gap-3">
                    {{-- Status dropdown — explicit admin intent.
                         Published = live next publish + in nav
                         Draft     = hidden from next publish + nav
                         Archived  = retired, hidden from page manager by default --}}
                    @php
                        $currentStatus = $tab['page_status'] ?? 'published';
                        $statusColour = match ($currentStatus) {
                            'published' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
                            'draft' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
                            'archived' => 'bg-zinc-100 text-zinc-500 dark:bg-neutral-800 dark:text-zinc-400',
                            default => 'bg-zinc-100 text-zinc-500',
                        };
                    @endphp
                    <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                        <button type="button" x-on:click="open = !open"
                                class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium {{ $statusColour }} hover:opacity-80 cursor-pointer">
                            <span class="size-1.5 rounded-full bg-current"></span>
                            {{ ucfirst($currentStatus) }}
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </button>
                        <div x-show="open" x-cloak x-transition.opacity
                             class="absolute right-0 mt-1 w-44 rounded-md border border-zinc-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 shadow-lg z-20">
                            <button type="button"
                                    @class([
                                        'w-full text-left px-3 py-2 text-xs hover:bg-zinc-50 dark:hover:bg-neutral-800',
                                        'font-semibold text-emerald-700 dark:text-emerald-300' => $currentStatus === 'published',
                                    ])
                                    wire:click="updatePageStatus('{{ $page }}', 'published')"
                                    x-on:click="open = false">
                                <span class="inline-flex items-center gap-2">
                                    <span class="size-1.5 rounded-full bg-emerald-500"></span> Published
                                </span>
                                <span class="block text-xs text-zinc-400 mt-0.5">Live on next publish + in nav</span>
                            </button>
                            <button type="button"
                                    @class([
                                        'w-full text-left px-3 py-2 text-xs hover:bg-zinc-50 dark:hover:bg-neutral-800 border-t border-zinc-100 dark:border-neutral-800',
                                        'font-semibold text-amber-700 dark:text-amber-300' => $currentStatus === 'draft',
                                    ])
                                    wire:click="updatePageStatus('{{ $page }}', 'draft')"
                                    x-on:click="open = false">
                                <span class="inline-flex items-center gap-2">
                                    <span class="size-1.5 rounded-full bg-amber-500"></span> Draft
                                </span>
                                <span class="block text-xs text-zinc-400 mt-0.5">Hidden from public + nav</span>
                            </button>
                            <button type="button"
                                    @class([
                                        'w-full text-left px-3 py-2 text-xs hover:bg-zinc-50 dark:hover:bg-neutral-800 border-t border-zinc-100 dark:border-neutral-800',
                                        'font-semibold text-zinc-600 dark:text-zinc-300' => $currentStatus === 'archived',
                                    ])
                                    @if ($currentStatus === 'published')
                                        wire:confirm="Archive this page? It will be removed from your live site on next publish (recoverable from the archive filter)."
                                    @endif
                                    wire:click="updatePageStatus('{{ $page }}', 'archived')"
                                    x-on:click="open = false">
                                <span class="inline-flex items-center gap-2">
                                    <span class="size-1.5 rounded-full bg-zinc-400"></span> Archived
                                </span>
                                <span class="block text-xs text-zinc-400 mt-0.5">Retired, retained for recovery</span>
                            </button>
                        </div>
                    </div>
                    @if (($tab['page_id'] ?? null) && config('site.use_versioned_renderer'))
                        @php
                            $hasPublicHost = isset($site) && ($site->preview_domain || ($site->custom_domain && $site->custom_domain_status === 'active'));
                        @endphp
                        @if ($hasPublicHost)
                            <flux:button size="xs" variant="ghost" icon="pencil-square"
                                         :href="route('site.editor-shell', ['site' => $siteId, 'page' => $tab['page_id']])"
                                         target="_blank">
                                Edit Site
                            </flux:button>
                        @endif
                    @endif

                    @if ($tab['is_service'] ?? false)
                        <x-confirm-button
                            name="delete-service-page-{{ $page }}"
                            title="Delete this service page?"
                            description="The page will be permanently removed and cannot be recovered."
                            confirmLabel="Delete"
                            confirmVariant="danger"
                            wire:click="deleteServicePage('{{ $page }}')">
                            <x-slot:trigger>
                                <button type="button"
                                        class="text-xs text-red-500 hover:text-red-700 underline cursor-pointer">
                                    Delete page
                                </button>
                            </x-slot:trigger>
                        </x-confirm-button>
                    @endif
                </div>
            </div>

            {{-- Hero section --}}
            @php
                // Same nested-first / top-level-fallback pattern as the tab
                // builder above — text_zone lives under [placement][text_zone]
                // when the snapshot was freshly built; legacy entries may
                // have it top-level from before the placement-write fix.
                $heroEntry = is_array($heroImages[$page] ?? null) ? $heroImages[$page] : [];
                $heroEntryPlacement = is_array($heroEntry['placement'] ?? null) ? $heroEntry['placement'] : [];
                $currentZone = $tab['hero_url']
                    ? ($heroEntryPlacement['text_zone'] ?? $heroEntry['text_zone'] ?? 'middle-left')
                    : null;
            @endphp
            @php
                // cropY is the raw CSS object-position-Y value (0–100):
                // 0 = image-top aligned with container-top, 100 = image-
                // bottom aligned with container-bottom, 50 = centred. The
                // slider semantics MUST match CSS so admins can slide all
                // the way to 0 to crop to the top of the image. Earlier
                // this used a band-centre model that clamped min/max to
                // bandPct/2, blocking 0 entirely.
                $thumbIsHome = $page === 'home';
                $thumbBandPct = (int) str_replace('vh', '', $thumbIsHome ? ($heroSizes['home'] ?? '55vh') : ($heroSizes['inner'] ?? '35vh'));
                $thumbCropY = max(0, min(100, $tab['bg_position_y'] ?? 50));
            @endphp
            @php
                // Pages that don't render an "About this service" intro
                // band: home (full-width hero + sections directly below)
                // and projects (its own portfolio archetype). Every other
                // page surfaces the intro UI unconditionally — its
                // placeholder + Regenerate Intro button handles the
                // "no intro yet" state, so service / about / contact
                // pages can back-fill missing intros without dropping
                // into tinker.
                // home now generates its own intro alongside the hero (BuildPreviewJob
                // dual-prompt path) and the Service Area section uses it as a
                // visually distinct shot from the hero. So home gets the intro
                // panel too. contact + projects still excluded — contact rarely
                // surfaces an inline image, projects has its own gallery flow.
                $hasIntro = ! in_array($page, ['contact', 'projects'], true);
                $hasBand = ($tab['is_service'] ?? false) || $page === 'about';

                // Pill-tab definition. Projects page has the richer
                // settings/gallery/case-studies surface; everything else
                // shares the simpler images / sections pair (Form is a
                // section inside the Sections pill for now — no
                // standalone form pill until the editor is built).
                $pillTabs = $page === 'projects'
                    ? ['images' => 'Images', 'settings' => 'Settings', 'gallery' => 'Gallery', 'case_studies' => 'Case Studies']
                    : ['sections' => 'Sections', 'images' => 'Images'];

                // Persist the pill selection per (site, page) so a refresh
                // lands on the same pill. JSON-encode the valid keys so the
                // Alpine init can validate the saved value (a stale
                // 'gallery' from a projects page wouldn't be valid on a
                // service page) and fall back to 'images'.
                $pillTabKey = 'siteworks.pageTab.'.$siteId.'.'.$page;
                $pillTabValidJson = json_encode(array_keys($pillTabs));

                // Home page model is already on $tabs['home'] from viewData —
                // no per-row query. Used to show the legacy per-page override
                // on Home only when generated_pages.layout_preset_key is set.
                $homeHasOverride = filled($tabs['home']['layout_preset_key'] ?? null);
            @endphp

            {{-- Sub-tab pills — second-level hierarchy below the
                 page-tab strip at the top. High-contrast pop on the
                 dark background: amber active state matches the
                 site-wide toggle accent so navigation reads in the
                 same vocabulary as the action elements. Hollow
                 zinc-700-bordered inactive state with zinc-800 hover
                 fill keeps clickable affordance without competing
                 with the active pill. Fully rounded to differentiate
                 from the squared content cards. --}}
            @php $defaultPill = array_key_first($pillTabs); @endphp
            <div x-data="{
                    pageTab: (() => {
                        const saved = localStorage.getItem('{{ $pillTabKey }}');
                        const valid = {{ $pillTabValidJson }};
                        return valid.includes(saved) ? saved : '{{ $defaultPill }}';
                    })(),
                 }"
                 x-init="$watch('pageTab', v => localStorage.setItem('{{ $pillTabKey }}', v))"
                 class="mb-6">
                <div class="flex flex-wrap gap-3 mb-4">
                    @foreach ($pillTabs as $key => $label)
                        <button type="button" x-on:click="pageTab = '{{ $key }}'"
                                :class="pageTab === '{{ $key }}'
                                    ? 'bg-amber-500 text-zinc-900 border-amber-500 shadow-sm'
                                    : 'bg-transparent text-zinc-500 dark:text-zinc-400 border-zinc-300 dark:border-zinc-700 hover:bg-zinc-100 dark:hover:bg-zinc-800 hover:text-zinc-800 dark:hover:text-zinc-100'"
                                class="text-xs font-semibold px-4 py-1.5 rounded-full border transition-colors cursor-pointer">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>

            <div x-show="pageTab === 'images'" x-cloak>
            @if (($tab['page_id'] ?? null) && ! ($tab['page_archived'] ?? false) && $page === $activeTab)
                @php
                    $imageSlots = [
                        'hero' => 'Hero',
                        'intro' => 'Intro',
                        'band' => 'Band',
                        'band_2' => 'Band 2',
                        'band_3' => 'Band 3',
                    ];
                @endphp
                <div class="mb-6" data-image-slot-pickers>
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400 mb-3">Image slots</h4>
                    <div class="flex flex-wrap gap-2 mb-3">
                        @foreach ($imageSlots as $slotKey => $slotLabel)
                            <button type="button" wire:click="selectImageSlot('{{ $slotKey }}')"
                                    class="text-xs font-medium px-3 py-1 rounded-full transition-colors cursor-pointer {{ $imageSlot === $slotKey
                                        ? 'bg-zinc-900 dark:bg-white text-white dark:text-zinc-900'
                                        : 'bg-zinc-100 dark:bg-neutral-800 text-zinc-600 dark:text-zinc-400 hover:bg-zinc-200 dark:hover:bg-neutral-700' }}">
                                {{ $slotLabel }}
                            </button>
                        @endforeach
                    </div>
                    <livewire:image-slot-picker
                        :site-id="$siteId"
                        :page-id="$tab['page_id']"
                        :slot="$imageSlot"
                        :key="'image-slot-'.$tab['page_id'].'-'.$imageSlot"
                        lazy.bundle />
                </div>
            @endif
            <div class="mb-6" x-data="{ cropModal: false, cropY: {{ $thumbCropY }} }">
                <h4 class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400 mb-3">Hero Image</h4>

                {{-- Hero block (everything from Hero Source selector through
                     version history) — always visible; intro stacks below. --}}
                <div>
                @if ($tab['is_service'] ?? false)
                    <div class="flex items-center justify-between gap-3 p-3 rounded-lg border border-zinc-200 dark:border-neutral-700 mb-3">
                        <div>
                            <span class="text-sm font-medium text-zinc-700 dark:text-zinc-200">Hero Source</span>
                            <span class="text-xs text-zinc-400 ml-2">
                                {{ ($tab['hero_source'] ?? 'shared') === 'shared' ? 'Uses the site-wide shared hero' : 'Uses a page-specific hero when available' }}
                            </span>
                        </div>
                        <select wire:change="updateHeroSource('{{ $page }}', $event.target.value)"
                                aria-label="Hero source for {{ $page }} page"
                                class="text-xs rounded-md border border-zinc-200 bg-white dark:bg-neutral-900 dark:border-neutral-700 pl-2 pr-8 py-1.5">
                            <option value="shared" @selected(($tab['hero_source'] ?? 'shared') === 'shared')>Shared</option>
                            <option value="dedicated" @selected(($tab['hero_source'] ?? 'shared') === 'dedicated')>Dedicated</option>
                        </select>
                    </div>
                @endif

                {{-- Fallback notice when core page is showing the shared
                     service hero because no own-page HeroVersion exists. Staff see
                     what the live renderer serves (not blank) and can trigger a
                     dedicated regen with one click. --}}
                @if ($tab['hero_is_fallback'] ?? false)
                    <div class="flex items-center justify-between gap-3 p-3 mb-3 rounded-lg border border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-900/20">
                        <div class="flex items-center gap-2 min-w-0">
                            <flux:icon.exclamation-triangle class="w-4 h-4 text-amber-500 shrink-0" />
                            <span class="text-xs text-amber-700 dark:text-amber-300 font-medium">
                                Using site-wide hero — this page has no dedicated hero image yet.
                            </span>
                        </div>
                        @unless ($demo)
                        <flux:button
                            size="xs"
                            variant="ghost"
                            icon="arrow-path"
                            wire:click="regenerateHero('{{ $page }}')"
                            wire:loading.attr="disabled"
                            wire:target="regenerateHero('{{ $page }}')"
                            class="shrink-0"
                        >
                            <span wire:loading.remove wire:target="regenerateHero('{{ $page }}')">Regenerate</span>
                            <span wire:loading wire:target="regenerateHero('{{ $page }}')">Generating…</span>
                        </flux:button>
                        @endunless
                    </div>
                @endif

                <div class="flex flex-col md:flex-row gap-4 items-start">
                    <div class="w-full md:w-1/2 aspect-video rounded-lg overflow-hidden border border-zinc-100 bg-zinc-50 flex items-center justify-center relative group cursor-pointer"
                         @if ($tab['hero_url']) x-on:click="cropModal = true" @endif>
                        @if ($tab['hero_url'])
                            <img src="{{ $tab['hero_url'] }}" alt="{{ $page }} hero"
                                 class="w-full h-full object-cover"
                                 style="object-position: center {{ $thumbCropY }}%" />
                            <div class="absolute inset-x-0 top-0 bg-black/50 pointer-events-none" style="height: {{ max(0, $thumbCropY - $thumbBandPct / 2) }}%"></div>
                            <div class="absolute inset-x-0 bottom-0 bg-black/50 pointer-events-none" style="height: {{ max(0, 100 - $thumbCropY - $thumbBandPct / 2) }}%"></div>
                            <div class="absolute inset-0 bg-black/0 group-hover:bg-black/20 transition-all flex items-center justify-center">
                                <span class="text-white text-xs font-medium opacity-0 group-hover:opacity-100 transition-opacity drop-shadow-md">Adjust Crop</span>
                            </div>
                        @else
                            <span class="text-xs text-zinc-400">no hero yet</span>
                        @endif
                    </div>

                    {{-- Crop position modal --}}
                    @if ($tab['hero_url'])
                        <template x-teleport="body">
                            <div x-show="cropModal" x-cloak x-transition.opacity
                                 class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
                                 x-on:keydown.escape.window="cropModal = false">
                                <div class="bg-white dark:bg-neutral-900 rounded-xl shadow-2xl w-full max-w-2xl overflow-hidden"
                                     x-on:click.away="cropModal = false"
                                     x-trap.noscroll="cropModal">
                                    <div class="flex items-center justify-between px-5 py-3 border-b border-zinc-200 dark:border-neutral-700">
                                        <h3 class="text-sm font-semibold text-zinc-800 dark:text-zinc-100">Adjust Vertical Crop — {{ ucwords(str_replace('-', ' ', $page)) }}</h3>
                                        <button type="button" x-on:click="cropModal = false" class="text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200 cursor-pointer">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                        </button>
                                    </div>
                                    <div class="p-5 space-y-4">
                                        @php
                                            $isHome = $page === 'home';
                                            $cropHeight = $isHome ? ($heroSizes['home'] ?? '55vh') : ($heroSizes['inner'] ?? '35vh');
                                            $bandPct = (int) str_replace('vh', '', $cropHeight);
                                        @endphp
                                        {{-- Full image with crop band overlay.
                                             cropY is raw CSS object-position-Y (0–100). The visible band
                                             starts at `cropY * (100 - bandPct) / 100` and is bandPct%
                                             tall — same math the rendered hero uses. At cropY=0 the band
                                             sits at image top; at cropY=100 it sits at image bottom. --}}
                                        <div class="relative rounded-lg overflow-hidden border border-zinc-200 dark:border-neutral-700 mx-auto">
                                            <img src="{{ $tab['hero_url'] }}" alt="full image" class="w-full block" />
                                            {{-- Dark overlay above visible band --}}
                                            <div class="absolute inset-x-0 top-0 bg-black/60 transition-all pointer-events-none"
                                                 x-bind:style="'height: ' + (cropY * (100 - {{ $bandPct }}) / 100) + '%'"></div>
                                            {{-- Dark overlay below visible band --}}
                                            <div class="absolute inset-x-0 bottom-0 bg-black/60 transition-all pointer-events-none"
                                                 x-bind:style="'height: ' + ((100 - cropY) * (100 - {{ $bandPct }}) / 100) + '%'"></div>
                                            {{-- Top edge line --}}
                                            <div class="absolute inset-x-0 h-px bg-white/80 pointer-events-none transition-all"
                                                 x-bind:style="'top: ' + (cropY * (100 - {{ $bandPct }}) / 100) + '%'"></div>
                                            {{-- Bottom edge line --}}
                                            <div class="absolute inset-x-0 h-px bg-white/80 pointer-events-none transition-all"
                                                 x-bind:style="'top: ' + (cropY * (100 - {{ $bandPct }}) / 100 + {{ $bandPct }}) + '%'"></div>
                                            {{-- Height label --}}
                                            <div class="absolute right-2 text-xs text-white/90 font-mono bg-black/50 px-1.5 py-0.5 rounded pointer-events-none"
                                                 x-bind:style="'top: ' + (cropY * (100 - {{ $bandPct }}) / 100 + {{ $bandPct }}/2) + '%'" x-text="'{{ $cropHeight }}'"></div>
                                        </div>
                                        <div class="flex items-center gap-3">
                                            <span class="text-xs text-zinc-400 w-8">Top</span>
                                            <input type="range" min="0" max="100" step="1"
                                                   x-model.number="cropY"
                                                   class="flex-1 accent-zinc-700" />
                                            <span class="text-xs text-zinc-400 w-12">Bottom</span>
                                            <span class="text-xs font-mono text-zinc-500 dark:text-zinc-400 w-10 text-right" x-text="cropY + '%'"></span>
                                        </div>
                                        <p class="text-xs text-zinc-400">Highlighted band shows the visible area at {{ $cropHeight }} ({{ $isHome ? 'homepage' : 'inner page' }})</p>
                                        <div class="flex justify-end gap-2">
                                            <flux:button size="sm" variant="ghost" x-on:click="cropModal = false">Cancel</flux:button>
                                            <flux:button size="sm" variant="primary" icon="check"
                                                         x-on:click="$wire.setHeroCropY('{{ $page }}', cropY); cropModal = false">
                                                Apply
                                            </flux:button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </template>
                    @endif
                    <div class="flex flex-col gap-3 w-full md:w-1/2">
                        {{-- Image Prompt — full width. Text position lives on
                             the hero section card in the Sections pill (it's
                             a layout concern alongside the headline copy,
                             not an image-prompting concern). --}}
                        <div class="grid grid-cols-1 gap-3 items-start">
                        {{-- Prompt editor --}}
                        <div x-data="{ promptModal: false }">
                            <div class="flex items-center justify-between mb-1">
                                <label class="text-xs text-zinc-400">Image Prompt</label>
                                <div class="flex items-center gap-2">
                                    <span wire:loading wire:target="generatePromptFromBrief" class="text-xs text-amber-600 dark:text-amber-400 animate-pulse">Generating prompt…</span>
                                    <button type="button"
                                            x-on:click="$wire.set('promptBriefPage', '{{ $page }}'); promptModal = true"
                                            wire:loading.class="hidden" wire:target="generatePromptFromBrief"
                                            class="text-xs text-amber-600 dark:text-amber-400 hover:text-amber-800 dark:hover:text-amber-300 underline underline-offset-2 cursor-pointer">
                                        New prompt
                                    </button>
                                </div>
                            </div>
                            <textarea wire:model.blur="heroPrompts.{{ $page }}" rows="6"
                                      placeholder="Leave blank to auto-generate a new prompt"
                                      class="w-full text-xs rounded-md border border-zinc-200 bg-white px-2 py-1.5 leading-relaxed dark:bg-neutral-900 dark:border-neutral-700"></textarea>

                            {{-- Brief → prompt modal --}}
                            <template x-teleport="body">
                                <div x-show="promptModal" x-cloak x-transition.opacity
                                     class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
                                     x-on:keydown.escape.window="promptModal = false">
                                    <div class="bg-white dark:bg-neutral-900 rounded-xl shadow-2xl w-full max-w-lg overflow-hidden"
                                         x-on:click.away="promptModal = false">
                                        <div class="flex items-center justify-between px-5 py-3 border-b border-zinc-200 dark:border-neutral-700">
                                            <h3 class="text-sm font-semibold text-zinc-800 dark:text-zinc-100">Describe what you want</h3>
                                            <button type="button" x-on:click="promptModal = false" class="text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200 cursor-pointer">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                            </button>
                                        </div>
                                        <div class="p-5 space-y-4">
                                            <p class="text-xs text-zinc-500 dark:text-zinc-400">Write a short description of the image you want.</p>
                                            <textarea wire:model="promptBrief" rows="3"
                                                      placeholder="e.g. A plumber fixing a boiler in a modern kitchen, warm lighting"
                                                      class="w-full text-sm rounded-md border border-zinc-200 bg-white px-3 py-2 dark:bg-neutral-900 dark:border-neutral-700"></textarea>
                                            <div class="flex justify-end gap-2">
                                                <flux:button size="sm" variant="ghost" x-on:click="promptModal = false">Cancel</flux:button>
                                                @unless ($demo)
                                                <flux:button size="sm" variant="primary" icon="sparkles"
                                                             wire:click="generatePromptFromBrief"
                                                             wire:loading.attr="disabled"
                                                             wire:target="generatePromptFromBrief"
                                                             x-on:click="promptModal = false">
                                                    <span wire:loading.remove wire:target="generatePromptFromBrief">Generate Prompt</span>
                                                    <span wire:loading wire:target="generatePromptFromBrief">Generating…</span>
                                                </flux:button>
                                                @endunless
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                        </div>{{-- /text-position + image-prompt grid --}}

                        {{-- Model selector + regenerate --}}
                        @if (($tab['is_service'] ?? false) && ($tab['hero_source'] ?? 'shared') === 'shared')
                            <div class="text-xs rounded-md border border-dashed border-zinc-300 dark:border-neutral-700 px-3 py-2 text-zinc-500 dark:text-zinc-400">
                                This service page is using the shared site hero. Switch to Dedicated before generating a page-specific hero.
                            </div>
                        @else
                            @if ($tab['hero_model'])
                                <span class="text-xs text-zinc-400 font-mono truncate" title="{{ $tab['hero_model'] }}">
                                    Model: {{ str_replace('-preview', '', $tab['hero_model']) }}
                                </span>
                            @endif
                            <select
                                wire:model="heroModels.{{ $page }}"
                                class="w-full text-xs rounded-md border border-zinc-200 bg-white dark:bg-neutral-900 dark:border-neutral-700 pl-2 pr-8 py-1.5"
                            >
                                @foreach ($availableModels as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <select
                                wire:model="heroCompositions.{{ $page }}"
                                title="POV / composition framing for the hero — overrides the auto strategy"
                                class="w-full text-xs rounded-md border border-zinc-200 bg-white dark:bg-neutral-900 dark:border-neutral-700 pl-2 pr-8 py-1.5"
                            >
                                @foreach ($availableCompositions as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @unless ($demo)
                            <flux:button
                                size="sm"
                                variant="primary"
                                icon="arrow-path"
                                wire:click="regenerateHero('{{ $page }}')"
                                wire:loading.attr="disabled"
                                wire:target="regenerateHero('{{ $page }}')"
                            >
                                <span wire:loading.remove wire:target="regenerateHero('{{ $page }}')">Regenerate Hero</span>
                                <span wire:loading wire:target="regenerateHero('{{ $page }}')">Generating…</span>
                            </flux:button>
                            <flux:button
                                size="sm"
                                variant="filled"
                                icon="sparkles"
                                wire:click="generateVariations('{{ $page }}')"
                                wire:loading.attr="disabled"
                                wire:target="generateVariations('{{ $page }}')"
                                title="Fire 3 variations + pick from candidates (no auto-activate)"
                            >
                                <span wire:loading.remove wire:target="generateVariations('{{ $page }}')">Generate variations</span>
                                <span wire:loading wire:target="generateVariations('{{ $page }}')">Dispatching…</span>
                            </flux:button>
                            @endunless

                            {{-- Agent upload — bypass AI pipeline, file goes
                                 straight to S3 + becomes the active hero.
                                 Hidden input + button trigger pattern: clicking
                                 the button opens the file picker; on selection
                                 wire:change fires uploadHero which validates
                                 + writes a HeroVersion(source=user_upload). --}}
                            <input
                                type="file"
                                id="hero-upload-{{ $page }}"
                                wire:model="heroUpload"
                                x-on:livewire-upload-finish="$wire.uploadHero('{{ $page }}', 'hero')"
                                accept="image/jpeg,image/png,image/webp"
                                class="hidden">
                            <flux:button
                                size="sm"
                                variant="ghost"
                                icon="arrow-up-tray"
                                x-on:click="document.getElementById('hero-upload-{{ $page }}').click()"
                                wire:loading.attr="disabled"
                                wire:target="uploadHero, heroUpload"
                                title="Upload your own hero image (skips AI generation, sets it active)"
                            >
                                <span wire:loading.remove wire:target="uploadHero, heroUpload">Upload</span>
                                <span wire:loading wire:target="uploadHero, heroUpload">Uploading…</span>
                            </flux:button>
                        @endif
                    </div>
                </div>

                {{-- Hero version surface — uniform-tile selection grid.
                     All recent inactive versions render at the same size
                     (no "Recommended" hero card) so the agent picks
                     visually rather than trusting a numeric score
                     ranking. Sorted by id DESC (newest first) so fresh
                     fan-out outputs surface before pre-personalise relics
                     with higher static scores.

                     The active hero is rendered above by the hero block
                     itself; it doesn't appear here.

                     Older versions (everything past the recent cap) sit
                     behind the disclosure below with composition / hero-
                     safe / preserved-only filter chips for revert.

                     Width-on-wrapper (w-[36rem]) on the hover-zoom is
                     intentional — Tailwind preflight's `img { max-width:
                     100% }` collapses the popover if width is set on
                     the img. --}}
                @if ($tab['hero_recent_variants']->isNotEmpty() || $tab['hero_older_preserved']->isNotEmpty())
                    <div class="mt-4 space-y-3"
                         wire:loading.class="opacity-60 pointer-events-none animate-pulse"
                         wire:target="activateHeroVersion">

                        @if ($tab['hero_recent_variants']->isNotEmpty())
                            <div x-data="{ showRecentVariants: false }">
                                <button type="button" x-on:click="showRecentVariants = !showRecentVariants"
                                        class="text-xs text-zinc-500 dark:text-zinc-400 hover:text-zinc-800 dark:hover:text-zinc-200 underline underline-offset-2 cursor-pointer">
                                    <span x-text="showRecentVariants ? 'Hide' : 'Show'"></span> recent variants ({{ $tab['hero_recent_variants']->count() }})
                                </button>
                                <div x-show="showRecentVariants" x-cloak class="mt-2 grid grid-cols-2 sm:grid-cols-3 gap-2">
                                    @foreach ($tab['hero_recent_variants'] as $version)
                                        @php
                                            $source = $version->source;
                                            $badgeClass = match ($source) {
                                                \App\Enums\HeroVersionSource::UserUpload => 'bg-emerald-600',
                                                \App\Enums\HeroVersionSource::FacebookImport => 'bg-cyan-600',
                                                default => 'bg-blue-600',
                                            };
                                        @endphp
                                        <div class="relative group">
                                            <div class="cursor-pointer rounded-md overflow-hidden border border-zinc-200 dark:border-neutral-700 hover:border-zinc-400 dark:hover:border-zinc-500 transition-colors"
                                                 wire:click="activateHeroVersion({{ $version->id }})"
                                                 title="Click to use this hero — {{ $version->created_at->format('d M H:i') }}">
                                                <img src="{{ $version->url }}" alt="variant" class="w-full aspect-video object-cover" />
                                                <div class="absolute top-1 right-1 px-1.5 py-0.5 rounded-full text-white text-[9px] font-bold uppercase tracking-wide {{ $badgeClass }}">
                                                    {{ $source->label() }}
                                                </div>
                                                <div class="absolute inset-0 bg-black/0 group-hover:bg-black/30 transition-all flex items-center justify-center">
                                                    <span class="text-white text-xs font-medium opacity-0 group-hover:opacity-100 transition-opacity">Use this</span>
                                                </div>
                                            </div>
                                            {{-- Hover-zoom preview. --}}
                                            <div class="hidden group-hover:block absolute z-50 left-1/2 -translate-x-1/2 bottom-full mb-2 pointer-events-none w-[36rem]">
                                                <img src="{{ $version->url }}" alt=""
                                                     class="w-full aspect-video object-cover rounded-lg shadow-2xl ring-1 ring-black/20 dark:ring-white/20" />
                                                <div class="mt-1 text-center text-[10px] font-medium text-zinc-700 dark:text-zinc-300 bg-white/90 dark:bg-neutral-900/90 rounded px-2 py-0.5 inline-block w-full">
                                                    {{ $version->created_at->format('d M Y H:i') }}
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        {{-- Older preserved (disclosure-hidden) with
                             client-side filter chips. heroFilter Alpine
                             state defaults to 'all'; chip click flips
                             which tiles are visible via x-show. No
                             Livewire round-trip per chip. --}}
                        @if ($tab['hero_older_preserved']->isNotEmpty())
                            <div x-data="{ showOlder: false, heroFilter: 'all' }">
                                <button type="button" x-on:click="showOlder = !showOlder"
                                        class="text-xs text-zinc-500 dark:text-zinc-400 hover:text-zinc-800 dark:hover:text-zinc-200 underline underline-offset-2 cursor-pointer">
                                    <span x-text="showOlder ? 'Hide' : 'Show'"></span> older versions ({{ $tab['hero_older_preserved']->count() }})
                                </button>
                                <div x-show="showOlder" x-cloak class="mt-2">
                                    <div class="flex flex-wrap gap-1.5 mb-2 text-[10px]">
                                        @php
                                            $chips = [
                                                'all' => 'All',
                                                'hero-safe' => 'Hero-safe',
                                                'detail' => 'Detail',
                                                'wide' => 'Wide',
                                                'preserved' => 'Preserved-only',
                                                'rejected' => 'Rejected',
                                            ];
                                        @endphp
                                        @foreach ($chips as $key => $label)
                                            <button type="button"
                                                    x-on:click="heroFilter = '{{ $key }}'"
                                                    :class="heroFilter === '{{ $key }}'
                                                        ? 'bg-zinc-900 dark:bg-white text-white dark:text-zinc-900'
                                                        : 'bg-zinc-100 dark:bg-neutral-800 text-zinc-600 dark:text-zinc-400 hover:bg-zinc-200 dark:hover:bg-neutral-700'"
                                                    class="px-2 py-0.5 rounded-full transition-colors cursor-pointer">
                                                {{ $label }}
                                            </button>
                                        @endforeach
                                    </div>

                                    <div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-6 gap-2">
                                        @foreach ($tab['hero_older_preserved'] as $version)
                                            @php
                                                $p = $version->placement ?? [];
                                                $scores = $p['scores'] ?? [];
                                                $compClass = $p['composition_override']
                                                    ?? $p['composition_class']
                                                    ?? '';
                                                $isHeroSafe = ($p['qa_failure_reason'] ?? null) !== 'anatomy'
                                                    && ($p['qa_failure_reason'] ?? null) !== 'hero_composition'
                                                    && (float) ($scores['overall'] ?? 0) >= 0.55;
                                                $isDetail = str_starts_with($compClass, 'detail_');
                                                $isWide = str_starts_with($compClass, 'wide_');
                                                $isPreserved = (bool) ($p['preserved'] ?? false);
                                                $isRejected = (bool) ($p['preserved_for_audit'] ?? false);
                                                $rejectReason = $p['original_failure_reason'] ?? $p['qa_failure_reason'] ?? null;
                                                $source = $version->source;
                                                $badgeClass = match ($source) {
                                                    \App\Enums\HeroVersionSource::UserUpload => 'bg-emerald-600',
                                                    \App\Enums\HeroVersionSource::FacebookImport => 'bg-cyan-600',
                                                    default => 'bg-blue-600',
                                                };
                                            @endphp
                                            <div class="relative group"
                                                 x-show="
                                                    heroFilter === 'all'
                                                    || (heroFilter === 'hero-safe' && {{ $isHeroSafe ? 'true' : 'false' }})
                                                    || (heroFilter === 'detail' && {{ $isDetail ? 'true' : 'false' }})
                                                    || (heroFilter === 'wide' && {{ $isWide ? 'true' : 'false' }})
                                                    || (heroFilter === 'preserved' && {{ $isPreserved ? 'true' : 'false' }})
                                                    || (heroFilter === 'rejected' && {{ $isRejected ? 'true' : 'false' }})
                                                 "
                                                 x-cloak>
                                                <div class="cursor-pointer rounded-md overflow-hidden border {{ $isRejected ? 'border-red-400 dark:border-red-600' : 'border-zinc-200 dark:border-neutral-700' }}"
                                                     wire:click="activateHeroVersion({{ $version->id }})"
                                                     title="Click to use this hero — {{ $version->created_at->format('d M H:i') }}{{ $isRejected ? ' (REJECTED: '.$rejectReason.')' : '' }}">
                                                    <img src="{{ $version->url }}" alt="v{{ $version->id }}" class="w-full aspect-video object-cover {{ $isRejected ? 'opacity-75' : '' }}" />
                                                    <div class="absolute top-1 right-1 px-1.5 py-0.5 rounded-full text-white text-[9px] font-bold uppercase tracking-wide {{ $badgeClass }}">
                                                        {{ $source->label() }}
                                                    </div>
                                                    @if ($isRejected)
                                                        <div class="absolute top-1 left-1 px-1.5 py-0.5 rounded-full bg-red-600 text-white text-[9px] font-bold uppercase tracking-wide"
                                                             title="Flagged: {{ $rejectReason ?? 'unknown' }}">
                                                            Rejected
                                                        </div>
                                                    @endif
                                                    <div class="absolute inset-0 bg-black/0 group-hover:bg-black/30 transition-all flex items-center justify-center">
                                                        <span class="text-white text-xs font-medium opacity-0 group-hover:opacity-100 transition-opacity">Use this</span>
                                                    </div>
                                                    <div class="absolute bottom-0 inset-x-0 bg-black/60 text-[10px] text-white px-1 py-0.5 text-center truncate">
                                                        {{ $version->created_at->format('d M H:i') }}
                                                    </div>
                                                </div>
                                                {{-- Hover-zoom preview. --}}
                                                <div class="hidden group-hover:block absolute z-50 left-1/2 -translate-x-1/2 bottom-full mb-2 pointer-events-none w-[36rem]">
                                                    <img src="{{ $version->url }}" alt=""
                                                         class="w-full aspect-video object-cover rounded-lg shadow-2xl ring-1 ring-black/20 dark:ring-white/20" />
                                                    <div class="mt-1 text-center text-[10px] font-medium text-zinc-700 dark:text-zinc-300 bg-white/90 dark:bg-neutral-900/90 rounded px-2 py-0.5 inline-block w-full">
                                                        {{ $version->created_at->format('d M Y H:i') }}
                                                    </div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>
                @endif
                </div>
                {{-- end hero tab --}}

                {{-- Intro tab — service-page "about" image. No text-zone
                     or crop adjust: intros render as-is. Prompt box mirrors
                     the hero editor so a bakery regen cannot silently fall
                     back to the generic trades close-up. --}}
                @if ($hasIntro)
                    <div class="mt-8 pt-6 border-t border-zinc-200 dark:border-neutral-700">
                        <h4 class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400 mb-3">Intro Image</h4>
                        <div class="flex flex-col md:flex-row gap-4 items-start">
                            <div class="w-full md:w-1/2 aspect-[4/3] rounded-lg overflow-hidden border border-zinc-100 bg-zinc-50 flex items-center justify-center">
                                @if ($tab['intro_url'])
                                    <img src="{{ $tab['intro_url'] }}" alt="{{ $page }} intro" class="w-full h-full object-cover" />
                                @else
                                    <span class="text-xs text-zinc-400">no intro yet</span>
                                @endif
                            </div>
                            <div class="flex flex-col gap-3 w-full md:w-1/2">
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                    @if ($page === 'home')
                                        Supporting detail photo shown in the home's <em>Service Area</em>
                                        section so it reads as a different shot from the hero above.
                                    @else
                                        Supporting detail photo shown in the service page's
                                        <em>About this service</em> section.
                                    @endif
                                </p>
                                <div>
                                    <label class="text-xs text-zinc-400">Intro prompt</label>
                                    <textarea wire:model.blur="introPrompts.{{ $page }}" rows="4"
                                              placeholder="Leave blank to auto-generate from the business type"
                                              class="w-full text-xs rounded-md border border-zinc-200 bg-white px-2 py-1.5 leading-relaxed dark:bg-neutral-900 dark:border-neutral-700"></textarea>
                                </div>
                                <flux:select
                                    size="sm"
                                    label="Image model"
                                    wire:model.live="introModels.{{ $page }}"
                                >
                                    @foreach ($availableModels as $key => $label)
                                        <flux:select.option value="{{ $key }}">{{ $label }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                                <div class="flex items-center gap-2 flex-wrap">
                                    @unless ($demo)
                                    <flux:button
                                        size="sm"
                                        variant="primary"
                                        icon="arrow-path"
                                        wire:click="regenerateIntro('{{ $page }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="regenerateIntro('{{ $page }}')"
                                    >
                                        <span wire:loading.remove wire:target="regenerateIntro('{{ $page }}')">Regenerate Intro</span>
                                        <span wire:loading wire:target="regenerateIntro('{{ $page }}')">Generating…</span>
                                    </flux:button>
                                    @endunless

                                    {{-- Agent upload for the intro slot — same
                                         pattern as hero, slot='intro'. --}}
                                    <input
                                        type="file"
                                        id="intro-upload-{{ $page }}"
                                        wire:model="heroUpload"
                                        x-on:livewire-upload-finish="$wire.uploadHero('{{ $page }}', 'intro')"
                                        accept="image/jpeg,image/png,image/webp"
                                        class="hidden">
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        icon="arrow-up-tray"
                                        x-on:click="document.getElementById('intro-upload-{{ $page }}').click()"
                                        wire:loading.attr="disabled"
                                        wire:target="uploadHero, heroUpload"
                                        title="Upload your own intro image (skips AI generation, sets it active)"
                                    >
                                        <span wire:loading.remove wire:target="uploadHero, heroUpload">Upload</span>
                                        <span wire:loading wire:target="uploadHero, heroUpload">Uploading…</span>
                                    </flux:button>
                                </div>
                            </div>
                        </div>

                        {{-- Intro version history — mirrors the hero
                             carousel but clicks activate via
                             activateIntroVersion (slot='intro' scope). --}}
                        @if ($tab['intro_versions']->count() > 1)
                            <div class="mt-3" x-data="{ showIntroVersions: false }">
                                <button type="button" x-on:click="showIntroVersions = !showIntroVersions"
                                        class="text-xs text-amber-600 dark:text-amber-400 hover:text-amber-800 dark:hover:text-amber-300 underline underline-offset-2 cursor-pointer">
                                    <span x-text="showIntroVersions ? 'Hide' : 'Show'"></span> version history ({{ $tab['intro_versions']->count() }})
                                </button>
                                <div x-show="showIntroVersions" x-cloak class="mt-2 grid grid-cols-3 sm:grid-cols-4 md:grid-cols-6 gap-2"
                                     wire:loading.class="opacity-60 pointer-events-none animate-pulse"
                                     wire:target="activateIntroVersion">
                                    @foreach ($tab['intro_versions'] as $version)
                                        @php
                                            $source = $version->source;
                                            $badgeClass = match ($source) {
                                                \App\Enums\HeroVersionSource::UserUpload => 'bg-emerald-600',
                                                \App\Enums\HeroVersionSource::FacebookImport => 'bg-cyan-600',
                                                default => 'bg-blue-600',
                                            };
                                        @endphp
                                        <div class="relative group">
                                            <div class="cursor-pointer rounded-md overflow-hidden border-2 {{ $version->is_active ? 'border-zinc-900 dark:border-white' : 'border-zinc-200 dark:border-neutral-700' }}"
                                                 @if (!$version->is_active) wire:click="activateIntroVersion({{ $version->id }})" @endif
                                                 title="{{ $version->is_active ? 'Active' : 'Click to activate' }} — {{ $version->created_at->format('d M H:i') }}">
                                                <img src="{{ $version->url }}" alt="v{{ $version->id }}" class="w-full aspect-[4/3] object-cover" />
                                                <div class="absolute top-1 right-1 px-1.5 py-0.5 rounded-full text-white text-[9px] font-bold uppercase tracking-wide {{ $badgeClass }}">
                                                    {{ $source->label() }}
                                                </div>
                                                @if (!$version->is_active)
                                                    <div class="absolute inset-0 bg-black/0 group-hover:bg-black/30 transition-all flex items-center justify-center">
                                                        <span class="text-white text-xs font-medium opacity-0 group-hover:opacity-100 transition-opacity">Use this</span>
                                                    </div>
                                                @endif
                                                <div class="absolute bottom-0 inset-x-0 bg-black/60 text-xs text-white px-1 py-0.5 text-center truncate">
                                                    {{ $version->created_at->format('d M H:i') }}
                                                </div>
                                            </div>
                                            <div class="hidden group-hover:block absolute z-50 left-1/2 -translate-x-1/2 bottom-full mb-2 pointer-events-none w-[28rem]">
                                                <img src="{{ $version->url }}" alt=""
                                                     class="w-full aspect-[4/3] object-cover rounded-lg shadow-2xl ring-1 ring-black/20 dark:ring-white/20" />
                                                <div class="mt-1 text-center text-[10px] font-medium text-zinc-700 dark:text-zinc-300 bg-white/90 dark:bg-neutral-900/90 rounded px-2 py-0.5 inline-block w-full">
                                                    {{ $version->created_at->format('d M Y H:i') }}
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                @endif

                @if ($hasBand)
                    <div class="mt-8 pt-6 border-t border-zinc-200 dark:border-neutral-700">
                        <h4 class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400 mb-3">Band image</h4>
                        <div class="flex flex-col md:flex-row gap-4 items-start">
                            <div class="w-full md:w-1/2 aspect-[4/3] rounded-lg overflow-hidden border border-zinc-100 bg-zinc-50 flex items-center justify-center">
                                @if ($tab['band_url'])
                                    <img src="{{ $tab['band_url'] }}" alt="{{ $page }} band" class="w-full h-full object-cover" />
                                @else
                                    <span class="text-xs text-zinc-400">no band image yet</span>
                                @endif
                            </div>
                            <div class="flex flex-col gap-3 w-full md:w-1/2">
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                    @if ($tab['is_service'] ?? false)
                                        Optional photo for the features checklist band. Falls back to the intro image when empty.
                                    @else
                                        Optional photo for the values band. Falls back to the hero image when empty.
                                    @endif
                                </p>
                                <div class="flex items-center gap-2 flex-wrap">
                                    <input
                                        type="file"
                                        id="band-upload-{{ $page }}"
                                        wire:model="heroUpload"
                                        x-on:livewire-upload-finish="$wire.uploadHero('{{ $page }}', 'band')"
                                        accept="image/jpeg,image/png,image/webp"
                                        class="hidden">
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        icon="arrow-up-tray"
                                        x-on:click="document.getElementById('band-upload-{{ $page }}').click()"
                                        wire:loading.attr="disabled"
                                        wire:target="uploadHero, heroUpload"
                                        title="Upload your own band image (skips AI generation, sets it active)"
                                    >
                                        <span wire:loading.remove wire:target="uploadHero, heroUpload">Upload</span>
                                        <span wire:loading wire:target="uploadHero, heroUpload">Uploading…</span>
                                    </flux:button>
                                </div>
                            </div>
                        </div>

                        @if ($tab['band_versions']->count() > 1)
                            <div class="mt-3" x-data="{ showBandVersions: false }">
                                <button type="button" x-on:click="showBandVersions = !showBandVersions"
                                        class="text-xs text-amber-600 dark:text-amber-400 hover:text-amber-800 dark:hover:text-amber-300 underline underline-offset-2 cursor-pointer">
                                    <span x-text="showBandVersions ? 'Hide' : 'Show'"></span> version history ({{ $tab['band_versions']->count() }})
                                </button>
                                <div x-show="showBandVersions" x-cloak class="mt-2 grid grid-cols-3 sm:grid-cols-4 md:grid-cols-6 gap-2"
                                     wire:loading.class="opacity-60 pointer-events-none animate-pulse"
                                     wire:target="activateBandVersion">
                                    @foreach ($tab['band_versions'] as $version)
                                        @php
                                            $source = $version->source;
                                            $badgeClass = match ($source) {
                                                \App\Enums\HeroVersionSource::UserUpload => 'bg-emerald-600',
                                                \App\Enums\HeroVersionSource::FacebookImport => 'bg-cyan-600',
                                                default => 'bg-blue-600',
                                            };
                                        @endphp
                                        <div class="relative group">
                                            <div class="cursor-pointer rounded-md overflow-hidden border-2 {{ $version->is_active ? 'border-zinc-900 dark:border-white' : 'border-zinc-200 dark:border-neutral-700' }}"
                                                 @if (!$version->is_active) wire:click="activateBandVersion({{ $version->id }})" @endif
                                                 title="{{ $version->is_active ? 'Active' : 'Click to activate' }} — {{ $version->created_at->format('d M H:i') }}">
                                                <img src="{{ $version->url }}" alt="v{{ $version->id }}" class="w-full aspect-[4/3] object-cover" />
                                                <div class="absolute top-1 right-1 px-1.5 py-0.5 rounded-full text-white text-[9px] font-bold uppercase tracking-wide {{ $badgeClass }}">
                                                    {{ $source->label() }}
                                                </div>
                                                @if (!$version->is_active)
                                                    <div class="absolute inset-0 bg-black/0 group-hover:bg-black/30 transition-all flex items-center justify-center">
                                                        <span class="text-white text-xs font-medium opacity-0 group-hover:opacity-100 transition-opacity">Use this</span>
                                                    </div>
                                                @endif
                                                <div class="absolute bottom-0 inset-x-0 bg-black/60 text-xs text-white px-1 py-0.5 text-center truncate">
                                                    {{ $version->created_at->format('d M H:i') }}
                                                </div>
                                            </div>
                                            <div class="hidden group-hover:block absolute z-50 left-1/2 -translate-x-1/2 bottom-full mb-2 pointer-events-none w-[28rem]">
                                                <img src="{{ $version->url }}" alt=""
                                                     class="w-full aspect-[4/3] object-cover rounded-lg shadow-2xl ring-1 ring-black/20 dark:ring-white/20" />
                                                <div class="mt-1 text-center text-[10px] font-medium text-zinc-700 dark:text-zinc-300 bg-white/90 dark:bg-neutral-900/90 rounded px-2 py-0.5 inline-block w-full">
                                                    {{ $version->created_at->format('d M Y H:i') }}
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            </div>{{-- /Images pill content --}}

            {{-- Settings / Gallery / Case Studies pills — projects page only.
                 Splits what was projects-page-editor.blade.php into separate
                 sub-views so each gets its own pill. --}}
            @if ($page === 'projects')
                <div x-show="pageTab === 'settings'" x-cloak>
                    @if (($tab['page_id'] ?? null) && ! ($tab['page_archived'] ?? false))
                        <div class="mb-4">
                            <livewire:page-layout-override :site-id="$siteId" :page-id="$tab['page_id']" :key="'page-layout-override-'.$tab['page_id']" lazy.bundle />
                        </div>
                    @endif
                    <livewire:projects-page-editor :site-id="$siteId" :key="'projects-editor-'.$siteId" lazy.bundle />
                </div>
                <div x-show="pageTab === 'gallery'" x-cloak>
                    @php $projectsPageId = $tab['page_id'] ?? null; @endphp
                    @if ($projectsPageId)
                        <livewire:projects-gallery-editor
                            :site-id="$siteId"
                            :page-id="$projectsPageId"
                            :key="'gallery-pill-'.$projectsPageId"
                            lazy.bundle />
                    @endif
                </div>
                <div x-show="pageTab === 'case_studies'" x-cloak>
                    @if ($projectsPageId ?? null)
                        <livewire:case-study-editor
                            :site-id="$siteId"
                            :page-id="$projectsPageId"
                            :key="'cases-pill-'.$projectsPageId"
                            lazy.bundle />
                    @endif
                </div>
            @else
            {{-- Sections pill — content + SEO + (non-home) lead form.
                 Wraps the original non-projects branch unchanged. --}}
            <div x-show="pageTab === 'sections'" x-cloak>
                @if (($tab['page_id'] ?? null) && (($tab['is_service'] ?? false) || $page === 'about' || ($page === 'home' && $homeHasOverride)))
                    <div class="mb-4">
                        @if ($page === 'home')
                            <p class="mb-2 text-xs text-zinc-500">This page overrides the site-wide homepage layout. Clear it to use the Homepage layout below.</p>
                        @endif
                        <livewire:page-layout-override :site-id="$siteId" :page-id="$tab['page_id']" :key="'page-layout-override-'.$tab['page_id']" lazy.bundle />
                    </div>
                @endif

                {{-- Contact form toggle — lives at the top of the
                     Sections pill on the Contact page until a dedicated
                     Form editor lands. --}}
                @if ($page === 'contact')
                    <div class="flex items-center justify-between p-3 rounded-lg border border-zinc-200 dark:border-neutral-700 mb-4">
                        <div>
                            <span class="text-sm font-medium text-zinc-700 dark:text-zinc-200">Contact Form</span>
                            <span class="text-xs text-zinc-400 ml-2">{{ $contactFormEnabled ? 'Shown on preview' : 'Hidden from preview' }}</span>
                        </div>
                        <button type="button"
                                wire:click="toggleContactForm"
                                class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors cursor-pointer {{ $contactFormEnabled ? 'bg-amber-500' : 'bg-zinc-300' }}">
                            <span class="inline-block h-4 w-4 rounded-full bg-white transition-transform {{ $contactFormEnabled ? 'translate-x-6' : 'translate-x-1' }}"></span>
                        </button>
                    </div>
                @endif

            {{-- Home-only layout + hero scene controls (Sections pill).
                 Site-wide About/Service layout, Logo size, and Chrome & type
                 live on the Design tab (Layout / Header pills). --}}
            @if ($page === 'home')
                <div class="mb-4 rounded-lg border border-zinc-200 dark:border-neutral-700 p-4">
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400 mb-3">Homepage Layout</h4>
                    <livewire:page-layout-settings :site-id="$siteId" :kind="'home'" :key="'page-layout-home-'.$siteId" lazy.bundle />
                </div>

                <p class="text-sm text-neutral-500 dark:text-neutral-400">Site-wide layout and header controls now live under Design → Layout and Design → Header.</p>

                {{-- Hero scene studio — multi-slide editor that backs
                     sites.home_hero_scene. Single-slide / scene-disabled sites
                     keep rendering through hero.blade.php's legacy path. --}}
                <div class="mb-4 rounded-lg border border-zinc-200 dark:border-neutral-700 p-4">
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400 mb-3">Hero Scene</h4>
                    <livewire:home-hero-scene-studio :siteId="$siteId" :key="'hhs-studio-'.$siteId" lazy.bundle />
                </div>
            @endif

            {{-- Content sections --}}
            <div>
                <div class="flex items-center justify-between mb-3">
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Content Sections</h4>
                    @unless ($demo)
                    <flux:button
                        size="xs"
                        variant="ghost"
                        icon="arrow-path"
                        wire:click="regenerateContent('{{ $page }}')"
                        wire:loading.attr="disabled"
                        wire:target="regenerateContent('{{ $page }}')"
                    >
                        <span wire:loading.remove wire:target="regenerateContent('{{ $page }}')">Regenerate</span>
                        <span wire:loading wire:target="regenerateContent('{{ $page }}')">Regenerating…</span>
                    </flux:button>
                    @endunless
                </div>
                @php
                    // Hide the legacy single-asset hero card when the home
                    // page has an active multi-slide scene — the per-slide
                    // editor in the Hero Scene panel above already owns the
                    // heading/sub/cta for every slide. Showing the old card
                    // alongside is duplicative AND lets the agent type into a
                    // field that no longer drives the rendered hero.
                    $heroSceneActiveForPage = $page === 'home'
                        && is_array($site->home_hero_scene ?? null)
                        && ! empty($site->home_hero_scene['slides']);
                @endphp
                <div class="space-y-3">
                    @foreach ($tab['sections'] as $section)
                        @continue(in_array($section['name'], ['seo', 'geo', 'meta'])
                            || ($section['name'] === 'lead_form' && $page === 'home')
                            || ($heroSceneActiveForPage && $section['name'] === 'hero'))
                        @php
                            $storedIndex = $section['__stored_index'] ?? $loop->index;
                            $sectionKey = "{$page}.{$section['name']}.{$storedIndex}";
                            $isEditing = $editing === $sectionKey;
                            $sd = $section['data'];
                        @endphp
                        <div class="rounded-lg border {{ $isEditing ? 'border-zinc-400 ring-1 ring-zinc-300' : 'border-zinc-200 dark:border-neutral-700' }} p-4" data-section-index="{{ $storedIndex }}">
                            {{-- Section header --}}
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">
                                    {{ match($section['name']) { 'faqs' => 'FAQs', 'cta' => 'CTA', 'phone_cta_strip' => 'Phone CTA Strip', 'cta_band' => 'CTA Band', 'lead_form' => 'Lead Form', 'suburb_list' => 'Areas Covered', 'service_area_card' => 'Service Area', default => \Illuminate\Support\Str::headline($section['name']) } }}
                                </span>
                                <div class="flex items-center gap-2">
                                    @if (is_array($sd) && isset($sd['items']))
                                        <span class="text-xs text-zinc-400">{{ count($sd['items']) }} items</span>
                                    @endif
                                    @if (in_array($section['name'], ['reviews', 'reviews_badge'], true))
                                        {{-- No editable copy — rating/count/stars all derive from
                                             the cached Google reviews. Offering the generic
                                             Title/Subtitle flyout here just writes keys the
                                             templates never render. On/off + display options
                                             live in the Google Reviews panel (Details tab). --}}
                                        <span class="text-xs text-zinc-400">Managed in the Google Reviews panel</span>
                                    @else
                                        <button
                                            type="button"
                                            wire:click="edit('{{ $page }}', '{{ $section['name'] }}', {{ $storedIndex }})"
                                            class="text-xs font-medium text-amber-600 dark:text-amber-400 hover:text-amber-800 dark:hover:text-amber-300 underline underline-offset-2 cursor-pointer"
                                        >
                                            {{ $isEditing ? 'Cancel' : 'Edit' }}
                                        </button>
                                    @endif
                                </div>
                            </div>

                            @if ($isEditing && is_array($sd))
                                @if (($tab['page_id'] ?? null) && ! ($tab['page_archived'] ?? false)
                                    && app(\App\Services\Site\SectionSchema::class)->variantOptionsFor($section['name']) !== [])
                                    <div class="mt-3">
                                        <livewire:section-style-picker
                                            :site-id="$siteId"
                                            :page-id="$tab['page_id']"
                                            :section-index="$storedIndex"
                                            :key="'section-style-'.$tab['page_id'].'-'.$storedIndex.'-'.($tab['revision_id'] ?? 'none')"
                                        />
                                    </div>
                                @endif
                                {{-- ===== EDIT MODE ===== --}}
                                {{-- AUDIT-04 (option b): lead_form sections on service pages use the
                                     dedicated lead-form-editor component so all 6 fields are
                                     editable (title, intro, benefits, extra_fields, submit_label).
                                     Home-page lead_form is excluded here via the @continue filter
                                     above and rendered via its own standalone block below. --}}
                                @if ($section['name'] === 'lead_form' && $page !== 'home')
                                    <div class="mt-4">
                                        <livewire:lead-form-editor
                                            :siteId="$siteId"
                                            :pageType="$page"
                                            :key="'lead-form-'.$siteId.'-'.$page"
                                            :open="true"
                                        />
                                        <div class="flex items-center gap-3 mt-4">
                                            <flux:button size="sm" variant="ghost" wire:click="edit('{{ $page }}', '{{ $section['name'] }}', {{ $storedIndex }})" icon="x-mark">
                                                Close
                                            </flux:button>
                                        </div>
                                    </div>
                                @else
                                {{-- Schema has two shapes:
                                     Legacy: heading / subheading / intro / cta_label
                                     Current: title / subtitle / intro / cta_label or button_label
                                     Title + subtitle are editable for virtually every section
                                     type (including stubs like phone_cta_strip and suburb_list
                                     whose template supplies sensible defaults when empty). Save
                                     path at saveSection() writes to whichever alias key exists
                                     or creates 'heading' / 'subheading' on stub sections. --}}
                                {{-- Template-default hints shown as placeholders
                                     when the admin hasn't overridden anything.
                                     Stub sections (phone_cta_strip, suburb_list)
                                     generate no content — their Blade templates
                                     supply these defaults from profile data at
                                     render time. Showing them here makes clear
                                     what's already rendering vs what typing
                                     into the input would override. Update
                                     alongside template changes. --}}
                                @php
                                    $templateDefaults = [
                                        'phone_cta_strip' => [
                                            'title' => '24/7 Emergency Call-Out',
                                            'subtitle' => 'Rapid response across our coverage area',
                                        ],
                                        'suburb_list' => [
                                            'title' => 'Areas we cover',
                                            'subtitle' => 'Where our team works across the region',
                                        ],
                                    ];
                                    $sectionDefaults = $templateDefaults[$section['name']] ?? [];
                                    $titlePlaceholder = $sectionDefaults['title'] ?? 'Section title';
                                    $subtitlePlaceholder = $sectionDefaults['subtitle'] ?? 'Optional supporting line';
                                @endphp
                                <div class="mt-4 space-y-4">
                                    @php
                                        // Hero sections get a two-column copy/layout row:
                                        // Heading + Subheading on the left (4/5), the
                                        // 3×3 text-position grid on the right (1/5),
                                        // so the writer can see how the words and the
                                        // overlay land together. Other sections fall
                                        // through to the standard stacked form.
                                        $isHeroSection = $section['name'] === 'hero'
                                            && $tab['hero_url']
                                            && (! ($tab['is_service'] ?? false) || ($tab['hero_source'] ?? 'shared') === 'dedicated');
                                    @endphp

                                    <div @class([
                                        'flex flex-col sm:flex-row gap-4 items-start' => $isHeroSection,
                                    ])>
                                        <div @class([
                                            'flex-1 min-w-0 space-y-4' => $isHeroSection,
                                            'space-y-4' => ! $isHeroSection,
                                        ])>
                                            {{-- Heading / Title — always shown.
                                                 Label matches the legacy key when
                                                 present so existing content that
                                                 uses 'heading' still reads right. --}}
                                            <div>
                                                <label class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400 block mb-1">
                                                    {{ array_key_exists('heading', $sd) ? 'Heading' : 'Title' }}
                                                </label>
                                                <input type="text" wire:model.blur="editHeading"
                                                       placeholder="{{ $titlePlaceholder }}"
                                                       class="w-full text-sm rounded-md border border-zinc-200 bg-white px-3 py-2 dark:bg-neutral-900 dark:border-neutral-700" />
                                                @if (isset($sectionDefaults['title']) && $editHeading === '')
                                                    <p class="mt-1 text-[11px] text-zinc-400">Leave blank to use the default above. Typing here overrides it.</p>
                                                @endif
                                            </div>

                                            {{-- Subheading / Subtitle / Intro — always shown.
                                                 Label picks the legacy alias when
                                                 present, otherwise defaults to
                                                 "Subtitle" for stubs. --}}
                                            <div>
                                                <label class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400 block mb-1">
                                                    @if (array_key_exists('subheading', $sd))
                                                        Subheading
                                                    @elseif (array_key_exists('intro', $sd))
                                                        Intro
                                                    @else
                                                        Subtitle
                                                    @endif
                                                </label>
                                                <textarea wire:model.blur="editSubheading" rows="3"
                                                          placeholder="{{ $subtitlePlaceholder }}"
                                                          class="w-full text-sm rounded-md border border-zinc-200 bg-white px-3 py-2 dark:bg-neutral-900 dark:border-neutral-700"></textarea>
                                            </div>
                                        </div>

                                        @if ($isHeroSection)
                                            <div class="shrink-0">
                                                <label class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400 block mb-1">Text Position</label>
                                                <div class="inline-grid grid-cols-3 gap-1 p-1.5 bg-zinc-100 dark:bg-neutral-800 rounded-lg">
                                                    @foreach (['top-left','top-center','top-right','middle-left','middle-center','middle-right','bottom-left','bottom-center','bottom-right'] as $zone)
                                                        @php
                                                            [$zRow, $zCol] = explode('-', $zone);
                                                            $label = strtoupper(substr($zRow, 0, 1)).strtoupper(substr($zCol, 0, 1));
                                                            $isActive = $currentZone === $zone;
                                                        @endphp
                                                        <button type="button"
                                                                wire:click="setTextZone('{{ $page }}', '{{ $zone }}')"
                                                                class="w-8 h-7 rounded text-[10px] font-bold transition-all cursor-pointer
                                                                       {{ $isActive
                                                                            ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900 shadow-sm'
                                                                            : 'bg-white text-zinc-400 hover:bg-zinc-200 dark:bg-neutral-700 dark:text-zinc-500 dark:hover:bg-neutral-600' }}"
                                                                title="{{ ucwords(str_replace('-', ' ', $zone)) }}">
                                                            {{ $label }}
                                                        </button>
                                                    @endforeach
                                                </div>
                                                <p class="mt-1 text-[11px] text-zinc-400">Where the headline + sub copy sit on the hero. Saves on click.</p>
                                            </div>
                                        @endif
                                    </div>

                                    {{-- Body — TipTap WYSIWYG bound to editBodyDoc so formatting
                                         (bold/links/lists) survives the round-trip. wire:ignore keeps
                                         Livewire morphs off TipTap's DOM; wire:key rebuilds the editor
                                         when the flyout switches sections. --}}
                                    @if (array_key_exists('body', $sd))
                                        <div wire:key="rich-body-{{ $editing }}">
                                            <label class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400 block mb-1">Body</label>
                                            <div wire:ignore x-data="richBodyEditor()"
                                                 class="rounded-md border border-zinc-200 bg-white dark:bg-neutral-900 dark:border-neutral-700">
                                                <div class="flex items-center gap-1 border-b border-zinc-100 dark:border-neutral-800 px-2 py-1.5">
                                                    <button type="button" x-on:click="run('bold')"
                                                            x-bind:class="active('bold') ? 'bg-zinc-200 dark:bg-neutral-700' : ''"
                                                            class="px-2 py-0.5 rounded text-sm font-bold text-zinc-700 dark:text-zinc-200 hover:bg-zinc-100 dark:hover:bg-neutral-800 cursor-pointer"
                                                            title="Bold">B</button>
                                                    <button type="button" x-on:click="run('italic')"
                                                            x-bind:class="active('italic') ? 'bg-zinc-200 dark:bg-neutral-700' : ''"
                                                            class="px-2 py-0.5 rounded text-sm italic text-zinc-700 dark:text-zinc-200 hover:bg-zinc-100 dark:hover:bg-neutral-800 cursor-pointer"
                                                            title="Italic">I</button>
                                                    <span class="w-px h-4 bg-zinc-200 dark:bg-neutral-700 mx-1"></span>
                                                    <button type="button" x-on:click="run('bulletList')"
                                                            x-bind:class="active('bulletList') ? 'bg-zinc-200 dark:bg-neutral-700' : ''"
                                                            class="px-2 py-0.5 rounded text-sm text-zinc-700 dark:text-zinc-200 hover:bg-zinc-100 dark:hover:bg-neutral-800 cursor-pointer"
                                                            title="Bullet list">&bull; list</button>
                                                    <button type="button" x-on:click="run('orderedList')"
                                                            x-bind:class="active('orderedList') ? 'bg-zinc-200 dark:bg-neutral-700' : ''"
                                                            class="px-2 py-0.5 rounded text-sm text-zinc-700 dark:text-zinc-200 hover:bg-zinc-100 dark:hover:bg-neutral-800 cursor-pointer"
                                                            title="Numbered list">1. list</button>
                                                    <span class="w-px h-4 bg-zinc-200 dark:bg-neutral-700 mx-1"></span>
                                                    <button type="button" x-on:click="setLink()"
                                                            x-bind:class="active('link') ? 'bg-zinc-200 dark:bg-neutral-700' : ''"
                                                            class="px-2 py-0.5 rounded text-sm underline text-zinc-700 dark:text-zinc-200 hover:bg-zinc-100 dark:hover:bg-neutral-800 cursor-pointer"
                                                            title="Add / edit link">link</button>
                                                </div>
                                                <div x-ref="host" class="text-sm text-zinc-900 dark:text-zinc-100"></div>
                                            </div>
                                        </div>
                                    @endif

                                    {{-- CTA label / Button label --}}
                                    @if (array_key_exists('cta_label', $sd) || array_key_exists('button_label', $sd))
                                        <div>
                                            <label class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400 block mb-1">CTA Button Text</label>
                                            <input type="text" wire:model.blur="editCtaLabel"
                                                   class="w-full text-sm rounded-md border border-zinc-200 bg-white px-3 py-2 dark:bg-neutral-900 dark:border-neutral-700" />
                                        </div>
                                    @endif

                                    {{-- Contact fields --}}
                                    @if (array_key_exists('phone', $sd))
                                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                            <div>
                                                <label class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400 block mb-1">Phone</label>
                                                <input type="text" wire:model.blur="editPhone"
                                                       class="w-full text-sm rounded-md border border-zinc-200 bg-white px-3 py-2 dark:bg-neutral-900 dark:border-neutral-700" />
                                            </div>
                                            <div>
                                                <label class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400 block mb-1">Email</label>
                                                <input type="text" wire:model.blur="editEmail"
                                                       class="w-full text-sm rounded-md border border-zinc-200 bg-white px-3 py-2 dark:bg-neutral-900 dark:border-neutral-700" />
                                            </div>
                                            <div>
                                                <label class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400 block mb-1">Coverage</label>
                                                <input type="text" wire:model.blur="editCoverage"
                                                       class="w-full text-sm rounded-md border border-zinc-200 bg-white px-3 py-2 dark:bg-neutral-900 dark:border-neutral-700" />
                                            </div>
                                        </div>
                                    @endif

                                    @if ($editEntryList !== '')
                                        @php
                                            $entryFieldRules = app(SectionSchema::class)->repeatableFieldRules($section['name'], $editEntryList);
                                            $portraitLabels = [
                                                'image_id' => 'Main portrait',
                                                'alternate_image_id' => 'Alternate portrait',
                                                'hover_image_id' => 'Hover portrait',
                                            ];
                                            $portraitUploadUrl = route('site.admin.portrait-upload', $siteId, false);
                                        @endphp
                                        <div>
                                            <div class="mb-2 flex items-center justify-between gap-3">
                                                <label class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Team members</label>
                                                <button type="button" wire:click="addEntry"
                                                        class="text-xs font-medium text-amber-600 underline underline-offset-2 hover:text-amber-800 dark:text-amber-400 dark:hover:text-amber-300">
                                                    + Add member
                                                </button>
                                            </div>
                                            <div class="flex flex-col gap-3" x-data="{ dragFrom: null, dragOver: null }">
                                                @foreach ($editEntries as $entryIndex => $entry)
                                                    <div wire:key="repeatable-entry-{{ $editing }}-{{ $entryIndex }}"
                                                         draggable="true"
                                                         x-on:dragstart="$event.dataTransfer.effectAllowed = 'move'; dragFrom = {{ $entryIndex }}"
                                                         x-on:dragover.prevent="$event.dataTransfer.dropEffect = 'move'; dragOver = {{ $entryIndex }}"
                                                         x-on:drop.prevent="if (dragFrom !== null && dragFrom !== {{ $entryIndex }}) { $wire.reorderEntries(dragFrom, {{ $entryIndex }}); } dragFrom = null; dragOver = null"
                                                         x-on:dragend="dragFrom = null; dragOver = null"
                                                         x-bind:class="dragOver === {{ $entryIndex }} ? 'ring-2 ring-zinc-400' : ''"
                                                         class="rounded-md bg-zinc-50 p-3 transition-all dark:bg-neutral-800">
                                                        <div class="mb-3 flex items-center justify-between gap-3">
                                                            <span class="cursor-grab text-xs font-medium text-zinc-500 active:cursor-grabbing">⋮⋮ Member {{ $entryIndex + 1 }}</span>
                                                            <button type="button" wire:click="removeEntry({{ $entryIndex }})"
                                                                    class="text-xs text-red-500 hover:text-red-700">Remove</button>
                                                        </div>
                                                        <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                                                            @foreach ($entryFieldRules as $entryField => $entryRules)
                                                                @if (($entryRules['type'] ?? null) === 'plain')
                                                                    <label class="block">
                                                                        <span class="mb-1 block text-xs text-zinc-400">{{ Str::headline($entryField) }}</span>
                                                                        <input type="text" wire:model.blur="editEntries.{{ $entryIndex }}.{{ $entryField }}"
                                                                               maxlength="{{ $entryRules['max'] ?? 500 }}"
                                                                               class="w-full rounded border border-zinc-200 bg-white px-2 py-1 text-xs dark:border-neutral-700 dark:bg-neutral-900" />
                                                                    </label>
                                                                @elseif (($entryRules['type'] ?? null) === 'image' && isset($portraitLabels[$entryField]))
                                                                    <div>
                                                                        <span class="mb-1 block text-xs text-zinc-400">{{ $portraitLabels[$entryField] }}</span>
                                                                        <div class="flex items-center gap-2">
                                                                            <label class="cursor-pointer rounded border border-zinc-200 bg-white px-2 py-1 text-xs hover:bg-zinc-100 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:bg-neutral-700">
                                                                                {{ isset($entry[$entryField]) ? 'Replace' : 'Choose image' }}
                                                                                <input type="file"
                                                                                       accept="image/jpeg,image/png,image/webp"
                                                                                       class="sr-only"
                                                                                       data-portrait-upload="{{ $portraitUploadUrl }}"
                                                                                       data-portrait-property="editEntries.{{ $entryIndex }}.{{ $entryField }}" />
                                                                            </label>
                                                                            @if (isset($entry[$entryField]))
                                                                                <span class="text-xs text-zinc-400">#{{ $entry[$entryField] }}</span>
                                                                                <button type="button" wire:click="clearEntryMedia({{ $entryIndex }}, '{{ $entryField }}')"
                                                                                        class="text-xs text-red-500 hover:text-red-700">Clear</button>
                                                                            @endif
                                                                        </div>
                                                                    </div>
                                                                @endif
                                                            @endforeach
                                                        </div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif

                                    {{-- Editable items (services/trust/values/faqs) with drag reorder + icon picker --}}
                                    @php $editingFaqs = $section['name'] === 'faqs'; @endphp
                                    @if (!empty($editItems))
                                        <div>
                                            <label class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400 block mb-2">{{ $editingFaqs ? 'Questions' : 'Items' }}</label>
                                            <div class="space-y-2"
                                                 x-data="{
                                                     dragFrom: null,
                                                     dragOver: null,
                                                 }">
                                                @foreach ($editItems as $idx => $editItem)
                                                    <div class="flex items-start gap-2 p-3 bg-zinc-50 dark:bg-neutral-800 rounded-md transition-all"
                                                         draggable="true"
                                                         x-on:dragstart="$event.dataTransfer.effectAllowed = 'move'; dragFrom = {{ $idx }}"
                                                         x-on:dragover.prevent="$event.dataTransfer.dropEffect = 'move'; dragOver = {{ $idx }}"
                                                         x-on:drop.prevent="if (dragFrom !== null && dragFrom !== {{ $idx }}) { $wire.reorderItems(dragFrom, {{ $idx }}); } dragFrom = null; dragOver = null;"
                                                         x-on:dragend="dragFrom = null; dragOver = null;"
                                                         x-bind:class="dragOver === {{ $idx }} ? 'ring-2 ring-zinc-400 bg-zinc-100' : ''"
                                                    >
                                                        {{-- Drag handle --}}
                                                        <div class="flex-shrink-0 flex flex-col items-center justify-center gap-0.5 pt-4 cursor-grab active:cursor-grabbing text-zinc-400 hover:text-zinc-600"
                                                             title="Drag to reorder">
                                                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                                                <path d="M7 2a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM13 2a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM7 8a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM13 8a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM7 14a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM13 14a2 2 0 1 0 0 4 2 2 0 0 0 0-4z"/>
                                                            </svg>
                                                        </div>

                                                        {{-- Icon preview + picker (hidden for FAQs) --}}
                                                        @if (!$editingFaqs)
                                                        <div class="w-28 flex-shrink-0" x-data="{ pickerOpen: false }" wire:ignore.self>
                                                            <label class="text-xs text-zinc-400 block mb-0.5">Icon</label>
                                                            <div class="relative">
                                                                <div class="flex items-center gap-1">
                                                                    @if (!empty($editItem['icon']))
                                                                        <img src="https://unpkg.com/lucide-static@latest/icons/{{ $editItem['icon'] }}.svg"
                                                                             class="w-5 h-5 flex-shrink-0 dark:invert" alt="{{ $editItem['icon'] }}"
                                                                             x-on:error="$el.style.display='none'" />
                                                                    @endif
                                                                    <input type="text" wire:model.blur="editItems.{{ $idx }}.icon"
                                                                           class="w-full text-xs rounded border border-zinc-200 bg-white px-2 py-1 font-mono dark:bg-neutral-900 dark:border-neutral-700"
                                                                           placeholder="wrench" />
                                                                    <button type="button"
                                                                            x-on:click.stop="pickerOpen = !pickerOpen"
                                                                            class="flex-shrink-0 w-6 h-6 flex items-center justify-center rounded border border-zinc-200 dark:border-neutral-700 bg-zinc-50 dark:bg-neutral-800 hover:bg-zinc-100 dark:hover:bg-neutral-700 cursor-pointer text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200"
                                                                            title="Browse icons">
                                                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                                                    </button>
                                                                </div>
                                                                {{-- Icon picker — only mounts in DOM when opened --}}
                                                                <template x-if="pickerOpen">
                                                                    <div x-on:click.away="pickerOpen = false"
                                                                         x-on:mousedown.prevent
                                                                         x-transition
                                                                         class="absolute z-50 top-full left-0 mt-1 w-72 max-h-52 overflow-y-auto bg-white dark:bg-neutral-900 border border-zinc-200 dark:border-neutral-700 rounded-lg shadow-xl p-2 grid grid-cols-5 gap-1">
                                                                        @foreach (['wrench','hammer','drill','paintbrush','ruler','hard-hat','brick-wall','home','building','building-2','warehouse','door-open','flame','thermometer','snowflake','wind','fan','sun','droplets','shower-head','bath','pipette','zap','plug','lightbulb','cable','battery-charging','shield-check','award','badge-check','medal','trophy','clock','calendar','phone','mail','map-pin','navigation','truck','package','settings-2','cog','nut','scan-line','tree-pine','tree-deciduous','leaf','fence','scissors','spray-can','users','handshake','heart','star','thumbs-up','smile','check-circle','circle-check','lock','key','eye','search','credit-card','receipt','pound-sterling','calculator','file-text','clipboard','book-open','scale','layers','sparkles'] as $icon)
                                                                            <button type="button"
                                                                                    x-on:click="$wire.set('editItems.{{ $idx }}.icon', '{{ $icon }}'); pickerOpen = false;"
                                                                                    class="flex flex-col items-center gap-0.5 p-1.5 rounded hover:bg-zinc-100 dark:hover:bg-neutral-800 cursor-pointer transition-colors"
                                                                                    title="{{ $icon }}">
                                                                                <img src="https://unpkg.com/lucide-static@latest/icons/{{ $icon }}.svg"
                                                                                     class="w-5 h-5 dark:invert" alt="{{ $icon }}" loading="lazy" />
                                                                            </button>
                                                                        @endforeach
                                                                    </div>
                                                                </template>
                                                            </div>
                                                        </div>
                                                        @endif

                                                        <div class="flex-1">
                                                            <label class="text-xs text-zinc-400 block mb-0.5">{{ $editingFaqs ? 'Question' : 'Title' }}</label>
                                                            <input type="text" wire:model.blur="editItems.{{ $idx }}.title"
                                                                   class="w-full text-xs rounded border border-zinc-200 bg-white px-2 py-1 dark:bg-neutral-900 dark:border-neutral-700" />
                                                        </div>
                                                        <div class="flex-[2]">
                                                            <label class="text-xs text-zinc-400 block mb-0.5">{{ $editingFaqs ? 'Answer' : 'Body' }}</label>
                                                            <input type="text" wire:model.blur="editItems.{{ $idx }}.body"
                                                                   class="w-full text-xs rounded border border-zinc-200 bg-white px-2 py-1 dark:bg-neutral-900 dark:border-neutral-700" />
                                                        </div>
                                                        <div class="flex flex-col gap-1 mt-3">
                                                            @unless ($demo)
                                                            <button type="button"
                                                                    wire:click="regenerateItem({{ $idx }})"
                                                                    wire:loading.attr="disabled"
                                                                    wire:target="regenerateItem({{ $idx }})"
                                                                    class="text-xs text-amber-600 dark:text-amber-400 hover:text-amber-800 dark:hover:text-amber-300 cursor-pointer disabled:opacity-40"
                                                                    title="Rewrite this item">
                                                                <span wire:loading.remove wire:target="regenerateItem({{ $idx }})">↻</span>
                                                                <span wire:loading wire:target="regenerateItem({{ $idx }})">…</span>
                                                            </button>
                                                            @endunless
                                                            <button type="button" wire:click="removeItem({{ $idx }})"
                                                                    class="text-xs text-red-500 hover:text-red-700 cursor-pointer" title="Remove">
                                                                ✕
                                                            </button>
                                                        </div>
                                                    </div>
                                                @endforeach
                                            </div>
                                            <button type="button" wire:click="addItem"
                                                    class="mt-2 text-xs font-medium text-amber-600 dark:text-amber-400 hover:text-amber-800 dark:hover:text-amber-300 underline underline-offset-2 cursor-pointer">
                                                + Add item
                                            </button>
                                        </div>
                                    @endif

                                    {{-- Editable contact form fields.
                                         Rendered whenever the section is a contact form, not only when
                                         it already has fields -- otherwise a form with none (which is
                                         every site today) showed no way to add one. --}}
                                    @if ($section['name'] === 'contact_form')
                                        <div>
                                            <div class="flex items-center justify-between mb-2">
                                                <label class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                                    Form Fields
                                                    <span class="normal-case font-normal text-zinc-400">(name and email are always on the form)</span>
                                                </label>
                                                @if (count($editFormFields) < 8)
                                                    <button type="button" wire:click="addFormField"
                                                            class="text-xs font-medium text-accent hover:underline cursor-pointer">
                                                        + Add field
                                                    </button>
                                                @endif
                                            </div>

                                            @if (empty($editFormFields))
                                                <p class="text-xs text-zinc-400 italic mb-2">
                                                    No custom fields — the form shows Name, Email, Phone and Message.
                                                </p>
                                            @endif

                                            <div class="space-y-2">
                                                @foreach ($editFormFields as $fidx => $formField)
                                                    <div class="flex items-start gap-2 p-3 bg-zinc-50 dark:bg-neutral-800 rounded-md" wire:key="cf-field-{{ $fidx }}">
                                                        <div class="w-24 flex-shrink-0">
                                                            <label class="text-xs text-zinc-400 block mb-0.5">Label</label>
                                                            <input type="text" wire:model.blur="editFormFields.{{ $fidx }}.label"
                                                                   class="w-full text-xs rounded border border-zinc-200 bg-white px-2 py-1 dark:bg-neutral-900 dark:border-neutral-700" />
                                                        </div>
                                                        <div class="w-24 flex-shrink-0">
                                                            {{-- The key the answer is stored under. Shown because renaming it
                                                                 on an existing field orphans past submissions -- so it must
                                                                 be a visible, deliberate act, not derived from the label. --}}
                                                            <label class="text-xs text-zinc-400 block mb-0.5" title="The key answers are stored under">Field key</label>
                                                            <input type="text" wire:model.blur="editFormFields.{{ $fidx }}.name"
                                                                   placeholder="job_postcode"
                                                                   class="w-full text-xs font-mono rounded border border-zinc-200 bg-white px-2 py-1 dark:bg-neutral-900 dark:border-neutral-700" />
                                                        </div>
                                                        <div class="w-16 flex-shrink-0">
                                                            <label class="text-xs text-zinc-400 block mb-0.5">Type</label>
                                                            <select wire:model.blur="editFormFields.{{ $fidx }}.type"
                                                                    class="w-full text-xs rounded border border-zinc-200 bg-white pl-1 pr-6 py-1 dark:bg-neutral-900 dark:border-neutral-700">
                                                                <option value="text">text</option>
                                                                <option value="email">email</option>
                                                                <option value="tel">tel</option>
                                                                <option value="select">select</option>
                                                                <option value="textarea">textarea</option>
                                                            </select>
                                                        </div>
                                                        <div class="flex-1">
                                                            <label class="text-xs text-zinc-400 block mb-0.5">Placeholder</label>
                                                            <input type="text" wire:model.blur="editFormFields.{{ $fidx }}.placeholder"
                                                                   class="w-full text-xs rounded border border-zinc-200 bg-white px-2 py-1 dark:bg-neutral-900 dark:border-neutral-700" />
                                                        </div>
                                                        @if (in_array($formField['type'] ?? '', ['select', 'radio']))
                                                            {{-- radio included: it was omitted here and in the save,
                                                                 so radio options were unreachable and then discarded. --}}
                                                            <div class="flex-1">
                                                                <label class="text-xs text-zinc-400 block mb-1">Options</label>

                                                                <div class="space-y-1">
                                                                    @foreach ($formField['options'] ?? [] as $oidx => $formOption)
                                                                        <div class="flex items-center gap-1.5" wire:key="cf-opt-{{ $fidx }}-{{ $oidx }}">
                                                                            <input type="text" wire:model.blur="editFormFields.{{ $fidx }}.options.{{ $oidx }}"
                                                                                   class="flex-1 text-xs rounded border border-zinc-200 bg-white px-2 py-1 dark:bg-neutral-900 dark:border-neutral-700"
                                                                                   placeholder="Option {{ $oidx + 1 }}" />
                                                                            <button type="button"
                                                                                    wire:click="removeFieldOption({{ $fidx }}, {{ $oidx }})"
                                                                                    title="Remove option"
                                                                                    aria-label="Remove option {{ $oidx + 1 }}"
                                                                                    class="text-zinc-400 hover:text-red-600 dark:hover:text-red-400 cursor-pointer">
                                                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                                                            </button>
                                                                        </div>
                                                                    @endforeach
                                                                </div>

                                                                @if (count($formField['options'] ?? []) < 10)
                                                                    <button type="button"
                                                                            wire:click="addFieldOption({{ $fidx }})"
                                                                            class="mt-1 text-xs text-accent hover:underline cursor-pointer">
                                                                        + Add option
                                                                    </button>
                                                                @endif
                                                            </div>
                                                        @endif
                                                        <div class="flex flex-col items-center gap-1 pt-3">
                                                            <label class="flex items-center gap-1 text-xs text-zinc-400 cursor-pointer">
                                                                <input type="checkbox" wire:model.blur="editFormFields.{{ $fidx }}.required"
                                                                       class="rounded border-zinc-300" />
                                                                Req
                                                            </label>
                                                            <div class="flex items-center gap-1">
                                                                @if ($fidx > 0)
                                                                    <button type="button" wire:click="moveFormFieldUp({{ $fidx }})"
                                                                            title="Move up" aria-label="Move field up"
                                                                            class="text-xs text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200 cursor-pointer">↑</button>
                                                                @endif
                                                                @if ($fidx < count($editFormFields) - 1)
                                                                    <button type="button" wire:click="moveFormFieldDown({{ $fidx }})"
                                                                            title="Move down" aria-label="Move field down"
                                                                            class="text-xs text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200 cursor-pointer">↓</button>
                                                                @endif
                                                                <button type="button" wire:click="removeFormField({{ $fidx }})"
                                                                        title="Remove field" aria-label="Remove field"
                                                                        class="text-xs text-red-500 hover:text-red-700 cursor-pointer">✕</button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>

                                        {{-- Submit label + privacy note --}}
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                            <div>
                                                <label class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400 block mb-1">Submit Button Text</label>
                                                <input type="text" wire:model.blur="editSubmitLabel"
                                                       class="w-full text-sm rounded-md border border-zinc-200 bg-white px-3 py-2 dark:bg-neutral-900 dark:border-neutral-700" />
                                            </div>
                                            <div>
                                                <label class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400 block mb-1">Privacy Note</label>
                                                <input type="text" wire:model.blur="editPrivacyNote"
                                                       class="w-full text-sm rounded-md border border-zinc-200 bg-white px-3 py-2 dark:bg-neutral-900 dark:border-neutral-700" />
                                            </div>
                                        </div>
                                    @endif

                                    {{-- Areas list editor — only shown for suburb_list.
                                         Each row is a plain string (area name); agents can
                                         add/rename/remove rows in-place. Template gates on
                                         count >= 3 so fewer than 3 rows hides the section
                                         on the public page rather than rendering sparse. --}}
                                    @if ($section['name'] === 'suburb_list')
                                        <div>
                                            <div class="flex items-center justify-between mb-1">
                                                <label class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                                    Areas ({{ count($editAreas) }})
                                                </label>
                                                <button type="button" wire:click="addArea"
                                                        class="text-xs text-amber-600 dark:text-amber-400 hover:text-amber-800 dark:hover:text-amber-300 underline underline-offset-2 cursor-pointer">
                                                    + Add area
                                                </button>
                                            </div>
                                            @if (empty($editAreas))
                                                <p class="text-xs text-zinc-400 italic">No areas yet — click "+ Add area" to add coverage locations. The section hides on the public page unless at least 3 are set.</p>
                                            @endif
                                            <div class="space-y-2">
                                                @foreach ($editAreas as $idx => $area)
                                                    <div class="flex items-center gap-2" wire:key="area-row-{{ $idx }}">
                                                        <input type="text"
                                                               wire:model.blur="editAreas.{{ $idx }}"
                                                               placeholder="e.g. Penzance"
                                                               class="flex-1 text-sm rounded-md border border-zinc-200 bg-white px-3 py-2 dark:bg-neutral-900 dark:border-neutral-700" />
                                                        <button type="button" wire:click="removeArea({{ $idx }})"
                                                                class="text-xs font-medium text-red-500 hover:text-red-700 cursor-pointer"
                                                                title="Remove area">
                                                            ✕
                                                        </button>
                                                    </div>
                                                @endforeach
                                            </div>
                                            @if (count($editAreas) > 0 && count($editAreas) < 3)
                                                <p class="mt-1 text-xs text-amber-600 dark:text-amber-400">Heads up — section needs at least 3 areas to render publicly. Currently {{ count($editAreas) }}.</p>
                                            @endif
                                        </div>
                                    @endif

                                    {{-- Save / Cancel --}}
                                    <div class="flex items-center gap-3 pt-2">
                                        <flux:button size="sm" variant="primary" wire:click="saveSection" icon="check">
                                            Save Changes
                                        </flux:button>
                                        <flux:button size="sm" variant="ghost" wire:click="edit('{{ $page }}', '{{ $section['name'] }}', {{ $storedIndex }})" icon="x-mark">
                                            Cancel
                                        </flux:button>
                                    </div>
                                </div>
                                @endif {{-- end @else (generic editor) for AUDIT-04 branch --}}

                            @elseif (is_array($sd))
                                {{-- ===== READ-ONLY MODE ===== --}}
                                <div class="mt-2">
                                    @php
                                        // Local ProseMirror → plain-text flattener for the summary
                                        // row. Same logic as edit() in the component script.
                                        $flat = function ($node) use (&$flat) {
                                            if (is_string($node)) return $node;
                                            if (! is_array($node)) return '';
                                            if (isset($node['text']) && is_string($node['text'])) return $node['text'];
                                            $out = [];
                                            foreach (($node['content'] ?? []) as $child) $out[] = $flat($child);
                                            return implode(' ', array_filter($out, fn ($s) => $s !== ''));
                                        };
                                        $pickStr = function (array $d, array $keys) use ($flat) {
                                            foreach ($keys as $k) {
                                                if (! array_key_exists($k, $d)) continue;
                                                if (is_string($d[$k]) && $d[$k] !== '') return $d[$k];
                                                if (is_array($d[$k])) {
                                                    $f = trim($flat($d[$k]));
                                                    if ($f !== '') return $f;
                                                }
                                            }
                                            return null;
                                        };
                                        $headline = $pickStr($sd, ['heading', 'title']);
                                        $sub = $pickStr($sd, ['subheading', 'subtitle', 'intro', 'body']);
                                    @endphp
                                    @if ($headline)
                                        <p class="text-sm text-zinc-800 dark:text-zinc-100 font-medium mb-1">
                                            {{ $headline }}
                                        </p>
                                    @endif

                                    @if ($sub)
                                        <p class="text-xs text-zinc-500 mb-2 line-clamp-2">
                                            {{ \Illuminate\Support\Str::limit(str_replace(['\n', "\n"], ' ', $sub), 150) }}
                                        </p>
                                    @endif

                                    @if (!empty($sd['items']) && is_array($sd['items']))
                                        <div class="flex flex-wrap gap-2 mt-2">
                                            @foreach (array_slice($sd['items'], 0, 6) as $item)
                                                <span class="inline-flex items-center gap-1.5 text-xs bg-zinc-100 dark:bg-neutral-800 text-zinc-600 dark:text-zinc-300 rounded-full px-2.5 py-1">
                                                    @if (!empty($item['icon']))
                                                        <i data-lucide="{{ $item['icon'] }}" class="w-3 h-3"></i>
                                                    @endif
                                                    {{ $item['title'] ?? $item['question'] ?? '—' }}
                                                </span>
                                            @endforeach
                                        </div>
                                    @endif

                                    {{-- suburb_list stores its entries under `areas` as
                                         a flat string list (not objects), so the items
                                         chip-renderer above can't pick it up. Mirror the
                                         style for strings. --}}
                                    @if (!empty($sd['areas']) && is_array($sd['areas']))
                                        <div class="flex flex-wrap gap-2 mt-2">
                                            @foreach (array_slice($sd['areas'], 0, 8) as $area)
                                                @if (is_string($area) && $area !== '')
                                                    <span class="inline-flex items-center gap-1.5 text-xs bg-zinc-100 dark:bg-neutral-800 text-zinc-600 dark:text-zinc-300 rounded-full px-2.5 py-1">
                                                        {{ $area }}
                                                    </span>
                                                @endif
                                            @endforeach
                                        </div>
                                    @endif

                                    @if (!empty($sd['phone']) || !empty($sd['email']))
                                        <div class="flex flex-wrap gap-3 text-xs text-zinc-500 mt-2">
                                            @if (!empty($sd['phone']))
                                                <span>📞 {{ $sd['phone'] }}</span>
                                            @endif
                                            @if (!empty($sd['email']))
                                                <span>✉ {{ $sd['email'] }}</span>
                                            @endif
                                        </div>
                                    @endif

                                    @if (!empty($sd['cta_label']))
                                        <span class="inline-block mt-2 text-xs font-semibold text-white px-3 py-1 rounded"
                                              style="background-color: var(--brand-accent, #f59e0b);">
                                            {{ $sd['cta_label'] }}
                                        </span>
                                    @endif

                                    {{-- Contact form fields summary --}}
                                    @if (!empty($sd['fields']) && is_array($sd['fields']))
                                        <div class="flex flex-wrap gap-2 mt-2">
                                            @foreach ($sd['fields'] as $field)
                                                <span class="inline-flex items-center gap-1 text-xs bg-zinc-100 dark:bg-neutral-800 text-zinc-600 dark:text-zinc-300 rounded-full px-2.5 py-1">
                                                    <span class="text-xs text-zinc-400">{{ $field['type'] ?? 'text' }}</span>
                                                    {{ $field['label'] ?? $field['name'] ?? '—' }}
                                                    @if (!empty($field['required']))
                                                        <span class="text-red-400">*</span>
                                                    @endif
                                                </span>
                                            @endforeach
                                        </div>
                                    @endif

                                    @if (!empty($sd['submit_label']))
                                        <span class="inline-block mt-2 text-xs font-semibold text-white px-3 py-1 rounded"
                                              style="background-color: var(--brand-accent, #f59e0b);">
                                            {{ $sd['submit_label'] }}
                                        </span>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach

                    @if (empty($tab['sections']))
                        <p class="text-xs text-zinc-400 py-4">No content sections generated yet.</p>
                    @endif
                </div>
            </div>

            {{-- Lead form editor (home page only) --}}
            @if ($page === 'home')
                <div class="mt-4 rounded-lg border border-zinc-200 dark:border-neutral-700 p-4">
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400 mb-3">Lead Form</h4>
                    <livewire:lead-form-editor :siteId="$siteId" :key="'lead-form-'.$siteId" lazy.bundle />
                </div>
            @endif

            {{-- SEO card --}}
            @php
                $pageSeo = $tab['seo'] ?? [];
                $pageGeo = $tab['geo'] ?? [];
            @endphp
            @if (!empty($pageSeo) || !empty($pageGeo))
                <div class="mt-4 rounded-lg border border-zinc-200 dark:border-neutral-700 p-4">
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400 mb-3">SEO</h4>
                    <div class="space-y-2">
                        @if (!empty($pageSeo['meta_title']))
                            <div>
                                <label class="text-xs text-zinc-400 block">Title Tag</label>
                                <p class="text-sm text-zinc-800 dark:text-zinc-100">{{ $pageSeo['meta_title'] }}</p>
                                <span class="text-xs text-zinc-400">{{ strlen($pageSeo['meta_title']) }} / 60 chars</span>
                            </div>
                        @endif
                        @if (!empty($pageSeo['meta_description']))
                            <div>
                                <label class="text-xs text-zinc-400 block">Meta Description</label>
                                <p class="text-xs text-zinc-600 dark:text-zinc-300">{{ $pageSeo['meta_description'] }}</p>
                                <span class="text-xs text-zinc-400">{{ strlen($pageSeo['meta_description']) }} / 155 chars</span>
                            </div>
                        @endif
                    </div>
                </div>
            @endif
            </div>{{-- /Sections pill content --}}


            @endif {{-- /if !projects: standard content-sections + lead-form + SEO --}}

            </div>{{-- /pageTab Alpine wrapper --}}
        </div>
    @endforeach
</div>
