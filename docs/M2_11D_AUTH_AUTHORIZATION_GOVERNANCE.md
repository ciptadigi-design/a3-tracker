# M2.11D — Auth, authorization and tenant governance parity

Laravel now implements the security/governance boundary without changing Supabase DEV or porting operational domains. Users support normalized username/email login, explicit `active|disabled` status, and Sanctum sessions. Memberships preserve `owner|admin|technician|operator` plus `invited|active|suspended|revoked`; inactive users, suspended memberships, inactive accounts, and archived branches fail closed.

`PlatformPrivilegeService`, `AccountAccessResolver`, and `BranchAccessResolver` centralize privilege, tenant, and branch decisions. Owners receive implicit all-branch access; other roles require active `account_membership_branches`. Platform Superuser is only the explicit UUID-bound `platform_user_privileges.role=superuser` row and is separate from account roles. Platform/settings Gates are explicit and never identity-string based.

Governance routes cover account/branch/member foundations and protected bootstrap. `ProvisionMember` is transactional, with unique constraints preventing duplicate users/memberships/assignments. Immutable `governance_audit_logs` capture high-value changes without secrets. `/api/v1/me` returns safe identity, platform, membership, account and branch scope data. Complete provisioning edge parity, password broker/email workflows, and operational policies remain deferred.

Tests cover username login, disabled users, suspended memberships, archived accounts, cross-account/branch denial, Owner != Superuser, explicit privilege, rollback, UUIDs and MySQL/InnoDB CI. Supabase migrations and pgTAP remain the reference specification.
