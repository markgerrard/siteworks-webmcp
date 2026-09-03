<?php

namespace App\Exceptions;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

class AiServiceRequestException extends RequestException
{
    public function __construct(
        Response $response,
        string $path,
        public readonly string $provider,
    ) {
        parent::__construct($response);

        $detail = $response->json('detail', $response->body());
        if (is_array($detail)) {
            $detail = json_encode($detail);
        }

        $this->message = "AI service {$path} failed: {$detail}";
    }
}
