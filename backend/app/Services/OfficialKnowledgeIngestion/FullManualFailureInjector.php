<?php

namespace App\Services\OfficialKnowledgeIngestion;

class FullManualFailureInjector
{
    public function checkpoint(string $checkpoint): void
    {
        // Intentionally empty. Tests replace this service to prove rollback at
        // stable transaction boundaries without adding runtime flags.
    }
}
