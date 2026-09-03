<?php

namespace App\Services\Shop\Fulfilment;

use App\Models\Site;
use App\Support\Postcode\PostcodeNormaliser;
use App\Support\Postcode\PostcodeNormaliserFactory;
use App\Support\ShopMoney;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class FulfilmentService
{
    public const SESSION_KEY = 'shop.fulfilment';

    public function __construct(
        private readonly ZoneMatcher $matcher,
        private readonly PostcodeNormaliserFactory $normalisers,
    ) {}

    public function config(Site $site): ?FulfilmentConfig
    {
        $config = FulfilmentConfig::fromSite($site);

        return $config?->isActive() ? $config : null;
    }

    public function normaliser(Site $site): PostcodeNormaliser
    {
        return $this->normalisers->forCountry($site->shopCountryCode());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function sessionFor(Site $site, Request $request): ?array
    {
        $config = $this->config($site);
        if ($config === null) {
            return null;
        }

        $payload = $request->session()->get(self::SESSION_KEY);
        if (! is_array($payload)) {
            return null;
        }

        if ((int) ($payload['site_id'] ?? 0) !== (int) $site->id) {
            return null;
        }

        $checkedAt = is_string($payload['checked_at'] ?? null) ? $payload['checked_at'] : null;
        if ($checkedAt === null) {
            return null;
        }

        $expires = strtotime($checkedAt.' + '.$config->rememberDays().' days');
        if ($expires === false || $expires < time()) {
            return null;
        }

        $currentHash = $this->configHash($site);
        if (($payload['config_hash'] ?? '') !== $currentHash) {
            $postcode = is_string($payload['postcode'] ?? null) ? $payload['postcode'] : '';
            $payload['zone'] = $postcode !== '' ? $this->matchedZone($site, $postcode) : null;
            $payload['config_hash'] = $currentHash;
            $request->session()->put(self::SESSION_KEY, $payload);
        }

        return $payload;
    }

    public function configHash(Site $site): string
    {
        $config = $this->config($site);
        if ($config === null) {
            return '';
        }

        return hash('sha256', json_encode($config->toArray()) ?: '');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function widgetState(Site $site, Request $request): ?array
    {
        $config = $this->config($site);
        if ($config === null) {
            return null;
        }

        $session = $this->sessionFor($site, $request);
        $zone = is_array($session['zone'] ?? null) ? $session['zone'] : null;
        $display = is_string($session['display'] ?? null) ? $session['display'] : '';
        $postcode = is_string($session['postcode'] ?? null) ? $session['postcode'] : '';

        $lines = [];
        $miss = null;
        if ($postcode !== '') {
            $built = $this->resultCopy($config, $site, $zone, $display !== '' ? $display : $postcode);
            $lines = $built['lines'];
            $miss = $built['miss'];
        }

        return [
            'prompt' => $config->widgetPrompt(),
            'remember_days' => $config->rememberDays(),
            'postcode' => $postcode,
            'display' => $display,
            'zone' => $zone,
            'lines' => $lines,
            'miss' => $miss,
            'checked' => $postcode !== '',
            'check_url' => url('/shop/fulfilment/check'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function check(Site $site, Request $request, string $postcode): array
    {
        $config = $this->config($site);
        if ($config === null) {
            return [
                'valid' => false,
                'error' => 'Delivery check is not available.',
                'postcode' => '',
                'display' => '',
                'zone' => null,
                'lines' => [],
                'miss' => null,
            ];
        }

        $normaliser = $this->normaliser($site);
        $display = trim($postcode);
        $normalised = $normaliser->normalise($display);

        if ($normalised === '' || ! $normaliser->isValid($normalised)) {
            return [
                'valid' => false,
                'error' => 'Enter a valid postcode.',
                'postcode' => $normalised,
                'display' => $display,
                'zone' => null,
                'lines' => [],
                'miss' => null,
            ];
        }

        $zone = $config->methodEnabled('delivery')
            ? $this->matcher->match($normalised, $config->zones(), $normaliser)
            : null;

        $copy = $this->resultCopy($config, $site, $zone, $this->prettyPostcode($normalised, $display, $site));

        $payload = [
            'site_id' => (int) $site->id,
            'postcode' => $normalised,
            'display' => $copy['display'],
            'zone' => $zone,
            'checked_at' => now()->toIso8601String(),
            'config_hash' => $this->configHash($site),
        ];
        $request->session()->put(self::SESSION_KEY, $payload);

        return [
            'valid' => true,
            'error' => null,
            'postcode' => $normalised,
            'display' => $copy['display'],
            'zone' => $zone,
            'lines' => $copy['lines'],
            'miss' => $copy['miss'],
        ];
    }

    public function forget(Request $request, Site $site): void
    {
        $payload = $request->session()->get(self::SESSION_KEY);
        if (is_array($payload) && (int) ($payload['site_id'] ?? 0) === (int) $site->id) {
            $request->session()->forget(self::SESSION_KEY);
        }
    }

    /**
     * @return array{name: string, matched_prefix?: string, fee_cents: int, free_over_cents: int|null, lead_time: string, min_order_cents: int|null}|null
     */
    public function matchedZone(Site $site, string $postcode): ?array
    {
        $config = $this->config($site);
        if ($config === null || ! $config->methodEnabled('delivery')) {
            return null;
        }

        $normaliser = $this->normaliser($site);
        $normalised = $normaliser->normalise($postcode);
        if ($normalised === '' || ! $normaliser->isValid($normalised)) {
            return null;
        }

        return $this->matcher->match($normalised, $config->zones(), $normaliser);
    }

    public function defaultMethod(Site $site, Request $request): ?string
    {
        $config = $this->config($site);
        if ($config === null) {
            return null;
        }

        $enabled = $config->enabledMethods();
        if ($enabled === []) {
            return null;
        }

        $session = $this->sessionFor($site, $request);
        $zone = is_array($session['zone'] ?? null) ? $session['zone'] : null;

        if ($zone !== null && $config->methodEnabled('delivery')) {
            return 'delivery';
        }

        foreach (['collect', 'shipping', 'delivery'] as $method) {
            if (in_array($method, $enabled, true)) {
                return $method;
            }
        }

        return $enabled[0];
    }

    /**
     * @return array{cost_cents: int, method_label: string, zone_name: ?string, fee_cents: int, shipping_visible: bool, collect_address: ?string, delivery_pending?: bool, minimum_order_message?: string}
     */
    public function quoteMethod(Site $site, string $method, string $postcode, int $subtotalCents, int $fallbackCostCents, string $fallbackLabel, bool $strict = true): array
    {
        $config = $this->config($site);
        if ($config === null) {
            return [
                'cost_cents' => $fallbackCostCents,
                'method_label' => $fallbackLabel,
                'zone_name' => null,
                'fee_cents' => $fallbackCostCents,
                'shipping_visible' => true,
                'collect_address' => null,
            ];
        }

        if (! $config->methodEnabled($method)) {
            if (! $strict) {
                return [
                    'cost_cents' => $fallbackCostCents,
                    'method_label' => $fallbackLabel,
                    'zone_name' => null,
                    'fee_cents' => $fallbackCostCents,
                    'shipping_visible' => true,
                    'collect_address' => null,
                ];
            }
            throw ValidationException::withMessages([
                'fulfilment_method' => 'Choose a delivery option.',
            ]);
        }

        if ($method === 'collect') {
            return [
                'cost_cents' => 0,
                'method_label' => $config->label('collect'),
                'zone_name' => null,
                'fee_cents' => 0,
                'shipping_visible' => false,
                'collect_address' => $config->collectAddress(),
            ];
        }

        if ($method === 'shipping') {
            return [
                'cost_cents' => $fallbackCostCents,
                'method_label' => $config->label('shipping') !== FulfilmentConfig::DEFAULT_SHIPPING_LABEL
                    ? $config->label('shipping')
                    : $fallbackLabel,
                'zone_name' => null,
                'fee_cents' => $fallbackCostCents,
                'shipping_visible' => true,
                'collect_address' => null,
            ];
        }

        $zone = $this->matchedZone($site, $postcode);
        if ($zone === null) {
            if (! $strict) {
                return [
                    'cost_cents' => 0,
                    'method_label' => $config->label('delivery'),
                    'zone_name' => null,
                    'fee_cents' => 0,
                    'shipping_visible' => true,
                    'collect_address' => null,
                    'delivery_pending' => true,
                ];
            }
            $normaliser = $this->normaliser($site);
            $normalised = $normaliser->normalise($postcode);
            throw ValidationException::withMessages([
                'postcode' => ($normalised === '' || ! $normaliser->isValid($normalised))
                    ? 'Enter a valid postcode.'
                    : 'This postcode is not in our delivery area.',
            ]);
        }

        $minOrder = $zone['min_order_cents'];
        if ($minOrder !== null && $subtotalCents < $minOrder) {
            $minimumOrderMessage = $this->minimumOrderMessage($site, $zone['name'], $minOrder);
            if (! $strict) {
                return [
                    'cost_cents' => (int) $zone['fee_cents'],
                    'method_label' => $config->label('delivery'),
                    'zone_name' => $zone['name'],
                    'fee_cents' => (int) $zone['fee_cents'],
                    'shipping_visible' => true,
                    'collect_address' => null,
                    'minimum_order_message' => $minimumOrderMessage,
                ];
            }
            throw ValidationException::withMessages([
                'fulfilment_method' => $minimumOrderMessage,
            ]);
        }

        $fee = $zone['fee_cents'];
        $freeOver = $zone['free_over_cents'];
        if ($freeOver !== null && $subtotalCents >= $freeOver) {
            $fee = 0;
        }

        return [
            'cost_cents' => $fee,
            'method_label' => $config->label('delivery'),
            'zone_name' => $zone['name'],
            'fee_cents' => $fee,
            'shipping_visible' => true,
            'collect_address' => null,
        ];
    }

    /**
     * @param  array{name: string, matched_prefix?: string, fee_cents: int, free_over_cents: int|null, lead_time: string, min_order_cents: int|null}|null  $zone
     * @return array{display: string, lines: list<array{method: string, text: string}>, miss: ?string}
     */
    public function resultCopy(FulfilmentConfig $config, Site $site, ?array $zone, string $display): array
    {
        $currency = $site->shop_currency ?? 'GBP';
        $lines = [];
        $miss = null;

        if ($config->methodEnabled('delivery')) {
            if ($zone !== null) {
                $parts = [
                    $config->label('delivery').' to '.($zone['matched_prefix'] ?? $display).': '.ShopMoney::format((int) $zone['fee_cents'], $currency),
                ];
                if (($zone['lead_time'] ?? '') !== '') {
                    $parts[] = $zone['lead_time'];
                }
                if ($zone['free_over_cents'] !== null) {
                    $parts[] = 'free over '.ShopMoney::format((int) $zone['free_over_cents'], $currency);
                }
                $lines[] = ['method' => 'delivery', 'text' => implode(' · ', $parts)];
            } else {
                $miss = $this->missMessage($config);
            }
        }

        if ($config->methodEnabled('collect')) {
            $parts = [$config->label('collect')];
            if ($config->collectAddress() !== '') {
                $parts[] = $config->collectAddress();
            }
            if ($config->collectLeadTime() !== '') {
                $parts[] = $config->collectLeadTime();
            }
            $lines[] = ['method' => 'collect', 'text' => implode(' · ', $parts)];
        }

        if ($config->methodEnabled('shipping')) {
            $text = $config->label('shipping');
            if ($config->shippingNote() !== '') {
                $text .= ' · '.$config->shippingNote();
            }
            $lines[] = ['method' => 'shipping', 'text' => $text];
        }

        return [
            'display' => $display,
            'lines' => $lines,
            'miss' => $miss,
        ];
    }

    private function missMessage(FulfilmentConfig $config): string
    {
        $options = [];
        if ($config->methodEnabled('collect')) {
            $options[] = 'you can collect';
        }
        if ($config->methodEnabled('shipping')) {
            $options[] = 'we ship nationwide';
        }

        if ($options === []) {
            return 'Not in our delivery area';
        }

        return 'Not in our delivery area — '.implode(' or ', $options);
    }

    private function minimumOrderMessage(Site $site, string $zoneName, int $minimumOrderCents): string
    {
        return 'Minimum order '.ShopMoney::format($minimumOrderCents, $site->shop_currency ?? 'GBP').' for delivery to '.$zoneName;
    }

    private function prettyPostcode(string $normalised, string $original, Site $site): string
    {
        if ($site->shopCountryCode() === 'GB' && strlen($normalised) >= 5) {
            return substr($normalised, 0, -3).' '.substr($normalised, -3);
        }

        return $original !== '' ? $original : $normalised;
    }
}
