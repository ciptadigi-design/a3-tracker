<?php

namespace App\Services\PdfExtraction;

/**
 * V1.5.5 - the PdfTextExtractor bound in AppServiceProvider from this release
 * onward. Dispatches on the document's actual security profile:
 *
 *  - unencrypted -> SmalotPdfTextExtractor (unchanged from V1.5.3/V1.5.4;
 *    tried FIRST and unconditionally, so the common unencrypted case pays
 *    zero extra parsing cost - this class never runs PdfSecurityInspector at
 *    all unless Smalot itself first reports UNSUPPORTED_PDF_SECURITY).
 *  - encrypted + the one profile V1.5.5 supports (Standard Security
 *    Handler, /V 4, /R 4, AES-128/AESV2, confirmed empty user password) ->
 *    SecuredPdfTextExtractor.
 *  - encrypted + anything else (R2/R3/R5/R6, RC4, split/Identity crypt
 *    filters, a real non-empty user password, an undetermined profile) ->
 *    UNSUPPORTED_PDF_SECURITY, identical to V1.5.3/V1.5.4 behavior. This
 *    milestone changes behavior for exactly one profile and nothing else.
 */
final class ProfileAwarePdfTextExtractor implements PdfTextExtractor
{
    // Deliberately typed to the CONCRETE classes, not the PdfTextExtractor
    // interface: PdfTextExtractor is bound to this very class in
    // AppServiceProvider, so a parameter typed as the interface would make
    // Laravel's container auto-wiring resolve straight back to
    // ProfileAwarePdfTextExtractor itself - infinite recursion until the
    // process exhausts its memory limit (caught the hard way in this
    // milestone's own test suite before it ever reached Production).
    public function __construct(
        private readonly SmalotPdfTextExtractor $unencrypted = new SmalotPdfTextExtractor,
        private readonly PdfSecurityInspector $inspector = new PdfSecurityInspector,
        private readonly SecuredPdfTextExtractor $secured = new SecuredPdfTextExtractor,
    ) {}

    public function extract(string $absolutePath): ExtractedPdfDocument
    {
        try {
            return $this->unencrypted->extract($absolutePath);
        } catch (PdfExtractionException $e) {
            if ($e->errorCode !== PdfExtractionErrorCode::UNSUPPORTED_PDF_SECURITY) {
                throw $e;
            }
        }

        try {
            $profile = $this->inspector->inspect($absolutePath);
        } catch (PdfExtractionException) {
            // Smalot already told us this file is encrypted; if inspecting it
            // further fails (malformed /Encrypt dictionary, dangling
            // reference, etc.) that is certainly not the one supported
            // profile - fail closed to the same UNSUPPORTED_PDF_SECURITY
            // Smalot itself would have produced, not a different code.
            throw PdfExtractionException::unsupportedSecurity();
        }
        if (! $profile->isSupportedAesV2Profile()) {
            throw PdfExtractionException::unsupportedSecurity();
        }

        return $this->secured->extract($absolutePath);
    }
}
