<?php

namespace App\Services\OfficialKnowledgeIngestion;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JsonException;

final class ProtectedMaintenanceState
{
    private const TABLES = [
        'machine_error_codes',
        'maintenance_error_solutions',
        'maintenance_tickets',
        'maintenance_documents',
        'maintenance_actions',
        'maintenance_document_extractions',
        'maintenance_document_imports',
        'maintenance_document_pages',
        'maintenance_document_references',
        'maintenance_knowledge',
        'maintenance_knowledge_entries',
        'maintenance_knowledge_review_sessions',
    ];

    /** @return array<string, array{count: int, sha256: string}> */
    public function snapshot(): array
    {
        $result = [];
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $rows = DB::table($table)->get()->map(function ($row): string {
                $values = (array) $row;
                ksort($values, SORT_STRING);

                return $this->json($values);
            })->sort(SORT_STRING)->values();
            $context = hash_init('sha256');
            foreach ($rows as $row) {
                hash_update($context, pack('N', strlen($row)).$row);
            }
            $result[$table] = ['count' => $rows->count(), 'sha256' => hash_final($context)];
        }

        return $result;
    }

    /** @param array<mixed> $value */
    private function json(array $value): string
    {
        try {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \RuntimeException('Protected maintenance state could not be canonicalized.', previous: $exception);
        }
    }
}
