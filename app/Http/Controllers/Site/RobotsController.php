<?php

namespace App\Http\Controllers\Site;

use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\SiteHostResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class RobotsController
{
    /**
     * Named AI-crawler user agents that get the same Allow/Disallow rule as
     * the `*` block, never wider access. Mirrored, not special-cased, so a
     * site that blocks everyone also blocks these by name (some crawlers
     * only honour their own User-agent line, not the wildcard).
     */
    private const AI_CRAWLERS = [
        'GPTBot',
        'ChatGPT-User',
        'Google-Extended',
        'Applebot-Extended',
        'CCBot',
        'PerplexityBot',
        'ClaudeBot',
        'anthropic-ai',
    ];

    public function __construct(
        protected SiteHostResolver $hosts,
    ) {}

    public function __invoke(Request $request): Response
    {
        abort_unless(config('site.use_versioned_renderer'), 404);

        $site = $this->hosts->resolve($request);
        abort_unless($site, 404);

        $current = SiteVersionCurrent::where('site_id', $site->id)->first();
        abort_unless($current, 404);

        $version = SiteVersion::find($current->version_id);
        abort_unless($version, 404);

        if (! $this->hosts->isIndexableHost($request, $site)) {
            $body = self::agentBlocks('Disallow: /');

            return response($body, 200, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Cache-Tag' => "site:{$site->id}",
            ]);
        }

        $sitemap = $request->getScheme().'://'.$request->getHost().'/sitemap.xml';
        $body = self::agentBlocks('Allow: /')."Sitemap: {$sitemap}\n";

        return response($body, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Tag' => "site:{$site->id}",
        ]);
    }

    /**
     * Emit `User-agent: *` followed by one block per named AI crawler, all
     * carrying the identical rule line so no agent gets wider access than
     * the wildcard.
     */
    private static function agentBlocks(string $rule): string
    {
        $body = "User-agent: *\n{$rule}\n";
        foreach (self::AI_CRAWLERS as $agent) {
            $body .= "User-agent: {$agent}\n{$rule}\n";
        }

        return $body;
    }
}
