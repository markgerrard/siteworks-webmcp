<?php

namespace App\Http\Requests\Site\Editor;

use Illuminate\Validation\Rule;

final class RestoreImageVersionRequest extends EditorOperationRequest
{
    public function rules(): array
    {
        return [
            'scope' => ['required', 'string', Rule::in(['hero', 'logo'])],
            'version_id' => ['required', 'integer'],
            'composition_revision' => ['sometimes', 'integer', 'min:0'],
            'page_type' => ['sometimes', 'string'],
            'slot' => ['sometimes', 'string'],
        ];
    }
}
