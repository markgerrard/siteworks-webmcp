<?php

namespace App\Http\Requests\Site\Editor;

final class RestoreMediaVersionRequest extends EditorOperationRequest
{
    public function rules(): array
    {
        return [
            'page_id' => ['required', 'integer', 'min:1'],
            'stored_index' => ['required', 'integer', 'min:0'],
            'field_path' => ['required', 'string', 'max:200'],
            'media_id' => ['required', 'integer'],
            'revision_base' => ['sometimes', 'integer', 'min:1'],
            'structure_epoch' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
