<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Services\Site\ThemeResolver;
use Illuminate\Http\Response;

class FontPairCssController extends Controller
{
    public function __invoke(string $display, string $body): Response
    {
        $slugs = array_values(array_unique([$display, $body]));

        foreach ($slugs as $slug) {
            abort_unless(isset(ThemeResolver::FONTS[$slug]), 404);
            abort_unless(is_file(public_path("fonts/{$slug}.css")), 404);
        }

        $css = collect($slugs)
            ->map(fn (string $slug): string => (string) file_get_contents(public_path("fonts/{$slug}.css")))
            ->implode("\n");

        return response($css, 200, [
            'Content-Type' => 'text/css; charset=UTF-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
