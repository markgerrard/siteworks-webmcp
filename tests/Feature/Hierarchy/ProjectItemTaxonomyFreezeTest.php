<?php

use App\Models\GeneratedPage;
use App\Models\ProjectCategory;
use App\Models\ProjectItem;
use App\Models\Site;
use App\Observers\ProjectItemObserver;

it('exposes detail page and taxonomy relationships', function () {
    $site = Site::factory()->create();
    $detailPage = GeneratedPage::factory()->for($site)->create(['page_type' => 'projects/kitchen']);
    $category = ProjectCategory::factory()->for($site)->create(['name' => 'Residential']);
    $item = ProjectItem::factory()->for($site)->create([
        'detail_page_id' => $detailPage->id,
        'category_id' => $category->id,
    ]);

    expect($item->detailPage->is($detailPage))->toBeTrue()
        ->and($item->projectCategory->is($category))->toBeTrue()
        ->and($category->projectItems->sole()->is($item))->toBeTrue()
        ->and($site->projectCategories->sole()->is($category))->toBeTrue();
});

it('rejects cross-site detail pages and taxonomy categories', function () {
    $site = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $foreignDetailPage = GeneratedPage::factory()->for($otherSite)->create(['page_type' => 'projects/kitchen']);
    $foreignCategory = ProjectCategory::factory()->for($otherSite)->create(['name' => 'Residential']);

    expect(fn () => ProjectItem::factory()->for($site)->create(['detail_page_id' => $foreignDetailPage->id]))
        ->toThrow(DomainException::class, 'same site')
        ->and(fn () => ProjectItem::factory()->for($site)->create(['category_id' => $foreignCategory->id]))
        ->toThrow(DomainException::class, 'same site');
});

it('pins computeContentHash inputs and byte shape', function () {
    $site = Site::factory()->create();
    $item = ProjectItem::factory()->for($site)->create([
        'title' => 'Loft conversion',
        'description' => 'Added two bedrooms',
        'category' => 'Residential',
        'metrics' => ['area' => '42m2', 'weeks' => 8],
    ]);

    $expected = sha1("Loft conversion\nAdded two bedrooms\nResidential\n{\"area\":\"42m2\",\"weeks\":8}");
    expect($item->fresh()->content_hash)->toBe($expected);

    $item->update(['category_id' => null, 'detail_page_id' => null]);
    expect($item->fresh()->content_hash)->toBe($expected);

    $method = new ReflectionMethod(ProjectItemObserver::class, 'computeContentHash');
    $source = array_slice(file($method->getFileName()), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1);

    expect(implode('', $source))->toBe(<<<'PHP'
    protected function computeContentHash(ProjectItem $item): string
    {
        return sha1(implode("\n", [
            (string) $item->title,
            (string) $item->description,
            (string) $item->category,
            $item->metrics === null ? 'null' : json_encode($item->metrics),
        ]));
    }
PHP."\n");
});

it('pins the unpublished drift comparison shape byte for byte', function () {
    $method = new ReflectionMethod(ProjectItem::class, 'hasUnpublishedDrift');
    $source = array_slice(file($method->getFileName()), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1);

    expect(implode('', $source))->toBe(<<<'PHP'
    public function hasUnpublishedDrift(): bool
    {
        $snap = $this->published_snapshot;
        if (! is_array($snap)) {
            return false;
        }

        return ($snap['title'] ?? null) !== $this->title
            || ($snap['description'] ?? null) !== $this->description
            || ($snap['category'] ?? null) !== $this->category
            || ($snap['metrics'] ?? null) !== $this->metrics
            || ($snap['image_id'] ?? null) !== $this->image_id
            || ($snap['sort_order'] ?? null) !== $this->sort_order;
    }
PHP."\n");
});
