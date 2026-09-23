<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaintenanceOfficialErrorEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'variant_key' => $this->variant_key,
            'classification' => $this->classification,
            'cause' => $this->cause,
            'alert_measure' => $this->alert_measure,
            'correction' => $this->correction,
            'warning' => $this->warning,
            'note' => $this->note,
            'isolation_dipsw' => $this->isolation_dipsw,
            'detached_control' => $this->detached_control,
            'applicabilities' => $this->applicabilities->map(fn ($scope) => [
                'id' => $scope->id,
                'sequence' => $scope->sequence,
                'scope_type' => $scope->scope_type,
                'scope_label' => $scope->scope_label,
                'machine_model_id' => $scope->machine_model_id,
            ])->values(),
            'parts' => $this->parts->map(fn ($part) => [
                'id' => $part->id,
                'sequence' => $part->sequence,
                'part_name' => $part->part_name,
                'part_code' => $part->part_code,
                'applicability_label' => $part->applicability_label,
            ])->values(),
            'steps' => $this->steps->map(fn ($step) => [
                'id' => $step->id,
                'step_number' => $step->step_number,
                'instruction' => $step->instruction,
                'applicability_label' => $step->applicability_label,
                'requires_technician' => $step->requires_technician,
            ])->values(),
            'references' => $this->references->map(fn ($reference) => [
                'id' => $reference->id,
                'step_number' => $reference->step_number,
                'reference_type' => $reference->reference_type,
                'reference_value' => $reference->reference_value,
                'page_number' => $reference->page_number,
                'section_number' => $reference->section_number,
            ])->values(),
            'provenance' => [
                'document' => [
                    'id' => $this->document->id,
                    'title' => $this->document->title,
                    'document_type' => $this->document->document_type,
                    'manufacturer_id' => $this->document->manufacturer_id,
                    'machine_model_id' => $this->document->machine_model_id,
                ],
                'section_number' => $this->section_number,
                'source_page_start' => $this->source_page_start,
                'source_page_end' => $this->source_page_end,
                'source_hash' => $this->source_hash,
            ],
            'raw_source_text' => $this->raw_source_text,
        ];
    }
}
