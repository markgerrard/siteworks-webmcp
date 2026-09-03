<?php

namespace App\Http\Requests\Site\Editor;

final class UpdateDraftProductRequest extends EditorOperationRequest
{
    public function rules(): array
    {
        return [
            'slug' => ['sometimes', 'string'],
            'product_id' => ['sometimes', 'integer', 'min:1'],
            'product_revision' => ['required', 'integer', 'min:0'],
            'name' => ['sometimes', 'string'],
            'description' => ['sometimes', 'string'],
            'tax_class_code' => ['sometimes', 'string'],
            'variants' => ['sometimes', 'array', 'max:20'],
            'variants.*.sku' => ['required_with:variants', 'string', 'max:32'],
            'variants.*.label' => ['sometimes', 'string'],
            'variants.*.price_pence' => ['required_with:variants', 'integer', 'min:1', 'max:10000000'],
            'variants.*.weight_grams' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'],
            'catalogue_revision' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
