<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCspReportRequest;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CspReportController extends Controller
{
    public function __invoke(StoreCspReportRequest $request): Response
    {
        $report = $request->validated()['csp-report'];

        Log::channel('csp')->warning('csp-violation', [
            'document-uri' => $this->clip($report['document-uri'] ?? null),
            'violated-directive' => $this->clip($report['violated-directive'] ?? null),
            'blocked-uri' => $this->clip($report['blocked-uri'] ?? null),
            'source-file' => $this->clip($report['source-file'] ?? null),
            'line-number' => $report['line-number'] ?? null,
            'user-agent' => $this->clip($request->userAgent(), 200),
        ]);

        return response()->noContent();
    }

    private function clip(mixed $value, int $limit = StoreCspReportRequest::MAX_FIELD_CHARS): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return Str::limit($value, $limit, '');
    }
}
