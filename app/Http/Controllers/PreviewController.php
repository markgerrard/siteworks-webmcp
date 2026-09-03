<?php

namespace App\Http\Controllers;

use App\Models\Preview;
use App\Models\Site;
use App\Support\Textures\TextureResolver;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PreviewController extends Controller
{
    public function show(string $slug, ?string $page = null): View
    {
        $preview = Preview::where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        return $this->renderPreview($preview, $slug, $page, hostRouted: false);
    }

    /**
     * Resolve the active preview from the request Host: branded preview FQDN
     * maps to Site::preview_domain, customer FQDN to Site::custom_domain; 404 if none.
     */
    public function showByHost(Request $request, ?string $page = null): View
    {
        $host = strtolower($request->getHost());
        $site = $this->resolveSiteByHost($host);

        if (! $site) {
            throw new NotFoundHttpException("No preview bound to host [{$host}].");
        }

        $preview = $site->latestPreview;
        if (! $preview || ! $preview->is_active) {
            throw new NotFoundHttpException('This preview has not been published yet.');
        }

        return $this->renderPreview($preview, $preview->slug, $page, hostRouted: true);
    }

    private function resolveSiteByHost(string $host): ?Site
    {
        return app(\App\Services\Site\SiteHostResolver::class)->siteForHost($host);
    }

    private function renderPreview(Preview $preview, string $slug, ?string $page, bool $hostRouted): View
    {
        $snapshot = $preview->snapshot;
        $layout = $snapshot['layout'] ?? 'one_page';

        if ($layout === 'one_page' && $page !== null && str_contains($page, '/')) {
            $layout = 'multi_page';
        }

        // URL generator used by blade views for intra-preview links.
        // When routed via a preview/custom host, produce clean paths (/, /about).
        // When routed via /preview/{slug}, produce namespaced paths.
        // Nullable $p so group headers (page === null) can call through safely
        // and blades can branch on an empty return.
        $pageUrl = $hostRouted
            ? fn (?string $p) => $p === null ? '#' : '/'.($p === 'home' ? '' : $p)
            : fn (?string $p) => $p === null ? '#' : route('preview.page', [$slug, $p]);
        $allPages = $snapshot['pages'] ?? [];
        $rawKeys = array_keys($allPages);
        $pageKeys = array_values(array_diff($rawKeys, ['contact']));
        if (in_array('contact', $rawKeys)) {
            $pageKeys[] = 'contact';
        }
        $currentPage = $page ?: ($pageKeys[0] ?? 'home');

        // In multi-page mode, 404 on unknown page names so we don't
        // silently fall through to an empty layout.
        if ($layout === 'multi_page' && $page !== null && ! array_key_exists($page, $allPages)) {
            throw new NotFoundHttpException("Page [{$page}] does not exist on this preview.");
        }

        $site = $preview->site;
        $site?->loadMissing('businessProfile');

        return view('preview.layout', [
            'project' => $site,
            'siteTexture' => $site ? TextureResolver::resolve($site) : TextureResolver::none(),
            'pages' => $allPages,
            'pageKeys' => $pageKeys,
            'layout' => $layout,
            'currentPage' => $currentPage,
            'previewSlug' => $slug,
            'profile' => $snapshot['profile'] ?? [],
            'theme' => $snapshot['theme'] ?? [],
            'heroImages' => $snapshot['hero_images'] ?? [],
            'logoUrl' => $snapshot['logo_url'] ?? null,
            'contactFormEnabled' => $snapshot['contact_form_enabled'] ?? true,
            'watermarkEnabled' => $snapshot['watermark_enabled'] ?? true,
            'heroSizeConfig' => $snapshot['hero_sizes'] ?? [],
            'navLabels' => $snapshot['nav_labels'] ?? [],
            'navigation' => $snapshot['navigation'] ?? [],
            'chatbot' => $snapshot['chatbot'] ?? [],
            'hostRouted' => $hostRouted,
            'pageUrl' => $pageUrl,
            'topBarEnabled' => $snapshot['top_bar_enabled'] ?? true,
            'shouldShowFacebookDisclaimer' => $this->shouldShowFacebookDisclaimer($preview->site),
        ]);
    }

    private function shouldShowFacebookDisclaimer(Site $site): bool
    {
        $site->loadMissing(['importedMedia' => fn ($query) => $query
            ->where('source', 'facebook')
            ->whereIn('assigned_to', ['hero', 'project', 'face_reference'])]);

        return $site->importedMedia->isNotEmpty();
    }
}
