<?php

namespace App\Http\Controllers\Shop;

use App\Services\Shop\RenderContext;
use App\Services\Shop\SnapshotReader;
use Illuminate\Http\Request;

class EnquireController
{
    public function __construct(protected SnapshotReader $reader) {}

    public function show(Request $request)
    {
        $site = $request->attributes->get('resolved_site');
        abort_unless($site, 404);

        $slug = (string) $request->query('product', '');
        abort_unless($slug !== '', 404);

        $json = $this->reader->forSite($site->id);
        abort_unless($json, 404);

        $ctx = RenderContext::fromRequest($request, $request->attributes->getBoolean('is_preview_host'));
        $json = $ctx->filterSnapshot($json);

        $product = $json['products'][$slug] ?? null;
        abort_unless(is_array($product), 404);

        $name = $product['product_detail']['name']
            ?? $product['product_card']['name']
            ?? $slug;

        return view('shop.enquire', [
            'site' => $site,
            'product' => $product,
            'productName' => $name,
            'message' => "I'd like to enquire about {$name}.",
        ]);
    }
}
