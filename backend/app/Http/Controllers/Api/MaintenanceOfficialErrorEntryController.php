<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MaintenanceOfficialErrorEntryListResource;
use App\Http\Resources\MaintenanceOfficialErrorEntryResource;
use App\Models\MaintenanceOfficialErrorEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            $codes = $this->compactCodes($filters['code']);
            $query->whereIn(DB::raw("REPLACE(UPPER(code), '-', '')"), $codes);
        }
        if (isset($filters['search'])) {
            $compacts = $this->compactCodes($filters['search']);
            $query->where(function (Builder $match) use ($compacts) {
                foreach ($compacts as $compact) {
                    $match->orWhereRaw("REPLACE(UPPER(code), '-', '') LIKE ?", ['%'.$compact.'%']);
                }
            })
                ->reorder()
                ->orderByRaw("CASE WHEN REPLACE(UPPER(code), '-', '') IN (".implode(',', array_fill(0, count($compacts), '?')).') THEN 0 ELSE 1 END', $compacts)
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

    /**
     * An explicit C- or J-code prefix keeps that family only. Bare digits are
     * ambiguous (the manual reuses numbers across the C and J families), so they
     * match both; each record keeps its own code identity.
     *
     * @return list<string>
     */
    private function compactCodes(string $value): array
    {
        $compact = preg_replace('/[\s-]+/', '', strtoupper(trim($value))) ?? '';

        return preg_match('/^[CJ]/', $compact) === 1 ? [$compact] : ['C'.$compact, 'J'.$compact];
    }
}
