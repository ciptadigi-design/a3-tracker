<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OperationalPersonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ...$this->resource->attributesToArray(),
            'operational_person_branches' => $this->whenLoaded('branchAssignments', fn () => $this->branchAssignments->map(fn ($assignment) => [
                ...$assignment->attributesToArray(),
                'branches' => $assignment->relationLoaded('branch') ? $assignment->branch?->only(['id', 'code', 'name']) : null,
            ])->values()),
        ];
    }
}
