<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('avatar') && ! $this->has('avatar_url')) {
            $this->merge(['avatar_url' => $this->input('avatar')]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => $this->isMethod('POST')
                ? ['required', 'string', 'min:1', 'max:80']
                : ['sometimes', 'required', 'string', 'min:1', 'max:80'],
            'avatar_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'is_kids' => ['sometimes', 'boolean'],
            'pin' => ['sometimes', 'nullable', 'string', 'regex:/^\d{4,6}$/'],
        ];
    }
}
