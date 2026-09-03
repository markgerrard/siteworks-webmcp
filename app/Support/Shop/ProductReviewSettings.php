<?php

namespace App\Support\Shop;

use App\Models\Site;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ProductReviewSettings
{
    public const DEFAULT_LABEL = 'Reviews';

    public function __construct(
        public readonly bool $enabled,
        public readonly string $label,
        public readonly bool $publicForm,
        public readonly bool $moderate,
        public readonly bool $showOnCards,
        public readonly int $minReviewsForCard,
    ) {}

    /**
     * @param  array<string, mixed>|null  $raw
     */
    public static function from(?array $raw): self
    {
        $raw = is_array($raw) ? $raw : [];

        return new self(
            enabled: (bool) ($raw['enabled'] ?? false),
            label: self::normaliseLabel($raw['label'] ?? self::DEFAULT_LABEL),
            publicForm: (bool) ($raw['public_form'] ?? false),
            moderate: array_key_exists('moderate', $raw) ? (bool) $raw['moderate'] : true,
            showOnCards: array_key_exists('show_on_cards', $raw) ? (bool) $raw['show_on_cards'] : true,
            minReviewsForCard: max(1, (int) ($raw['min_reviews_for_card'] ?? 1)),
        );
    }

    public static function fromSite(Site $site): self
    {
        return self::from(is_array($site->reviews_settings) ? $site->reviews_settings : null);
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function validate(array $input): self
    {
        $validator = Validator::make($input, [
            'enabled' => ['required', 'boolean'],
            'label' => ['required', 'string', 'min:1', 'max:40'],
            'public_form' => ['required', 'boolean'],
            'moderate' => ['required', 'boolean'],
            'show_on_cards' => ['required', 'boolean'],
            'min_reviews_for_card' => ['required', 'integer', 'min:1', 'max:99'],
        ]);

        $validated = $validator->validate();
        $validated['label'] = self::normaliseLabel($validated['label']);

        if ($validated['label'] === '') {
            throw ValidationException::withMessages([
                'label' => ['The reviews label is required.'],
            ]);
        }

        return self::from($validated);
    }

    /**
     * @return array{enabled: bool, label: string, public_form: bool, moderate: bool, show_on_cards: bool, min_reviews_for_card: int}
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'label' => $this->label,
            'public_form' => $this->publicForm,
            'moderate' => $this->moderate,
            'show_on_cards' => $this->showOnCards,
            'min_reviews_for_card' => $this->minReviewsForCard,
        ];
    }

    public function merge(string $key, mixed $value): self
    {
        $payload = $this->toArray();
        $payload[$key] = $value;

        return self::validate($payload);
    }

    private static function normaliseLabel(mixed $label): string
    {
        if (! is_string($label)) {
            return self::DEFAULT_LABEL;
        }

        $trimmed = trim($label);

        return $trimmed === '' ? '' : mb_substr($trimmed, 0, 40);
    }
}
