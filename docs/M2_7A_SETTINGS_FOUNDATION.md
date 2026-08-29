# M2.7A Settings Foundation

## Purpose and scope

Settings is the administrative control plane for the workspace: how the tenant is configured, how its branches are structured, who belongs to it, and which accepted operational actions an Operator may perform. It does not replace Machines, Components, Inventory, Errors, Daily, Machine Cost, or Reports.

The route is `/settings`. It is shown in desktop and mobile navigation only to active Owners and Admins. A direct visit by a Technician or Operator renders a non-administrative access explanation; all reads and mutations remain database-authorized.

## Information architecture

Settings uses seven sections with persistent per-user/per-account selection:

1. Workspace — orientation, workspace profile, default timezone, and recent administration evidence.
2. Branches — active and archived branches, create/edit/archive/restore.
3. Members & Roles — existing Auth-backed memberships, fixed roles, suspension/reactivation.
4. Permissions — compact fixed-role and configurable-Operator matrix.
5. Operations — accepted Daily behavior and the existing Operator/PIC directory.
6. Machine Models — existing manufacturer/model administration plus explicit Component and Inventory handoffs.
7. Advanced — Advanced Machine Economics only.

Settings intentionally contains no analytics dashboard and no duplicate Component Catalog, Model Profile, Inventory Item, Inventory Location, Purchase, Error, Counter, or Machine CRUD.

## Workspace settings

`manage_workspace_settings` updates the trimmed display name and `default_timezone`. Names must contain 1–120 characters. The tenant UUID, account code, branch identity, event actor snapshots, and historical business snapshots do not change. Account lifecycle status is shown but is not editable in M2.7A.

The canonical timezone is an IANA name validated by `pg_timezone_names`. Resolution remains:

`machine.timezone → branch.timezone → account.default_timezone`

Changing the workspace default affects only future interpretation for branches and machines that inherit it. Explicit overrides and stored historical timestamps are not overwritten.

## Branch administration

`manage_settings_branch` supports create, update, archive, and restore. Codes are trimmed, uppercased, and remain uniquely normalized per account. Names are required. A branch timezone is either null (inherit the account default) or a valid IANA name.

Archive changes `is_active` and the existing audit fields. It never deletes or reassigns Machines, Counter history, Inventory Locations, Errors, Purchases, memberships, or report evidence. Archived branches are excluded from new operational selection by the existing tenant loader, while their rows and references remain intact. Restore re-enables the same branch identity. Because branch codes may be operational identifiers, administrators should treat mature codes as stable even though guarded editing remains available.

## Members, roles, and Owner protection

The system roles remain fixed:

- Owner
- Admin
- Technician
- Operator

M2.7A does not add custom roles. `get_settings_members` returns only account-scoped membership identity, profile display name, email, role, lifecycle status, and joined timestamps to an active Owner/Admin. It does not expose passwords or sensitive Auth metadata.

`manage_settings_membership` manages existing memberships only and supports `active` and `suspended`. It does not create Auth users or invitations. The current project has no secure invitation delivery backend; onboarding is explicitly deferred. Supabase Auth continues to own credentials and email changes.

Role/status mutations call the established `manage_account_membership` contract and its concurrency-safe account lock. Existing trigger protection prevents demoting, suspending, revoking, or deleting the last active Owner. Admins cannot create, promote, or modify Owners. Suspended members fail active-membership authorization immediately, while their membership and historical attribution remain.

## Operational permission architecture

`account_operational_permissions` is one account-level policy row, created deterministically for every existing and future account. It avoids boolean growth on `accounts` while keeping the current fixed-role model understandable. `has_operational_capability(account, capability)` resolves active membership role + account policy + tenant scope.

The operational RPCs remain the final enforcement point. M2.7A keeps the accepted implementations behind non-client-callable base functions and exposes capability-aware wrappers with the original signatures. A transaction-local override is set only by a non-executable security-definer helper after capability authorization; direct clients cannot invoke the helper or base functions. Existing idempotency, validation, locking, snapshots, and inventory/economics behavior inside each operational function remain unchanged.

### Exact permission matrix

