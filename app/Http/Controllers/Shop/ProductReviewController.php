<?php

namespace App\Http\Controllers\Shop;

use App\Enums\Shop\ProductReviewSource;
use App\Enums\Shop\ProductReviewStatus;
use App\Models\Shop\ProductReview;
use App\Services\Shop\SnapshotReader;
use App\Support\Shop\ProductReviewSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductReviewController
{
    public function __construct(protected SnapshotReader $reader) {}

    public function create(Request $request, string $slug): View
    {
        [$site, $product, $settings] = $this->formContext($request, $slug);

        return view('shop.review-form', [
            'site' => $site,
            'product' => $product,
            'reviewSettings' => $settings,
            'honeypotField' => $site->enquiryHoneypotFieldName(),
        ]);
    }

    public function store(Request $request, string $slug): RedirectResponse
    {
        [$site, $product, $settings] = $this->formContext($request, $slug);
        $honeypotField = $site->enquiryHoneypotFieldName();

        $validated = $request->validate([
            'author_name' => ['required', 'string', 'max:60'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'title' => ['required', 'string', 'max:80'],
            'body' => ['required', 'string', 'max:2000'],
            $honeypotField => ['nullable', 'string', 'max:255'],
        ]);

        $pdp = \App\Support\Shop\ShopUrls::productAbsolute($product['slug']);

        if (($validated[$honeypotField] ?? '') !== '') {
            return redirect($pdp)->with('status', 'Thanks — your review is awaiting approval.');
        }

        $status = $settings->moderate ? ProductReviewStatus::Pending : ProductReviewStatus::Published;

        ProductReview::create([
            'site_id' => $site->id,
            'product_id' => $product['id'],
            'rating' => $validated['rating'],
            'title' => $validated['title'],
            'body' => $validated['body'],
            'author_name' => $validated['author_name'],
            'author_email_hash' => null,
            'status' => $status,
            'source' => ProductReviewSource::Shopper,
            'ip_hash' => hash('sha256', (string) $request->ip()),
        ]);

        $message = $status === ProductReviewStatus::Pending
            ? 'Thanks — your review is awaiting approval.'
            : 'Thanks — your review is awaiting approval.';

        return redirect($pdp)->with('status', $message);
    }

    /**
     * @return array{0: \App\Models\Site, 1: array<string, mixed>, 2: ProductReviewSettings}
     */
    private function formContext(Request $request, string $slug): array
    {
        $site = $request->attributes->get('resolved_site');
        abort_unless($site, 404);

        $settings = ProductReviewSettings::fromSite($site);
        abort_unless($settings->enabled && $settings->publicForm, 404);

        $json = $this->reader->forSite($site->id);
        abort_unless($json, 404);

        $product = $json['products'][$slug] ?? null;
        abort_unless(is_array($product), 404);

        return [$site, $product, $settings];
    }
}
