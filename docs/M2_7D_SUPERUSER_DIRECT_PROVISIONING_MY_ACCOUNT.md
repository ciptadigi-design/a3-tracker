# M2.7D — Superuser Direct Provisioning & My Account

## Authority boundaries

Platform administration remains independent of workspace roles. The only platform privilege is the existing explicit row:

`auth.users.id → platform_user_privileges(user_id, role='superuser', is_active=true)`.

Owner, Admin, Technician, Operator, username, email, and display name never imply this privilege. Settings navigation, the route guard, governance RPCs, Direct Active provisioning, activation, managed email changes, and managed password reset all require the explicit active privilege. Global operational projection still follows the selected top-bar Branch.

## Operator-controlled bootstrap

`bootstrap-platform-superuser` is deliberately unusable until an operator configures both:

- `PLATFORM_BOOTSTRAP_USER_ID`: the exact immutable Auth UUID selected from an authoritative identity audit.
- `PLATFORM_BOOTSTRAP_TOKEN`: a temporary high-entropy token.

The function requires both values, compares the supplied token without early exit, verifies the exact Auth user through the server-side admin API, and calls the service-role-only `bootstrap_platform_superuser` database function. The database operation is repeat-safe and records grant time, explicit grantor UUID, and a non-secret operator note. No authenticated self-promotion RPC or frontend bootstrap control exists.

Operator sequence:

1. Audit Auth/profile/membership relationships and select the immutable UUID.
2. Generate a temporary token outside source control.
3. Set the two Edge Function secrets.
4. Deploy/invoke the bootstrap function with the exact UUID and token.
5. Verify one active `platform_user_privileges` row.
6. Unset both temporary bootstrap secrets. The deployed function then returns not found for every request.
7. Refresh or sign in again so `TenantProvider` reloads privilege state. Browser storage does not need clearing.

Changing display name, username, email, or password never changes the Auth UUID and therefore never removes the privilege. Future Owners are not promoted automatically.

## Direct Active provisioning

Settings → Members & Roles defaults to Direct Active creation. The Superuser supplies display name, username, email, initial password, role, and one or more Branches. The trusted `provision-member` function:

1. verifies the caller JWT;
2. invokes the caller-scoped `prepare_direct_member_provisioning` authorization and reservation;
3. creates a confirmed Supabase Auth user with the server-only admin API;
4. finalizes one profile, active membership, role, and normalized Branch assignments;
5. completes safe Settings audit evidence; and
6. returns only sanitized IDs/status.

Passwords never enter PostgreSQL parameters, application tables, audit payloads, logs, or responses. Email and username resolve to the same Supabase Auth password.

The request reservation remains the recovery anchor across the Auth/PostgreSQL boundary. Same request plus same non-secret payload converges. Conflicting reuse is rejected. A newly created Auth identity carries only the provisioning request UUID in server-owned metadata so a retry can reconcile an Auth-success/database-failure boundary without adopting an unrelated existing identity.

Invitation-compatible schema remains, but normal onboarding is no longer invitation-first.

## Existing invited-member activation

Activation targets the existing immutable Auth user UUID and invited membership. It verifies the Auth email, replaces the initial password server-side, confirms the email for this rollout, updates the existing profile, activates the existing membership, and reconciles Branch assignments. It never creates a duplicate identity or membership.

DEV's existing Graha invite is intentionally left unchanged until the explicit manual activation step. Automated tests use rollback-scoped local fixtures.

## Managed identity actions

Member governance edits keep display name/username/role/status/Branches in the audited Settings RPC. Email change and password reset are distinct buttons and trusted Auth-admin operations:

- Change email updates Supabase Auth and records only the normalized new email.
- Reset password replaces the credential and records only `credential_replaced=true`.

Existing passwords are never recoverable or displayed.

## My Account

Every authenticated user can open top-right user menu → My Account:

- Display name and username update through `manage_my_profile`.
- Email change requires current-password verification and updates confirmed Supabase Auth email.
- Password change requires current-password verification plus a valid new password.

My Account cannot change workspace role, membership status, Branch assignments, or platform privilege. Email verification/reconfirmation is intentionally deferred for the current centrally administered rollout.

## Security, audit, and data boundaries

All new database functions use `SECURITY DEFINER` with an empty search path and fully qualified objects. Public and anonymous execution is revoked. The bootstrap and safe Auth audit recorder are service-role-only. Authenticated preparation/finalization functions revalidate explicit Platform Superuser privilege, Account, membership target, role, Branches, identity, and idempotency.

`identity_change_events` stores only safe identity evidence. `settings_change_events` continues to store tenant-governance evidence. Neither stores passwords, hashes, tokens, service credentials, or session material.

Granting platform privilege creates no membership, Branch assignment, PIC, Machine, Inventory, counter, Error, lifecycle, cost, or Report evidence.

## Graha clean-room contract

The Graha Admin remains authorized only for Graha. After activation and login, Tuparev is absent from the Branch selector and Settings is hidden. Graha begins with its real empty operational state. Shared Component Catalog, Model Profiles, and Inventory Item definitions remain Account-level masters; physical and operational evidence remains Branch-scoped.
