<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OperationalPersonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $branchAssignments = $this->whenLoaded('branchAssignments', fn () => $this->branchAssignments
            ->sort(function ($left, $right) {
                $activeOrder = ((int) $right->is_active) <=> ((int) $left->is_active);
                if ($activeOrder !== 0) {
                    return $activeOrder;
                }

                $leftCode = mb_strtolower(trim((string) $left->branch?->code));
                $rightCode = mb_strtolower(trim((string) $right->branch?->code));
                $codeOrder = $leftCode <=> $rightCode;

                return $codeOrder !== 0 ? $codeOrder : ((string) $left->branch_id <=> (string) $right->branch_id);
            })
            ->map(fn ($assignment) => [
                ...$assignment->attributesToArray(),
                'branches' => $assignment->relationLoaded('branch') ? $assignment->branch?->only(['id', 'code', 'name']) : null,
            ])->values());

        return [
            ...$this->resource->attributesToArray(),
            'operational_person_branches' => $branchAssignments,
        ];
    }
}
