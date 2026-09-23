<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MaintenanceOfficialErrorEntryListResource;
use App\Http\Resources\MaintenanceOfficialErrorEntryResource;
use App\Models\MaintenanceOfficialErrorEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class MaintenanceOfficialErrorEntryController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'code' => 'nullable|string|max:64',
            'document_id' => 'nullable|uuid',
            'machine_model_id' => 'nullable|uuid',
        ]);

        $query = MaintenanceOfficialErrorEntry::query()
            ->visibleTo($request->user())
            ->with(['document:id,title', 'applicabilities'])
            ->orderBy('code')
            ->orderBy('variant_key')
            ->orderBy('id');

        if (isset($filters['code'])) {
            $query->where('code', strtoupper(trim($filters['code'])));
        }
        if (isset($filters['document_id'])) {
            $query->where('document_id', $filters['document_id']);
        }
        if (isset($filters['machine_model_id'])) {
            $query->whereHas('applicabilities', fn (Builder $scope) => $scope->where('machine_model_id', $filters['machine_model_id']));
        }

        return MaintenanceOfficialErrorEntryListResource::collection($query->get());
    }

    public function show(Request $request, string $id)
    {
        $entry = MaintenanceOfficialErrorEntry::query()
            ->visibleTo($request->user())
            ->with(['document:id,title,document_type,manufacturer_id,machine_model_id', 'applicabilities', 'parts', 'steps', 'references'])
            ->findOrFail($id);

        return new MaintenanceOfficialErrorEntryResource($entry);
    }
}
