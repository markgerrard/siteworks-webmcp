<?php

namespace App\Services\Shop\Fulfilment;

use App\Models\Site;

final class FulfilmentConfig
{
    public const METHODS = ['delivery', 'collect', 'shipping'];

    public const DEFAULT_DELIVERY_LABEL = 'Local delivery';

    public const DEFAULT_COLLECT_LABEL = 'Click & collect';

    public const DEFAULT_SHIPPING_LABEL = 'Shipping';

    public const DEFAULT_WIDGET_PROMPT = 'Check delivery to your postcode';

    public const DEFAULT_REMEMBER_DAYS = 30;

    public const PREFIX_MAX_LENGTH = 8;

    public const NAME_MAX_LENGTH = 80;

    public const LEAD_TIME_MAX_LENGTH = 40;

    /**
     * @param  array<string, mixed>  $raw
     */
    private function __construct(private readonly array $raw) {}

    public static function from(mixed $raw): ?self
    {
        if (! is_array($raw) || $raw === []) {
            return null;
        }

        return new self($raw);
    }

    public static function fromSite(Site $site): ?self
    {
        return self::from($site->fulfilment);
    }

    public function isActive(): bool
    {
        return $this->methodEnabled('delivery')
            || $this->methodEnabled('collect')
            || $this->methodEnabled('shipping');
    }

    public function methodEnabled(string $method): bool
    {
        $block = $this->raw[$method] ?? null;

        return is_array($block) && ($block['enabled'] ?? false) === true;
    }

    /**
     * @return list<string>
     */
    public function enabledMethods(): array
    {
        return array_values(array_filter(
            self::METHODS,
            fn (string $method): bool => $this->methodEnabled($method),
        ));
    }

    public function label(string $method): string
    {
        $block = is_array($this->raw[$method] ?? null) ? $this->raw[$method] : [];
        $label = is_string($block['label'] ?? null) ? trim($block['label']) : '';

        if ($label !== '') {
            return $label;
        }

        return match ($method) {
            'delivery' => self::DEFAULT_DELIVERY_LABEL,
            'collect' => self::DEFAULT_COLLECT_LABEL,
            default => self::DEFAULT_SHIPPING_LABEL,
        };
    }

    /**
     * @return list<array{name: string, prefixes: list<string>, fee_cents: int, free_over_cents: int|null, lead_time: string, min_order_cents: int|null}>
     */
    public function zones(): array
    {
        $delivery = is_array($this->raw['delivery'] ?? null) ? $this->raw['delivery'] : [];
        $zones = is_array($delivery['zones'] ?? null) ? $delivery['zones'] : [];
        $out = [];

        foreach ($zones as $zone) {
            if (! is_array($zone)) {
                continue;
            }

            $prefixes = [];
            foreach ($zone['prefixes'] ?? [] as $prefix) {
                if (is_string($prefix) && $prefix !== '') {
                    $prefixes[] = strtoupper(preg_replace('/\s+/', '', $prefix) ?? '');
                }
            }

            $out[] = [
                'name' => (string) ($zone['name'] ?? ''),
                'prefixes' => array_values($prefixes),
                'fee_cents' => (int) ($zone['fee_cents'] ?? 0),
                'free_over_cents' => array_key_exists('free_over_cents', $zone) && $zone['free_over_cents'] !== null
                    ? (int) $zone['free_over_cents']
                    : null,
                'lead_time' => (string) ($zone['lead_time'] ?? ''),
                'min_order_cents' => array_key_exists('min_order_cents', $zone) && $zone['min_order_cents'] !== null
                    ? (int) $zone['min_order_cents']
                    : null,
            ];
        }

        return $out;
    }

    public function collectAddress(): string
    {
        $block = is_array($this->raw['collect'] ?? null) ? $this->raw['collect'] : [];

        return is_string($block['address'] ?? null) ? $block['address'] : '';
    }

    public function collectHours(): string
    {
        $block = is_array($this->raw['collect'] ?? null) ? $this->raw['collect'] : [];

        return is_string($block['hours'] ?? null) ? $block['hours'] : '';
    }

    public function collectLeadTime(): string
    {
        $block = is_array($this->raw['collect'] ?? null) ? $this->raw['collect'] : [];

        return is_string($block['lead_time'] ?? null) ? $block['lead_time'] : '';
    }

    public function shippingNote(): string
    {
        $block = is_array($this->raw['shipping'] ?? null) ? $this->raw['shipping'] : [];

        return is_string($block['note'] ?? null) ? $block['note'] : '';
    }

    public function widgetPrompt(): string
    {
        $block = is_array($this->raw['widget'] ?? null) ? $this->raw['widget'] : [];
        $prompt = is_string($block['prompt'] ?? null) ? trim($block['prompt']) : '';

        return $prompt !== '' ? $prompt : self::DEFAULT_WIDGET_PROMPT;
    }

