<?php

namespace App\Http\Requests\Site\Editor;

final class UploadImageRequest extends EditorOperationRequest
{
    public function rules(): array
    {
        if ($this->hasFile('file')) {
            return ['file' => ['required', 'file', 'image', 'max:5120']];
        }

        return [
            'data_base64' => ['required', 'string'],
            'composition_revision' => ['sometimes', 'integer', 'min:0'],
            'mime' => ['sometimes', 'string'],
            'filename' => ['sometimes', 'string'],
            'page_id' => ['sometimes', 'integer', 'min:1'],
            'stored_index' => ['sometimes', 'integer', 'min:0'],
            'field_path' => ['sometimes', 'string', 'max:200'],
            'revision_base' => ['sometimes', 'integer', 'min:1'],
            'structure_epoch' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
