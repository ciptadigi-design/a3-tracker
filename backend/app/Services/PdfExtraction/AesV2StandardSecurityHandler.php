<?php

namespace App\Services\PdfExtraction;

use Com\Tecnick\Pdf\Encrypt\Decrypt;

/**
 * V1.5.5 - the ONE supported profile's actual decryption engine: PDF Standard
 * Security Handler, /V 4, /R 4, AES-128 (/CFM /AESV2), single (non-split)
 * crypt filter, empty user password. Wraps tecnickcom/tc-lib-pdf-encrypt's
 * Decrypt class (chosen over an in-house implementation per
 * docs/maintenance/V1.5.5_AESV2_EXTRACTION.md section 3 - both were proven
 * correct against the real production document during development, but a
 * maintained dependency carries less ongoing security burden for the actual
 * cipher/key-derivation primitives) rather than duplicating Algorithm 1/2/5
 * a second time in this codebase.
 *
 * SECURITY BOUNDARY - same spirit as PdfSecurityInspector:
 *  - The ONLY credential ever attempted is the empty string. There is no
 *    constructor or method parameter through which a caller could supply a
 *    different one.
 *  - Never logs, returns, or exposes the password, the derived document key,
 *    the derived per-object keys, or raw /O, /U, /OE, /UE, /Perms values.
 *  - authenticate() must be called (and return true) before decryptObject()
 *    is used - PdfExtractionErrorCode::UNSUPPORTED_PDF_SECURITY is the
 *    caller's responsibility to raise when authentication fails; this class
 *    does not classify failures, only reports pass/fail.
 */
final class AesV2StandardSecurityHandler
{
    private readonly Decrypt $decrypt;

    private bool $authenticated = false;

    /**
     * @param  array<string,mixed>  $encryptDict  raw /Encrypt dictionary, as read by PdfObjectDictionaryReader
     * @param  string  $fileId  raw bytes of the trailer's /ID[0]
     */
    public function __construct(array $encryptDict, string $fileId)
    {
        $this->decrypt = new Decrypt([
            'V' => (int) ($encryptDict['V'] ?? 0),
            'mode' => 2, // tc-lib's own enum: 2 = AES-128 - the one profile this class supports
            'O' => (string) ($encryptDict['O'] ?? ''),
            'U' => (string) ($encryptDict['U'] ?? ''),
            'P' => (int) ($encryptDict['P'] ?? 0),
            'fileid' => $fileId,
            'Length' => (int) ($encryptDict['Length'] ?? 128),
            'EncryptMetadata' => is_bool($encryptDict['EncryptMetadata'] ?? null) ? $encryptDict['EncryptMetadata'] : true,
        ]);
    }

    /** The only credential ever tried - see class docblock. Never accepts a caller-supplied password. */
    public function authenticateEmptyUserPassword(): bool
    {
        $this->authenticated = $this->decrypt->authenticate('') && $this->decrypt->getAuthenticatedRole() === 'user';

        return $this->authenticated;
    }

    public function decryptObject(string $ciphertext, int $objectNumber, int $generationNumber): string
    {
        if (! $this->authenticated) {
            throw new PdfExtractionException(PdfExtractionErrorCode::UNSUPPORTED_PDF_SECURITY, 'decryptObject() called before a successful authenticateEmptyUserPassword().');
        }

        return $this->decrypt->decryptString($ciphertext, $objectNumber, $generationNumber);
    }
}
