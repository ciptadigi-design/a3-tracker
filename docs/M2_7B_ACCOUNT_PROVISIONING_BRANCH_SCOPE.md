# M2.7B Account Provisioning and Branch Scope

## Tenancy and role contract

The Platform is the global A3 Tracker environment. An Account is a tenant/workspace; a Branch is an operational unit inside one Account. `account_memberships` remains the user-to-Account relationship, so one Auth user may hold different roles in multiple Accounts.

Platform privilege is stored independently in `platform_user_privileges`. A platform Superuser is not represented by fake Owner memberships. It may enter active tenant contexts for support and use their Branches. Existing tenant memberships are not removed or rewritten by this migration; granting the new platform privilege is a deliberate platform administration operation.

Owner is the tenant-governance role. Owner has every active Branch implicitly and manages workspace profile, Branches, members, permission policy, PIC assignments, and shared administrative masters. Admin is an operational administrator: it may manage Machines and operational modules only inside explicitly assigned Branches, and cannot invoke tenant-governance RPCs. Technician and Operator are also explicitly Branch-scoped; their operational mutations additionally follow the existing capability policy. Suspended memberships resolve no scope.

The sidebar and direct `/settings` route expose Settings only to Owner or platform Superuser. Database authorization independently enforces this boundary.

## Username and Auth boundary

`profiles.username` is the user-facing identity and `profiles.username_normalized` is the authoritative lowercase key. Both are user-level, not membership-level. The database trims and lowercases usernames, enforces `^[a-z0-9._-]{3,32}$`, reserves `admin`, `root`, `system`, `support`, `api`, `auth`, and `null`, and owns the partial unique index. Existing profiles retain `NULL`; their email login remains valid.

The login form accepts Email or username. The `auth-login` Edge Function resolves a normalized username through the service-role-only `resolve_login_username` RPC, retrieves the Auth identity server-side, and performs the normal Supabase password sign-in. The service role receives no broad `profiles` table grant. The function returns session tokens, never the resolved email. Email and username use the same Supabase Auth password. All failures return `Invalid username/email or password.`; the endpoint intentionally logs neither identifier nor password. Invalid usernames also pass through an invalid Supabase sign-in so Auth-side rate controls remain involved. Remaining production concern: configure Supabase Auth rate limits/CAPTCHA according to the final threat model.

`SUPABASE_SERVICE_ROLE_KEY` exists only in the Edge runtime. It must never be named `VITE_*`/`NEXT_PUBLIC_*`, bundled, printed, or logged. Required Edge secrets/configuration names are `ALLOWED_ORIGINS` and `INVITE_REDIRECT_URL`; Supabase supplies `SUPABASE_URL`, `SUPABASE_ANON_KEY`, and `SUPABASE_SERVICE_ROLE_KEY` to deployed functions.

## Invite-first provisioning and recovery

Owner submits display name, global username, email, fixed system role, Branch IDs, and a `client_request_id` to `provision-member`. The Edge Function verifies the caller JWT. The caller-scoped `prepare_member_provisioning` RPC re-resolves Owner/platform authorization, validates the Account, role, active Branches, email, username, and reserves concurrent username/email requests before any Auth side effect.

The server then locates an existing Auth identity by normalized email or sends a Supabase invite. It never creates or displays a password. `finalize_member_provisioning`, still called under the Owner JWT, verifies the prepared request and exact Auth identity, creates/updates the profile, creates one invited membership, assigns Branches, and completes Settings audit evidence.

If Auth succeeds but database finalization fails, retrying the same request finds the existing Auth identity and completes the missing database work without another invitation. A different payload with the same request key is rejected. Database uniqueness wins username, email-in-Account, and membership races. Completed reservations cease blocking the same user from joining another Account. An existing membership is rejected cleanly. Invite completion uses the in-app password setup screen; `accept_current_memberships` activates invited memberships only after an authenticated session and only when scoped roles have a Branch assignment.

## Branch access and operational scope

