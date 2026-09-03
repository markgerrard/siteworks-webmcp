<?php

namespace App\Http\Controllers\Shop;

use App\Models\Shop\Cart;
use App\Models\Shop\Order;
use App\Models\Site;
use App\Models\SiteEnquiry;
use App\Services\Shop\PersonalisationImageStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PersonalisationFileController
{
    public function __construct(protected PersonalisationImageStore $images) {}

    public function show(Request $request): StreamedResponse
    {
        abort_unless($request->hasValidSignature(), 403);

        $siteId = (int) $request->query('site');
        $path = (string) $request->query('path');
        $site = $request->attributes->get('resolved_site');
        if (! $site instanceof Site) {
            $site = Site::query()->find($siteId);
        }
        abort_unless($site instanceof Site, 403);
        abort_unless($siteId === (int) $site->id, 403);
        abort_unless($this->pathBelongsToSite($path, $siteId), 403);

        $audience = (string) $request->query('audience', 'session');
        abort_unless($this->mayView($request, $site, $path, $audience), 403);

        $disk = $this->images->disk();
        abort_unless($disk->exists($path), 404);

        $mime = $disk->mimeType($path) ?: 'application/octet-stream';
        abort_unless(str_starts_with((string) $mime, 'image/'), 403);

        return $disk->response($path, basename($path), [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="'.basename($path).'"',
        ]);
    }

    private function pathBelongsToSite(string $path, int $siteId): bool
    {
        $prefix = 'sites/'.$siteId.'/personalisation/';

        return $path !== '' && str_starts_with($path, $prefix) && ! str_contains($path, '..');
    }

    private function mayView(Request $request, Site $site, string $path, string $audience): bool
    {
        if ($audience === 'mail') {
            return Auth::check() ? $this->isSiteStaff($site) : true;
        }

        if ($this->isSiteStaff($site)) {
            return true;
        }

        return $this->sessionOwns($request, $site, $path);
    }

    private function isSiteStaff(Site $site): bool
    {
        $user = Auth::user();
        if ($user === null) {
            return false;
        }

        if ($user->isAdmin() || $user->isManager() || $user->isSeniorManager()) {
            return true;
        }

        if ($user->isAgent()) {
            return (int) $site->created_by_user_id === (int) $user->id
                || (int) $site->assigned_to_user_id === (int) $user->id;
        }

        return $user->client_id !== null && (int) $user->client_id === (int) $site->client_id;
    }

    private function sessionOwns(Request $request, Site $site, string $path): bool
    {
        if (! preg_match('#/personalisation/(cart|enquiry|order)-(\d+)/#', $path, $m)) {
            return false;
        }

        $kind = $m[1];
        $id = (int) $m[2];

        if ($kind === 'cart') {
            $sessionId = (string) ($request->cookie(CartController::COOKIE_NAME) ?: '');
            if ($sessionId === '') {
                return false;
            }

            return Cart::query()
                ->whereKey($id)
                ->where('site_id', $site->id)
                ->where('session_cookie_id', $sessionId)
                ->exists();
        }

        if ($kind === 'enquiry') {
            $enquiryId = $request->session()->get('shop.quote_enquiry_id');

            return is_numeric($enquiryId)
                && (int) $enquiryId === $id
                && SiteEnquiry::query()->whereKey($id)->where('site_id', $site->id)->exists();
        }

        $order = Order::query()->whereKey($id)->where('site_id', $site->id)->first();
        if ($order === null) {
            return false;
        }

        $customer = Auth::guard('customer')->user();
        if ($customer && (int) $customer->site_id === (int) $site->id && (int) $order->customer_id === (int) $customer->id) {
            return true;
        }

        return (int) $request->session()->get('shop.last_order_id') === $id;
    }
}
