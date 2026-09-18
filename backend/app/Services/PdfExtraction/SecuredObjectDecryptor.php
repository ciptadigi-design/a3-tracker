<?php

namespace App\Services\PdfExtraction;

use Smalot\PdfParser\RawData\FilterHelper;

/**
 * V1.5.5 - decrypts every string and stream in a RawDataParser::parseData()
 * result in place (returning a new, decrypted copy - RawDataParser's own
 * output is never mutated), so the result can be handed to Smalot's own
 * object-construction code (SecuredParserBridge) exactly as if it had parsed
 * an unencrypted file. No PDF is ever written back to disk - see
 * docs/maintenance/V1.5.5_AESV2_EXTRACTION.md section 5 for why this avoids
 * the temp-file approach originally anticipated in V1.5.4.
 *
 * Stream content: RawDataParser already attempted (and, for still-encrypted
 * ciphertext, silently failed - its own `ignore_filter_decoding_errors`
 * default) to apply the stream's declared /Filter (typically FlateDecode)
 * BEFORE this class ever sees it, so the stream token still holds the raw
 * AES-128-CBC ciphertext untouched. This class decrypts it with the
 * per-object key, THEN applies the declared filter itself (decrypt-then-
 * decompress is the correct order - PDF compresses, then encrypts, at write
 * time) using Smalot's own Smalot\PdfParser\RawData\FilterHelper, so decoding
 * behavior for FlateDecode/ASCII85Decode/etc. stays byte-identical to what
 * Smalot already does for every unencrypted PDF.
 *
 * Loose string values (dictionary strings outside of stream content, e.g. an
 * Info dict entry or an annotation's /Contents - never actual page text,
 * which always lives in stream content) are decrypted too, for
 * completeness, and re-escaped back into valid PDF literal-string source via
 * PdfObjectDictionaryReader::encodeLiteralString() - Smalot's own
 * Parser::parseHeaderElement() re-parses whatever string value it is given
 * as literal-string source, so raw decrypted bytes must be escaped first or
 * an unescaped `(`, `)`, or `\` byte would corrupt the reconstruction.
 */
final class SecuredObjectDecryptor
{
    private readonly FilterHelper $filterHelper;

    public function __construct(
        private readonly AesV2StandardSecurityHandler $handler,
        private readonly PdfObjectDictionaryReader $reader = new PdfObjectDictionaryReader,
    ) {
        $this->filterHelper = new FilterHelper;
    }

    /**
     * @param  array<string,array>  $data  RawDataParser::parseData()'s object map (id "N_G" => raw parts)
     * @param  string  $encryptObjectId  the trailer's /Encrypt reference (e.g. "6_0") - excluded from
     *                                   decryption: ISO 32000-1 7.6.1 exempts the /Encrypt dictionary's own
     *                                   content from encryption, so its /O and /U are already plaintext.
     * @return array<string,array> a new map, same shape, strings/streams decrypted
     */
    public function decryptAll(array $data, string $encryptObjectId): array
    {
        $out = [];
        foreach ($data as $id => $parts) {
            if ($id === $encryptObjectId || $this->isExemptFromEncryption($parts)) {
                // ISO 32000-1 7.5.8.2: a cross-reference stream (/Type /XRef) is
                // excluded from encryption in its entirety, same reasoning as
                // /Encrypt itself - a reader must be able to parse it before it
                // can even authenticate. Confirmed empirically against the real
                // production document: its xref stream's own /ID array holds two
                // 16-byte PLAINTEXT copies of the file ID (matching the trailer's
                // own /ID) - attempting to AES-decrypt them fails immediately
                // (16 raw bytes cannot be valid IV+ciphertext), and its FlateDecode
                // stream content is genuinely uncompressed-only, never encrypted.
                $out[$id] = $parts;

                continue;
            }
            [$objNum, $genNum] = $this->splitObjectId((string) $id);
            $out[$id] = $this->decryptObjectParts($parts, $objNum, $genNum);
        }

        return $out;
    }

    /** @return array{0:int,1:int} */
    private function splitObjectId(string $id): array
    {
        $pieces = explode('_', $id);

        return [(int) ($pieces[0] ?? 0), (int) ($pieces[1] ?? 0)];
    }

    private function isExemptFromEncryption(array $parts): bool
    {
        foreach ($parts as $part) {
            if (($part[0] ?? null) !== '<<') {
                continue;
            }
            $dict = $this->reader->walkDictTokens($part[1]);

            return ($dict['Type'] ?? null) === 'XRef';
        }

        return false;
    }

    private function decryptObjectParts(array $parts, int $objNum, int $genNum): array
    {
        [$declaredFilters, $declaredLength] = $this->declaredStreamMetadata($parts);

        return array_map(
            fn (array $part) => match ($part[0] ?? null) {
                '<<' => [$part[0], $this->decryptDictTokens($part[1], $objNum, $genNum), $part[2] ?? null],
                'stream' => $this->decryptStreamPart($part, $objNum, $genNum, $declaredFilters, $declaredLength),
                default => $part,
            },
            $parts,
        );
    }

