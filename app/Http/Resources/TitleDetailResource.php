<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class TitleDetailResource extends TitleResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'seasons' => SeasonResource::collection($this->whenLoaded('seasons')),
        ];
    }
}
