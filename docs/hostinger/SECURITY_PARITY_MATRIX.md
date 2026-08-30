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

M2.11D status: authentication, explicit platform privilege, account membership status, account/branch scope resolvers, fail-closed checks, and foundation tests are **FOUNDATION_PORTED/PARITY_TESTED**. Full operational-table policy translation remains **DEFERRED_DOMAIN**. Frontend filtering is never a security boundary.
