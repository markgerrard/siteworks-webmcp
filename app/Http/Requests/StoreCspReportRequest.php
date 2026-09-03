<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

class StoreCspReportRequest extends FormRequest
{
    public const MAX_FIELD_CHARS = 256;

    public const MAX_BODY_BYTES = 16384;

    /**
     * @var list<string>
     */
    public const ALLOWED_CONTENT_TYPES = [
        'application/csp-report',
        'application/json',
        'application/reports+json',
    ];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->contentTypeIsAllowed()) {
            throw new HttpResponseException(response()->json([
                'message' => 'Unsupported Media Type',
            ], 415));
        }

        if ($this->bodyExceedsLimit()) {
            throw new HttpResponseException(response()->json([
                'message' => 'Payload Too Large',
            ], 413));
        }

        $decoded = json_decode($this->getContent(), true);
        if (! is_array($decoded)) {
            throw new HttpResponseException(response()->json([
                'message' => 'Invalid CSP report',
            ], 422));
        }

        $this->replace($decoded);
    }

    /**
     * @return array<string, ValidationRule|array<int, string>|string>
     */
    public function rules(): array
    {
        $clip = 'max:'.self::MAX_FIELD_CHARS;

        return [
            'csp-report' => ['required', 'array'],
            'csp-report.document-uri' => ['nullable', 'string', $clip],
            'csp-report.violated-directive' => ['nullable', 'string', $clip],
            'csp-report.effective-directive' => ['nullable', 'string', $clip],
            'csp-report.blocked-uri' => ['nullable', 'string', $clip],
            'csp-report.source-file' => ['nullable', 'string', $clip],
            'csp-report.line-number' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'csp-report.column-number' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Invalid CSP report',
        ], 422));
    }

    private function contentTypeIsAllowed(): bool
    {
        $type = strtolower(trim(Str::before((string) $this->header('Content-Type', ''), ';')));

        return in_array($type, self::ALLOWED_CONTENT_TYPES, true);
    }

    private function bodyExceedsLimit(): bool
    {
        $declared = (int) $this->header('Content-Length', 0);

        return $declared > self::MAX_BODY_BYTES
            || strlen($this->getContent()) > self::MAX_BODY_BYTES;
    }
}
