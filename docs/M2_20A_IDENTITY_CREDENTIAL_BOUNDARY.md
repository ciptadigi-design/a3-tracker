# M2.20A — Identity & Credential Boundary

Implementation and security contract; Production deployment is **not authorized** by this milestone. Exact implementation SHA and CI run links are reported after the commit and push. M2.20B–G remain outstanding; external pilot readiness stays **NOT_READY**.

## Baseline and worktree protection

```
CURRENT_BRANCH=develop
HEAD_SHA=27007dc8d95b88ccf2c796b9723d9827332d03cf
ORIGIN_DEVELOP_SHA=27007dc8d95b88ccf2c796b9723d9827332d03cf
WORKTREE_CLEAN=NO
PREEXISTING_MODIFIED_FILES=3
PREEXISTING_UNTRACKED_FILES=33
PRODUCTION_BACKEND_SHA=a51c81f74af8e463d7676a4f7536e0bfb3506f5f (user-supplied; no Production access)
```

Every pre-existing path and SHA-256 is recorded in [the preservation manifest](evidence/m2-20a-preexisting-manifest.json). These paths are excluded from this commit. No reset, clean, stash, or unrelated rewrite was used.

## Reconstructed prior contract

| Path/action | Baseline behavior |
| --- | --- |
| Create global user | Owner or Platform can provision a user with name, normalized email/username, initial password, non-Owner role, and active account branches. |
| Attach existing user | `ProvisionMember` reused either matching global email or username. Two matches pointing to different users failed with 422. All other existing matches could attach silently. |
| Update role/status | `GovernanceController::updateMember` authorized account governance; Owner promotion required Platform. Membership writes occurred before profile/branch writes. |
| Update branches | Existing assignments were deactivated, then incoming assignments inserted without account/active validation. Composite foreign keys eventually rejected foreign branches. |
| Managed email/username/password | Account governance alone authorized mutation of the membership's global user, including shared and Platform identities. |
| Suspend/revoke | Membership status removes account access through `AccountAccessResolver`. Does not delete global user or another membership. |
| Global disable | No public global-disable route exists. `User.status` is checked by `EnsureActiveUser` and login. Privileged console/model administration is separate. |
| Self-management | Authenticated identity can update its own profile; email/password changes require current password. No public signup or forgot-password route. |
| Sessions | Routes use the web session guard and `active.user`; session driver is configurable. Production uses file sessions per the supplied contract. Conditional Sanctum session middleware is not an unconditional revocation boundary. |
| Password reset | Changed hash without reliable old-session revocation. A replayed target session still authenticated. |
| Owner protection | Controller/model checked sequential active-Owner counts; no shared transaction lock serialized requests against different Owners. |
| Platform privilege | Explicit `platform_user_privileges` record, separate from account Owner role; membership administration did not protect target Platform identities. |
| Multi-account | One global user can have multiple account memberships. Operational people remain separate, optionally linked to a user. |

## Test-first reproduction

Tests ran only with synthetic Tenant A/B, their Owners/Staff, Platform user, and A/B branches. The initial secure-contract run produced **13 failures and 3 passes** before implementation. The raw local red log is `/tmp/m2-20a-red.txt`; the observations below retain the relevant results without exception stacks.

| Scenario | Observed baseline response/effect |
| --- | --- |
| A/C: foreign email, new username | 201; foreign global identity attached to Tenant A. |
| B/D: foreign username, new email | 201; foreign global identity attached to Tenant A. |
| E: both identifiers match same foreign user | 201; foreign global identity attached. |
| F: Platform email or Platform username | Each returned 201 and attached the Platform identity. |
| Different foreign email and username owners | 422; no successful attachment. Error distinguished mismatched users. |
| G: already shared identity, managed email | 200; Tenant A Owner changed shared global email. The other managed credential paths had the same authority check; regression tests now deny all three independently. |
| F07: foreign branch with Admin/Suspended request | Failed at the FK, but prior role changed from Operator to Admin, status became Suspended, and the original branch assignment became inactive. |
| Managed password reset, old target session | Reset succeeded; replayed old session returned 200. |

