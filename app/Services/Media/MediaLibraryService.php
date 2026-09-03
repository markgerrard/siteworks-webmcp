<?php

namespace App\Services\Media;

use App\Exceptions\MediaInUseException;
use App\Models\Site;
use App\Models\SiteMedia;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\SiteMediaIngestService;
use App\Support\Media\MediaKind;
use App\Support\Media\MediaOrigin;
use App\Support\MediaStorage;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MediaLibraryService
{
    public function __construct(
        private readonly SiteMediaIngestService $ingest,
    ) {}

    /**
     * @param  array{title?: string, alt_text?: string, tags?: list<string>}  $attrs
     */
    public function upload(Site $site, UploadedFile $file, array $attrs = []): SiteMedia
    {
        $media = $this->ingest->ingestUpload($site, $file, ActorChannel::Ui, 'upload');

        $filename = pathinfo((string) $file->getClientOriginalName(), PATHINFO_FILENAME);

        return $this->applyLibraryFields($media, [
            'title' => $attrs['title'] ?? $this->limitTitle($filename),
            'alt_text' => $attrs['alt_text'] ?? $media->alt_text,
            'tags' => $attrs['tags'] ?? [],
            'provisional' => false,
        ]);
    }

    /** Why the last generate() returned false. The demo has no generation pipeline, so it is always 'demo'. */
    public ?string $lastRefusal = null;

    /**
     * Image generation belonged to the private pipeline, which this demo does
     * not ship. The method is kept so callers keep their contract; it always
     * refuses.
     */
    public function generate(Site $site, string $prompt, string $aspect): bool
    {
        $this->lastRefusal = 'demo';

        return false;
    }

    /** Human message for the last refusal (null when the last call succeeded). */
    public function refusalMessage(): ?string
    {
        return match ($this->lastRefusal) {
            'demo' => 'Image generation is not available in this demo.',
            default => null,
        };
    }

    public function keep(SiteMedia $media): SiteMedia
    {
        $media->update(['provisional' => false]);

        return $media->fresh() ?? $media;
    }

    public function regenerate(SiteMedia $media): bool
    {
        $prompt = trim((string) $media->prompt);

        if ($prompt === '') {
            throw new InvalidArgumentException('Media has no stored prompt to regenerate from.');
        }

        $aspect = (string) (($media->metadata['aspect'] ?? null) ?: '16:9');

        return $this->generate($media->site, $prompt, $aspect);
    }

    /**
     * @param  array{title?: string, alt_text?: string, tags?: list<string>, decorative?: bool}  $attrs
     */
    public function update(SiteMedia $media, array $attrs): SiteMedia
    {
        $payload = [];

        if (array_key_exists('title', $attrs)) {
            $payload['title'] = $this->limitTitle((string) $attrs['title']);
        }

        if (array_key_exists('tags', $attrs)) {
            $payload['tags'] = array_values($attrs['tags'] ?? []);
        }

        if (($attrs['decorative'] ?? false) === true) {
            $payload['alt_text'] = '';
        } elseif (array_key_exists('alt_text', $attrs)) {
            $payload['alt_text'] = $attrs['alt_text'];
        }

        $media->update($payload);

        return $media->fresh() ?? $media;
    }

    public function delete(SiteMedia $media): void
    {
        $usages = $media->usages()->get()->concat($this->untrackedReferences($media));

        if ($usages->isNotEmpty()) {
            throw new MediaInUseException($media, $usages);
        }

        $media->delete();
    }

    /**
     * Live references that predate site_media_usages: project items, before/after
     * pairs and section image ids in page content. SoftDeletes would make a deleted asset vanish
     * from rendered pages, so they count as usages. Returned as unsaved SiteMediaUsage rows.
     *
     * @return Collection<int, \App\Models\SiteMediaUsage>
     */
    public function untrackedReferences(SiteMedia $media): Collection
    {
        $refs = new Collection();
        $mk = fn (string $type, int $id, string $slot) => new \App\Models\SiteMediaUsage([
            'site_media_id' => $media->id, 'usable_type' => $type, 'usable_id' => $id, 'slot' => $slot,
        ]);

        \App\Models\ProjectItem::query()->where('site_id', $media->site_id)->where('image_id', $media->id)
            ->pluck('id')->each(fn ($id) => $refs->push($mk('project_item', (int) $id, 'image')));

        \App\Models\BeforeAfterPair::query()->where('site_id', $media->site_id)
            ->where(fn ($q) => $q->where('before_image_id', $media->id)->orWhere('after_image_id', $media->id))
            ->pluck('id')->each(fn ($id) => $refs->push($mk('before_after_pair', (int) $id, 'image')));

        \App\Models\GeneratedPage::query()->where('site_id', $media->site_id)->get(['id', 'content_data'])
            ->each(function ($page) use ($media, $refs, $mk): void {
                $sections = $page->content_data['sections'] ?? [];
                if (is_array($sections) && in_array($media->id, \App\Services\Site\PageRenderer::extractReferencedMediaIds($sections), true)) {
                    $refs->push($mk('page', (int) $page->id, 'section'));
                }
            });

        return $refs;
    }

    /**
     * @param  array{kind?: string, origin?: string, tag?: string, usage?: string, q?: string}  $filters
     * @return Collection<int, SiteMedia>
     */
    public function list(Site $site, array $filters = []): Collection
    {
        $query = SiteMedia::query()->library()->where('site_id', $site->id);

        $kind = trim((string) ($filters['kind'] ?? ''));
        if ($kind !== '') {
            $query->where('kind', $kind);
        }

        $origin = trim((string) ($filters['origin'] ?? ''));
        if ($origin !== '') {
            $query->where('origin', $origin);
        }

        $tag = trim((string) ($filters['tag'] ?? ''));
        if ($tag !== '') {
            $query->whereJsonContains('tags', $tag);
        }

        $usage = trim((string) ($filters['usage'] ?? ''));
        if ($usage === 'used') {
            $query->whereHas('usages');
        } elseif ($usage === 'unused') {
            $query->whereDoesntHave('usages');
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $query->where(function (Builder $inner) use ($like): void {
                $inner->where('title', 'ilike', $like)
                    ->orWhere('alt_text', 'ilike', $like)
                    ->orWhereRaw('tags::text ilike ?', [$like]);
            });
        }

        return $query->with('usages')->orderByDesc('id')->get();
    }

    public function purgeProvisional(DateTimeInterface $olderThan): int
    {
        $deleted = 0;
        $disk = MediaStorage::disk();

        // withTrashed: a discarded provisional row is soft-deleted but its object still exists.
        SiteMedia::withTrashed()
            ->where('provisional', true)
            ->where('created_at', '<', $olderThan)
            ->whereDoesntHave('usages')
            ->each(function (SiteMedia $media) use ($disk, &$deleted): void {
                if (is_string($media->s3_key) && $media->s3_key !== '') {
                    $disk->delete($media->s3_key);
                }

                $media->forceDelete();
                $deleted++;
            });

        return $deleted;
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    public function applyLibraryFields(SiteMedia $media, array $attrs = []): SiteMedia
    {
        $metadata = is_array($media->metadata) ? $media->metadata : [];

        $media->fill([
            'kind' => MediaKind::fromMime($media->mime_type),
            'origin' => MediaOrigin::fromSource((string) $media->source, $attrs['llm_call_id'] ?? $media->llm_call_id),
            'width' => $attrs['width'] ?? $metadata['width'] ?? $media->width,
            'height' => $attrs['height'] ?? $metadata['height'] ?? $media->height,
            'title' => array_key_exists('title', $attrs) ? $this->limitTitle((string) $attrs['title']) : $media->title,
            'alt_text' => array_key_exists('alt_text', $attrs) ? $attrs['alt_text'] : $media->alt_text,
            'tags' => array_key_exists('tags', $attrs) ? array_values($attrs['tags'] ?? []) : ($media->tags ?? []),
            'prompt' => array_key_exists('prompt', $attrs) ? $attrs['prompt'] : $media->prompt,
            'provisional' => $attrs['provisional'] ?? $media->provisional,
            'llm_call_id' => $attrs['llm_call_id'] ?? $media->llm_call_id,
            'metadata' => array_key_exists('aspect', $attrs)
                ? array_merge($metadata, ['aspect' => $attrs['aspect']])
                : $media->metadata,
        ]);
        $media->save();

        return $media->fresh() ?? $media;
    }

    public const ASPECTS = ['1:1', '16:9', '4:3', '3:2'];

    public function assertAspect(string $aspect): void
    {
        if (! in_array($aspect, self::ASPECTS, true)) {
            throw new InvalidArgumentException(
                "Unsupported aspect [{$aspect}]; accepted: ".implode(', ', self::ASPECTS).'.'
            );
        }
    }

    private function limitTitle(string $title): ?string
    {
        $title = trim($title);

        if ($title === '') {
            return null;
        }

        return Str::limit($title, 120, '');
    }
}
