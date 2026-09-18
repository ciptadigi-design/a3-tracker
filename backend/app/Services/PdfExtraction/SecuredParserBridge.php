<?php

namespace App\Services\PdfExtraction;

use Smalot\PdfParser\Document;
use Smalot\PdfParser\Parser;

/**
 * V1.5.5 - a genuine, ordinary PHP subclass of Smalot\PdfParser\Parser (no
 * vendor file is modified, forked, or patched - the vendor package on disk
 * is byte-for-byte what Composer installed). Its only job is to build a
 * Document from an ALREADY-DECRYPTED $xref/$data pair (produced by
 * RawDataParser::parseData() + SecuredObjectDecryptor::decryptAll()) instead
 * of Parser::parseContent()'s normal path, which re-parses a raw file and
 * throws on the very /Encrypt trailer entry we have already handled.
 *
 * buildDocument() is deliberately the exact tail of Parser::parseContent()
 * (compare vendor/smalot/pdfparser/src/Smalot/PdfParser/Parser.php) - same
 * per-object construction loop, same trailer parsing, same Document
 * assembly - reusing Parser's own protected parseObject()/parseTrailer()
 * and $objects property rather than reimplementing any of it. This is why
 * V1.5.5 never needed to write a PDF serializer/rewriter: Smalot's own
 * object-construction code (including its existing /Type /ObjStm expansion
 * logic in parseObject(), which SecuredObjectDecryptor's decrypted object
 * streams pass through unchanged) already does that work for an unencrypted
 * $data map, encrypted or not.
 */
final class SecuredParserBridge extends Parser
{
    /**
     * @param  array{trailer: array<string,mixed>}  $xref
     * @param  array<string,array>  $data  already decrypted by SecuredObjectDecryptor
     */
    public function buildDocument(array $xref, array $data): Document
    {
        $document = new Document;
        $this->objects = [];
        foreach ($data as $id => $structure) {
            $this->parseObject($id, $structure, $document);
            unset($data[$id]);
        }
        $document->setTrailer($this->parseTrailer($xref['trailer'], $document));
        $document->setObjects($this->objects);

        return $document;
    }
}
