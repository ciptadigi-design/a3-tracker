<?php

namespace App\Services;

use App\Models\MachineErrorCode;
use App\Models\MaintenanceDocumentImport;
use App\Models\MaintenanceDocumentReference;
use App\Models\MaintenanceErrorSolution;
use App\Models\MaintenanceKnowledgeEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Publishes one approved maintenance_knowledge_entry into the live knowledge base:
 * machine_error_codes (create/update) + maintenance_error_solutions (the
 * technician procedure, as a single ordered step) + maintenance_document_references
 * (linking back to the source document/page). Only entries carrying a `code` create
 * an error code row - machine_error_codes.code is a required column, so a coded
 * entry (typically ERROR_CODE/JAM_CODE) publishes fully, while an uncoded entry
 * (e.g. a general WARNING or PM_SCHEDULE note with no natural code) is marked
 * published without a downstream row - there is no target table for those yet
 * ("foundation only" - future phases may add one).
 */
class MaintenanceKnowledgePublishService
{
    public function publish(MaintenanceKnowledgeEntry $entry, User $actor): array
    {
        return DB::transaction(function () use ($entry, $actor) {
            $entry = MaintenanceKnowledgeEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($entry->status !== 'APPROVED') {
                throw new ConflictHttpException('only an approved knowledge entry can be published');
            }
            if ($entry->published_at !== null) {
                throw new ConflictHttpException('this knowledge entry has already been published');
            }

            $import = MaintenanceDocumentImport::with('document')->findOrFail($entry->import_id);
            $accountId = $import->document->account_id;
            $audit = app(GovernanceAudit::class);

            $errorCode = null;
            $solution = null;
            $reference = null;

            if (! empty($entry->code)) {
                $errorCode = MachineErrorCode::where('account_id', $accountId)
                    ->where('machine_model_id', $import->machine_model_id)
                    ->where('code', strtoupper(trim($entry->code)))
                    ->first();
                $before = $errorCode ? $audit->snapshot($errorCode) : [];
                $errorCode ??= new MachineErrorCode(['account_id' => $accountId, 'machine_model_id' => $import->machine_model_id]);
                $errorCode->fill([
                    'code' => $entry->code,
                    'title' => $entry->title,
                    'category' => $entry->category ?? $entry->knowledge_type,
                    'severity' => $entry->severity ?? 'warning',
                    'manufacturer_description' => $entry->description,
                    'operator_description' => $entry->operator_solution,
                ]);
                $errorCode->save();
                $audit->changed($actor, 'machine_error_code.published_from_import', 'machine_error_code', $errorCode->id, $accountId, $before, $audit->snapshot($errorCode), array_keys($errorCode->getChanges()));

                if (! empty($entry->technician_solution)) {
                    $nextStep = (int) (MaintenanceErrorSolution::where('machine_error_code_id', $errorCode->id)->max('step_number')) + 1;
                    $solution = MaintenanceErrorSolution::create([
                        'machine_error_code_id' => $errorCode->id,
                        'step_number' => $nextStep,
                        'instruction' => $entry->technician_solution,
                        'requires_technician' => true,
                        'created_by' => $actor->id,
                    ]);
                    $audit->changed($actor, 'maintenance_error_solution.created', 'maintenance_error_solution', $solution->id, $accountId, [], $audit->snapshot($solution));
                }

                $reference = MaintenanceDocumentReference::create([
                    'document_id' => $import->document_id,
                    'machine_error_code_id' => $errorCode->id,
                    'reference_type' => 'error_code',
                    'page_number' => $this->extractLeadingPageNumber($entry->page_reference),
                    'section_title' => $entry->title,
                    'created_by' => $actor->id,
                ]);
                $audit->changed($actor, 'maintenance_document_reference.created', 'maintenance_document_reference', $reference->id, $accountId, [], $audit->snapshot($reference));
            }

            $before = $audit->snapshot($entry);
            $entry->update(['published_at' => now()]);
            $audit->changed($actor, 'maintenance_knowledge_entry.published', 'maintenance_knowledge_entry', $entry->id, $accountId, $before, $audit->snapshot($entry), array_keys($entry->getChanges()));

            $this->advanceImportStatusIfComplete($import);

            return compact('entry', 'errorCode', 'solution', 'reference');
        });
    }

    private function extractLeadingPageNumber(?string $pageReference): ?int
    {
        if ($pageReference && preg_match('/\d+/', $pageReference, $m)) {
            return (int) $m[0];
        }

        return null;
    }

    // Once every entry in the import is either published or rejected, the import
    // itself is considered done - a coarse, foundation-level state, not a precise
    // per-entry audit trail (that lives on the entries themselves).
    public function advanceImportStatusIfComplete(MaintenanceDocumentImport $import): void
    {
        $unresolved = $import->entries()->whereNull('published_at')->where('status', '!=', 'REJECTED')->exists();
        if (! $unresolved && $import->status !== 'PUBLISHED') {
            $import->update(['status' => 'PUBLISHED']);
        } elseif ($unresolved && $import->status === 'PROCESSING') {
            $import->update(['status' => 'REVIEW']);
        }
    }
}
