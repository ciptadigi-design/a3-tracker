<?php

namespace App\Services\OfficialKnowledgeIngestion;

use App\Models\MaintenanceDocument;
use App\Models\MaintenanceOfficialErrorEntry;
use App\Models\MaintenanceOfficialErrorReference;
use App\Services\OfficialKnowledgeParsing\ParsedOfficialErrorEntry;
use App\Services\OfficialKnowledgeParsing\ParserOutcome;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Persists one current normalized snapshot per document/code/variant identity.
 * Source revision history is intentionally deferred beyond the V2.1 controlled-ingestion scope.
 */
final class OfficialKnowledgeIngestionService
{
    public function __construct(
        private readonly OfficialKnowledgeCanonicalizer $canonicalizer = new OfficialKnowledgeCanonicalizer,
    ) {}

    public function plan(
        MaintenanceDocument $document,
        ParsedOfficialErrorEntry $parsed,
        OfficialKnowledgeIngestionPolicy $policy = OfficialKnowledgeIngestionPolicy::PASS_ONLY,
    ): OfficialKnowledgeIngestionResult {
        $rejection = $this->rejectionReason($document, $parsed, $policy);
        if ($rejection !== null) {
            return new OfficialKnowledgeIngestionResult(OfficialKnowledgeIngestionAction::REJECTED, reason: $rejection);
        }

        $existing = $this->findExisting($document, $parsed);
        if ($existing === null) {
            return new OfficialKnowledgeIngestionResult(OfficialKnowledgeIngestionAction::CREATED);
        }

        $digest = $this->canonicalizer->entryDigest($parsed);

        return new OfficialKnowledgeIngestionResult(
            ($existing->normalized_digest !== null
                ? hash_equals((string) $existing->normalized_digest, $digest)
                : hash_equals((string) $existing->source_hash, $parsed->sourceHash))
                ? OfficialKnowledgeIngestionAction::UNCHANGED
                : OfficialKnowledgeIngestionAction::UPDATED,
            $existing,
        );
    }

    public function planReviewed(MaintenanceDocument $document, ParsedOfficialErrorEntry $parsed): OfficialKnowledgeIngestionResult
    {
        $rejection = $this->rejectionReason($document, $parsed, OfficialKnowledgeIngestionPolicy::PASS_ONLY);
        if ($rejection !== null) {
            return new OfficialKnowledgeIngestionResult(OfficialKnowledgeIngestionAction::REJECTED, reason: $rejection);
        }
        $existing = $this->findExisting($document, $parsed);
        if ($existing === null) {
            return new OfficialKnowledgeIngestionResult(OfficialKnowledgeIngestionAction::CREATED);
        }

        return new OfficialKnowledgeIngestionResult(
            $existing->normalized_digest !== null
                && hash_equals((string) $existing->normalized_digest, $this->canonicalizer->entryDigest($parsed))
                    ? OfficialKnowledgeIngestionAction::UNCHANGED
                    : OfficialKnowledgeIngestionAction::UPDATED,
            $existing,
        );
    }

    public function ingest(
        MaintenanceDocument $document,
        ParsedOfficialErrorEntry $parsed,
        OfficialKnowledgeIngestionPolicy $policy = OfficialKnowledgeIngestionPolicy::PASS_ONLY,
    ): OfficialKnowledgeIngestionResult {
        $plan = $this->plan($document, $parsed, $policy);
        if ($plan->action === OfficialKnowledgeIngestionAction::REJECTED) {
            return $plan;
        }

        return DB::transaction(fn (): OfficialKnowledgeIngestionResult => $this->persist($document, $parsed));
    }

    /**
     * @param  list<ParsedOfficialErrorEntry>  $entries
     */
    public function ingestBatch(
        MaintenanceDocument $document,
        array $entries,
        OfficialKnowledgeIngestionPolicy $policy = OfficialKnowledgeIngestionPolicy::PASS_ONLY,
    ): OfficialKnowledgeBatchIngestionResult {
        if ($entries === []) {
            return new OfficialKnowledgeBatchIngestionResult(false, [
                new OfficialKnowledgeIngestionResult(
                    OfficialKnowledgeIngestionAction::REJECTED,
                    reason: 'The ingestion batch cannot be empty.',
                ),
            ]);
        }

        $plans = [];
        $identities = [];

        foreach ($entries as $parsed) {
            $identity = $parsed->code."\0".$parsed->variantKey;
            if (isset($identities[$identity])) {
                $plans[] = new OfficialKnowledgeIngestionResult(
                    OfficialKnowledgeIngestionAction::REJECTED,
                    reason: 'The ingestion batch contains a duplicate semantic identity.',
                );
            } else {
                $plans[] = $this->plan($document, $parsed, $policy);
                $identities[$identity] = true;
            }
        }

        if (collect($plans)->contains(fn (OfficialKnowledgeIngestionResult $result): bool => $result->action === OfficialKnowledgeIngestionAction::REJECTED)) {
            return new OfficialKnowledgeBatchIngestionResult(false, $plans);
        }

        $results = DB::transaction(function () use ($document, $entries): array {
            return array_map(fn (ParsedOfficialErrorEntry $parsed): OfficialKnowledgeIngestionResult => $this->persist($document, $parsed), $entries);
        });

        return new OfficialKnowledgeBatchIngestionResult(true, $results);
    }

