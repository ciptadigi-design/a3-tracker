# M2.12 deployment checklist

All boxes are for a future authorized cutover. M2.12 performs no Production action.

## Pre-cutover

- [ ] Hostinger preflight complete (PHP/extensions/MySQL/SSH/Apache/SSL/permissions/backups).
- [ ] Laravel frontend/auth/tenant adapters complete; no Supabase Production dependency.
- [ ] Approved SHA and release manifest recorded.
- [ ] `npm ci && npm run build` and Composer production install pass.
- [ ] Fresh and existing MySQL migration rehearsals pass; `migrate --force` is the only Production migration command.
- [ ] Data matrix, crosswalk, source snapshot, counts, fingerprints, and approvals complete.
- [ ] Backup and disposable restore verified.
- [ ] DNS, rollback, freeze, and monitoring owners identified.

## Cutover

- [ ] Announce and technically enforce source write freeze.
- [ ] Capture final snapshot and source fingerprints.
- [ ] Back up target database, `.env`, storage, current release, and public assets.
- [ ] Upload staged release and configure secrets out of band.
- [ ] Run migrations, bootstrap, and approved import.
- [ ] Verify health, version SHA, auth, authorization, direct routes, API JSON, and sensitive-file denial.
- [ ] Activate release; DNS change requires separate authorization.

## Post-cutover

- [ ] Read-only smoke: login/logout, branch/machine scope, Counter, Components, Inventory, Machine Cost, Errors, Reports, Settings.
- [ ] Compare counts/fingerprints and monitor logs/health.
- [ ] Unfreeze writes only after business acceptance.
- [ ] Preserve rollback backup and release manifest.

## Rollback

- [ ] Level 1 release rollback assessed.
- [ ] Level 2 application + known-good DB restore assessed.
- [ ] Level 3 DNS abort/legacy return assessed.
- [ ] Re-run health/version/auth/scope checks and record decision owner.
