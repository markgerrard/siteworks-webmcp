<?php

namespace App\Http\Requests\Site\Editor;

final class SelectLogoRequest extends EditorOperationRequest
{
    public function rules(): array
    {
        return [
            'concept_id' => ['required', 'integer'],
            'composition_revision' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
