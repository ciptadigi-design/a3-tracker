# Maintenance V2.1 Semantic Error-Code Parser

The V2.1 parser is a deterministic, database-free transformation from extracted manual text to immutable `ParsedOfficialErrorEntry` DTOs. It does not acquire PDFs and has no persistence or legacy knowledge-pipeline dependency.

## Pipeline boundary

1. Source acquisition is outside this implementation. Callers supply raw text or ordered `SourceTextChunk` values with optional page numbers.
2. `MalfunctionSectionDetector` recognizes dotted malfunction headings followed by a four-character alphanumeric `C-` code. A section ends only at the next valid malfunction heading, never at a page boundary.
3. `OfficialErrorFieldParser` maps known headings and aliases, preserves ordered parts and solution steps, and derives references without removing reference text from its authoritative instruction.
4. `ParsedOfficialErrorEntry` and its step/reference DTOs hold normalized fields, exact semantic source text, SHA-256, diagnostics, and outcome.
5. `OfficialErrorEntryValidator` assigns `PASS`, `WARN`, or `FAIL`. There is deliberately no persistence implementation in this iteration.

Page chunks are joined with one newline only when the preceding chunk has no ending line break. The resulting detected section bytes are `raw_source_text`; SHA-256 is computed directly over those exact bytes, matching the official-entry model contract.

## Applicability and variant safety

Applicability is accepted only from an explicit parenthesized section-heading qualifier, an explicit applicability field, or the literal `Main body:` classification prefix. Slash-separated explicit labels retain their source order. Variant normalization uppercases the labels, preserves letters, digits, and hyphens, and uses underscores for separators.

When applicability is not explicit, the parser emits `AMBIGUOUS_APPLICABILITY`, leaves the applicability list empty, and assigns `UNRESOLVED_<first 12 SHA-256 characters>`. This stable source-scoped fallback intentionally prevents uncertain variants of the same code from being merged. It is not an equipment inference.

## Diagnostics and outcomes

- `PASS`: identity and ordered structure are safe and no diagnostics were emitted.
- `WARN`: the DTO is usable, but a non-critical condition such as an unknown heading, missing classification, missing ordered solution, or ambiguous applicability needs policy attention.
- `FAIL`: safe identity or ordered structure was not established. Missing malfunction headings, conflicting Code fields, and malformed step sequences are errors.

Unknown headings stop canonical field capture at that boundary, remain intact in `raw_source_text`, and emit `UNKNOWN_FIELD_HEADING`. This prevents unknown content—including uncertain warning boundaries—from being silently folded into another field. A future ingestion layer must not persist `FAIL` results without an explicit policy.

## Explicit exclusions

The parser does not access the Production document, interpret diagrams, perform OCR or LLM extraction, run jobs, write a database, or use `maintenance_document_pages`, legacy candidates, review sessions, canonical publishing, or the legacy knowledge services. Golden fixtures are small local excerpts or clearly labeled synthetic structural cases only.
