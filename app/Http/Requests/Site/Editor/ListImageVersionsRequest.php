<?php

namespace App\Http\Requests\Site\Editor;

use Illuminate\Validation\Rule;

final class ListImageVersionsRequest extends EditorOperationRequest
{
    public function rules(): array
    {
        return [
            'scope' => ['required', 'string', Rule::in(['hero', 'logo', 'media'])],
            'stored_index' => ['sometimes', 'integer', 'min:0'],
            'page_type' => ['sometimes', 'string'],
            'slot' => ['sometimes', 'string'],
            'page_id' => ['sometimes', 'integer', 'min:1'],
            'field_path' => ['sometimes', 'string'],
        ];
    }
}
