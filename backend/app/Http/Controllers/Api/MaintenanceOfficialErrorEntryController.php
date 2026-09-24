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
            'search' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9\s-]+$/'],
            'per_page' => 'nullable|integer|min:1|max:25',
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
            $query->whereRaw("REPLACE(UPPER(code), '-', '') = ?", [$this->compactCode($filters['code'])]);
        }
        if (isset($filters['search'])) {
            $compact = $this->compactCode($filters['search']);
            $query->whereRaw("REPLACE(UPPER(code), '-', '') LIKE ?", ['%'.$compact.'%'])
                ->reorder()
                ->orderByRaw("CASE WHEN REPLACE(UPPER(code), '-', '') = ? THEN 0 ELSE 1 END", [$compact])
                ->orderBy('code')
                ->orderBy('variant_key')
                ->orderBy('id');
        }
        if (isset($filters['document_id'])) {
            $query->where('document_id', $filters['document_id']);
        }
        if (isset($filters['machine_model_id'])) {
            $query->whereHas('applicabilities', fn (Builder $scope) => $scope->where('machine_model_id', $filters['machine_model_id']));
        }

        if (isset($filters['search'])) {
            return MaintenanceOfficialErrorEntryListResource::collection(
                $query->paginate($filters['per_page'] ?? 20)->withQueryString(),
            );
        }

        return MaintenanceOfficialErrorEntryListResource::collection($query->get());
    }

    public function show(Request $request, string $id)
    {
        $entry = MaintenanceOfficialErrorEntry::query()
            ->visibleTo($request->user())
            ->with(['document:id,title,document_type,manufacturer_id,machine_model_id', 'ingestionRun', 'applicabilities', 'parts', 'steps', 'references', 'assistedVersions.steps'])
            ->findOrFail($id);

        return new MaintenanceOfficialErrorEntryResource($entry);
    }

    private function compactCode(string $value): string
    {
        $compact = preg_replace('/[\s-]+/', '', strtoupper(trim($value))) ?? '';

        return str_starts_with($compact, 'C') ? $compact : 'C'.$compact;
    }
}
