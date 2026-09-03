<?php

namespace App\Http\Requests\Site\Editor;

use Illuminate\Validation\Rule;

final class ListProductsRequest extends EditorOperationRequest
{
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in(['draft', 'published', 'archived', 'any'])],
            'category_slug' => ['sometimes', 'string'],
            'q' => ['sometimes', 'string'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'cursor' => ['sometimes', 'string'],
        ];
    }
}
