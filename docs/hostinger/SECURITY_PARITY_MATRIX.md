# Security parity matrix

| Control | Current enforcement | Laravel/MySQL replacement | Proof |
|---|---|---|---|
| Anonymous denial | Supabase Auth + RLS | Sanctum middleware | API feature test |
| Tenant isolation | `is_account_member`, RLS | Account membership resolver, scoped repositories, FKs | cross-account fixture |
| Branch isolation | `can_access_branch`/operational scope | Branch scope resolver + policy | cross-branch read/write tests |
| Suspended membership | membership status predicates | resolver rejects suspended/pending | auth matrix |
| Platform Superuser | `platform_user_privileges`, explicit functions | privilege table + middleware/policy; never infer from identity | privilege escalation tests |
| Governance | owner/admin policies and SECURITY DEFINER RPCs | Gates/Policies + service authorization | role matrix |
| Inventory mutation | RPC checks, locks, immutable ledger | FormRequest + policy + transaction + `FOR UPDATE` + unique idempotency key | concurrency tests |
| History protection | triggers/RPCs | immutable models, guarded updates, DB constraints/triggers where needed | mutation rejection tests |

All 39 RLS-enabled tables require a row-by-row translation review before implementation; frontend filtering is never a security boundary.
