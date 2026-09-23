<?php

namespace App\Services\OfficialKnowledgeParsing;

final class OfficialErrorFieldParser
{
    /** @var array<string, string> */
    private const FIELD_ALIASES = [
        'code' => 'code',
        'classification' => 'classification',
        'cause' => 'cause',
        'causes' => 'cause',
        'measures to take when an alert occurs' => 'alert_measure',
        'measures to take when alert occurs' => 'alert_measure',
        'resulting operation' => 'alert_measure',
        'solution' => 'solution',
        'procedure' => 'solution',
        'faulty part isolation dipsw' => 'isolation_dipsw',
        'dipsw' => 'isolation_dipsw',
        'control while detached' => 'detached_control',
        'control during separation' => 'detached_control',
        'estimated abnormal parts' => 'parts',
        'correction' => 'correction',
        'warning' => 'warning',
        'note' => 'note',
        'applicability' => 'applicability',
        'applicable models' => 'applicability',
    ];

    public function __construct(
        private readonly OfficialErrorEntryValidator $validator = new OfficialErrorEntryValidator,
    ) {}

    public function parse(SemanticMalfunctionSection $section): ParsedOfficialErrorEntry
    {
        $diagnostics = $section->diagnostics;
        $blocks = $this->parseFieldBlocks($section->rawSourceText, $diagnostics);

        $fieldCode = $this->field($blocks, 'code');
        if ($fieldCode !== null && strtoupper(trim($fieldCode)) !== $section->code) {
            $diagnostics[] = new ParserDiagnostic(
                ParserDiagnosticCode::CODE_MISMATCH,
                ParserDiagnosticSeverity::ERROR,
                'The Code field does not match the malfunction-section heading.',
                ['heading_code' => $section->code, 'field_code' => trim($fieldCode)],
            );
        }

        $classification = $this->field($blocks, 'classification');
        if ($classification === null) {
            $diagnostics[] = new ParserDiagnostic(
                ParserDiagnosticCode::MISSING_CLASSIFICATION,
                ParserDiagnosticSeverity::WARNING,
                'The semantic section has no Classification field.',
            );
        }

        [$applicabilities, $variantKey] = $this->parseApplicability(
            $section,
            $this->field($blocks, 'applicability'),
            $classification,
            $diagnostics,
        );
        $parts = $this->parseParts($this->field($blocks, 'parts'));
        $steps = $this->parseSteps($this->field($blocks, 'solution'), $diagnostics);

        if ($steps === []) {
            $diagnostics[] = new ParserDiagnostic(
                ParserDiagnosticCode::NO_SOLUTION_STEPS,
                ParserDiagnosticSeverity::WARNING,
                'No ordered Solution or Procedure steps were parsed.',
            );
        }

        $references = [];
        foreach ($steps as $step) {
            array_push($references, ...$step->references);
        }

        $isolationDipsw = $this->field($blocks, 'isolation_dipsw');
        if ($isolationDipsw !== null) {
            $references[] = new ParsedOfficialReference('DIPSW', $isolationDipsw);
        }

        $sourceHash = hash('sha256', $section->rawSourceText);

        return new ParsedOfficialErrorEntry(
            $section->code,
            $variantKey,
            $section->sectionNumber,
            $classification,
            $this->field($blocks, 'cause'),
            $this->field($blocks, 'alert_measure'),
            $this->field($blocks, 'correction'),
            $this->field($blocks, 'warning'),
            $this->field($blocks, 'note'),
            $isolationDipsw,
            $this->field($blocks, 'detached_control'),
            $applicabilities,
            $parts,
            $steps,
            $references,
            $section->sourcePageStart,
            $section->sourcePageEnd,
            $section->rawSourceText,
            $sourceHash,
            $diagnostics,
            $this->validator->outcome($section->code, $diagnostics),
        );
    }