```
F01_EMAIL_REUSE_REPRODUCED=YES
F01_USERNAME_REUSE_REPRODUCED=YES
F01_PLATFORM_IDENTITY_RISK_REPRODUCED=YES
F01_GLOBAL_CREDENTIAL_CONTROL_REPRODUCED=YES
F07_PARTIAL_UPDATE_REPRODUCED=YES
CURRENT_PASSWORD_RESET_REVOKES_OLD_SESSIONS=NO (baseline)
```

## Implemented safe contract

**Credential management is Platform-only (Option 2).** Existing data has no identity provenance proving tenant ownership. A membership, including an exclusive membership, is not proof of authority over global credentials.

- Owner may provision a **fresh** global identity only when neither normalized identifier resolves to an existing user. The supplied initial password applies only to that fresh identity. User creation, membership, branch assignments, and existing governance record are one transaction.
- Any existing identifier collision fails with the existing neutral **409 `Conflict.`** envelope. No account ownership, privilege state, membership count, or foreign user metadata is returned. Two different matching identities receive the same response. Database uniqueness races also roll back and return a neutral conflict.
- The same-account retry exception requires both normalized identifiers to exactly match the same active user, an existing membership in this account, identical non-Owner role, identical active branch set, and membership status Active or Suspended. Any Platform privilege record excludes this retry, even if inactive. Active retries are read-only. Suspended retries reactivate membership to retain the existing tested product behavior. Revoked/invited membership, role change, branch change, single-identifier match, or collation-equivalent but textually different identifiers fail closed. Submitted name/password never change the existing global identity.
- Existing identity attachment requires **`POST /api/v1/platform/accounts/{id}/members`**, explicit `user_id`, role, and account-valid branch IDs. Both controller and service require Platform authority. Existing memberships conflict; use the lifecycle endpoint for membership updates. Attachment never changes global credentials or creates an Operational Person. This is a controlled API, not an invitation system.
- Owners manage only their account's membership role (excluding promotion to Owner), status, and branch assignments. Including global profile fields in a membership update requires Platform authority. Managed email and password routes also require Platform authority.
- Tenant administration cannot mutate an attached Platform identity's membership or global profile/credentials. The check protects any privilege record, independent of target active state. Explicit Platform operations and self-management remain supported.
- Membership suspend/revoke affects only that account. No global identity is deleted or disabled. No global-disable HTTP endpoint was added; a membership `status=disabled` request is invalid. Global disable through trusted model administration revokes authentication globally.
- Email and username normalize before request validation. Non-string values remain validation errors. Usernames use the existing provisioning character set and the actual 32-character database limit. Case-insensitive lookup uses the database's own collation, including MySQL accent folding, and database unique constraints remain authoritative. Self/managed collision validation excludes the target user. Password limits are 10–128 characters, with confirmation for changes/resets.

The Settings UI is already Platform-only (`src/app/AppShell.jsx`). Its self-password flow already ends the local session and returns to Login. No frontend redesign or source change is needed.

## Atomicity and concurrency

`MemberLifecycle::update` validates branch ownership/active state before the transaction, then takes an exclusive lock on the **account row**, reauthorizes, reads the target membership with a locking read, checks Platform/profile authority and Owner transitions, revalidates branches, then writes membership/profile/assignments/audit atomically. Persistence exceptions roll back all writes.

The account row is the stable serialization point shared by lifecycle changes, provisioning, and explicit attachment. This serializes changes to different Owners without process-local locks or external services. The last-Owner decision uses a current locking read of active Owners after acquiring that lock. The pre-existing model guard remains a secondary sequential safeguard; runtime membership mutations must use these lifecycle/provisioning services. Direct bulk SQL is not an application administration API.

The MySQL test holds the account lock in one transaction and starts an independent PHP/MySQL process changing the other Owner. The competing process must remain blocked. The first transition commits; the competitor then returns 409 and exactly one active Owner remains. Demotion, suspension, and revocation each run this test. SQLite skips these tests; it is not used as locking evidence.

## Session revocation and schema

```
SESSION_REVOCATION_MECHANISM=users.session_version checked on every authenticated request
SCHEMA_CHANGE_REQUIRED=YES
MIGRATIONS_CREATED=2026_09_13_000100_add_user_session_version.php
```

The migration adds an unsigned BIGINT with deterministic default/backfill **0**. Login captures the authenticated user's version. `User::save` increments the version in the same SQL write as a password or global status change, using `session_version + 1` to avoid lost increments. Every authenticated request reloads the identity and rejects inactive users or a version mismatch with 401 and session invalidation.

