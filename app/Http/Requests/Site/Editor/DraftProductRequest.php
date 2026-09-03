<?php

namespace App\Http\Requests\Site\Editor;

final class DraftProductRequest extends EditorOperationRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string'],
            'description' => ['sometimes', 'string'],
            'category_slug' => ['required', 'string'],
            'tax_class_code' => ['sometimes', 'string'],
            'variants' => ['required', 'array', 'min:1', 'max:20'],
            'variants.*.sku' => ['required', 'string', 'max:32'],
            'variants.*.label' => ['sometimes', 'string'],
            'variants.*.price_pence' => ['required', 'integer', 'min:1', 'max:10000000'],
            'variants.*.weight_grams' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'],
            'catalogue_revision' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
