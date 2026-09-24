<?php

namespace App\Services\AssistedKnowledge;

use App\Contracts\AssistedKnowledgeProvider;
use App\Models\MaintenanceOfficialErrorEntry;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ConfiguredJsonAssistedKnowledgeProvider implements AssistedKnowledgeProvider
{
    public function generate(MaintenanceOfficialErrorEntry $entry, string $language): array
    {
        $endpoint = (string) config('maintenance_assisted.provider.endpoint');
        $key = (string) config('maintenance_assisted.provider.key');
        if ($endpoint === '' || $key === '') {
            throw new RuntimeException('Assisted knowledge provider is not configured.');
        }

        $entry->loadMissing('steps');
        $response = Http::asJson()->acceptJson()->withToken($key)
            ->timeout((int) config('maintenance_assisted.provider.timeout', 60))
            ->post($endpoint, [
                'model' => config('maintenance_assisted.provider.model'),
                'language' => $language,
                'contract' => 'maintenance-assisted-v1',
                'constraints' => [
                    'translate_only_present_fields' => true,
                    'preserve_solution_step_count_and_order' => true,
                    'preserve_technical_identifiers_exactly' => true,
                    'do_not_add_diagnoses_or_procedures' => true,
                    'do_not_weaken_or_omit_warnings' => true,
                ],
                'response_schema' => [
                    'official_entry_id' => 'uuid',
                    'language' => 'id',
                    'source_normalized_digest' => 'sha256',
                    'error_code' => 'exact official code',
                    'classification_translation' => 'string|null',
                    'classification_simplified' => 'string|null',
                    'cause_translation' => 'string|null',
                    'cause_simplified' => 'string|null',
                    'solution_steps' => [['official_step_id' => 'uuid', 'official_order' => 'integer', 'translation' => 'string', 'simplified' => 'string']],
                    'warning_translation' => 'string|null',
                    'warning_simplified' => 'string|null',
                ],
                'official' => [
                    'official_entry_id' => $entry->id,
                    'source_normalized_digest' => $entry->normalized_digest,
                    'error_code' => $entry->code,
                    'classification' => $entry->classification,
                    'cause' => $entry->cause,
                    'warning' => $entry->warning,
                    'solution_steps' => $entry->steps->map(fn ($step) => [
                        'official_step_id' => $step->id,
                        'official_order' => $step->step_number,
                        'text' => $step->instruction,
                    ])->values()->all(),
                ],
            ])->throw()->json();

        if (! is_array($response)) {
            throw new RuntimeException('Assisted knowledge provider returned malformed JSON.');
        }

        return $response;
    }

    public function metadata(): array
    {
        return [
            'provider' => (string) config('maintenance_assisted.provider.name', 'configured-json'),
            'model' => (string) config('maintenance_assisted.provider.model', 'unconfigured'),
            'revision' => (string) config('maintenance_assisted.provider.revision', 'v1'),
        ];
    }
}
