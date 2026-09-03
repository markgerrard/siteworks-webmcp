<?php

namespace App\Services\Site;

use App\Models\GeneratedPage;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;

class CompositionDefaults
{
    /**
     * Build a sensible default composition JSON for a site, derived from its existing
     * GeneratedPage rows + theme + business profile basics.
     *
     * @return array{nav: array{items: array<int, array{type: string, page_id: int, label: string}>}, footer: array{columns: array<empty, empty>, show_credit: bool}, theme: array{primary_override: null, accent_override: null}, homepage_page_id: int|null}
     */
    public function forSite(Site $site): array
    {
        $pages = GeneratedPage::where('site_id', $site->id)
            ->whereNull('archived_at')
            ->whereNull('parent_id')
            // sort_order alone is not a TOTAL order: pages routinely share a value, and
            // Postgres is then free to return ties in any order — so a site's nav order
            // was non-deterministic between runs. Invisible while compositions were
            // created once and stored; it surfaced the moment the shop-nav work started
            // regenerating them, as a byte-identity fixture that passed alone and failed
            // in the suite. id is the stable tiebreaker.
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $home = $pages->firstWhere('page_type', 'home') ?? $pages->first();

        $navItems = $pages
            ->reject(fn ($p) => $p->page_type === 'home')
            ->reject(fn ($p) => $p->parent_id !== null)
            // Contact is rejected here and re-appended below so the Shop entry
            // can sit before it — see the nav-order comment further down.
            ->reject(fn ($p) => $p->page_type === 'contact')
            ->map(fn ($p) => [
                'type' => 'page',
                'page_id' => $p->id,
                'label' => $p->nav_label ?: ucfirst($p->page_type),
            ])
            ->values()
            ->toArray();

        // Append a Shop nav entry before Contact when the site actually has something to
        // sell. NOT "has a ShopSnapshotCurrent row" — the reconcile gave one to
        // every site, so that predicate blessed 26 non-shops. It was replaced in
        // Site::hasPurchasableShop() but survived here, giving any draft created from
        // defaults a Shop link regardless.
        if ($site->hasPurchasableShop()) {
            $navItems[] = ['type' => 'shop', 'label' => 'Shop'];
        }

        // Re-append Contact last (after Shop) so nav order stays: Pages… [Shop] Contact.
        $contact = $pages->firstWhere('page_type', 'contact');
        if ($contact) {
            $navItems[] = [
                'type' => 'page',
                'page_id' => $contact->id,
                'label' => $contact->nav_label ?: 'Contact',
            ];
        }

        return [
            'nav' => ['items' => $navItems],
            'footer' => [
                'columns' => [],
                'show_credit' => true,
            ],
            'theme' => [
                'primary_override' => null,
                'accent_override' => null,
            ],
            'homepage_page_id' => $home?->id,
        ];
    }
}