    /** @return array{0: string[], 1: ?int} [declared /Filter names, declared /Length] */
    private function declaredStreamMetadata(array $parts): array
    {
        foreach ($parts as $part) {
            if (($part[0] ?? null) !== '<<') {
                continue;
            }
            $dict = $this->reader->walkDictTokens($part[1]);
            $filter = $dict['Filter'] ?? null;
            $filters = match (true) {
                $filter === null => [],
                is_array($filter) => array_values(array_filter($filter, 'is_string')),
                default => [$filter],
            };
            $length = is_int($dict['Length'] ?? null) ? $dict['Length'] : null;

            return [$filters, $length];
        }

        return [[], null];
    }

    /** @param array<int,array> $tokens flat [nameToken, valueToken, ...] pairs, as produced by RawDataParser */
    private function decryptDictTokens(array $tokens, int $objNum, int $genNum): array
    {
        $out = [];
        $count = count($tokens);
        for ($i = 0; $i + 1 < $count; $i += 2) {
            $out[] = $tokens[$i]; // name token - never encrypted, kept as-is
            $out[] = $this->decryptValueToken($tokens[$i + 1], $objNum, $genNum);
        }
        if ($count % 2 === 1) {
            $out[] = $tokens[$count - 1]; // malformed trailing token - preserve rather than drop
        }

        return $out;
    }

    private function decryptValueToken(array $token, int $objNum, int $genNum): array
    {
        return match ($token[0] ?? null) {
            '<<' => [$token[0], $this->decryptDictTokens($token[1], $objNum, $genNum), $token[2] ?? null],
            '[' => [$token[0], array_map(fn (array $el) => $this->decryptValueToken($el, $objNum, $genNum), $token[1]), $token[2] ?? null],
            '(' => ['(', $this->decryptToEscapedLiteral($this->reader->decodeLiteralString((string) $token[1]), $objNum, $genNum), $token[2] ?? null],
            '<' => ['(', $this->decryptToEscapedLiteral((string) (@hex2bin((string) $token[1]) ?: ''), $objNum, $genNum), $token[2] ?? null],
            default => $token,
        };
    }

    private function decryptToEscapedLiteral(string $ciphertext, int $objNum, int $genNum): string
    {
        if ($ciphertext === '') {
            return '';
        }
        $plain = $this->handler->decryptObject($ciphertext, $objNum, $genNum);

        return $this->reader->encodeLiteralString($plain);
    }

    private function decryptStreamPart(array $part, int $objNum, int $genNum, array $declaredFilters, ?int $declaredLength): array
    {
        // Deliberately $part[1] (the raw bytes RawDataParser's own stream-boundary
        // scan extracted), NEVER $part[3][0]: RawDataParser unconditionally attempts
        // to apply the stream's declared /Filter (e.g. FlateDecode) BEFORE this class
        // ever sees the data, and - for genuinely encrypted content - that attempt
        // can "succeed" with silently-truncated garbage rather than failing cleanly.
        // Confirmed against the real production document: PHP's compress.zlib://
        // stream-wrapper fallback (FilterHelper::decodeFilterFlateDecode()'s second
        // attempt, used when gzuncompress() itself returns false) can misinterpret a
        // few bytes of high-entropy AES ciphertext as the start of a valid deflate
        // stream and return a short, non-empty, incorrectly "decoded" result instead
        // of throwing - silently corrupting $part[3][0] for roughly 14% of this
        // document's direct (non-object-stream) streams. $part[1] is populated by a
        // separate code path (the stream/endstream boundary scan) that never invokes
        // any filter and is unaffected.
        $raw = $part[1] ?? null;
        if (! is_string($raw) || $raw === '') {
            return $part;
        }
        // RawDataParser's own decodeStream() truncates to the declared /Length when
        // the raw extraction over-reads (e.g. a trailing CRLF before "endstream") -
        // replicated here so AES-CBC receives an exact, block-aligned ciphertext.
        $ciphertext = ($declaredLength !== null && $declaredLength < strlen($raw))
            ? substr($raw, 0, $declaredLength)
            : $raw;

        $plain = $this->handler->decryptObject($ciphertext, $objNum, $genNum);
        $plain = $this->applyDeclaredFilters($plain, $declaredFilters);

        $part[3] = [$plain, []];

        return $part;
    }

    private function applyDeclaredFilters(string $data, array $filters): string
    {
        foreach ($filters as $filter) {
            if ($filter === 'Crypt') {
                // The Standard Security Handler already covers this stream -
                // an explicit /Crypt entry in the filter chain (rare; not
                // present in the real document this milestone targets) needs
                // no further action once AES-128-CBC decryption above ran.
                continue;
            }
            if (in_array($filter, $this->filterHelper->getAvailableFilters(), true)) {
                $data = $this->filterHelper->decodeFilter($filter, $data);
            }
        }

        return $data;
    }
}
