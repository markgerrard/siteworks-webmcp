<?php

namespace App\Http\Controllers\Shop;

use App\Services\Shop\Fulfilment\FulfilmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FulfilmentController
{
    public function __construct(protected FulfilmentService $fulfilment) {}

    public function check(Request $request): JsonResponse|RedirectResponse
    {
        $site = $request->attributes->get('resolved_site');
        abort_unless($site, 404);

        if ($this->fulfilment->config($site) === null) {
            abort(404);
        }

        if ($request->boolean('change')) {
            $this->fulfilment->forget($request, $site);
            $state = $this->fulfilment->widgetState($site, $request);

            if ($this->wantsJson($request)) {
                return response()->json([
                    'valid' => true,
                    'error' => null,
                    'checked' => false,
                    'postcode' => '',
                    'display' => '',
                    'zone' => null,
                    'lines' => [],
                    'miss' => null,
                    'prompt' => $state['prompt'] ?? null,
                ]);
            }

            return redirect()->to($this->backUrl($request));
        }

        $result = $this->fulfilment->check($site, $request, (string) $request->query('postcode', ''));

        if ($this->wantsJson($request)) {
            return response()->json($result, $result['valid'] ? 200 : 422);
        }

        if (! $result['valid']) {
            return redirect()->to($this->backUrl($request))
                ->withErrors(['postcode' => $result['error'] ?? 'Enter a valid postcode.'])
                ->withInput(['postcode' => $request->query('postcode')]);
        }

        return redirect()->to($this->backUrl($request));
    }

    private function wantsJson(Request $request): bool
    {
        if ($request->wantsJson()) {
            return true;
        }

        return strtolower((string) $request->header('X-Requested-With')) === 'xmlhttprequest';
    }

    private function backUrl(Request $request): string
    {
        $fallback = $request->getSchemeAndHttpHost().'/shop';
        $back = $request->headers->get('referer', $fallback);
        if (! is_string($back) || $back === '') {
            return $fallback;
        }

        $host = parse_url($back, PHP_URL_HOST);
        if ($host !== $request->getHost()) {
            return $fallback;
        }

        return $back;
    }
}
