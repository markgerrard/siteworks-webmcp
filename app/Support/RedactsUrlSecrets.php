<?php

namespace App\Support;

/** Scrubs credentials from URLs before free text is persisted or logged. */
class RedactsUrlSecrets
{
    private const PATTERN = '/([?&](?:token|key|api_key|apikey|access_token|auth_token|secret|signature|password)=)[^&\s\'"]+/i';

    public static function scrub(string $message): string
    {
        return (string) preg_replace(self::PATTERN, '$1[redacted]', $message);
    }
}
