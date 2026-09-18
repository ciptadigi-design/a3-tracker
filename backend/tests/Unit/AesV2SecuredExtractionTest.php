<?php

namespace Tests\Unit;

use App\Services\PdfExtraction\PdfExtractionErrorCode;
use App\Services\PdfExtraction\PdfExtractionException;
use App\Services\PdfExtraction\PdfSecurityInspector;
use App\Services\PdfExtraction\ProfileAwarePdfTextExtractor;
use App\Services\PdfExtraction\SmalotPdfTextExtractor;
use PHPUnit\Framework\TestCase;

/**
 * V1.5.5 - end-to-end characterization of ProfileAwarePdfTextExtractor (the
 * PdfTextExtractor bound in AppServiceProvider from this release onward)
 * against every fixture in tests/Fixtures/pdf-security/. See
 * docs/maintenance/V1.5.5_AESV2_EXTRACTION.md for the architecture and
 * docs/maintenance/V1.5.4_SECURED_PDF_COMPATIBILITY.md for the real-document
 * evidence these fixtures reproduce.
 *
 * No temp file is ever created by this architecture (decryption happens
 * entirely in memory against RawDataParser's already-parsed object map - see
 * SecuredPdfTextExtractor's docblock) - several tests below assert that
 * directly rather than testing a cleanup step that has nothing to clean up.
 */
class AesV2SecuredExtractionTest extends TestCase
{
    private function fixturePath(string $name): string
    {
        return __DIR__.'/../Fixtures/pdf-security/'.$name;
    }

    /**
     * Snapshot every candidate location this architecture would use IF it
     * ever created a temp file (it does not - see class docblock): the
     * system temp dir and this project's private storage tree. A before/after
     * diff proves no new file appeared anywhere plausible, not just in one
     * assumed location.
     */
    private function tempDirSnapshot(): array
    {
        $dirs = array_filter([sys_get_temp_dir(), __DIR__.'/../../storage/app'], 'is_dir');
        $files = [];
        foreach ($dirs as $dir) {
            $files = array_merge($files, glob($dir.'/*') ?: []);
        }

        return $files;
    }

    // --- 1. supported profile is detected ---

    public function test_supported_profile_is_detected(): void
    {
        $profile = (new PdfSecurityInspector)->inspect($this->fixturePath('encrypted-r4-aes128-empty-user-password.pdf'));

        $this->assertTrue($profile->isSupportedAesV2Profile());
    }

    public function test_unsupported_profiles_are_not_detected_as_supported(): void
    {
        $inspector = new PdfSecurityInspector;
        foreach ([
            'encrypted-r4-aes128-nonempty-user-password.pdf',
            'encrypted-r6-aes256-unsupported.pdf',
            'encrypted-r3-rc4-unsupported.pdf',
        ] as $name) {
            $profile = $inspector->inspect($this->fixturePath($name));
            $this->assertFalse($profile->isSupportedAesV2Profile(), $name);
        }
    }

    // --- 2. empty user password authenticates (pinned already in PdfSecurityInspectorTest; reconfirmed here as part of the full chain) ---

    public function test_empty_user_password_authenticates_for_the_supported_fixture(): void
    {
        $profile = (new PdfSecurityInspector)->inspect($this->fixturePath('encrypted-r4-aes128-empty-user-password.pdf'));
        $this->assertTrue($profile->emptyUserPasswordValid);
    }

    // --- 3, 4, 5. decrypts successfully, parseable by Smalot, correct text ---

    public function test_supported_encrypted_fixture_decrypts_and_extracts_the_exact_sanitized_text(): void
    {
        $extractor = new ProfileAwarePdfTextExtractor;
        $document = $extractor->extract($this->fixturePath('encrypted-r4-aes128-empty-user-password.pdf'));

        $this->assertSame(1, $document->totalPages());
        $pages = iterator_to_array($document->pages());
        $this->assertSame(
            "A3 Tracker PDF Security Fixture\nPage 1\nError Code Example: C-0000\nFixture content only",
            trim($pages[1]),
        );
    }

    public function test_decrypted_fixture_text_matches_the_unencrypted_baseline_exactly(): void
    {
        $extractor = new ProfileAwarePdfTextExtractor;
        $plain = $extractor->extract($this->fixturePath('unencrypted-baseline.pdf'));
        $secured = $extractor->extract($this->fixturePath('encrypted-r4-aes128-empty-user-password.pdf'));

        $plainPages = iterator_to_array($plain->pages());
        $securedPages = iterator_to_array($secured->pages());
        $this->assertSame(trim($plainPages[1]), trim($securedPages[1]));
    }

    // --- 6. original encrypted fixture is never mutated ---

    public function test_original_encrypted_fixture_remains_byte_for_byte_unchanged_after_extraction(): void
    {
        $path = $this->fixturePath('encrypted-r4-aes128-empty-user-password.pdf');
        $before = hash_file('sha256', $path);

        (new ProfileAwarePdfTextExtractor)->extract($path);

        $this->assertSame($before, hash_file('sha256', $path));
    }

    // --- 7, 8, 9. no temp artifact ever exists (success, parser exception, extraction exception paths) ---

    public function test_no_temp_file_is_created_on_the_successful_decrypt_path(): void
    {
        $before = $this->tempDirSnapshot();
        (new ProfileAwarePdfTextExtractor)->extract($this->fixturePath('encrypted-r4-aes128-empty-user-password.pdf'));
        $this->assertSame($before, $this->tempDirSnapshot());
    }

