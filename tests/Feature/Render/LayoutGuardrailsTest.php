<?php

namespace Tests\Feature\Render;

use App\Services\Site\PageLayoutRegistry;
use Tests\TestCase;

class LayoutGuardrailsTest extends TestCase
{
    public function test_numbered_rows_on_two_home_families_warns(): void
    {
        $recipe = config('site_home_layouts.editorial');
        $recipe['variants']['services'] = 'numbered-rows';
        $recipe['variants']['trust'] = 'numbered-rows';
        $w = app(PageLayoutRegistry::class)->recipeWarnings($recipe, 'home');
        $this->assertCount(1, $w);
        $this->assertStringContainsString('numbered-rows', $w[0]);
    }

    public function test_adjacent_same_treatment_and_long_ledger_warn(): void
    {
        $recipe = config('site_home_layouts.editorial');
        $recipe['variants'] = ['services' => 'numbered-rows', 'trust' => 'numbered-rows'];
        $recipe['surfaces'] = ['services' => 'contrast', 'trust' => 'contrast'];
        $items = array_fill(0, 8, ['title' => 't', 'body' => 'b']);
        $sections = [
            ['type' => 'hero'],
            ['type' => 'services', 'items' => $items],
            ['type' => 'trust', 'items' => $items],
        ];
        $w = app(PageLayoutRegistry::class)->adjacencyWarnings($sections, $recipe, 'home');
        $this->assertCount(3, $w); // adjacency + two long ledgers
    }

    public function test_clean_recipe_has_no_warnings(): void
    {
        $recipe = config('site_home_layouts.editorial');
        $this->assertSame([], app(PageLayoutRegistry::class)->recipeWarnings($recipe, 'home'));
    }

    public function test_explicit_null_variant_does_not_inherit_recipe_ledger(): void
    {
        $recipe = config('site_home_layouts.editorial');
        $recipe['variants'] = ['services' => 'numbered-rows'];
        $items = array_fill(0, 8, ['title' => 't', 'body' => 'b']);
        $sections = [
            ['type' => 'services', 'variant' => null, 'items' => $items],
        ];
        $w = app(PageLayoutRegistry::class)->adjacencyWarnings($sections, $recipe, 'home');
        $this->assertSame([], $w);
    }

    public function test_absent_variant_inherits_recipe_ledger_and_warns(): void
    {
        $recipe = config('site_home_layouts.editorial');
        $recipe['variants'] = ['services' => 'numbered-rows'];
        $items = array_fill(0, 8, ['title' => 't', 'body' => 'b']);
        $sections = [
            ['type' => 'services', 'items' => $items],
        ];
        $w = app(PageLayoutRegistry::class)->adjacencyWarnings($sections, $recipe, 'home');
        $this->assertCount(1, $w);
        $this->assertStringContainsString('single-column ledger', $w[0]);
    }
}
