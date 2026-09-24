<?php

namespace App\Services\AssistedKnowledge;

use App\Models\MaintenanceOfficialErrorEntry;

class AssistedKnowledgeValidator
{
    public function __construct(private readonly TechnicalTokenExtractor $tokens) {}

    public function validate(MaintenanceOfficialErrorEntry $entry, array $payload, string $language = 'id'): array
    {
        $entry->loadMissing('steps');
        $errors = [];

        foreach (['official_entry_id', 'language', 'source_normalized_digest', 'error_code', 'classification_translation', 'classification_simplified', 'cause_translation', 'cause_simplified', 'warning_translation', 'warning_simplified', 'solution_steps'] as $requiredKey) {
            if (! array_key_exists($requiredKey, $payload)) {
                $errors[] = 'structured_field_missing:'.$requiredKey;
            }
        }

        if ($language !== 'id') {
            $errors[] = 'unsupported_language';
        }
        if (($payload['language'] ?? null) !== $language) {
            $errors[] = 'language_mismatch';
        }
        if (($payload['official_entry_id'] ?? null) !== $entry->id) {
            $errors[] = 'official_entry_mismatch';
        }
        if (($payload['source_normalized_digest'] ?? null) !== $entry->normalized_digest) {
            $errors[] = 'source_digest_mismatch';
        }
        if (($payload['error_code'] ?? null) !== $entry->code) {
            $errors[] = 'error_code_mismatch';
        }

        foreach ([
            'classification' => $entry->classification,
            'cause' => $entry->cause,
            'warning' => $entry->warning,
        ] as $field => $official) {
            $translation = $payload[$field.'_translation'] ?? null;
            $simplified = $payload[$field.'_simplified'] ?? null;
            if ($official === null || trim((string) $official) === '') {
                if ($translation !== null || $simplified !== null) {
                    $errors[] = $field.'_must_be_absent';
                }

                continue;
            }
            if (! is_string($translation) || trim($translation) === '' || ! is_string($simplified) || trim($simplified) === '') {
                $errors[] = $field.'_missing';

                continue;
            }
            if ($this->tokens->missing($official, $translation) !== [] || $this->tokens->missing($official, $simplified) !== []) {
                $errors[] = $field.'_technical_token_mismatch';
            }
        }

        $generatedSteps = $payload['solution_steps'] ?? null;
        if (! is_array($generatedSteps)) {
            $errors[] = 'solution_steps_malformed';
            $generatedSteps = [];
        }
        if (count($generatedSteps) !== $entry->steps->count()) {
            $errors[] = 'solution_step_count_mismatch';
        }
        foreach ($entry->steps->values() as $index => $officialStep) {
            $assisted = $generatedSteps[$index] ?? null;
            if (! is_array($assisted)) {
                $errors[] = 'solution_step_missing:'.$officialStep->step_number;

                continue;
            }
            if (($assisted['official_step_id'] ?? null) !== $officialStep->id || (int) ($assisted['official_order'] ?? 0) !== $officialStep->step_number) {
                $errors[] = 'solution_step_mapping_mismatch:'.$officialStep->step_number;
            }
            foreach (['translation', 'simplified'] as $mode) {
                $text = $assisted[$mode] ?? null;
                if (! is_string($text) || trim($text) === '') {
                    $errors[] = 'solution_step_'.$mode.'_missing:'.$officialStep->step_number;
                } elseif ($this->tokens->missing($officialStep->instruction, $text) !== []) {
                    $errors[] = 'solution_step_technical_token_mismatch:'.$officialStep->step_number.':'.$mode;
                }
            }
        }

        return array_values(array_unique($errors));
    }
}