    /**
     * @param  list<ParserDiagnostic>  $diagnostics
     * @return array<string, list<string>>
     */
    private function parseFieldBlocks(string $rawSourceText, array &$diagnostics): array
    {
        $lines = preg_split('/\R/u', $rawSourceText) ?: [];
        array_shift($lines); // The validated malfunction heading is section metadata, not a field.

        $blocks = [];
        $currentField = null;

        foreach ($lines as $lineNumber => $line) {
            $heading = $this->knownHeading($line);
            if ($heading !== null) {
                [$currentField, $inlineValue] = $heading;
                $blocks[$currentField] ??= [];
                if ($inlineValue !== null && $inlineValue !== '') {
                    $blocks[$currentField][] = $inlineValue;
                }

                continue;
            }

            $unknownHeading = $this->unknownHeading($line);
            if ($unknownHeading !== null) {
                $diagnostics[] = new ParserDiagnostic(
                    ParserDiagnosticCode::UNKNOWN_FIELD_HEADING,
                    ParserDiagnosticSeverity::WARNING,
                    "Unknown field heading '{$unknownHeading}' was preserved only in raw source text.",
                    ['heading' => $unknownHeading, 'line' => $lineNumber + 2],
                );
                $currentField = null;

                continue;
            }

            if ($currentField !== null) {
                $blocks[$currentField][] = rtrim($line);
            }
        }

        return $blocks;
    }

    /** @return array{string, string|null}|null */
    private function knownHeading(string $line): ?array
    {
        $trimmed = trim($line);
        foreach (self::FIELD_ALIASES as $alias => $canonical) {
            if (preg_match('/^'.preg_quote($alias, '/').'(?:\s*:\s*(.*))?$/iu', $trimmed, $match) === 1) {
                return [$canonical, array_key_exists(1, $match) ? $match[1] : null];
            }
        }

        return null;
    }

    private function unknownHeading(string $line): ?string
    {
        $trimmed = trim($line);
        if (preg_match('/^(Wiring diagram|I\/O (?:reference|check)|DIPSW\b[^:]*)\s*:/iu', $trimmed) === 1) {
            return null;
        }

        if (preg_match('/^([A-Z][A-Za-z0-9 &\/().-]{2,})\s*:\s*(?:.*)$/u', $trimmed, $match) === 1) {
            return trim($match[1]);
        }

        return null;
    }

    /** @param array<string, list<string>> $blocks */
    private function field(array $blocks, string $name): ?string
    {
        if (! array_key_exists($name, $blocks)) {
            return null;
        }

        $value = trim(implode("\n", $blocks[$name]));

        return $value === '' ? null : $value;
    }

    /**
     * @param  list<ParserDiagnostic>  $diagnostics
     * @return array{list<string>, string}
     */
    private function parseApplicability(
        SemanticMalfunctionSection $section,
        ?string $explicitField,
        ?string $classification,
        array &$diagnostics,
    ): array {
        $source = $section->headingApplicability ?? $explicitField;
        if ($source !== null) {
            $labels = array_values(array_filter(array_map(
                static fn (string $value): string => trim($value),
                preg_split('/\s*\/\s*/u', $source) ?: [],
            ), static fn (string $value): bool => $value !== ''));

            if ($labels !== []) {
                return [$labels, $this->normalizeVariantKey(implode('_', $labels))];
            }
        }

        if ($classification !== null && preg_match('/^Main\s+body\s*:/iu', $classification) === 1) {
            return [['Main body'], 'MAIN_BODY'];
        }

        $diagnostics[] = new ParserDiagnostic(
            ParserDiagnosticCode::AMBIGUOUS_APPLICABILITY,
            ParserDiagnosticSeverity::WARNING,
            'Applicability was not explicit; a source-hash-scoped unresolved variant was used to prevent merging.',
        );

        return [[], 'UNRESOLVED_'.strtoupper(substr(hash('sha256', $section->rawSourceText), 0, 12))];
    }

