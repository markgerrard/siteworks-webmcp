<?php

namespace App\Http\Requests\Site\Editor;

final class SetProductImageRequest extends EditorOperationRequest
{
    public function rules(): array
    {
        return [
            'slug' => ['sometimes', 'string'],
            'product_id' => ['sometimes', 'integer', 'min:1'],
            'product_revision' => ['required', 'integer', 'min:0'],
            'media_id' => ['required', 'integer', 'min:1'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:19'],
            'alt' => ['sometimes', 'string'],
            'catalogue_revision' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
