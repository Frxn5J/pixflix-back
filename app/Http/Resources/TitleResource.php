<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TitleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'type' => $this->type,
            'title' => $this->title,
            'description' => $this->description,
            'poster' => $this->poster,
            'gallery' => $this->gallery ?? [],
            'rating' => $this->rating,
            'year' => $this->year,
            'quality' => $this->quality,
            'languages' => $this->languages ?? [],
            'genres' => $this->genres ?? [],
            'category' => $this->category,
            'total_seasons' => $this->total_seasons,
            'total_episodes' => $this->total_episodes,
        ];
    }
}
