<?php

namespace App\Http\Requests\Site\Editor;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Contracts\Validation\Validator;

abstract class EditorOperationRequest extends FormRequest
{
    public static function fromRequest(Request $request): static
    {
        $formRequest = static::createFrom($request, app(static::class));
        $formRequest->setContainer(app());
        $formRequest->setRedirector(app(Redirector::class));
        $formRequest->validateResolved();

        return $formRequest;
    }

    public function authorize(): bool
    {
        return true;
    }

    protected function getValidatorInstance(): Validator
    {
        $validator = parent::getValidatorInstance();
        $validator->addRules([
            'approval_request_id' => ['sometimes', 'uuid'],
            'expected_revision' => ['sometimes', 'integer', 'min:0'],
        ]);

        return $validator;
    }

    protected function prepareForValidation(): void
    {
        $routeInputs = [];

        if ($this->route('page') !== null) {
            $routeInputs['page_id'] = $this->route('page');
        }

        if ($this->route('ref') !== null) {
            $routeInputs['job_ref'] = $this->route('ref');
        }

        $this->merge($routeInputs);
    }
}
