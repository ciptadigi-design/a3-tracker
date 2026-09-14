# M2.20G controlled external pilot acceptance

This document is the deployment-readiness gate for Cipta Grafika plus one controlled external printing company. All rehearsal identities and tenant records are synthetic. It authorizes neither customer onboarding nor Production deployment.

## Acceptance fixture and result

`M2_20GControlledPilotAcceptanceTest` creates a Platform Superuser and two isolated tenants named `CG_ACCOUNT` and `EXTERNAL_ACCOUNT`. Each tenant has two branches, Owner/Admin/Technician/Operator users, a machine, private manufacturer/model/component/profile, supplier, inventory item/location, operational person, component lifecycle, and branch assignments. The external account uses `Asia/Makassar`; its machine overrides to `Asia/Jayapura`. CG uses `Asia/Jakarta`.

The rehearsal covers bidirectional account isolation, same-account branch isolation, effective capabilities, zero-branch initial Owner attachment, owner-led setup, real Owner/Operator credential login, operational timestamps, FIFO purchase/receipt/replacement lineage, all seven reports, tenant export secrecy, account-local revocation, and last-Owner protection.

Two bounded G-P0 defects were found and fixed:

- Laravel's `required|array` rejected the intentional empty `branch_ids: []` when Platform attached the initial Owner before the first branch existed. The endpoint now requires the field to be present but permits an empty Owner assignment; `MemberLifecycle` continues enforcing branch requirements for non-Owners.
- Receipt validation accepted the canonical UTC ISO timestamp contract, but the service passed the ISO text directly to a MySQL `DATETIME` column. Receipt instants are now parsed once and normalized to UTC before receipt and FIFO-movement persistence; retry comparison remains instant-aware.

## Tenant export rehearsal

The manual export manifest is an explicit allowlist:

- account, branches, machines, tenant-owned manufacturer/model/component/profile configuration;
- membership metadata containing user identity metadata but no credential/session fields;
- operational people, counters, incidents, purchases, inventory movements, FIFO layers/allocations, replacements, click targets, and governance audit.

Every query is anchored to the selected account, directly or through its owned parent. Password hashes, remember tokens, session versions, authentication tokens, sessions, other-tenant identifiers, and unrelated global data are excluded. This is a support procedure, not a customer-facing export product.

## Support recovery matrix

| Case | Non-destructive investigation | Supported correction |
| --- | --- | --- |
| Cannot log in | User status, normalized login identity, membership status, session-version/audit evidence | Platform credential reset or membership reactivation under governed endpoints |
| Machine disappeared | Account/branch assignment, machine status, model status, audit history | Restore machine/model status or correct branch assignment through normal administration |
| Stock is wrong | Immutable movement history, receipt lines, FIFO layers/allocations, transfer pair, replacement link | Audited inventory adjustment; never delete/recreate ledger evidence |
| Counter is wrong | Effective sequence, previous reading, correction history, operator snapshot | Counter correction endpoint; never overwrite/delete the reading |
| User must lose access | User status, account membership, branch assignments, effective capabilities, session version | Suspend/revoke the selected membership, disable the identity, or security reset as appropriate |

Support must stay within one explicitly selected account. Raw credentials and unrelated tenant rows are never exposed.

## Historical time risk and CSV decision

M2.20F did not rewrite historical timestamps. Pre-F CG rows entered through browser-dependent local datetime conversion may have ambiguous intent. This does not affect a fresh external tenant and does not block its new operational reporting. CG should review only materially disputed historical boundary rows; any correction/backfill requires a separate evidence-based plan.

CSV formula neutralization is G-P1, required before general commercial sale. For the controlled pilot, exports remain staff-generated, manually reviewed, and opened only by trusted recipients. It is not a G-P0 provided this handling control remains in force.

## Production compatibility read-only result

The exact Production release `a51c81f74af8e463d7676a4f7536e0bfb3506f5f` was verified before aggregate-only MariaDB queries ran inside a `READ ONLY` transaction. The transaction was rolled back. No business rows, credentials, or private values were printed.

Zero violations were found for IANA timezone validity, IDR account/purchase currency, machine/branch/model ownership, component private references, inventory location/item ownership, purchase/receipt/movement/replacement relationships, incident machine/branch relationships, operational-person branch relationships, membership branch relationships, policy booleans, or duplicate normalized email/username groups.

## Production delta from `a51c81f`