| Capability | Owner | Admin | Technician | Operator | Accepted Operator default |
| --- | --- | --- | --- | --- | --- |
| Daily Counter | Allowed | Allowed | Allowed | Allowed | Allowed (fixed) |
| Initialize component lifecycle | Allowed | Allowed | Denied | Configurable | Off |
| Replace component | Allowed | Allowed | Allowed | Configurable | On |
| Create Purchase | Allowed | Allowed | Denied | Configurable | Off |
| Receive goods | Allowed | Allowed | Denied | Configurable | Off |
| Inventory adjustment | Allowed | Allowed | Denied | Configurable | Off |
| Inventory transfer | Allowed | Allowed | Denied | Configurable | Off |
| Log Errors | Allowed | Allowed | Allowed | Configurable | On |
| Manage Component Catalog / Model Profiles | Allowed | Allowed | Denied | Denied | Fixed |
| Manage Settings / structural masters | Allowed | Allowed | Denied | Denied | Fixed |

The migration defaults reproduce the database behavior immediately before M2.7A: Operator replacement and Error logging are enabled; Operator initialization, purchasing, receiving, adjustments, and transfers are disabled. Technician behavior also remains unchanged.

## Operations and administrative handoffs

Daily supports multiple chronological readings per day. Operator/PIC selection remains required and is not made optional. Counter type remains model/machine architecture, not a synthetic account preference. The existing Operator/PIC master stays in Operations because it had no separate module.

Manufacturer and Machine Model administration uses the existing `operationalMasters` service and `MasterRecordDialog`; no second table or service was created. Component counts and Model Profile counts link to `/components`, where their existing CRUD remains. Inventory Location counts link to `/inventory`, where location and stock CRUD remain.

## Advanced Machine Economics

The Settings mutation wraps the existing `set_machine_economics_advanced_enabled` contract and records administration evidence.

- Off: Standard economics only.
- On: Standard remains unchanged, plus Advanced Operating Costs and Full Contribution become available.

Changing the flag does not create, backfill, delete, or modify operating-cost rows. Historical cost evidence is untouched.

## Security, RLS, RPCs, and audit evidence

New tables have RLS enabled. Active account members may read their account policy; only Owner/Admin may read account administration evidence. There are no client table-write grants. Mutations are security-definer RPCs with empty `search_path`, qualified objects, active-membership checks, account locks, and tenant-scoped lookups. Anonymous, suspended, lower-role, and cross-account callers are denied. The frontend uses no service-role key.

`settings_change_events` captures action, target, normalized request payload, before/after state, actor UUID, immutable display-name snapshot, and timestamps for workspace, branch, membership, policy, and advanced-economics changes.

## Idempotency and concurrency

Every M2.7A administrative mutation requires a `client_request_id`. `(account_id, client_request_id)` is unique. An identical retry returns the current authoritative result; reuse with another action or payload raises `23505`. Account-row locks serialize workspace-wide administration. Existing normalized branch-code uniqueness resolves duplicate creation deterministically. Membership changes reuse the established account lock and last-Owner trigger. Policy and advanced-setting changes also lock the account, so simultaneous updates have an explicit database order. Archive and restore preserve one branch row.

## Responsive behavior

Desktop uses a compact sticky Settings section rail and bounded content panel. On tablet/mobile the rail becomes a horizontally scrollable section strip. Summary cards collapse from four to two to one column, lists reflow without horizontal page overflow, and only the permission matrix owns an intentional internal horizontal scroll. Editable BlockingDialog forms reuse the accepted one-row mobile footer ordering and sizing: submit, cancel, icon-only reset. Focus trap, focus restoration, Escape handling, inert background, scroll lock, and busy protection come from the shared `BlockingDialog`.

## Production and future scope

M2.7A is forward-only and adds no fake users, branches, memberships, or operational data. The only deterministic backfill is one permission policy row per existing account, with values matching accepted behavior.

Production migration/go-live and Maintenance are not part of this milestone. Future Settings work may cover custom roles, per-user overrides, SSO, API keys, integrations, notifications, Maintenance policies, backups, and secure Auth invitations. None is implemented here.
