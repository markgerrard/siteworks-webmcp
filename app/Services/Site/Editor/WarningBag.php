<?php

namespace App\Services\Site\Editor;

use InvalidArgumentException;

final class WarningBag
{
    /**
     * @var list<array<string, mixed>>
     */
    private array $warnings = [];

    public function add(
        string $code,
        string $message,
        ?string $path = null,
        string $severity = 'warn',
    ): void {
        WarningCodes::assert($code);

        if (! in_array($severity, ['info', 'warn'], true)) {
            throw new InvalidArgumentException("Unknown warning severity [{$severity}].");
        }

        $this->warnings[] = [
            'code' => $code,
            'message' => $message,
            ...($path === null ? [] : ['path' => $path]),
            'severity' => $severity,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->warnings;
    }

    public function has(string $code): bool
    {
        foreach ($this->warnings as $warning) {
            if ($warning['code'] === $code) {
                return true;
            }
        }

        return false;
    }
}
