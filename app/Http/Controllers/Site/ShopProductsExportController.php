<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Shop\ProductsExporter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The HUMAN export control — reachable from both the agents products list
 * (`sites.shop.products.export`) and the client portal products page
 * (`client.portal.shop.products.export`), same controller, same
 * ProductsExporter data-building path: a client
 * exporting their own catalogue is authorised the same way they view it
 * (SitePolicy `view`), not staff-only — that stayed `delete` historically
 * only because export and delete shared one ability check by accident.
 * The WebMCP `export_products` operation is a separate, staff-only,
 * signed-URL surface (App\Services\Site\Editor\Operations\ExportProductsOperation) —
 * this route is unrelated to that gate.
 */
class ShopProductsExportController extends Controller
{
    public function __invoke(Request $request, Site $site, ProductsExporter $exporter): StreamedResponse
    {
        $this->authorize('view', $site);
        abort_unless($site->shopEnabled(), 404);

        $format = self::paramIn($request, 'format', ProductsExporter::FORMATS, 'csv');
        $status = self::paramIn($request, 'status', ProductsExporter::STATUSES, 'any');
        $categorySlug = $request->query('category_slug');
        $categorySlug = is_string($categorySlug) && $categorySlug !== '' ? $categorySlug : null;

        $products = $exporter->collect($site, $status, $categorySlug);
        $content = $exporter->render($products, $format);
        $filename = $exporter->filename($site, $format);
        $mime = $exporter->mime($format);

        return response()->streamDownload(function () use ($content): void {
            echo $content;
        }, $filename, [
            'Content-Type' => $mime.'; charset=UTF-8',
        ]);
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function paramIn(Request $request, string $key, array $allowed, string $default): string
    {
        $value = $request->query($key, $default);

        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }
}
