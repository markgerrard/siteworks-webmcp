<?php

namespace App\Http\Requests\Site\Editor;

final class GetProductRequest extends EditorOperationRequest
{
    public function rules(): array
    {
        return [
            'slug' => ['sometimes', 'string'],
            'product_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
