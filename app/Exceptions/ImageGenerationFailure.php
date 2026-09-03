<?php

namespace App\Exceptions;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use RuntimeException;
use Throwable;

class ImageGenerationFailure extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $provider,
        public readonly string $breakerReason = 'unknown',
        public readonly ?int $httpStatus = null,
        public readonly string $source = 'internal_error',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public static function fromProviderCall(Throwable $exception, string $provider): self
    {
        if ($exception instanceof self) {
            return $exception;
        }

        [$breakerReason, $httpStatus, $source] = self::breakerClassification($exception);

        return new self(
            message: $exception->getMessage(),
            provider: $provider,
            breakerReason: $breakerReason,
            httpStatus: $httpStatus,
            source: $source,
            previous: $exception,
        );
    }

    /**
     * @return array{0: string, 1: ?int, 2: string}
     */
    public static function breakerClassification(Throwable $exception): array
    {
        $httpStatus = null;
        $source = 'internal_error';

        if ($exception instanceof RequestException) {
            $httpStatus = $exception->response?->status();
            $source = 'provider_error';
        } elseif ($exception instanceof ConnectionException) {
            $source = 'provider_error';
        }

        $reason = $httpStatus !== null ? "http_{$httpStatus}" : 'unknown';

        return [$reason, $httpStatus, $source];
    }

    public static function qaHardFail(string $message, string $provider): self
    {
        return new self(
            $message,
            $provider,
            'qa_hard_fail',
            source: 'provider_error',
        );
    }

    /**
     * @return array{provider: string, breaker_reason: string, http_status: ?int}
     */
    public function context(): array
    {
        return [
            'provider' => $this->provider,
            'breaker_reason' => $this->breakerReason,
            'http_status' => $this->httpStatus,
        ];
    }
}
