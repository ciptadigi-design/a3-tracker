<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaintenanceOfficialErrorEntryListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'variant_key' => $this->variant_key,
            'classification' => $this->classification,
            'applicabilities' => $this->applicabilities->map(fn ($scope) => [
                'id' => $scope->id,
                'sequence' => $scope->sequence,
                'scope_type' => $scope->scope_type,
                'scope_label' => $scope->scope_label,
                'machine_model_id' => $scope->machine_model_id,
            ])->values(),
            'document' => [
                'id' => $this->document->id,
                'title' => $this->document->title,
            ],
            'source' => [
                'section_number' => $this->section_number,
                'page_start' => $this->source_page_start,
                'page_end' => $this->source_page_end,
            ],
        ];
    }
}