Legacy sessions without a stamp are version 0: existing-user sessions survive deployment, but the first reset or global status transition invalidates them. They cannot learn a new version except by authenticating again. Disable/re-enable does not resurrect old sessions. The resetting administrator's unrelated session remains valid; a self-reset invalidates the caller too. The session version is hidden from user serialization. No file enumeration, Redis, workers, or new session storage driver is required.

No request already authorized before a reset is retroactively cancelled; rejection occurs on the next authenticated request. Trusted maintenance code must use the User model for security changes; raw SQL bypasses model behavior and is not a supported credential-reset operation.

## Global mutation review

| Global mutation | Who can call / proof of control | Shared user | Platform identity |
| --- | --- | --- | --- |
| Fresh creation | Account governor, only if both identifiers unused; transaction and unique indexes | Cannot reuse existing identity | Cannot reuse existing identity |
| Managed name/username | Explicit Platform privilege; tenant membership alone is insufficient | Platform-only | Platform-only |
| Managed email | Explicit Platform privilege | Platform-only | Platform-only |
| Managed password | Explicit Platform privilege; atomic version increment | Platform-only | Platform-only |
| Own profile/email/password | Authenticated target identity; current password for email/password | Self only | Self only |
| Global status | No tenant/public endpoint; trusted model operation | Not granted to tenant Owner | Not granted to tenant Owner |
| Bootstrap privilege | Explicit Platform gate; existing controlled console bootstrap remains separate | No credential mutation | Explicit platform grant |

Reviewed `User` lookups, `forceFill`/password/email/username/status mutations, membership writes, platform lookup, routes, console bootstrap, and models. No membership-based global identity control remains in these HTTP paths.

## Regression matrix

Coverage is in `M2_20AIdentityBoundaryTest`, `M2_20AMysqlIdentityTest`, and existing governance/auth/scope suites.

| Cases | Evidence |
| --- | --- |
| A01–A02 | Fresh create and same-account retry; identity raw attributes unchanged; existing suspended-retry regression preserved. |
| A03–A06, A29 | Independent foreign email/username/both/Platform collision datasets, normalized variants, split-identity collision, neutral metadata-free response. |
| A07–A09 | Admin, Technician, Operator cannot provision or govern members. |
| A10 | Explicit Platform attachment passes; Owner call forbidden. |
| A11–A13 | Shared and exclusive managed email/username/password mutations denied to Owner. |
| A14 | Membership disable is invalid; membership revoke does not alter global status; no global-disable route. |
| A15, A24–A25 | Revocation/suspension in A preserves B; next-request A access fails and B access succeeds; reciprocal Owner scope denial. |
| A16–A17 | Foreign and nonexistent branch requests leave raw membership/assignment state unchanged; injected persistence failure also rolls back profile and branches. |
| A18–A20 | Last Owner demote/suspend/revoke denied; Platform promotion creates a second Owner and permits exactly one removal. |
| A21 | Independent MySQL process serialization for demote, suspend, revoke. |
| A22–A23 | Replayed target session rejected after Platform reset; unrelated admin session succeeds; real file-cookie replay rejected. |
| A26 | Disabled target denied next request; disable/re-enable rejects unstamped legacy session. |
| A27–A28 | Operational Person creation creates neither user nor membership; fresh login creates no Operational Person. |
| A30 | Owner lacks explicit Platform privilege; existing privilege/self-management tests remain valid. |

Additional cases cover malformed identifier types, MySQL accent/case collisions, retry tampering/revoked memberships, and Platform-managed shared profile updates.

## Verification and release gates

Targeted identity/governance/session/branch suites passed before full runs. Full verification uses SQLite memory and the isolated local MySQL database `a3_tracker_m220a_test` (MySQL 9.7.1/InnoDB). The existing exact-SHA CI runs the entire backend suite on **MySQL 8**. Local PHP 8.4 is used; three existing PHPUnit doc-comment metadata deprecations are reported, not concealed.

Frontend files are untouched. Auth/Settings tests are run as compatibility evidence; the existing Database CI also runs the repository's full Node test/build and database checks. Changed PHP files are checked with Pint and PHP syntax checks. `git diff --check` and all 36 pre-existing SHA-256 checks gate the commit.