    public function rememberDays(): int
    {
        $block = is_array($this->raw['widget'] ?? null) ? $this->raw['widget'] : [];
        $days = $block['remember_days'] ?? self::DEFAULT_REMEMBER_DAYS;

        if (! is_numeric($days)) {
            return self::DEFAULT_REMEMBER_DAYS;
        }

        return max(1, min(365, (int) $days));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }

    /**
     * JSON Schema for the agent `set_fulfilment` input `fulfilment` object.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $zone = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['name', 'prefixes', 'fee_cents'],
            'properties' => [
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::NAME_MAX_LENGTH],
                'prefixes' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'string',
                        'minLength' => 1,
                        'maxLength' => self::PREFIX_MAX_LENGTH,
                        'pattern' => '^[A-Z0-9]+$',
                    ],
                ],
                'fee_cents' => ['type' => 'integer', 'minimum' => 0],
                'free_over_cents' => ['type' => ['integer', 'null'], 'minimum' => 0],
                'lead_time' => ['type' => 'string', 'maxLength' => self::LEAD_TIME_MAX_LENGTH],
                'min_order_cents' => ['type' => ['integer', 'null'], 'minimum' => 0],
            ],
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'delivery' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'enabled' => ['type' => 'boolean'],
                        'label' => ['type' => 'string', 'maxLength' => 40],
                        'zones' => ['type' => 'array', 'items' => $zone],
                    ],
                ],
                'collect' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'enabled' => ['type' => 'boolean'],
                        'label' => ['type' => 'string', 'maxLength' => 40],
                        'address' => ['type' => 'string', 'maxLength' => 200],
                        'hours' => ['type' => 'string', 'maxLength' => 120],
                        'lead_time' => ['type' => 'string', 'maxLength' => self::LEAD_TIME_MAX_LENGTH],
                    ],
                ],
                'shipping' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'enabled' => ['type' => 'boolean'],
                        'label' => ['type' => 'string', 'maxLength' => 40],
                        'note' => ['type' => 'string', 'maxLength' => 200],
                    ],
                ],
                'widget' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'prompt' => ['type' => 'string', 'maxLength' => 80],
                        'remember_days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 365],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{ok: true, value: array<string, mixed>}|array{ok: false, errors: array<string, list<string>>}
     */
    public static function validate(array $raw): array
    {
        $errors = [];
        $delivery = is_array($raw['delivery'] ?? null) ? $raw['delivery'] : [];
        $collect = is_array($raw['collect'] ?? null) ? $raw['collect'] : [];
        $shipping = is_array($raw['shipping'] ?? null) ? $raw['shipping'] : [];
        $widget = is_array($raw['widget'] ?? null) ? $raw['widget'] : [];

        $deliveryEnabled = (bool) ($delivery['enabled'] ?? false);
        $zonesIn = is_array($delivery['zones'] ?? null) ? array_values($delivery['zones']) : [];
        $seenPrefixes = [];
        $zones = [];

        if ($deliveryEnabled && $zonesIn === []) {
            $errors['delivery.zones'][] = 'Add at least one delivery zone.';
        }

        foreach ($zonesIn as $index => $zone) {
            if (! is_array($zone)) {
                $errors["delivery.zones.{$index}"][] = 'Each zone must be an object.';

                continue;
            }

            $name = is_string($zone['name'] ?? null) ? trim($zone['name']) : '';
            if ($name === '') {
                $errors["delivery.zones.{$index}.name"][] = 'Each zone needs a name.';
            } elseif (mb_strlen($name) > self::NAME_MAX_LENGTH) {
                $errors["delivery.zones.{$index}.name"][] = 'Zone names must be 80 characters or fewer.';
            }

            $prefixItems = is_array($zone['prefixes'] ?? null) ? $zone['prefixes'] : [];
            $prefixes = [];
            foreach ($prefixItems as $prefix) {
                if (! is_string($prefix)) {
                    $errors["delivery.zones.{$index}.prefixes"][] = 'Prefixes must be text.';

                    continue;
                }
                $normalised = strtoupper(preg_replace('/\s+/', '', $prefix) ?? '');
                if ($normalised === '') {
                    $errors["delivery.zones.{$index}.prefixes"][] = 'Prefixes cannot be empty.';

                    continue;
                }
                if (strlen($normalised) > self::PREFIX_MAX_LENGTH) {
                    $errors["delivery.zones.{$index}.prefixes"][] = 'Prefixes must be 8 characters or fewer.';

                    continue;
                }
                if (! preg_match('/^[A-Z0-9]+$/', $normalised)) {
                    $errors["delivery.zones.{$index}.prefixes"][] = 'Prefixes must be letters and numbers only.';

                    continue;
                }
                if (isset($seenPrefixes[$normalised])) {
                    $errors["delivery.zones.{$index}.prefixes"][] = "Prefix {$normalised} is already used on another zone.";

                    continue;
                }
                $seenPrefixes[$normalised] = true;
                $prefixes[] = $normalised;
            }

            if ($prefixes === []) {
                $errors["delivery.zones.{$index}.prefixes"][] = 'Each zone needs at least one prefix.';
            }

            $fee = $zone['fee_cents'] ?? 0;
            if (! is_int($fee) && ! (is_string($fee) && is_numeric($fee))) {
                $errors["delivery.zones.{$index}.fee_cents"][] = 'Fee must be 0 or more.';
                $fee = 0;
            } elseif ((int) $fee < 0) {
                $errors["delivery.zones.{$index}.fee_cents"][] = 'Fee must be 0 or more.';
            }

            $leadTime = is_string($zone['lead_time'] ?? null) ? trim($zone['lead_time']) : '';
            if (strlen($leadTime) > self::LEAD_TIME_MAX_LENGTH) {
                $errors["delivery.zones.{$index}.lead_time"][] = 'Lead time must be 40 characters or fewer.';
            }

            $freeOver = $zone['free_over_cents'] ?? null;
            if ($freeOver === '' || $freeOver === false) {
                $freeOver = null;
            }
            if ($freeOver !== null && (! is_numeric($freeOver) || (int) $freeOver < 0)) {
                $errors["delivery.zones.{$index}.free_over_cents"][] = 'Free-over must be 0 or more.';
                $freeOver = null;
            }

            $minOrder = $zone['min_order_cents'] ?? null;
            if ($minOrder === '' || $minOrder === false) {
                $minOrder = null;
            }
            if ($minOrder !== null && (! is_numeric($minOrder) || (int) $minOrder < 0)) {
                $errors["delivery.zones.{$index}.min_order_cents"][] = 'Minimum order must be 0 or more.';
                $minOrder = null;
            }

            $zones[] = [
                'name' => $name,
                'prefixes' => array_values($prefixes),
                'fee_cents' => (int) $fee,
                'free_over_cents' => $freeOver === null ? null : (int) $freeOver,
                'lead_time' => $leadTime,
                'min_order_cents' => $minOrder === null ? null : (int) $minOrder,
            ];
        }

        $deliveryLabel = self::boundedLabel($delivery['label'] ?? null, self::DEFAULT_DELIVERY_LABEL, $errors, 'delivery.label');
        $collectLabel = self::boundedLabel($collect['label'] ?? null, self::DEFAULT_COLLECT_LABEL, $errors, 'collect.label');
        $shippingLabel = self::boundedLabel($shipping['label'] ?? null, self::DEFAULT_SHIPPING_LABEL, $errors, 'shipping.label');

        $collectLead = is_string($collect['lead_time'] ?? null) ? trim($collect['lead_time']) : '';
        if (strlen($collectLead) > self::LEAD_TIME_MAX_LENGTH) {
            $errors['collect.lead_time'][] = 'Lead time must be 40 characters or fewer.';
        }

        $prompt = is_string($widget['prompt'] ?? null) ? trim($widget['prompt']) : self::DEFAULT_WIDGET_PROMPT;
        if (strlen($prompt) > 80) {
            $errors['widget.prompt'][] = 'The widget prompt must be 80 characters or fewer.';
        }

        $remember = $widget['remember_days'] ?? self::DEFAULT_REMEMBER_DAYS;
        if (! is_numeric($remember) || (int) $remember < 1 || (int) $remember > 365) {
            $errors['widget.remember_days'][] = 'Remember days must be between 1 and 365.';
            $remember = self::DEFAULT_REMEMBER_DAYS;
        }

        $value = [
            'delivery' => [
                'enabled' => $deliveryEnabled,
                'label' => $deliveryLabel,
                'zones' => $zones,
            ],
            'collect' => [
                'enabled' => (bool) ($collect['enabled'] ?? false),
                'label' => $collectLabel,
                'address' => self::boundedString($collect['address'] ?? '', 200),
                'hours' => self::boundedString($collect['hours'] ?? '', 120),
                'lead_time' => $collectLead,
            ],
            'shipping' => [
                'enabled' => (bool) ($shipping['enabled'] ?? false),
                'label' => $shippingLabel,
                'note' => self::boundedString($shipping['note'] ?? '', 200),
            ],
            'widget' => [
                'prompt' => $prompt !== '' ? $prompt : self::DEFAULT_WIDGET_PROMPT,
                'remember_days' => (int) $remember,
            ],
        ];

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        return ['ok' => true, 'value' => $value];
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private static function boundedLabel(mixed $value, string $default, array &$errors, string $key): string
    {
        $label = is_string($value) ? trim($value) : '';
        if (strlen($label) > 40) {
            $errors[$key][] = 'Labels must be 40 characters or fewer.';

            return $default;
        }

        return $label !== '' ? $label : $default;
    }

    private static function boundedString(mixed $value, int $max): string
    {
        $text = is_string($value) ? $value : '';

        return mb_substr($text, 0, $max);
    }
}
