<?php

namespace App\Http\Requests\Site\Editor;

use Illuminate\Validation\Rule;

final class SectionsRequest extends EditorOperationRequest
{
    public function rules(): array
    {
        $op = (string) $this->input('op', '');

        return [
            'op' => ['required', 'string', Rule::in(['add', 'remove', 'move', 'set_variant'])],
            'page_id' => ['required', 'integer', 'min:1'],
            'type' => ['required_if:op,add', 'string'],
            'position' => [
                // § D6.0: the positional key is omittable wherever its id form is
                // accepted — for add that is the before/after_section_id anchor.
                Rule::requiredIf($op === 'add' && ! $this->filled('before_section_id') && ! $this->filled('after_section_id')),
                'integer',
                'min:0',
            ],
            'variant' => ['present_if:op,set_variant', 'nullable', 'string'],
            'fields' => ['sometimes', 'array'],
            'section_id' => ['sometimes', 'string'],
            'before_section_id' => ['sometimes', 'string'],
            'after_section_id' => ['sometimes', 'string'],
            'stored_index' => [
                Rule::requiredIf(in_array($op, ['remove', 'set_variant'], true) && ! $this->filled('section_id')),
                'integer',
                'min:0',
            ],
            'from' => [
                Rule::requiredIf($op === 'move' && ! $this->filled('section_id')),
                'integer',
                'min:0',
            ],
            'to' => ['required_if:op,move', 'integer', 'min:0'],
            'revision_base' => ['sometimes', 'integer', 'min:1'],
            'structure_epoch' => ['required', 'integer', 'min:0'],
        ];
    }
}