    private function normalizeVariantKey(string $value): string
    {
        $normalized = preg_replace('/[^A-Z0-9-]+/', '_', strtoupper(trim($value))) ?? '';

        return trim(preg_replace('/_+/', '_', $normalized) ?? '', '_');
    }

    /** @return list<string> */
    private function parseParts(?string $value): array
    {
        if ($value === null) {
            return [];
        }

        $parts = preg_split('/(?:\R|\s*;\s*)/u', $value) ?: [];

        return array_values(array_filter(array_map(
            static fn (string $part): string => trim(preg_replace('/^(?:[-•]|\d+[.)])\s*/u', '', $part) ?? $part),
            $parts,
        ), static fn (string $part): bool => $part !== ''));
    }

    /**
     * @param  list<ParserDiagnostic>  $diagnostics
     * @return list<ParsedOfficialErrorStep>
     */
    private function parseSteps(?string $value, array &$diagnostics): array
    {
        if ($value === null) {
            return [];
        }

        $parsed = [];
        $currentNumber = null;
        $currentLines = [];
        $orphanedContent = false;

        foreach (preg_split('/\R/u', $value) ?: [] as $line) {
            if (preg_match('/^\s*(?:\((\d+)\)|(\d+)[.)])\s+(.+)$/u', $line, $match) === 1) {
                if ($currentNumber !== null) {
                    $parsed[] = $this->makeStep($currentNumber, $currentLines);
                }
                $currentNumber = (int) ($match[1] !== '' ? $match[1] : $match[2]);
                $currentLines = [rtrim($match[3])];

                continue;
            }

            if (trim($line) !== '') {
                if ($currentNumber === null) {
                    $orphanedContent = true;
                } else {
                    $currentLines[] = rtrim($line);
                }
            }
        }

        if ($currentNumber !== null) {
            $parsed[] = $this->makeStep($currentNumber, $currentLines);
        }

        $actual = array_map(static fn (ParsedOfficialErrorStep $step): int => $step->number, $parsed);
        $expected = $parsed === [] ? [] : range(1, count($parsed));
        if ($orphanedContent || $actual !== $expected) {
            $diagnostics[] = new ParserDiagnostic(
                ParserDiagnosticCode::MALFORMED_STEP_SEQUENCE,
                ParserDiagnosticSeverity::ERROR,
                'Solution step numbering is not a complete contiguous sequence beginning at 1.',
                ['actual_sequence' => implode(',', $actual)],
            );
        }

        return $parsed;
    }

    /** @param list<string> $lines */
    private function makeStep(int $number, array $lines): ParsedOfficialErrorStep
    {
        $instruction = trim(implode("\n", $lines));

        return new ParsedOfficialErrorStep($number, $instruction, $this->extractReferences($instruction, $number));
    }

    /** @return list<ParsedOfficialReference> */
    private function extractReferences(string $instruction, int $stepNumber): array
    {
        $references = [];
        foreach (preg_split('/\R/u', $instruction) ?: [] as $line) {
            $line = trim($line);
            if (preg_match('/Wiring diagram\s*:\s*.+$/iu', $line, $match) === 1) {
                $references[] = new ParsedOfficialReference('WIRING_DIAGRAM', trim($match[0]), $stepNumber);
            }
            if (preg_match('/I\/O (?:reference|check)\s*:\s*.+$/iu', $line, $match) === 1) {
                $references[] = new ParsedOfficialReference('IO_CHECK', trim($match[0]), $stepNumber);
            }
            if (preg_match('/\b([A-Z]\.\d+(?:\.\d+){2,}\s+.+)$/u', $line, $match) === 1) {
                $references[] = new ParsedOfficialReference('SERVICE_SECTION', trim($match[1]), $stepNumber, sectionNumber: strtok($match[1], ' '));
            }
            if (preg_match('/\bDIPSW\b.+$/iu', $line, $match) === 1) {
                $references[] = new ParsedOfficialReference('DIPSW', trim($match[0]), $stepNumber);
            }
        }

        return $references;
    }
}
