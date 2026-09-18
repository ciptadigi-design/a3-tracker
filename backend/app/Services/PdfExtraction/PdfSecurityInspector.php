<?php

namespace App\Services\PdfExtraction;

use Smalot\PdfParser\RawData\RawDataParser;

/**
 * V1.5.4 research artifact - NOT part of the extraction pipeline yet (nothing
 * in DocumentExtractionService/ExtractMaintenanceDocumentJob calls this). Its
 * only job is to classify a PDF's Standard Security Handler configuration
 * from structure that is never itself encrypted (ISO 32000-1 7.6.1: the
 * /Encrypt dictionary's own keys/names/numbers are always in the clear - only
 * *string and stream content elsewhere in the document* is encrypted), plus a
 * narrowly-scoped check of whether the empty string authenticates as the
 * user password.
 *
 * Deliberately reuses Smalot's own Smalot\PdfParser\RawData\RawDataParser -
 * the same low-level tokenizer/xref reader SmalotPdfTextExtractor already
 * depends on - instead of writing a second PDF tokenizer. RawDataParser's
 * parseData() has no encryption guard at all (that check lives one layer up,
 * in Smalot\PdfParser\Parser::parseContent()) and already fully resolves the
 * /Encrypt object's dictionary structure for an encrypted file, which is
 * exactly the seam this class exploits: no parallel parser, no fork.
 *
 * SECURITY BOUNDARY - this class:
 *  - NEVER decrypts a string or stream. It has no AES/RC4 stream-cipher call
 *    for content, only the password-*authentication* comparison (Algorithm
 *    4/5), which never produces plaintext document content.
 *  - NEVER accepts an arbitrary password. The only credential ever tried is
 *    the empty string - there is no method parameter through which a caller
 *    could pass one, by design (see task V1.5.4 section F: "Do not attempt
 *    password cracking").
 *  - NEVER returns raw /O, /U, /OE, /UE, /Perms bytes, and never returns a
 *    derived file-encryption key.
 *  - Only supports empty-password authentication for standard-handler
 *    revisions 2-4 (RC4/AES-128 family - Algorithm 2 + Algorithm 4 or 5).
 *    Revisions 5/6 (AES-256) use a different, SHA-256-based key derivation
 *    (ISO 32000-2 Algorithm 2.A/2.B) that is intentionally NOT implemented
 *    here - emptyUserPasswordValid is null for those, not a guess.
 *  - Is not a decryptor: it cannot produce extracted page text. That remains
 *    exactly what PdfTextExtractor implementations do.
 */
class PdfSecurityInspector
{
    /** Revisions this class can authenticate an empty password against (Algorithm 4 for R2, Algorithm 5 for R3/R4). */
    private const EMPTY_PASSWORD_SUPPORTED_REVISIONS = [2, 3, 4];

    private const PADDING = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";

    private readonly PdfObjectDictionaryReader $reader;

    public function __construct(?PdfObjectDictionaryReader $reader = null)
    {
        $this->reader = $reader ?? new PdfObjectDictionaryReader;
    }

    public function inspect(string $absolutePath): PdfSecurityProfile
    {
        if (! is_file($absolutePath)) {
            throw PdfExtractionException::fileMissing();
        }
        $content = file_get_contents($absolutePath);
        if ($content === false) {
            throw PdfExtractionException::fileMissing();
        }

        [$xref, $data] = (new RawDataParser)->parseData($content);

        if (! isset($xref['trailer']['encrypt'])) {
            return PdfSecurityProfile::unencrypted();
        }

        $encryptDict = $this->reader->resolveDictionary($xref['trailer']['encrypt'], $data);
        $fileId = $this->reader->resolveFileId($xref['trailer']['id'][0] ?? null);

        $filter = $encryptDict['Filter'] ?? null;
        $subFilter = $encryptDict['SubFilter'] ?? null;
        $version = isset($encryptDict['V']) ? (int) $encryptDict['V'] : null;
        $revision = isset($encryptDict['R']) ? (int) $encryptDict['R'] : null;
        $encryptMetadata = $this->resolveBool($encryptDict['EncryptMetadata'] ?? null, true);

        [$streamCipher, $stringCipher, $keyLengthBits] = $this->resolveCiphers($encryptDict, $version);

        $emptyUserPasswordValid = null;
        if ($revision !== null && in_array($revision, self::EMPTY_PASSWORD_SUPPORTED_REVISIONS, true) && $fileId !== null) {
            $emptyUserPasswordValid = $this->tryAuthenticateEmptyPassword($encryptDict, $fileId, $revision, $keyLengthBits, $encryptMetadata);
        }

        return new PdfSecurityProfile(
            encrypted: true,
            filter: $filter,
            subFilter: $subFilter,
            version: $version,
            revision: $revision,
            keyLengthBits: $keyLengthBits,
            streamCipher: $streamCipher,
            stringCipher: $stringCipher,
            encryptMetadata: $encryptMetadata,
            emptyUserPasswordValid: $emptyUserPasswordValid,
        );
    }

