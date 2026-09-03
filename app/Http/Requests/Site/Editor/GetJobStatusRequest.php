<?php

namespace App\Http\Requests\Site\Editor;

final class GetJobStatusRequest extends EditorOperationRequest
{
    public function rules(): array
    {
        return ['job_ref' => ['required', 'string']];
    }
}
