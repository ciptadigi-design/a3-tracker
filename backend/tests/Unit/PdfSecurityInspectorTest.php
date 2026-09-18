<?php

namespace Tests\Unit;

use App\Services\PdfExtraction\PdfExtractionException;
use App\Services\PdfExtraction\PdfObjectDictionaryReader;
use App\Services\PdfExtraction\PdfSecurityInspector;
use PHPUnit\Framework\TestCase;

/**
 * V1.5.4 research - PdfSecurityInspector is a read-only classifier (never a
 * decryptor - see its class docblock for the exact security boundary). These
 * tests pin its behavior against the two committed synthetic fixtures
 * (tests/Fixtures/pdf-security/, see the README there for how they were
 * generated and why their security profile matches the real Konica document)
 * plus the literal-string decoder in isolation, since the real document
 * (encoding /O and /U as PDF literal strings rather than hex strings) is
 * never committed to this repository.
 */
class PdfSecurityInspectorTest extends TestCase
{
    private function fixturePath(string $name): string
    {
        return __DIR__.'/../Fixtures/pdf-security/'.$name;
    }

    public function test_unencrypted_fixture_is_classified_as_not_encrypted(): void
    {
        $profile = (new PdfSecurityInspector)->inspect($this->fixturePath('unencrypted-baseline.pdf'));

        $this->assertFalse($profile->encrypted);
        $this->assertNull($profile->filter);
        $this->assertNull($profile->version);
        $this->assertNull($profile->revision);
        $this->assertNull($profile->streamCipher);
        $this->assertNull($profile->stringCipher);
        $this->assertNull($profile->emptyUserPasswordValid);
    }

    public function test_encrypted_fixture_is_classified_with_the_exact_real_document_security_profile(): void
    {
        $profile = (new PdfSecurityInspector)->inspect($this->fixturePath('encrypted-r4-aes128-empty-user-password.pdf'));

        $this->assertTrue($profile->encrypted);
        $this->assertSame('Standard', $profile->filter);
        $this->assertSame(4, $profile->version);
        $this->assertSame(4, $profile->revision);
        $this->assertSame(128, $profile->keyLengthBits);
        $this->assertSame('AESV2', $profile->streamCipher);
        $this->assertSame('AESV2', $profile->stringCipher);
        $this->assertTrue($profile->encryptMetadata, '/EncryptMetadata is absent from the dictionary, which defaults to true per ISO 32000-1 7.6.1.');
    }

    public function test_empty_user_password_is_confirmed_valid_for_the_fixture_matching_the_documented_real_document_finding(): void
    {
        $profile = (new PdfSecurityInspector)->inspect($this->fixturePath('encrypted-r4-aes128-empty-user-password.pdf'));

        // Cross-validated independently against `qpdf --show-encryption` and
        // pypdf's PasswordType.USER_PASSWORD result - see
        // docs/maintenance/V1.5.4_SECURED_PDF_COMPATIBILITY.md. The real
        // Konica document (not committed here) produces the same true result
        // from this exact inspector.
        $this->assertTrue($profile->emptyUserPasswordValid);
    }

    /**
     * The real document encodes /O and /U as PDF *literal* strings
     * (`(\373\252...)`), not the hex strings (`<28BF4E...>`) qpdf writes for
     * the fixture - both are spec-valid (ISO 32000-1 7.3.4). This exercises
     * the literal-string decoder directly against known escape sequences
     * rather than depending on a committed real-world example.
     *
     * V1.5.5: moved to PdfObjectDictionaryReader (shared with the new AESV2
     * decryption path, which also needs raw dictionary values) - see that
     * class's docblock.
     */
    public function test_literal_string_decoder_handles_every_pdf_escape_sequence(): void
    {
        $reader = new PdfObjectDictionaryReader;

        $cases = [
            'plain bytes pass through' => ['hello', 'hello'],
            'escaped parens/backslash' => ['a\\(b\\)c\\\\d', 'a(b)c\\d'],
            'named escapes' => ['\\n\\r\\t\\b\\f', "\n\r\t\x08\x0C"],
            'one-digit octal' => ['\\5', "\x05"],
            'three-digit octal' => ['\\101\\102\\103', 'ABC'], // 0o101=A 0o102=B 0o103=C
            'octal clamped to 3 digits then literal 8/9 stop the run' => ['\\1234', "\x53".'4'], // \123=S, trailing 4 literal
            'line continuation via backslash-LF produces nothing' => ["a\\\nb", 'ab'],
            'unrecognized escape drops the backslash' => ['\\x', 'x'],
        ];

        foreach ($cases as $label => [$raw, $expected]) {
            $this->assertSame($expected, $reader->decodeLiteralString($raw), $label);
        }
    }

    public function test_missing_file_throws_the_existing_file_missing_classification(): void
    {
        $this->expectException(PdfExtractionException::class);
        (new PdfSecurityInspector)->inspect($this->fixturePath('does-not-exist.pdf'));
    }
}