    private function resolveBool(mixed $value, bool $default): bool
    {
        return is_bool($value) ? $value : $default;
    }

    /** @return array{0:?string,1:?string,2:?int} [streamCipher, stringCipher, keyLengthBits] */
    private function resolveCiphers(array $encryptDict, ?int $version): array
    {
        if ($version === null) {
            return [null, null, null];
        }

        if ($version < 4) {
            // V=1/V=2: always RC4, keyed by /Length (bits), defaulting to 40.
            $bits = isset($encryptDict['Length']) ? (int) $encryptDict['Length'] : 40;

            return ['RC4', 'RC4', $bits];
        }

        if ($version === 4 || $version === 5) {
            $stmF = $encryptDict['StmF'] ?? 'Identity';
            $strF = $encryptDict['StrF'] ?? 'Identity';
            $cf = is_array($encryptDict['CF'] ?? null) ? $encryptDict['CF'] : [];

            return [
                $this->cipherForFilter($stmF, $cf),
                $this->cipherForFilter($strF, $cf),
                $this->keyLengthForFilter($stmF, $cf, $version),
            ];
        }

        return [null, null, null];
    }

    private function cipherForFilter(string $filterName, array $cf): ?string
    {
        if ($filterName === 'Identity') {
            return 'Identity';
        }
        $entry = $cf[$filterName] ?? null;
        $cfm = is_array($entry) ? ($entry['CFM'] ?? null) : null;

        return match ($cfm) {
            'V2' => 'RC4',
            'AESV2' => 'AESV2',
            'AESV3' => 'AESV3',
            default => $cfm, // report whatever was found rather than silently guessing
        };
    }

    private function keyLengthForFilter(string $filterName, array $cf, int $version): ?int
    {
        $entry = $cf[$filterName] ?? null;
        if (is_array($entry) && isset($entry['Length'])) {
            // /CF/*/Length is in BYTES per spec (unlike the top-level /Length, which is in bits).
            return ((int) $entry['Length']) * 8;
        }

        return $version === 5 ? 256 : 128;
    }

    /**
     * Algorithm 2 (file key) + Algorithm 4 (R2) / Algorithm 5 (R3/R4),
     * ISO 32000-1 7.6.3 - restricted to confirming whether the EMPTY string
     * authenticates as the user password. Never tries any other credential.
     */
    private function tryAuthenticateEmptyPassword(array $encryptDict, string $fileId, int $revision, ?int $keyLengthBits, bool $encryptMetadata): ?bool
    {
        $o = $encryptDict['O'] ?? null;
        $u = $encryptDict['U'] ?? null;
        if (! is_string($o) || strlen($o) !== 32 || ! is_string($u) || strlen($u) !== 32) {
            return null;
        }

        $keyLenBytes = $revision === 2 ? 5 : (int) round((($keyLengthBits ?? 128) / 8));
        if ($keyLenBytes < 5 || $keyLenBytes > 16) {
            return null;
        }

        $p = (int) ($encryptDict['P'] ?? 0);
        $pBytes = pack('V', $p & 0xFFFFFFFF);

        $material = self::PADDING.$o.$pBytes.$fileId;
        if ($revision >= 4 && ! $encryptMetadata) {
            $material .= "\xFF\xFF\xFF\xFF";
        }

        $hash = md5($material, true);
        if ($revision >= 3) {
            for ($i = 0; $i < 50; $i++) {
                $hash = md5(substr($hash, 0, $keyLenBytes), true);
            }
        }
        $fileKey = substr($hash, 0, $keyLenBytes);

        if ($revision === 2) {
            $computed = $this->rc4(self::PADDING, $fileKey);

            return hash_equals(substr($u, 0, 32), $computed);
        }

        $seed = md5(self::PADDING.$fileId, true);
        $enc = $this->rc4($seed, $fileKey);
        for ($k = 1; $k <= 19; $k++) {
            $xorKey = $fileKey ^ str_repeat(chr($k), strlen($fileKey));
            $enc = $this->rc4($enc, $xorKey);
        }

        return hash_equals(substr($u, 0, 16), substr($enc, 0, 16));
    }

    /** Minimal RC4 - authentication-comparison use only, never applied to document content. */
    private function rc4(string $data, string $key): string
    {
        $keyLen = strlen($key);
        if ($keyLen === 0) {
            return $data;
        }
        $s = range(0, 255);
        $j = 0;
        for ($i = 0; $i < 256; $i++) {
            $j = ($j + $s[$i] + ord($key[$i % $keyLen])) & 0xFF;
            [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
        }
        $i = 0;
        $j = 0;
        $out = '';
        $len = strlen($data);
        for ($n = 0; $n < $len; $n++) {
            $i = ($i + 1) & 0xFF;
            $j = ($j + $s[$i]) & 0xFF;
            [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
            $out .= chr(ord($data[$n]) ^ $s[($s[$i] + $s[$j]) & 0xFF]);
        }

        return $out;
    }
}
