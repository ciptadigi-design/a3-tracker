<?php

namespace Tests\Unit;

use App\Services\PdfExtraction\PdfExtractionErrorCode;
use App\Services\PdfExtraction\PdfExtractionException;
use App\Services\PdfExtraction\PdfSecurityInspector;
use App\Services\PdfExtraction\SmalotPdfTextExtractor;
use PHPUnit\Framework\TestCase;

/**
 * V1.5.4 research - characterizes CURRENT behavior (V1.5.3's
 * SmalotPdfTextExtractor, unchanged here) against the synthetic fixtures in
 * tests/Fixtures/pdf-security/, which reproduce the real production Konica
 * document's exact Standard Security Handler profile (R=4, V=4, AESV2/
 * AES-128, empty user password valid) without containing any of its content.
 * See docs/maintenance/V1.5.4_SECURED_PDF_COMPATIBILITY.md.
 *
 * This is NOT a decryption test suite - V1.5.4 does not implement
 * decryption. It pins the exact classification boundary a future V1.5.5
 * decryptor would need to replace.
 */
class SecuredPdfFixtureCharacterizationTest extends TestCase
{
    private function fixturePath(string $name): string
    {
        return __DIR__.'/../Fixtures/pdf-security/'.$name;
    }

    public function test_normal_unencrypted_fixture_remains_parseable_by_smalot(): void
    {
        $extractor = new SmalotPdfTextExtractor;
        $document = $extractor->extract($this->fixturePath('unencrypted-baseline.pdf'));

        $this->assertSame(1, $document->totalPages());
        $pages = iterator_to_array($document->pages());
        $this->assertStringContainsString('A3 Tracker PDF Security Fixture', $pages[1]);
        $this->assertStringContainsString('Error Code Example: C-0000', $pages[1]);
    }

    public function test_sanitized_encrypted_fixture_is_recognized_as_secured(): void
    {
        $profile = (new PdfSecurityInspector)->inspect($this->fixturePath('encrypted-r4-aes128-empty-user-password.pdf'));

        $this->assertTrue($profile->encrypted);
    }

    public function test_current_smalot_extractor_maps_the_fixture_to_unsupported_pdf_security(): void
    {
        $extractor = new SmalotPdfTextExtractor;

        try {
            $extractor->extract($this->fixturePath('encrypted-r4-aes128-empty-user-password.pdf'));
            $this->fail('Expected a PdfExtractionException for the encrypted fixture.');
        } catch (PdfExtractionException $e) {
            $this->assertSame(PdfExtractionErrorCode::UNSUPPORTED_PDF_SECURITY, $e->errorCode);
            $this->assertSame('Secured pdf file are currently not supported.', $e->getPrevious()->getMessage());
        }
    }

    /**
     * Pins the exact real-document finding (see the V1.5.4 doc,
     * "Empty User Password Result") without needing the real document
     * itself: this fixture was deliberately built to share the same
     * empty-user-password-valid behavior, independently cross-checked with
     * `qpdf --show-encryption` and pypdf at fixture-creation time.
     */
    public function test_fixtures_empty_user_password_behavior_matches_the_documented_real_pdf_profile(): void
    {
        $profile = (new PdfSecurityInspector)->inspect($this->fixturePath('encrypted-r4-aes128-empty-user-password.pdf'));

        $this->assertTrue($profile->emptyUserPasswordValid);
    }

    /**
     * Automated safety net, not a substitute for the manual review recorded
     * in the fixture README: both fixtures are tiny (a real 2639-page,
     * 116MB manual could never pass this) and contain none of the real
     * document's identifying strings.
     */
    public function test_fixtures_contain_no_real_manual_content(): void
    {
        foreach (['unencrypted-baseline.pdf', 'encrypted-r4-aes128-empty-user-password.pdf'] as $name) {
            $path = $this->fixturePath($name);
            $this->assertLessThan(10_000, filesize($path), "$name must stay a tiny synthetic fixture.");

            $bytes = file_get_contents($path);
            foreach (['Konica', 'Minolta', 'bizhub', 'Bizhub', 'Antenna House', 'AH Formatter'] as $needle) {
                $this->assertStringNotContainsString($needle, $bytes, "$name must not contain real-document identifying content ('$needle').");
            }
        }
    }

    public function test_security_profile_inspector_correctly_identifies_filter_v_r_length_and_cipher_configuration(): void
    {
        $profile = (new PdfSecurityInspector)->inspect($this->fixturePath('encrypted-r4-aes128-empty-user-password.pdf'));

        $this->assertSame('Standard', $profile->filter);
        $this->assertSame(4, $profile->version);
        $this->assertSame(4, $profile->revision);
        $this->assertSame(128, $profile->keyLengthBits);
        $this->assertSame('AESV2', $profile->streamCipher);
        $this->assertSame('AESV2', $profile->stringCipher);
    }
}