    public function test_no_temp_file_is_created_when_the_corrupt_fixture_fails_to_parse(): void
    {
        $before = $this->tempDirSnapshot();
        try {
            (new ProfileAwarePdfTextExtractor)->extract($this->fixturePath('encrypted-truncated-corrupt.pdf'));
        } catch (PdfExtractionException) {
        }
        $this->assertSame($before, $this->tempDirSnapshot());
    }

    public function test_no_temp_file_is_created_when_an_unsupported_profile_is_rejected(): void
    {
        $before = $this->tempDirSnapshot();
        try {
            (new ProfileAwarePdfTextExtractor)->extract($this->fixturePath('encrypted-r6-aes256-unsupported.pdf'));
        } catch (PdfExtractionException) {
        }
        $this->assertSame($before, $this->tempDirSnapshot());
    }

    // --- 10. unsupported revision fails closed ---

    public function test_unsupported_revision_r6_aes256_fails_closed(): void
    {
        try {
            (new ProfileAwarePdfTextExtractor)->extract($this->fixturePath('encrypted-r6-aes256-unsupported.pdf'));
            $this->fail('Expected UNSUPPORTED_PDF_SECURITY.');
        } catch (PdfExtractionException $e) {
            $this->assertSame(PdfExtractionErrorCode::UNSUPPORTED_PDF_SECURITY, $e->errorCode);
        }
    }

    public function test_unsupported_cipher_rc4_fails_closed(): void
    {
        try {
            (new ProfileAwarePdfTextExtractor)->extract($this->fixturePath('encrypted-r3-rc4-unsupported.pdf'));
            $this->fail('Expected UNSUPPORTED_PDF_SECURITY.');
        } catch (PdfExtractionException $e) {
            $this->assertSame(PdfExtractionErrorCode::UNSUPPORTED_PDF_SECURITY, $e->errorCode);
        }
    }

    // --- 11. non-empty user-password document fails closed (never attempted, never brute-forced) ---

    public function test_non_empty_user_password_document_fails_closed_without_ever_being_attempted(): void
    {
        try {
            (new ProfileAwarePdfTextExtractor)->extract($this->fixturePath('encrypted-r4-aes128-nonempty-user-password.pdf'));
            $this->fail('Expected UNSUPPORTED_PDF_SECURITY.');
        } catch (PdfExtractionException $e) {
            $this->assertSame(PdfExtractionErrorCode::UNSUPPORTED_PDF_SECURITY, $e->errorCode);
        }
    }

    // --- 12. malformed encryption dictionary / structurally corrupt file fails closed ---

    public function test_truncated_corrupt_encrypted_file_fails_closed_as_invalid_or_corrupt(): void
    {
        try {
            (new ProfileAwarePdfTextExtractor)->extract($this->fixturePath('encrypted-truncated-corrupt.pdf'));
            $this->fail('Expected an exception for a truncated file.');
        } catch (PdfExtractionException $e) {
            $this->assertContains($e->errorCode, [PdfExtractionErrorCode::INVALID_OR_CORRUPT_PDF, PdfExtractionErrorCode::UNSUPPORTED_PDF_SECURITY]);
        }
    }

    // --- 13, 14, 15, 16. safe error surface: no raw security material, no password, no temp path ---

    public function test_error_messages_never_contain_the_fixtures_own_o_or_u_values_or_the_test_password(): void
    {
        $cases = [
            'encrypted-r6-aes256-unsupported.pdf',
            'encrypted-r3-rc4-unsupported.pdf',
            'encrypted-r4-aes128-nonempty-user-password.pdf',
            'encrypted-truncated-corrupt.pdf',
        ];
        foreach ($cases as $name) {
            try {
                (new ProfileAwarePdfTextExtractor)->extract($this->fixturePath($name));
                $this->fail("Expected an exception for $name.");
            } catch (PdfExtractionException $e) {
                $message = $e->errorCode->userMessage();
                $this->assertStringNotContainsString('SomeUserPassword123', $message, $name);
                $this->assertDoesNotMatchRegularExpression('/[0-9a-f]{32,}/i', $message, "$name: userMessage must never contain hex-looking key/hash material.");
                $this->assertStringNotContainsString($this->fixturePath($name), $message, "$name: userMessage must never contain a filesystem path.");
                $this->assertStringNotContainsString(sys_get_temp_dir(), $message, $name);
            }
        }
    }

    // --- 17. unencrypted PDFs still follow the normal Smalot path unchanged ---

    public function test_unencrypted_pdf_produces_the_identical_result_via_the_dispatcher_and_smalot_directly(): void
    {
        $path = $this->fixturePath('unencrypted-baseline.pdf');
        $viaDispatcher = (new ProfileAwarePdfTextExtractor)->extract($path);
        $viaSmalotDirectly = (new SmalotPdfTextExtractor)->extract($path);

        $this->assertSame($viaSmalotDirectly->totalPages(), $viaDispatcher->totalPages());
        $dispatcherPages = iterator_to_array($viaDispatcher->pages());
        $directPages = iterator_to_array($viaSmalotDirectly->pages());
        $this->assertSame($directPages[1], $dispatcherPages[1]);
    }

    // --- 18. existing UNSUPPORTED_PDF_SECURITY behavior for a non-AESV2-profile encrypted PDF is unaffected ---
    // (the exact V1.5.3 hand-built fixture with a bogus /Encrypt reference is
    // covered by MaintenanceDocumentExtractionTest and
    // SecuredPdfFixtureCharacterizationTest already - this test adds the one
    // case not covered elsewhere: a *structurally real* but unsupported-cipher
    // encrypted PDF, already exercised above as test_unsupported_cipher_rc4_fails_closed.)
}