`account_membership_branches` is the normalized many-to-many relationship. Composite foreign keys forbid cross-Account membership/Branch pairs and the unique membership/Branch key prevents duplicates. Owner needs no assignment rows. Admin, Technician, and Operator require explicit active rows. `can_access_branch(account_id, branch_id)` resolves platform privilege, active Account/membership, Owner implicit scope, and scoped assignments. `can_access_operational_scope` additionally recognizes deliberate `NULL branch_id` account-global records.

Branches and Machines have explicit policies. Existing Branch-bearing operational tables receive restrictive RLS policies, so older permissive account policies cannot widen scope. A trigger-level guard also protects writes inside legacy `SECURITY DEFINER` RPCs and closes the race where a Branch assignment is revoked while a mutation is in flight. Report RPC scope resolution requires scoped users to provide an accessible Branch; only Owner/platform may request workspace-wide aggregation.

Machine lists inherit Machine RLS. The create/edit Branch selector receives only TenantProvider's accessible Branches; one Branch is preselected by the existing form behavior. Zero Branches disables creation with `No branch access assigned. Contact the workspace owner.` The database rejects inaccessible create, move, or mutation attempts.

Catalog components, model profiles, manufacturers, machine models, and inventory items remain Account/shared masters rather than being duplicated per Branch. Machine component/lifecycle evidence follows its Machine Branch. Inventory Locations with a Branch follow Branch scope; `branch_id IS NULL` continues to mean an intentional account-global/central location. Branch-specific PIC lists are filtered to the current Branch. Purchasing remains an Account-level procurement master, while receipt/location operations follow the chosen location's Branch.

## Operational People / PIC

`operational_person_branches` is a normalized many-to-many relationship with same-Account foreign keys and one person/Branch row. Owner manages assignments in Settings Operations; labels show every assigned Branch. Active PIC forms require at least one active Branch.

Daily Counter, Components replacement, Inventory, receiving, and Machine Cost query only active people assigned to the workflow Branch. Database triggers validate PIC assignment for counter readings, Branch-owned inventory movements/receipts, and machine operating costs. Removing an assignment disables future selection/validation only. Historical person IDs and name snapshots are unchanged, and the person record is not deleted. Account-global inventory locations retain account-level PIC semantics because they have no owning Branch.

Errors preserves the legacy account-member `responsible_user_id` and snapshots for historical rows, while new/edited responsibility uses `responsible_person_id`. Its server wrapper validates the active Operational Person against the incident Branch before delegating to the established revision implementation. Legacy evidence is not rewritten.

## Audit, migration, and backfill

`settings_change_events` remains the administrative audit system. Provisioning, username/member role/status/Branch edits, and PIC Branch edits carry idempotency keys and safe before/after evidence. Passwords and session tokens are never audit metadata.

The forward migration is `20260829000200_account_provisioning_branch_scope.sql`. It does not rewrite prior migrations. Owners are implicitly all-Branch. No Admin/Technician/Operator assignment is fabricated. No real Operational Person is assigned to every Branch. Consequently the migration backfills zero membership-Branch rows and zero PIC-Branch rows; Owner must configure actual operational truth. No `admin.tuparev` or other fake acceptance user is created.

## Manual acceptance

1. Log in as Owner/platform-authorized support and open Settings → Members & Roles.
2. Add `CG Tuparev Admin`, username `admin.tuparev`, a user-supplied real email, Admin, Tuparev; send invite.
3. Complete the email invite and set a password in the app.
4. Sign in once with `admin.tuparev`, then once with the same email. Both must reach the same user.
5. Confirm governance Settings/Branches/Members/Permissions are absent and `/settings` is denied.
6. Confirm the Branch context contains only Tuparev and Machine create preselects/offers only Tuparev.
7. Create a Machine if desired and confirm the established Model Profile provisioning contract; no lifecycle evidence is fabricated.
8. Attempt a different Branch through REST/RPC and confirm rejection.
9. Assign Person A to Tuparev and Person B elsewhere; verify Tuparev workflows offer only Person A.
10. Add Person A to a second Branch, verify both, then remove one assignment and confirm history remains unchanged while new selection is denied.

Production readiness/M2.8, Maintenance, public signup, arbitrary ACLs, OAuth/SSO, username password reset, billing, and fake demo accounts remain out of scope.
