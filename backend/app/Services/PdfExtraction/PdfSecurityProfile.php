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
}
