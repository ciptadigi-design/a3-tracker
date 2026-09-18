<?php

namespace App\Services\PdfExtraction;

/**
 * V1.5.4 research artifact - a read-only classification of a PDF's Standard
 * Security Handler configuration. Never carries decrypted content, raw /O or
 * /U material, or a derived file-encryption key - see PdfSecurityInspector's
 * docblock for the security boundary this type enforces.
 */
final class PdfSecurityProfile
{
    public function __construct(
        public readonly bool $encrypted,
        public readonly ?string $filter,
        public readonly ?string $subFilter,
        public readonly ?int $version,
        public readonly ?int $revision,
        public readonly ?int $keyLengthBits,
        /** 'RC4' | 'AESV2' | 'AESV3' | 'Identity' | null (null = not applicable/undetermined) */
        public readonly ?string $streamCipher,
        public readonly ?string $stringCipher,
        /** Per ISO 32000-1 7.6.1: defaults true when /EncryptMetadata is absent. Meaningless when !$encrypted. */
        public readonly bool $encryptMetadata,
        /**
         * Whether the empty string authenticates as the PDF's user password
         * (Algorithm 4/5, revisions 2-4 only - see
         * PdfSecurityInspector::EMPTY_PASSWORD_SUPPORTED_REVISIONS). Null
         * means "not determined": the file is unencrypted, uses a revision
         * this inspector does not attempt authentication for (5/6 - AES-256
         * uses a different, SHA-256-based algorithm not implemented here),
         * or has a malformed /O or /U entry.
         */
        public readonly ?bool $emptyUserPasswordValid,
    ) {}

    public static function unencrypted(): self
    {
        return new self(
            encrypted: false,
            filter: null,
            subFilter: null,
            version: null,
            revision: null,
            keyLengthBits: null,
            streamCipher: null,
            stringCipher: null,
            encryptMetadata: true,
            emptyUserPasswordValid: null,
        );
    }

    /**
     * V1.5.5 capability gate - true ONLY for the exact profile proven against
     * the real production document (docs/maintenance/V1.5.4_SECURED_PDF_COMPATIBILITY.md
     * and V1.5.5_AESV2_EXTRACTION.md): Standard Security Handler, /V 4, /R 4,
     * 128-bit AES (/CFM /AESV2) for both streams and strings, and a confirmed
     * empty user password.
     *
     * Deliberately exhaustive rather than permissive - every other
     * combination (R2/R3/R5/R6, RC4, split or Identity crypt filters, a real
     * non-empty user password, an undetermined profile) returns false here
     * and must be classified UNSUPPORTED_PDF_SECURITY by the caller. Fails
     * closed by construction: this method has no "unless" clause.
     */
    public function isSupportedAesV2Profile(): bool
    {
        return $this->encrypted
            && $this->filter === 'Standard'
            && $this->version === 4
            && $this->revision === 4
            && $this->keyLengthBits === 128
            && $this->streamCipher === 'AESV2'
            && $this->stringCipher === 'AESV2'
            && $this->emptyUserPasswordValid === true;
    }
}
