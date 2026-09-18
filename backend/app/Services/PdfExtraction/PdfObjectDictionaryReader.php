<?php

namespace App\Services\PdfExtraction;

/**
 * V1.5.5 - extracted from PdfSecurityInspector (V1.5.4) so PdfSecurityInspector
 * (safe profile classification) and the new AESV2 decryption path (V1.5.5, which
 * genuinely needs the raw /O, /U, /P, file-ID material PdfSecurityInspector
 * deliberately never returns) can share the exact same, already-proven-correct
 * dictionary-token-walking logic instead of one of them duplicating it.
 *
 * Reads a PDF dictionary's flat name/value tokens as produced by Smalot's own
 * Smalot\PdfParser\RawData\RawDataParser - no parallel tokenizer, same as
 * PdfSecurityInspector's own design rationale.
 *
 * This class has no security boundary of its own - it is a pure structural
 * reader. Callers decide what to do with (and how much to expose from) the
 * values it returns.
 */
class PdfObjectDictionaryReader
{
    /** @param array<int,array> $tokens flat [nameToken, valueToken, nameToken, valueToken, ...] as produced by RawDataParser */
    public function walkDictTokens(array $tokens): array
    {
        $dict = [];
        $count = count($tokens);
        for ($i = 0; $i + 1 < $count; $i += 2) {
            $nameToken = $tokens[$i];
            if (($nameToken[0] ?? null) !== '/') {
                continue; // Not a name key - skip defensively rather than assume well-formed input.
            }
            $dict[$nameToken[1]] = $this->tokenValue($tokens[$i + 1]);
        }

        return $dict;
    }

    public function tokenValue(array $token): mixed
    {
        return match ($token[0] ?? null) {
            '<<' => $this->walkDictTokens($token[1]),
            '[' => array_map(fn (array $element) => $this->tokenValue($element), $token[1]),
            '/' => $token[1],
            'numeric' => is_numeric($token[1]) ? $token[1] + 0 : null,
            'boolean' => $token[1] === 'true',
            '<' => @hex2bin((string) $token[1]), // hex string, e.g. <28BF4E5E...> - raw bytes
            '(' => $this->decodeLiteralString((string) $token[1]), // literal string, e.g. (\373\252\261...) - raw bytes
            default => null,
        };
    }

    /**
     * PDF literal-string decoding (ISO 32000-1 7.3.4.2). RawDataParser hands
     * back the raw source bytes between the outer parentheses verbatim
     * (including any backslash escapes) - a real PDF producer is free to
     * encode a dictionary string value as EITHER a hex string <...> or a
     * literal string (...); the real production Konica PDF uses the latter
     * for /O and /U.
     */
    public function decodeLiteralString(string $raw): string
    {
        $out = '';
        $len = strlen($raw);
        for ($i = 0; $i < $len; $i++) {
            $ch = $raw[$i];
            if ($ch !== '\\') {
                $out .= $ch;

                continue;
            }
            $next = $raw[$i + 1] ?? '';
            switch (true) {
                case $next === 'n':
                    $out .= "\n";
                    $i++;
                    break;
                case $next === 'r':
                    $out .= "\r";
                    $i++;
                    break;
                case $next === 't':
                    $out .= "\t";
                    $i++;
                    break;
                case $next === 'b':
                    $out .= "\x08";
                    $i++;
                    break;
                case $next === 'f':
                    $out .= "\x0C";
                    $i++;
                    break;
                case $next === '(':
                    $out .= '(';
                    $i++;
                    break;
                case $next === ')':
                    $out .= ')';
                    $i++;
                    break;
                case $next === '\\':
                    $out .= '\\';
                    $i++;
                    break;
                case $next === "\r": // line continuation: \<CR>, \<LF>, or \<CR><LF> produces nothing
                    $i++;
                    if (($raw[$i + 1] ?? '') === "\n") {
                        $i++;
                    }
                    break;
                case $next === "\n":
                    $i++;
                    break;
                case ctype_digit($next) && (int) $next < 8:
                    $octal = $next;
                    $i++;
                    for ($d = 0; $d < 2 && ctype_digit($raw[$i + 1] ?? '') && (int) $raw[$i + 1] < 8; $d++) {
                        $octal .= $raw[$i + 1];
                        $i++;
                    }
                    $out .= chr(octdec($octal) & 0xFF);
                    break;
                default:
                    // Per spec: an unrecognized escape drops the backslash and keeps the character.
                    $out .= $next;
                    $i++;
            }
        }

        return $out;
    }

    /**
     * Inverse of decodeLiteralString(): escapes raw bytes back into valid PDF
     * literal-string *source* text (the part that goes between the outer
     * parens). Needed because Smalot's own Parser::parseHeaderElement() takes
     * whatever string value it is given, wraps it in a fresh pair of parens,
     * and RE-PARSES it as literal-string source (ElementString::parse()) -
     * handing it raw decrypted bytes unescaped would corrupt/misparse any
     * byte that happens to be `(`, `)`, or `\`. Only those three bytes are
     * syntactically significant in a PDF literal string (ISO 32000-1
     * 7.3.4.2); every other byte, including non-printable and high bytes,
     * may appear literally with no escaping required.
     */
    public function encodeLiteralString(string $raw): string
    {
        $out = '';
        $len = strlen($raw);
        for ($i = 0; $i < $len; $i++) {
            $byte = $raw[$i];
            $out .= match ($byte) {
                '(' => '\\(',
                ')' => '\\)',
                '\\' => '\\\\',
                default => $byte,
            };
        }

        return $out;
    }

    public function resolveFileId(?string $hex): ?string
    {
        if ($hex === null || $hex === '') {
            return null;
        }
        $bytes = @hex2bin($hex);

        return $bytes === false ? null : $bytes;
    }

    /** @return array<string,mixed> flat map of a dictionary object's keys, e.g. ['V'=>4,'CF'=>['StdCF'=>['CFM'=>'AESV2','Length'=>16,...]],...] */
    public function resolveDictionary(string $ref, array $data): array
    {
        $parts = $data[$ref] ?? null;
        if (! is_array($parts) || ! isset($parts[0]) || ($parts[0][0] ?? null) !== '<<') {
            throw new PdfExtractionException(PdfExtractionErrorCode::INVALID_OR_CORRUPT_PDF, "Object {$ref} is not a dictionary.");
        }

        return $this->walkDictTokens($parts[0][1]);
    }
}