The combined A-F release changes 132 files across identity/session invalidation, tenant and branch authorization, backend-authoritative capabilities, administrative audit, Overview periods, Owner administration, operational timezone transport/report boundaries, inventory-consumption projection, IDR validation, deployment artifact validation, and their frontend affordances/tests. There is one migration: `2026_09_13_000100_add_user_session_version.php`.

The migration adds unsigned `users.session_version` with deterministic default `0`. It rewrites no business values and is compatible with existing users and MySQL/MariaDB. Application rollback may leave the additive column in place. Dropping it is unnecessary and would remove the revocation field; the documented `down()` is reserved for a separately authorized database rollback.

## Combined A-G deployment plan — do not execute without authorization

1. Pin and verify the reviewed G SHA on `develop`; require Database CI and Laravel MySQL Target CI green for that exact SHA.
2. Reconfirm Production `current`, `/api/v1/version`, host identity, database identity, and absence of an in-progress deployment.
3. Run the canonical Production backup gate. Continue only after validated database and `public_html` backups, manifest, SHA-256 checksums, and `BACKUP_GATE=PASS`.
4. Build from the exact SHA with `VITE_DATA_BACKEND=laravel` and `VITE_API_BASE_URL=/api/v1`.
5. Verify the frontend artifact and build manifest before copying anything.
6. Stage a fresh immutable release directory; install Production dependencies without development packages.
7. Link the canonical shared `.env` and shared storage using the fail-closed link procedures.
8. Run release/migration preflight and confirm only the session-version migration is pending.
9. Run the pending migration once and verify `users.session_version` exists with default `0`.
10. Record the exact release identity and verify it before activation.
11. Atomically activate the backend release symlink.
12. Sync only the validated `dist/` into the existing `public_html` using `sync-public-html.sh`; preserve infrastructure files and directory identity.
13. Reset OPcache only if the host's PHP cache does not revalidate the activated code; do not restart unrelated services.
14. Run unauthenticated health/version/401 checks, followed by authenticated Platform, CG Owner, Operator, session reset, cross-account, Owner Settings, Overview Period, report/time, inventory, and audit acceptance.
15. Record exact release SHA, migration result, artifact checksum, backup manifest, acceptance results, and rollback decision.

## Rollback plan

`ROLLBACK_CODE_TARGET=a51c81f74af8e463d7676a4f7536e0bfb3506f5f`, subject to confirming its staged release and runtime compatibility. Leave `users.session_version` in the database; do not run the migration `down()` merely to revert application code. Restore the validated pre-deployment frontend archive or activate the old release's validated `dist/` through canonical sync tooling.

Rolling code back to `a51c81f` reopens A-F identity, authorization, capability, audit, period, Owner-administration, timezone, currency, and report defects. Therefore rollback is emergency containment, not a safe steady state. Disable pilot access or place the application in controlled maintenance until the reviewed release can be restored.

## Post-deployment acceptance matrix

| Actor/scope | Required checks |
| --- | --- |
| Unauthenticated | `/api/v1/health` healthy; `/api/v1/version` equals exact release; `/api/v1/me` returns 401 |
| Platform | Account list/bootstrap controls available; no implicit operational branch access; global governance audited |
| CG Owner | Settings, members, branches, people, policies, audit, machines, inventory, Overview Period, seven reports |
| CG Operator | Only backend-issued effective capabilities and assigned branches; policy OFF denied and policy ON scoped |
| Session reset | Target's prior session denied on next request; acting administrator remains authenticated |
| Cross-account | Direct IDs, nested IDs, omitted filters, report filters, and replay keys expose/mutate no foreign data |
| Time/report | Jakarta/Makassar/Jayapura boundaries, opening/counter/incident/replacement/receipt/cost, IDR labels, truthful empty/non-empty reports |
| Release | Version SHA, migration status, logs, health, frontend manifest, and backup manifest all match the deployment record |

## Pilot entry criteria

The real external customer receives credentials only after all of these are true:

- Production runs the reviewed exact A-G release and the session-version migration is applied.
- Exact-SHA Database CI and Laravel MySQL CI are green.
- Canonical backup gate passes and rollback artifacts are validated.
- Production unauthenticated and authenticated acceptance passes.
- Synthetic two-tenant and branch-isolation acceptance remains green with no G-P0 finding.
- Owner self-sufficiency, effective capabilities, revocation, audit, timezone/report correctness, inventory/FIFO reconciliation, and IDR enforcement pass.
- Support staff have this recovery matrix and the tenant-export allowlist.
- Pilot exports remain manually reviewed until CSV formula neutralization is delivered.
- A named operator owns monitoring, incident response, rollback authority, and customer communication for the controlled window.
