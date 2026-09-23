# Maintenance V2.1 Official Knowledge Foundation

Maintenance V2.1 stores structured manufacturer evidence independently from the legacy error-code, extraction, publication, and ticket domains. The authoritative unit is one malfunction or error section from a `maintenance_documents` record. Physical PDF pages are provenance boundaries, not knowledge identities.

## Domain and identity

`maintenance_official_error_entries` is the parent record. Its stable identity is:

```text
(document_id, code, variant_key)
```

`variant_key` is an explicit semantic identity such as `MAIN_BODY`, `FS-531_FS-612`, or `FS-532`. The model trims it, uppercases it, preserves hyphens, converts other separators to `_`, and collapses repeated underscores. It never derives identity from solutions, parts, or other descriptive text. This permits multiple entries with code `C-1103` in one document when their equipment applicability differs.

Children preserve applicability, manufacturer part terminology, ordered solution steps, and typed references. A reference may apply to the entry or identify a step number. Model writes reject a step-level reference unless that numbered step already exists on the same entry.

The entry stores both normalized fields and `raw_source_text`. On Eloquent writes, `source_hash` is SHA-256 over the exact UTF-8 bytes assigned to `raw_source_text`, including whitespace and line endings. API reads never hash the document or PDF.

## Ownership and provenance

Official knowledge is document-owned. It has no duplicated account, branch, or machine ownership column. Visibility follows `maintenance_documents.account_id`:

- a global document is visible to authenticated active users under the existing document catalog rules;
- an account-owned document is visible to users with an active membership in that account.

The document foreign key uses delete restriction. Child records cascade only when their official error entry is deliberately removed. An optional applicability link to `machine_models` becomes null if that catalog model is deleted; the manufacturer `scope_type` and `scope_label` remain preserved.

## Read-only API

Authenticated endpoints:

```text
GET /api/v1/maintenance/official-error-entries
GET /api/v1/maintenance/official-error-entries/{id}
```

The list supports exact normalized `code`, `document_id`, and applicability `machine_model_id` filters. It returns summary and provenance metadata without `raw_source_text`. Detail returns the complete official fields, ordered children, document provenance, source hash, page range, and raw source section. No V2 mutation endpoint exists.

## Boundary

This foundation creates empty additive tables in real environments. It does not parse PDFs, read legacy page/candidate data, backfill records, translate or simplify manufacturer text, expose frontend navigation, or connect V2 entries to `machine_error_codes` or Tickets. Golden Dataset records live only in automated-test fixtures.
