<?php

namespace App\Http\Resources;

use App\Support\UrlSafety;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EpisodeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'title' => $this->title,
            'image' => UrlSafety::http($this->image),
            'release_date' => $this->release_date,
        ];
    }
}
