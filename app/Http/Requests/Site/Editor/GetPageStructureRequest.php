<?php

namespace App\Http\Requests\Site\Editor;

final class GetPageStructureRequest extends EditorOperationRequest
{
    public function rules(): array
    {
        return ['page_id' => ['required', 'integer', 'min:1']];
    }
}