Local final verification: **456/456 MySQL tests passed (1,987 assertions)**; **448 SQLite tests passed, 8 database-specific tests skipped (1,955 assertions)**. M2.20A contributes 24 identity tests plus 4 MySQL tests. GovernanceParityTest: 14; BranchAuthorizationScopeTest: 9; MyAccountParityTest: 3; SessionCsrf: 3; SessionPersistence: 1 — all passed on MySQL. Auth/Settings frontend compatibility tests: **50/50**. PHP syntax and Pint: **14/14 changed PHP files**. Three pre-existing PHPUnit metadata deprecations remain. All 36 pre-existing file hashes match.

The final response records the commit and exact-SHA CI outcomes. No deployment is performed.

## Production deployment plan — requires separate explicit authorization

```
PRODUCTION_DEPLOYMENT_REQUIRED=YES (to apply this closure)
SCHEMA_MIGRATION_REQUIRED=YES
SESSION_BEHAVIOR_CHANGED=YES
ROLLBACK_COMPLEXITY=MODERATE (additive schema; security enforcement must not silently regress)
PRODUCTION_CHANGED=NO
DATABASE_PRODUCTION_CHANGED=NO
PUBLIC_HTML_CHANGED=NO
```

1. Pin the reviewed 40-character implementation SHA and require green **Database CI** and **Laravel MySQL Target CI** for that exact SHA. Reconfirm the actual deployed backend SHA against the supplied `a51c81f...`; do not assume Production remained unchanged while this work ran.
2. Follow [the canonical release procedure](production/RELEASE_PROCEDURE.md). Perform read-only migration/session/storage/config preflight. Inventory all migrations between the actual deployed SHA and this release: the release starts from a develop baseline newer than Production, so this migration alone must not be assumed to be the entire deployment delta.
3. Enforce [the canonical backup gate](production/BACKUP_INTEGRITY_RUNBOOK.md): `scripts/deployment/production-backup.sh <milestone-slug> <backend-dir> <public_html-dir> <backups-root>`. Require **BACKUP_GATE=PASS**, validated DB gzip/SQL contents, validated frontend archive, hashes and backup manifest. No manual unvalidated backup substitute. This is planning only; no backup command has been run against Production.
4. Build a fresh exact-SHA release, install production Composer dependencies, and link canonical shared environment and file-session storage. Use existing release identity/preflight scripts; require `RELEASE_PREFLIGHT=PASS`. Follow the canonical frontend verification requirements if a full release artifact is selected; there is no M2.20A frontend source change or independent public_html update authorized here.
5. Review `migrate:status` and `migrate --pretend` for the complete pending set. For this migration verify MySQL/MariaDB support, expected users table, absence/presence of `session_version`, BIGINT unsigned compatibility, and lock/maintenance window. Apply normal Laravel migrations only after the backup and explicit deployment approval; never `migrate:fresh` in Production.
6. Verify the column is unsigned BIGINT, NOT NULL, default 0; existing rows have deterministic non-null values; migration ledger records the additive migration. Do not deploy new code against a schema lacking the field.
7. Switch the release using the canonical procedure, refresh required caches, verify CLI release identity and public `/api/v1/version` report the exact approved SHA, and verify health and authenticated routes. Verify shared session storage still points to the canonical location.
8. Acceptance with separately approved existing users: existing login succeeds; an unchanged pre-deployment session continues; Owner membership-only updates work; foreign identity provisioning conflicts without metadata; shared membership revocation preserves the other account. Do not create Production test users without explicit approval.
9. Target-user revocation acceptance requires explicit approval for the selected identity/password reset: capture its old session, reset through Platform administration, verify old target session gets 401 and new credentials authenticate. Verify the administrator's unrelated session stays authenticated. Verify a self-password reset returns to Login and old cookies fail. Avoid exposing credentials/session cookies in evidence.
10. Rollback: retain the additive column when reverting code; previous code tolerates the extra column, but **does not enforce session-version revocation or the repaired identity boundary**. A code rollback therefore requires access containment and postpones external access. Old file sessions rejected under new code could authenticate under old code; do not treat a release switch as a security-safe rollback by itself. Prefer a forward fix. A down migration removes version history and requires old code first; do not run it automatically. Database restore requires its own canonical backup/restore gate and explicit acceptance of post-backup data loss.

Stop after CI and user review. Do not merge main, deploy Production, start M2.20B/C, or expand Owner Settings.
