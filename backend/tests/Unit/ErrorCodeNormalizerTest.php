<?php

namespace Tests\Unit;

use App\Services\KnowledgeProcessing\ErrorCodeNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * M2 Downtime & Maintenance V1 - J-code support regression coverage,
 * alongside the pre-existing C-code behavior it must not change.
 */
class ErrorCodeNormalizerTest extends TestCase
{
    public function test_detects_c_code_candidates_unchanged(): void
    {
        $candidates = ErrorCodeNormalizer::detectCandidates('See code C-2451 for details. Also C - 3913.');
        $this->assertCount(2, $candidates);
        $this->assertSame('C-2451', $candidates[0]['normalized_code']);
        $this->assertSame('C-3913', $candidates[1]['normalized_code']);
    }

    public function test_does_not_match_dashless_model_number_as_a_code(): void
    {
        $this->assertSame([], ErrorCodeNormalizer::detectCandidates('Konica Minolta C1070 service manual'));
    }

    public function test_detects_j_code_candidates(): void
    {
        $candidates = ErrorCodeNormalizer::detectCandidates('Error J-1101 observed, also j - 2200.');
        $this->assertCount(2, $candidates);
        $this->assertSame('J-1101', $candidates[0]['normalized_code']);
        $this->assertSame('J-2200', $candidates[1]['normalized_code']);
    }

    public function test_normalize_accepts_c_and_j_codes_leniently(): void
    {
        $this->assertSame('C-3913', ErrorCodeNormalizer::normalize('c3913'));
        $this->assertSame('C-3913', ErrorCodeNormalizer::normalize('C-3913'));
        $this->assertSame('J-1101', ErrorCodeNormalizer::normalize('j1101'));
        $this->assertSame('J-1101', ErrorCodeNormalizer::normalize('J - 1101'));
    }

    public function test_normalize_rejects_unknown_prefixes_and_shapes(): void
    {
        $this->assertNull(ErrorCodeNormalizer::normalize('X-1101'));
        $this->assertNull(ErrorCodeNormalizer::normalize('C-11'));
        $this->assertNull(ErrorCodeNormalizer::normalize(''));
    }
}
