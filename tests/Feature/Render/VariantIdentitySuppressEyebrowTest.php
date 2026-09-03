<?php

namespace Tests\Feature\Render;

use Illuminate\Support\Facades\View;
use Tests\TestCase;

class VariantIdentitySuppressEyebrowTest extends TestCase
{
    public function test_classic_intro_honors_suppress_eyebrow_with_hidden_marker(): void
    {
        $html = View::make('site.sections.intro', [
            'section' => [
                'type' => 'intro',
                'variant' => 'classic',
                'title' => 'Extensions & Loft Conversions',
                'eyebrow' => 'About This Service',
                'body' => 'First paragraph of prose.',
                '__suppress_eyebrow' => true,
            ],
            'sectionIndex' => 1,
            'pageId' => 42,
            'mode' => 'public',
            'emitMarkers' => true,
            'emitFormMarkers' => false,
            'schema' => [],
            'theme' => [],
            'profile' => ['watermark_enabled' => false],
            'introImageUrl' => null,
        ])->render();

        $this->assertMatchesRegularExpression(
            '/<span class="hidden"[^>]*data-editable-field="eyebrow"/',
            $html,
        );
        $this->assertStringNotContainsString('About This Service</span>', $html);
        $this->assertStringContainsString('data-editable-field="title"', $html);
    }

    public function test_cards_features_honors_suppress_eyebrow_with_hidden_marker(): void
    {
        $html = View::make('site.sections.features', [
            'section' => [
                'type' => 'features',
                'variant' => 'cards',
                'title' => "What's Included",
                'eyebrow' => "What's Included",
                'intro' => 'Scope intro line.',
                'items' => [
                    ['icon' => 'hammer', 'title' => 'Item 1', 'body' => 'Body 1.'],
                ],
                '__suppress_eyebrow' => true,
            ],
            'sectionIndex' => 2,
            'pageId' => 42,
            'mode' => 'public',
            'emitMarkers' => true,
            'emitFormMarkers' => false,
            'schema' => [],
            'theme' => [],
        ])->render();

        $this->assertMatchesRegularExpression(
            '/<span class="hidden"[^>]*data-editable-field="eyebrow"/',
            $html,
        );
        $this->assertStringNotContainsString("What's Included</span>", $html);
        $this->assertStringContainsString('data-editable-field="title"', $html);
        $this->assertStringContainsString('Item 1', $html);
    }

    public function test_classic_story_honors_suppress_eyebrow_with_hidden_marker(): void
    {
        $html = View::make('site.sections.story', [
            'section' => [
                'type' => 'story',
                'variant' => 'classic',
                'title' => 'Our Story',
                'eyebrow' => 'About Us',
                'body' => 'First paragraph of story.',
                '__suppress_eyebrow' => true,
            ],
            'sectionIndex' => 1,
            'pageId' => 42,
            'mode' => 'public',
            'emitMarkers' => true,
            'emitFormMarkers' => false,
            'schema' => [],
            'theme' => [],
            'profile' => ['watermark_enabled' => false],
            'introImageUrl' => null,
        ])->render();

        $this->assertMatchesRegularExpression(
            '/<span class="hidden"[^>]*data-editable-field="eyebrow"/',
            $html,
        );
        $this->assertStringNotContainsString('About Us</span>', $html);
        $this->assertStringContainsString('data-editable-field="title"', $html);
        $this->assertStringContainsString('Our Story', $html);
    }

    public function test_classic_values_honors_suppress_eyebrow_with_hidden_marker(): void
    {
        $html = View::make('site.sections.values', [
            'section' => [
                'type' => 'values',
                'variant' => 'classic',
                'title' => 'What We Stand For',
                'eyebrow' => 'Our Values',
                'items' => [
                    ['title' => 'Value 1', 'body' => 'Conviction 1.'],
                ],
                '__suppress_eyebrow' => true,
            ],
            'sectionIndex' => 2,
            'pageId' => 42,
            'mode' => 'public',
            'emitMarkers' => true,
            'emitFormMarkers' => false,
            'schema' => [],
            'theme' => [],
        ])->render();

        $this->assertMatchesRegularExpression(
            '/<span class="hidden"[^>]*data-editable-field="eyebrow"/',
            $html,
        );
        $this->assertStringNotContainsString('Our Values</span>', $html);
        $this->assertStringContainsString('data-editable-field="title"', $html);
        $this->assertStringContainsString('What We Stand For', $html);
        $this->assertStringContainsString('Value 1', $html);
    }
}
