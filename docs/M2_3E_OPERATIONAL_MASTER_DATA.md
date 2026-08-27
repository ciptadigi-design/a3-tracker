# M2.3E Operational Master Data

M2.3E closes operational master-data gaps without starting Inventory or M2.4.

## Operational people

`operational_people` is an account-scoped, reusable PIC directory. Owner/admin members can create, edit, activate, archive, and delete records that have never been referenced. Technician/operator members have read access. Counter references use `ON DELETE RESTRICT`, so referenced people are archived rather than removed.

Daily Counter calls the operator-aware `record_machine_counter` signature. Each manual Daily reading stores both `operator_person_id` and `operator_name_snapshot`; `created_by` and `entered_by` continue to record the authenticated actor independently. Existing readings remain valid with both operator fields null. Corrections carry the original operator reference and snapshot forward while auditing the correcting actor.

The three requested Cipta Grafika names are not universal seed data. They live in `supabase/bootstrap/dev_cipta_grafika_operational_people.sql`, a guarded DEV-only script that resolves the Cipta Grafika account through `CG-TUP-A3-01` and verifies all three active rows.

## Machine catalog

The existing `manufacturers` and `machine_models` tables remain authoritative. Nullable `account_id` ownership distinguishes protected shared platform definitions from workspace-owned records, preserving Konica Minolta, AccurioPress C1070, machine references, profiles, lifecycle history, replacement history, and intelligence.

Settings → Operational masters provides Operators / PIC, Manufacturers, and Machine Models sections. Shared rows are read-only. Workspace rows use `BlockingDialog` forms, persistent drafts, explicit archive state, and guarded permanent deletion. Foreign keys deny deletion when a manufacturer, model, machine, profile, component, lifecycle, or related history still references a record.

Add Machine reads only active shared/current-workspace manufacturers and models. Models are filtered by manufacturer. Restored drafts containing unavailable catalog IDs display a conflict and are not silently rewritten.

## Timezones

Machine timezone remains a nullable IANA identifier. The form uses `Intl.supportedValuesOf('timeZone')` when available and always includes `Asia/Jakarta`, `Asia/Makassar`, and `Asia/Jayapura`. The first option explicitly represents inheritance.

Effective timezone resolution remains:

1. machine override
2. branch timezone
3. account default timezone

Machine Detail labels whether the displayed timezone is machine-specific, inherited from the branch, or inherited from the account. PostgreSQL's existing `is_valid_timezone` check remains authoritative.

## Verification

Database coverage is in `012_operational_master_data.test.sql`. It covers role boundaries, tenant isolation, operator snapshots and archival behavior, null historical operators, workspace catalog CRUD/protection, and timezone validity/inheritance foundations. The bootstrap must only run after Database CI passes and the linked DEV project is reconfirmed.
