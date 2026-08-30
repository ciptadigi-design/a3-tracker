# M2.11C Laravel/MySQL foundation

This milestone adds a Laravel 11 application in `backend/`, Sanctum dependency/configuration, UUID-based users, minimal `accounts`, `account_memberships`, `branches`, and explicit `platform_user_privileges` migrations, request IDs, JSON exception handling, health/version routes, and a MySQL 8 Docker development path.

The current React/Vite app is unchanged and remains `VITE_DATA_BACKEND=supabase` by default. No RPCs were ported, no Supabase/Vercel DEV data was changed, and no Hostinger or DNS action occurred. Future API work must use an explicit backend adapter; silent Laravel→Supabase fallback is prohibited.

Target conventions: `/api/v1`, `{data,meta,errors}` responses, UTC timestamps with explicit IANA business timezones, DECIMAL money, strict MySQL/InnoDB, server-generated UUIDs, fail-closed authorization scaffolding, and database-backed sessions/cache suitable for shared hosting. M2.11D will add complete membership/branch policies and provisioning parity.
