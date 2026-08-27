<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'profile_id' => ['sometimes', 'integer', 'exists:profiles,id'],
            'title_id' => ['required_without:episode_id', 'nullable', 'integer', 'exists:titles,id'],
            'episode_id' => ['required_without:title_id', 'nullable', 'integer', 'exists:episodes,id'],
            'position_sec' => ['required', 'integer', 'min:0', 'max:100000'],
            'duration_sec' => ['required', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
