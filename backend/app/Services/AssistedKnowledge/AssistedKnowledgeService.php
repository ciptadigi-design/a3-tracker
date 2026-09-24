<?php

namespace App\Services\AssistedKnowledge;

use App\Contracts\AssistedKnowledgeProvider;
use App\Models\MaintenanceAssistedErrorEntry;
use App\Models\MaintenanceOfficialErrorEntry;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AssistedKnowledgeService
{
    public function __construct(
        private readonly AssistedKnowledgeValidator $validator,
        private readonly AssistedKnowledgeProvider $provider,
    ) {}

    public function generate(MaintenanceOfficialErrorEntry $entry, string $language = 'id'): array
    {
        return $this->provider->generate($entry, $language);
    }

    public function validate(MaintenanceOfficialErrorEntry $entry, array $payload, string $language = 'id'): array
    {
        return $this->validator->validate($entry, $payload, $language);
    }

    public function persistValid(MaintenanceOfficialErrorEntry $entry, array $payload, array $metadata, int $version): MaintenanceAssistedErrorEntry
    {
        $errors = $this->validate($entry, $payload, (string) ($payload['language'] ?? ''));
        if ($errors !== []) {
            throw new InvalidArgumentException('Assisted knowledge validation failed: '.implode(', ', $errors));
        }

        return DB::transaction(function () use ($entry, $payload, $metadata, $version) {
            $assisted = MaintenanceAssistedErrorEntry::updateOrCreate([
                'official_error_entry_id' => $entry->id,
                'language' => $payload['language'],
                'content_version' => $version,
            ], [
                'status' => 'valid',
                'source_normalized_digest' => $entry->normalized_digest,
                'generator_provider' => $metadata['provider'],
                'generator_model' => $metadata['model'],
                'generator_revision' => $metadata['revision'] ?? null,
                'generated_at' => $metadata['generated_at'] ?? now(),
                'validated_at' => now(),
                'classification_translation' => $payload['classification_translation'],
                'classification_simplified' => $payload['classification_simplified'],
                'cause_translation' => $payload['cause_translation'],
                'cause_simplified' => $payload['cause_simplified'],
                'warning_translation' => $payload['warning_translation'],
                'warning_simplified' => $payload['warning_simplified'],
                'validation_errors' => null,
            ]);
            $assisted->steps()->delete();
            foreach ($payload['solution_steps'] as $step) {
                $assisted->steps()->create([
                    'official_step_id' => $step['official_step_id'],
                    'official_order' => $step['official_order'],
                    'translation' => trim($step['translation']),
                    'simplified' => trim($step['simplified']),
                ]);
            }

            return $assisted->load('steps');
        });
    }
}
