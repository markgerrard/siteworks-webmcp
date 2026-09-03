<?php

namespace App\Console\Commands\Site;

use App\Models\GeneratedPage;
use App\Models\Site\PageRevision;
use App\Services\Site\ContentShapeTranslator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResplitRichParagraphs extends Command
{
    protected $signature = 'site:resplit-rich-paragraphs {--dry-run}';

    protected $description = 'Re-split rich-field TipTap docs that have the entire legacy string in a single paragraph (including literal \n\n) into multiple paragraphs.';

    public function __construct(protected ContentShapeTranslator $translator)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $touched = 0;
        $dryRun = (bool) $this->option('dry-run');

        foreach (PageRevision::all() as $rev) {
            $content = $rev->content_data ?? [];
            $before = json_encode($content);
            $content = $this->walkSections($content);
            $after = json_encode($content);

            if ($before === $after) {
                continue;
            }

            $touched++;
            if ($dryRun) {
                continue;
            }

            DB::transaction(function () use ($rev, $content) {
                $rev->update(['content_data' => $content]);

                // Also update the mirrored generated_pages.content_data if this
                // revision is either the draft or published pointer.
                $page = GeneratedPage::find($rev->page_id);
                if ($page && ($page->draft_revision_id === $rev->id || $page->published_revision_id === $rev->id)) {
                    $currentPointer = $page->draft_revision_id ?? $page->published_revision_id;
                    if ($currentPointer === $rev->id) {
                        $page->update(['content_data' => $content]);
                    }
                }
            });
        }

        $this->info(($dryRun ? 'Would re-split' : 'Re-split')." rich paragraphs in {$touched} revisions.");

        return self::SUCCESS;
    }

    protected function walkSections(array $content): array
    {
        if (! isset($content['sections']) || ! is_array($content['sections'])) {
            return $content;
        }

        foreach ($content['sections'] as &$section) {
            $section = $this->walkSection($section);
        }

        return $content;
    }

    protected function walkSection(array $section): array
    {
        foreach ($section as $key => $value) {
            if ($this->isSingleParagraphDocWithBreaks($value)) {
                $text = $value['content'][0]['content'][0]['text'] ?? '';
                $section[$key] = $this->translator->stringToTipTapDoc($text);

                continue;
            }

            if (is_array($value)) {
                // recurse into items.*.body etc.
                $section[$key] = $this->walkSection($value);
            }
        }

        return $section;
    }

    /**
     * A doc is a target if:
     *   - it's a TipTap doc
     *   - has exactly one paragraph child
     *   - the paragraph has a single text node whose text contains a blank line
     *     (\n\n — or \r\n\r\n after normalisation)
     */
    protected function isSingleParagraphDocWithBreaks(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }
        if (($value['type'] ?? null) !== 'doc') {
            return false;
        }
        $content = $value['content'] ?? [];
        if (count($content) !== 1) {
            return false;
        }
        $para = $content[0];
        if (($para['type'] ?? null) !== 'paragraph') {
            return false;
        }
        $inner = $para['content'] ?? [];
        if (count($inner) !== 1) {
            return false;
        }
        $text = $inner[0]['text'] ?? null;
        if (! is_string($text)) {
            return false;
        }
        $normalised = preg_replace("/\r\n?/", "\n", $text);

        return preg_match("/\n{2,}/", $normalised) === 1;
    }
}
