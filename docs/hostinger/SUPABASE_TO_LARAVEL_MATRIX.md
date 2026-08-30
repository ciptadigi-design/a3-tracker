# Supabase → Laravel matrix

The frontend currently has 21 data-access modules and 52 distinct RPCs. Keep UI, routing, layouts, drafts, persistent UI state, pagination controls, charts, and feature components. Introduce `src/lib/api` (or `src/services/api`) as the only UI boundary. During development an explicit `BACKEND=reference|laravel` switch is allowed; production has exactly one backend and no silent fallback.

| Current surface | Laravel target |
|---|---|
| PostgREST table/view reads | `/api/v1` resource controllers + API Resources; preserve response fields where practical. |
| 52 RPCs | Actions/services for mutations; Query objects/views for reports; stable business routes, not `/rpc` mirroring. |
| `auth-login` | Sanctum login controller accepting username/email, throttling and generic failure. |
| `manage-account` | Authenticated account security controller + audit service. |
| `provision-member` | Admin-only provisioning controller/service and invitation/password workflow. |
| `bootstrap-platform-superuser` | One-time protected artisan command/admin action; explicit privilege row. |
| `supabase.auth.*` | Sanctum session/CSRF endpoints and Laravel password broker as needed. |
| RLS | Middleware → membership/branch scope → policies/gates → constrained repositories → FKs. |
| Storage/realtime | None used by frontend; do not add replacement complexity. |

Response convention is `{data, meta, errors}` for new endpoints, with compatibility adapters flattening legacy shapes when needed. Use 422 validation, 401 unauthenticated, 403 forbidden, 404 missing, 409 conflicts, and non-leaky 500 errors.

M2.11D implemented replacements: `auth-login` → `AuthController@login`; `provision-member` → transactional `ProvisionMember`; `bootstrap-platform-superuser` → protected API action and `platform:bootstrap-superuser`; `is_platform_superuser`, `is_account_member`, and `can_access_branch` → `PlatformPrivilegeService`, `AccountAccessResolver`, and `BranchAccessResolver`. Operational RPCs remain deferred.
