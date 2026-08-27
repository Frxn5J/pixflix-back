<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CatalogIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'type' => ['sometimes', 'in:movie,tvshow'],
            'q' => ['sometimes', 'string', 'max:120'],
            'genre' => ['sometimes', 'string', 'max:80'],
            'year' => ['sometimes', 'integer', 'min:1900', 'max:2100'],
            'language' => ['sometimes', 'string', 'max:80'],
            'quality' => ['sometimes', 'string', 'max:40'],
            'category' => ['sometimes', 'in:featured,normal'],
        ];
    }
}
