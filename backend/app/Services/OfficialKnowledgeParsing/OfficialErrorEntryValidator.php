<?php

namespace App\Services\OfficialKnowledgeParsing;

final class OfficialErrorEntryValidator
{
    /** @param list<ParserDiagnostic> $diagnostics */
    public function outcome(string $code, array $diagnostics): ParserOutcome
    {
        if ($code === '' || $this->hasErrors($diagnostics)) {
            return ParserOutcome::FAIL;
        }

        return $diagnostics === [] ? ParserOutcome::PASS : ParserOutcome::WARN;
    }

    /** @param list<ParserDiagnostic> $diagnostics */
    private function hasErrors(array $diagnostics): bool
    {
        foreach ($diagnostics as $diagnostic) {
            if ($diagnostic->severity === ParserDiagnosticSeverity::ERROR) {
                return true;
            }
        }

        return false;
    }
}