    /**
     * The caller owns the run-level transaction and document lock.
     *
     * @param  list<ParsedOfficialErrorEntry>  $entries
     * @return list<OfficialKnowledgeIngestionResult>
     */
    public function persistReviewedBatch(MaintenanceDocument $document, array $entries, string $ingestionRunId, ?callable $afterEntry = null): array
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new \LogicException('Reviewed full-manual persistence requires an existing transaction.');
        }

        $results = [];
        $pendingChildren = [];
        $total = count($entries);
        foreach ($entries as $index => $parsed) {
            $result = $this->persist($document, $parsed, $ingestionRunId, false);
            $results[] = $result;
            if ($result->action !== OfficialKnowledgeIngestionAction::UNCHANGED) {
                $pendingChildren[] = [$result->entry, $parsed];
            }
            if ($afterEntry !== null && $index + 1 < $total) {
                $afterEntry($index + 1, $total);
            }
        }
        $this->persistReviewedChildren($document, $pendingChildren);
        if ($afterEntry !== null && $total > 0) {
            $afterEntry($total, $total);
        }

        return $results;
    }

    private function rejectionReason(
        MaintenanceDocument $document,
        ParsedOfficialErrorEntry $parsed,
        OfficialKnowledgeIngestionPolicy $policy,
    ): ?string {
        if (! $document->exists || ! MaintenanceDocument::query()->whereKey($document->getKey())->exists()) {
            return 'The maintenance document must already exist.';
        }
        if (! hash_equals(hash('sha256', $parsed->rawSourceText), $parsed->sourceHash)) {
            return 'The parsed source hash does not match the raw source text.';
        }
        if ($parsed->outcome === ParserOutcome::FAIL) {
            return 'Parser outcome FAIL is not eligible for ingestion.';
        }
        if ($parsed->outcome === ParserOutcome::WARN && $policy === OfficialKnowledgeIngestionPolicy::PASS_ONLY) {
            return 'Parser outcome WARN is rejected by the PASS_ONLY policy.';
        }

        return null;
    }

    private function findExisting(MaintenanceDocument $document, ParsedOfficialErrorEntry $parsed, bool $lock = false): ?MaintenanceOfficialErrorEntry
    {
        $query = MaintenanceOfficialErrorEntry::query()
            ->where('document_id', $document->getKey())
            ->where('code', strtoupper(trim($parsed->code)))
            ->where('variant_key', MaintenanceOfficialErrorEntry::normalizeVariantKey($parsed->variantKey));

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function persist(MaintenanceDocument $document, ParsedOfficialErrorEntry $parsed, ?string $ingestionRunId = null, bool $persistChildren = true): OfficialKnowledgeIngestionResult
    {
        $entry = $this->findExisting($document, $parsed, true);
        $normalizedDigest = $this->canonicalizer->entryDigest($parsed);
        $unchanged = $entry !== null && ($entry->normalized_digest !== null
            ? hash_equals((string) $entry->normalized_digest, $normalizedDigest)
            : hash_equals((string) $entry->source_hash, $parsed->sourceHash));
        if ($unchanged) {
            return new OfficialKnowledgeIngestionResult(OfficialKnowledgeIngestionAction::UNCHANGED, $entry);
        }

        $action = $entry === null
            ? OfficialKnowledgeIngestionAction::CREATED
            : OfficialKnowledgeIngestionAction::UPDATED;

        if ($action === OfficialKnowledgeIngestionAction::UPDATED && $ingestionRunId === null && $entry->ingestion_run_id !== null) {
            throw new \LogicException('Controlled ingestion cannot overwrite a reviewed full-manual entry.');
        }

        $entry ??= new MaintenanceOfficialErrorEntry;
        $attributes = [
            'document_id' => $document->getKey(),
            'code' => $parsed->code,
            'variant_key' => $parsed->variantKey,
            'section_number' => $parsed->sectionNumber,
            'classification' => $parsed->classification,
            'cause' => $parsed->cause,
            'alert_measure' => $parsed->alertMeasure,
            'correction' => $parsed->correction,
            'warning' => $parsed->warning,
            'note' => $parsed->note,
            'isolation_dipsw' => $parsed->isolationDipsw,
            'detached_control' => $parsed->detachedControl,
            'source_page_start' => $parsed->sourcePageStart,
            'source_page_end' => $parsed->sourcePageEnd,
            'raw_source_text' => $parsed->rawSourceText,
            'source_hash' => $parsed->sourceHash,
            'normalized_digest' => $normalizedDigest,
        ];
        if ($ingestionRunId !== null) {
            $attributes['ingestion_run_id'] = $ingestionRunId;
        }
        $entry->fill($attributes);
        $entry->save();

        if ($action === OfficialKnowledgeIngestionAction::UPDATED) {
            $entry->references()->delete();
            $entry->steps()->delete();
            $entry->parts()->delete();
            $entry->applicabilities()->delete();
        }

        if ($persistChildren) {
            $this->persistChildren($document, $entry, $parsed);
        }

        return new OfficialKnowledgeIngestionResult($action, $entry->fresh());
    }

    /** @param list<array{0: MaintenanceOfficialErrorEntry, 1: ParsedOfficialErrorEntry}> $pending */
    private function persistReviewedChildren(MaintenanceDocument $document, array $pending): void
    {
        $timestamp = now();
        $applicabilities = [];
        $parts = [];
        $steps = [];
        $references = [];
        foreach ($pending as [$entry, $parsed]) {
            $stepNumbers = array_column($parsed->steps, 'number');
            foreach ($parsed->applicabilities as $index => $label) {
                $mainBody = strcasecmp($label, 'Main body') === 0;
                $applicabilities[] = [
                    'id' => (string) Str::uuid(), 'error_entry_id' => $entry->id, 'sequence' => $index + 1,
                    'scope_type' => $mainBody ? 'MAIN_BODY' : 'ACCESSORY', 'scope_label' => trim($label),
                    'machine_model_id' => $mainBody ? $document->machine_model_id : null,
                    'created_at' => $timestamp, 'updated_at' => $timestamp,
                ];
            }
            foreach ($parsed->parts as $index => $part) {
                $parts[] = [
                    'id' => (string) Str::uuid(), 'error_entry_id' => $entry->id, 'sequence' => $index + 1,
                    'part_name' => trim($part), 'part_code' => null, 'applicability_label' => null,
                    'created_at' => $timestamp, 'updated_at' => $timestamp,
                ];
            }
            foreach ($parsed->steps as $step) {
                $steps[] = [
                    'id' => (string) Str::uuid(), 'error_entry_id' => $entry->id, 'step_number' => $step->number,
                    'instruction' => trim($step->instruction), 'applicability_label' => null, 'requires_technician' => false,
                    'created_at' => $timestamp, 'updated_at' => $timestamp,
                ];
            }
            foreach ($parsed->references as $reference) {
                $type = strtoupper(trim($reference->type));
                if (! in_array($type, MaintenanceOfficialErrorReference::TYPES, true)
                    || ($reference->stepNumber !== null && ! in_array($reference->stepNumber, $stepNumbers, true))) {
                    throw new InvalidArgumentException('Invalid reviewed official error reference.');
                }
                $references[] = [
                    'id' => (string) Str::uuid(), 'error_entry_id' => $entry->id,
                    'step_number' => $reference->stepNumber, 'reference_type' => $type,
                    'reference_value' => trim($reference->value), 'page_number' => $reference->pageNumber,
                    'section_number' => $reference->sectionNumber,
                    'created_at' => $timestamp, 'updated_at' => $timestamp,
                ];
            }
        }

        foreach ([
            'maintenance_official_error_applicabilities' => $applicabilities,
            'maintenance_official_error_parts' => $parts,
            'maintenance_official_error_steps' => $steps,
            'maintenance_official_error_references' => $references,
        ] as $table => $rows) {
            foreach (array_chunk($rows, 100) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        }
    }

    private function persistChildren(
        MaintenanceDocument $document,
        MaintenanceOfficialErrorEntry $entry,
        ParsedOfficialErrorEntry $parsed,
    ): void {
        foreach ($parsed->applicabilities as $index => $label) {
            $mainBody = strcasecmp($label, 'Main body') === 0;
            $entry->applicabilities()->create([
                'sequence' => $index + 1,
                'scope_type' => $mainBody ? 'MAIN_BODY' : 'ACCESSORY',
                'scope_label' => $label,
                'machine_model_id' => $mainBody ? $document->machine_model_id : null,
            ]);
        }

        foreach ($parsed->parts as $index => $part) {
            $entry->parts()->create([
                'sequence' => $index + 1,
                'part_name' => $part,
            ]);
        }

        foreach ($parsed->steps as $step) {
            $entry->steps()->create([
                'step_number' => $step->number,
                'instruction' => $step->instruction,
            ]);
        }

        foreach ($parsed->references as $reference) {
            $entry->references()->create([
                'step_number' => $reference->stepNumber,
                'reference_type' => $reference->type,
                'reference_value' => $reference->value,
                'page_number' => $reference->pageNumber,
                'section_number' => $reference->sectionNumber,
            ]);
        }
    }
}
