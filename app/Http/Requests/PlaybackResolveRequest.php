<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PlaybackResolveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'slug' => ['required_without:episode_id', 'string', 'max:200'],
            'episode_id' => ['required_without:slug', 'integer', 'exists:episodes,id'],
            'prefer_stremio' => ['sometimes', 'boolean'],
            'language' => ['sometimes', 'nullable', 'string', 'max:80'],
        ];
    }
}
