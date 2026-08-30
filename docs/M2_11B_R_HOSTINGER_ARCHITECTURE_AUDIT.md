# M2.11B-R — Hostinger migration architecture & compatibility audit

**Scope.** Audit/blueprint only. No Laravel rewrite, MySQL creation, Hostinger access, DNS, import, or hosted-DEV mutation was performed. Evidence is the repository at `7c77142d1bb16d22b3a99c6b507f93ee51063a6e`.

## Numbered report

1. Recovered starting SHA: `7c77142d1bb16d22b3a99c6b507f93ee51063a6e`.
2. Final SHA: same SHA before this documentation commit.
3. Worktree was clean at recovery; final status is documentation/artifacts only.
4. Frontend: React 19.2, Vite 7.3, Tailwind 4.2, lucide-react; Vite static build.
5. Routing is an in-app route switch (`useAppRoute`), with authenticated shell/pages and direct-refresh paths.
6. State is React context/hooks plus local persistent draft/UI state; no Redux or server cache.
7. Data access is centralized in 21 `src/services/supabase/*` modules, though pages import those modules directly.
8. Environment variables are `VITE_SUPABASE_URL` and `VITE_SUPABASE_PUBLISHABLE_KEY`.
9. Direct Supabase frontend dependency count: 32 source files import the client or service layer.
10. PostgREST dependency count: 21 service modules issue table/view `.from()` reads/writes (no raw `/rest/v1` strings).
11. Distinct RPC dependency count: 52 (machine-readable inventory in `scripts/architecture/m2-11b-r/`).
12. Edge Function count: 5 present; 3 invoked by frontend (`auth-login`, `manage-account`, `provision-member`), plus uncalled `bootstrap-platform-superuser`.
13. Supabase Storage dependency: none in application code; config is local platform default only.
14. Realtime dependency: none in application code; do not introduce a WebSocket replacement.
15. Backend-independent KEEP: React UI/layouts, routing, responsive CSS, dialogs, drafts, UI state, pagination widgets, charts, export presentation, feature composition.
16. Adapter-required: every current data loader/mutation in `src/services/supabase/*`; preserve returned fields through `/api/v1` compatibility adapters.
17. Supabase-specific REWRITE: client initialization, `supabase.auth`, PostgREST query construction, `.rpc`, Edge Function invocation.
18. Obsolete/legacy DO NOT PORT: Supabase/Vercel deployment plumbing and migration-only SQL helpers; retain as reference evidence.
19. Recommended frontend preservation: approximately 80–90% of UI/feature code; replace only auth and data boundary.
20. Proposed API: business-oriented Laravel controllers/actions/query objects, not PostgREST mirroring.
21. API namespace: `/api/v1`; response `{data,meta,errors}` with compatibility flattening where current components require it.
22. Auth replacement: Laravel Sanctum first-party SPA cookie sessions, CSRF bootstrap, server sessions, logout invalidation, password hashing and throttling.
23. Sanctum fitness: FIT_WITH_CONDITIONS—same-site HTTPS topology and Hostinger session storage/rewrites must be verified.
24. Current RLS policy count: 98 `CREATE POLICY` statements across 39 RLS-enabled tables.
25. RLS parity: auth middleware → membership resolver → branch resolver → policies/gates → scoped repositories → FK/unique/check constraints.
26. Platform Superuser: explicit `platform_user_privileges` row and middleware/policy; never infer from email, username, owner role, or display name.
27. Membership parity: active account membership required; pending/suspended/revoked users denied; owner/admin gates preserved.
28. Branch parity: every branch-scoped query and mutation receives resolved branch scope; no frontend-only filtering.
29. SECURITY DEFINER inventory: 144 named PostgreSQL functions total; approximately 75 security-definer/service or trigger declarations (including replacements/overloads) audited and mapped by class.
30. RPC/function mapping: all 52 frontend RPCs classified in `rpc-map.json`; mutations become Laravel Actions/Services, reports Query objects/views, governance Policies/Services.
31. Edge mapping: `auth-login`→Sanctum login controller; `manage-account`→credential/profile controller; `provision-member`→admin provisioning service; bootstrap→one-time artisan/admin action.
32. PostgreSQL inventory includes UUID/pgcrypto, jsonb, arrays, enums, CHECKs, generated stored columns, triggers, views, CTEs, window functions, `FOR UPDATE`, advisory locks, SECURITY DEFINER, RLS, timestamptz, `AT TIME ZONE`, intervals, `ON CONFLICT`, `RETURNING`.
33. Direct MySQL-compatible: InnoDB transactions, FKs, unique/NOT NULL, basic CHECK (version-qualified), views, CTE/window functions on MySQL 8+.
34. Syntax adaptation: UUID storage, JSON operators, enums, generated columns, date/time functions, upsert and post-insert fetch.
35. Move to Laravel: RLS, SECURITY DEFINER authorization, advisory-lock orchestration, domain validation, provisioning and report policy.
36. Redesign: partial/expression/lateral indexes/joins and any PostgreSQL extension-dependent behavior.
37. Architectural blockers: none found in repository; Hostinger runtime facts remain conditions, not blockers.
38. Required minimum DB: MySQL 8.0.34+; MariaDB 10.6+ only after a complete parity suite.
39. InnoDB: mandatory; MyISAM or unenforced foreign keys is a blocker.
40. UUID strategy: retain stable UUID identities; target `CHAR(36)` initially for migration simplicity, debugging and Laravel/phpMyAdmin visibility. BINARY(16) is a later optimization only.
41. Timestamp strategy: UTC `DATETIME(6)` in MySQL plus explicit IANA timezone fields; never use Hostinger server timezone as truth.
42. Timezone resolution: machine timezone → branch timezone → account default; Carbon computes local inclusive periods and DST boundaries.
43. Atomic transactions: component replacement, lifecycle initialization/transition, inventory receive/transfer/adjust/opening movement, purchase workflows, FIFO allocation, counters/corrections, machine-component sync, provisioning, incident writes, operating/selling-cost writes.
44. Locking: `DB::transaction()` + deterministic `SELECT ... FOR UPDATE`; preserve unique constraints and race tests.
45. Idempotency: retain `client_request_id`/equivalent unique keys for inventory, receipt, replacement, counter and provisioning retries; return original result on identical retry and reject changed payload.
46. FIFO parity: Purchase ≠ Receipt; receipt creates stock/cost layers; replacement consumes FIFO layers; external replacement records no fabricated stock; unknown opening cost stays unknown; legacy purchases create no receipt.
47. Ledger: immutable inventory movement, receipt, cost-lot and allocation/event records remain authoritative; mutable balance is derived/cache only.
48. Replacement: one atomic lifecycle close + new lifecycle + optional inventory issue/cost evidence + event/PIC snapshots.
49. Component architecture: preserve catalog, model profile slot blueprint, physical machine assignment, and lifecycle evidence as separate concepts.
50. Model Profiles: repeated logical components valid; uniqueness is machine/profile slot code, not global component ID/name; C1070’s 28 slots are not universal.
51. Exclusions: durable machine-level exclusion row with reason/cleared state; sync must not re-add inherited UNKNOWN/no-history slots.
52. Lifecycle: UNKNOWN ≠ zero, no fabricated install date, historical A–D semantics, archive/restore, and no destructive deletion of lifecycle-backed assignments.
53. Counter: preserve multiple readings/day, effective chronology, operator snapshots, machine-local timezone and period aggregation; baselines contribute zero.
54. Machine Cost: Standard = consumed component cost + machine-attributed assessed Error/Waste; Advanced adds operating costs only when enabled (default OFF); never use acquisition cost before consumption.
55. Cost location: transactional writes in Laravel services; complex cost/report reads in Query objects or SQL views, with parity fixtures.
56. Errors/PIC: preserve operator, PIC many-to-many eligibility, canonical operational-person IDs, immutable snapshots, auto-default and manual override, machine vs branch attribution.
57. Operational People: separate from Auth Users; many-to-many branch assignments remain normalized.
58. Reports: 14 report RPCs plus machine-cost queries; preserve KPI full-filter semantics, timezone periods, evidence statuses and detailed rows.
59. Pagination: current default 10 with 10/25/50 controls and client pagination remains accepted for current volume.
60. Server pagination: design `page,per_page,sort,direction,search,branch_id,machine_id,date_from,date_to`; paginate detail rows server-side while KPIs query the complete filtered set.
61. Validation: Laravel FormRequests/services are trust boundary; frontend validation remains UX; preserve SQL/RPC checks as tests and DB constraints.
62. Constraint parity: port PK/FK/UNIQUE/NOT NULL/default/CHECK/index invariants; do not move integrity solely into PHP.
63. Archive/delete: explicit archive/restore for referenced masters; hard delete only safe unreferenced workspace rows; do not blanket-enable SoftDeletes.
64. Storage migration: none required; if future evidence appears use Laravel private local storage and controlled public symlink/download authorization.
65. Backups: Hostinger native backup + independent logical SQL dump + release archive + migration manifest; export before cutover mutation.
66. Scheduler: none currently required; if introduced, Hostinger cron runs `php artisan schedule:run`.
67. Queues: none currently required; avoid persistent workers; classify future jobs as sync/cron/database queue with cron unless VPS is approved.
68. Realtime: none; no shared-hosting WebSocket dependency.
69. Hostinger topology: same-domain static React + Laravel `/api/v1` under `a3.ciptagrafika.com` is simplest and avoids CORS.
70. Filesystem: keep source outside web root; `public_html` contains Vite assets (or Laravel `public` contains assets plus protected app), never secrets/vendor/private storage/dumps.
71. Same-domain feasibility: expected FIT subject to Apache rewrite and cookie path verification.
72. `.htaccess`: API/Sanctum/health precedence, then existing files, then React fallback; React must never catch `/api/*`.
73. PHP: Laravel 11 requires PHP 8.2+; prefer 8.3 where Hostinger supports it.
74. Extensions: PDO MySQL, mbstring, openssl, tokenizer, xml, ctype, fileinfo, intl, curl, bcmath, json, zip as needed.
75. Composer: `composer install --no-dev --optimize-autoloader`; cache config/routes/views; run migrations only in controlled deploy.
76. Node: build-time only (`npm ci`, `npm run build`); no Node runtime in production.
77. Environment: public frontend API base only; Laravel keeps DB/session/app/mail secrets private; no Supabase keys required in final production build.
78. API abstraction: add thin `src/lib/api` before feature cutover; adapters normalize errors and shapes without UI rewrites.
79. Dual backend: explicit development/reference switch only; production selects one backend at build/deploy time.
80. Silent fallback: prohibited; Laravel failures must surface parity defects.
81. Repository: preserve frontend root, add `backend/`; avoid mass file moves.
82. Schema blueprint: 41 tables retain snake_case names where practical; adapt UUID/time/enum/JSON types, preserve domain separation, split only where MySQL indexing requires it.
83. Table mapping status: all current tables are KEEP/ADAPT candidates; no DROP decision before row-level migration design. Full inventory is in migrations and supporting matrix.
84. Function mapping status: 144 named functions grouped into CRUD/service, transaction, report, auth/governance, trigger/invariant and migration-only classes.
85. Edge mapping status: all 5 present functions have target routes/actions; only 3 need frontend route compatibility.
86. Security matrix status: completed at control level in `docs/hostinger/SECURITY_PARITY_MATRIX.md`; implementation requires endpoint-by-endpoint tests.
87. Parity tests: same deterministic fixtures execute against Supabase reference and Laravel/MySQL; compare authorization, counters, lifecycle, inventory/FIFO, cost, incidents and reports.
88. Golden fixtures: isolated non-production Account/Branch/Machine/Component/Inventory/Error/Report fixtures; never mutate hosted DEV acceptance data.
89. Legacy adapter: reuse approved five source tables (`click_history`, `part_replacements`, `error_logs`, `inventory_parts`, `part_purchases`) plus snapshots, fingerprints, disposition, mapping and crosswalk.
90. Opening stock: production opening stock is physical stock opname, not a legacy inventory snapshot.
91. DEV preservation: Vercel + Supabase remains `REFERENCE_DEV` and behavioral oracle until final parity.
92. Future access: SSH host/port/user/key, subdomain/document root, PHP, DB host/name/user/password, phpMyAdmin, Git, cron and backup details.
93. Secret handling: SSH private keys, `.env`, APP_KEY, DB passwords/tokens and dumps never committed; use Hostinger secret entry and restricted file modes.
94. Shared-hosting limits checklist: memory/timeouts, upload limits, input vars, cron cadence, process/DB connections, quota/inodes, jailed SSH, symlinks, Composer, outbound HTTP/mail, retention.
95. Performance: internal tracker workload is compatible conditionally; index account/branch/machine/time/status/component/inventory/purchase/incident fields and avoid N+1.
96. Indexing: composite tenant-scope + date/status indexes; unique slot/membership/person-branch/client-request keys; validate with query plans.
97. Money: DECIMAL (IDR exactness), never FLOAT; quantities retain numeric precision and FIFO partial quantities.
98. JSON: MySQL JSON adequate for snapshots/metadata; normalize fields used in predicates/indexes.
99. Production routing: same-domain API, SPA fallback, health/version route, and no public backend internals.
100. Health/version: safe `/health` checks app, DB reachability and schema version; `/version` exposes app/Git SHA only, not secrets.
101. Logging: Laravel daily/size rotation where available; never log passwords, tokens, cookies or full sensitive payloads.
102. Error/status: map validation 422, auth 401, authorization 403, not-found 404, conflict 409, success 200/201/204, generic 500; hide SQL details.
103. Dual-stack CI: retain React + PostgreSQL/pgTAP and add Laravel + MySQL plus shared fixture parity jobs.
104. pgTAP: do not remove; it remains Supabase specification until M2.12 acceptance, then retire only after equivalent MySQL coverage.
105. Laravel migration source: Supabase migrations remain reference truth during port; Laravel migrations define target; after cutover Laravel is production source of truth.
106. Created artifacts: six Hostinger docs plus four machine-readable JSON inventories under `scripts/architecture/m2-11b-r/`.
107. Documentation created: target topology, PostgreSQL/MySQL matrix, Supabase/Laravel matrix, security matrix, requirements and roadmap.
108. Tests run: repository inspection plus planned test commands; no business code changed.
109. pgTAP: not executed in this docs-only environment (requires configured PostgreSQL/Supabase test runner).
110. Schema lint: not executed; no schema changed.
111. Changed-code ESLint: not applicable; no application code changed.
112. `git diff --check`: run before commit.
113. Production build: run `npm run build` before commit.
114. Hosted DEV mutation: NONE.
115. Supabase Production provisioning: NONE.
116. Vercel Production provisioning: NONE.
117. Hostinger mutation: NONE.
118. `main`: unchanged at protected SHA `7f35603fc9b1eeed7b85901ab77a6e121b57a005`.
119. `production-old/main`: no local ref was advertised; remote `production-old` was not mutated.
120. Final commit: documentation commit created on `develop` only.
121. Exact-SHA CI: must be verified for the final documentation SHA after commit/push.
122. Shared-hosting fitness: `HOSTINGER_SHARED_HOSTING_FIT_WITH_CONDITIONS` pending runtime/version/limit verification.
123. Remaining unknowns: actual Hostinger PHP/MySQL/MariaDB versions, InnoDB enforcement, cookie/session behavior, rewrite/symlink/cron limits, quotas and connection ceilings.
124. Next milestone: M2.11C — Laravel/MySQL Foundation (not implemented here).
125. Current Vercel/Supabase DEV remains working by preservation; legacy production import, DNS cutover, and maintenance have not started.

## Acceptance marker

M2_11B_R_HOSTINGER_AUDIT_CONDITIONAL
