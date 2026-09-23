<?php

namespace Tests\Unit;

use App\Services\OfficialKnowledgeParsing\ParsedOfficialErrorEntry;
use App\Services\OfficialKnowledgeParsing\ParserDiagnosticCode;
use App\Services\OfficialKnowledgeParsing\ParserOutcome;
use App\Services\OfficialKnowledgeParsing\SemanticErrorCodeParser;
use App\Services\OfficialKnowledgeParsing\SourceTextChunk;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SemanticErrorCodeParserTest extends TestCase
{
    private SemanticErrorCodeParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new SemanticErrorCodeParser;
    }

    public function test_c3913_golden_case_preserves_identity_parts_steps_raw_source_and_hash(): void
    {
        $raw = $this->fixture('c3913.txt');
        $first = $this->singleEntry($this->parser->parse($raw)->entries);
        $second = $this->singleEntry($this->parser->parse($raw)->entries);

        $this->assertSame(ParserOutcome::PASS, $first->outcome);
        $this->assertSame('C-3913', $first->code);
        $this->assertSame('MAIN_BODY', $first->variantKey);
        $this->assertSame('2.20.31', $first->sectionNumber);
        $this->assertSame('Main body: Fusing unit placement abnormality', $first->classification);
        $this->assertSame('The fusing unit is not installed.', $first->cause);
        $this->assertSame(['Fusing unit', 'Printer control board (PRCB)'], $first->parts);
        $this->assertCount(5, $first->steps);
        $this->assertSame(range(1, 5), array_column($first->steps, 'number'));
        $this->assertSame('Replace PRCB.', $first->steps[3]->instruction);
        $this->assertSame($raw, $first->rawSourceText);
        $this->assertSame(hash('sha256', $raw), $first->sourceHash);
        $this->assertSame($first->sourceHash, $second->sourceHash);
    }

    public function test_c1103_explicit_applicabilities_produce_distinct_variants(): void
    {
        $first = $this->singleEntry($this->parser->parse($this->fixture('c1103-fs531-fs612.txt'))->entries);
        $second = $this->singleEntry($this->parser->parse($this->fixture('c1103-fs532.txt'))->entries);

        $this->assertSame('C-1103', $first->code);
        $this->assertSame('C-1103', $second->code);
        $this->assertSame(['FS-531', 'FS-612'], $first->applicabilities);
        $this->assertSame(['FS-532'], $second->applicabilities);
        $this->assertSame('FS-531_FS-612', $first->variantKey);
        $this->assertSame('FS-532', $second->variantKey);
        $this->assertNotSame($first->variantKey, $second->variantKey);
    }

    public function test_complex_synthetic_case_preserves_fourteen_steps_safety_and_references(): void
    {
        $entry = $this->singleEntry($this->parser->parse($this->fixture('complex-c3911-shaped-synthetic.txt'))->entries);

        $this->assertSame(ParserOutcome::PASS, $entry->outcome);
        $this->assertCount(14, $entry->steps);
        $this->assertSame(range(1, 14), array_column($entry->steps, 'number'));
        $this->assertSame("Perform synthetic check 02.\nContinue the same instruction on this wrapped line.", $entry->steps[1]->instruction);
        $this->assertSame("Perform synthetic check 13.\nThis is another wrapped continuation, not a new step.", $entry->steps[12]->instruction);
        $this->assertSame('SYNTHETIC SAFETY TEXT — disconnect fixture power before step 10.', $entry->warning);
        $this->assertSame('DIPSW 99-1: synthetic isolation setting only.', $entry->isolationDipsw);
        $this->assertContains('WIRING_DIAGRAM', array_column($entry->references, 'type'));
        $this->assertContains('IO_CHECK', array_column($entry->references, 'type'));
        $this->assertContains('SERVICE_SECTION', array_column($entry->references, 'type'));
        $this->assertContains('DIPSW', array_column($entry->references, 'type'));
        $this->assertSame('Wiring diagram: Main body (1/4): 3-C, 3-D', $entry->steps[2]->references[0]->value);
        $this->assertStringContainsString('Wiring diagram: Main body (1/4): 3-C, 3-D', $entry->steps[2]->instruction);
    }

    public function test_letter_bearing_code_families_are_detected_and_normalized(): void
    {
        $result = $this->parser->parse($this->fixture('special-code-families.txt'));

        $this->assertSame(ParserOutcome::PASS, $result->outcome);
        $this->assertSame(['C-C152', 'C-C170', 'C-D010', 'C-E012'], array_column($result->entries, 'code'));
    }

    #[DataProvider('fieldAliasProvider')]
    public function test_field_heading_aliases_map_to_canonical_fields(string $heading, string $property): void
    {
        $raw = implode("\n", [
            '2.30.1 C-T001 (SYNTHETIC)',
            'Classification: Synthetic classification.',
            $heading.': Alias value.',
            'Solution:',
            '1. Synthetic step.',
        ]);

        $entry = $this->singleEntry($this->parser->parse($raw)->entries);
        $this->assertSame('Alias value.', $entry->{$property});
    }

    /** @return iterable<string, array{string, string}> */
    public static function fieldAliasProvider(): iterable
    {
        yield 'Cause' => ['Cause', 'cause'];
        yield 'Causes' => ['Causes', 'cause'];
        yield 'Measures alert article' => ['Measures to take when an alert occurs', 'alertMeasure'];
        yield 'Measures alert' => ['Measures to take when alert occurs', 'alertMeasure'];
        yield 'Resulting operation' => ['Resulting operation', 'alertMeasure'];
        yield 'Faulty part isolation DIPSW' => ['Faulty part isolation DIPSW', 'isolationDipsw'];
        yield 'DipSW' => ['DipSW', 'isolationDipsw'];
        yield 'Control while detached' => ['Control while detached', 'detachedControl'];
        yield 'Control during separation' => ['Control during separation', 'detachedControl'];
    }

    #[DataProvider('solutionAliasProvider')]
    public function test_solution_heading_aliases_parse_ordered_steps(string $heading): void
    {
        $raw = "2.30.2 C-T002 (SYNTHETIC)\nClassification: Synthetic.\n{$heading}:\n1. First.\n2. Second.";
        $entry = $this->singleEntry($this->parser->parse($raw)->entries);

        $this->assertSame(['First.', 'Second.'], array_column($entry->steps, 'instruction'));
    }

    /** @return iterable<string, array{string}> */
    public static function solutionAliasProvider(): iterable
    {
        yield 'Solution' => ['Solution'];
        yield 'Procedure' => ['Procedure'];
    }

    public function test_section_spans_page_chunks_until_the_next_valid_heading(): void
    {
        $result = $this->parser->parseChunks([
            new SourceTextChunk("2.40.1 C-A100 (SYNTHETIC)\nClassification: Synthetic first.\nSolution:\n1. First step.\n2. A step that", 241),
            new SourceTextChunk("continues on page 242.\n3. Third step.\n2.40.2 C-A101 (SYNTHETIC)\nClassification: Synthetic second.\nSolution:\n1. Other entry.", 242),
        ]);

        $this->assertCount(2, $result->entries);
        $first = $result->entries[0];
        $this->assertSame(241, $first->sourcePageStart);
        $this->assertSame(242, $first->sourcePageEnd);
        $this->assertCount(3, $first->steps);
        $this->assertSame("A step that\ncontinues on page 242.", $first->steps[1]->instruction);
        $this->assertStringContainsString('continues on page 242.', $first->rawSourceText);
        $this->assertStringNotContainsString('C-A101', $first->rawSourceText);
    }

    public function test_unknown_heading_warns_without_losing_raw_or_known_fields(): void
    {
        $raw = $this->fixture('unknown-content.txt');
        $entry = $this->singleEntry($this->parser->parse($raw)->entries);

        $this->assertSame(ParserOutcome::WARN, $entry->outcome);
        $this->assertSame('Synthetic known cause.', $entry->cause);
        $this->assertCount(1, $entry->steps);
        $this->assertSame($raw, $entry->rawSourceText);
        $this->assertContains(ParserDiagnosticCode::UNKNOWN_FIELD_HEADING, array_column($entry->diagnostics, 'code'));
    }

    public function test_ambiguous_applicability_uses_stable_non_merging_fallback(): void
    {
        $firstRaw = "2.50.1 C-A200\nClassification: Synthetic.\nSolution:\n1. First.";
        $secondRaw = "2.50.2 C-A200\nClassification: Synthetic.\nSolution:\n1. Second.";
        $first = $this->singleEntry($this->parser->parse($firstRaw)->entries);
        $repeat = $this->singleEntry($this->parser->parse($firstRaw)->entries);
        $second = $this->singleEntry($this->parser->parse($secondRaw)->entries);

        $this->assertStringStartsWith('UNRESOLVED_', $first->variantKey);
        $this->assertSame($first->variantKey, $repeat->variantKey);
        $this->assertNotSame($first->variantKey, $second->variantKey);
        $this->assertContains(ParserDiagnosticCode::AMBIGUOUS_APPLICABILITY, array_column($first->diagnostics, 'code'));
    }

    public function test_malformed_sequence_is_fail_and_missing_heading_is_fail(): void
    {
        $malformed = $this->parser->parse("2.60.1 C-A300 (SYNTHETIC)\nClassification: Synthetic.\nSolution:\n1. First.\n3. Third.");
        $missing = $this->parser->parse("Classification: No malfunction heading.\nSolution:\n1. Step.");

        $this->assertSame(ParserOutcome::FAIL, $malformed->outcome);
        $this->assertContains(ParserDiagnosticCode::MALFORMED_STEP_SEQUENCE, array_column($malformed->entries[0]->diagnostics, 'code'));
        $this->assertSame(ParserOutcome::FAIL, $missing->outcome);
        $this->assertContains(ParserDiagnosticCode::MISSING_CODE, array_column($missing->diagnostics, 'code'));
    }

    /** @param list<ParsedOfficialErrorEntry> $entries */
    private function singleEntry(array $entries): ParsedOfficialErrorEntry
    {
        $this->assertCount(1, $entries);

        return $entries[0];
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(__DIR__.'/../Fixtures/official-parser/'.$name);
        $this->assertIsString($contents);

        return $contents;
    }
}
