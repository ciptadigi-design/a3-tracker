# Production Release Procedure

Authoritative for the actual Hostinger deployment layout in use since M2.12E
(`releases/<sha>/` + `current` symlink + `shared/`, see
[BACKUP_INTEGRITY_RUNBOOK.md](BACKUP_INTEGRITY_RUNBOOK.md)). The individual
gates below already existed as separate scripts; this doc is the first place
that lists them together, in order, because no such list existed - the actual
per-release procedure lived only in script comments and operator memory,
which is exactly how the M2.17.5.6 incident happened (see next section).

## The incident this runbook exists because of

`docs/M2_12_HOSTINGER_CUTOVER_RUNBOOK.md` recorded a checklist bullet -
"`VITE_DATA_BACKEND=laravel` is the only Production backend" - and its build
step said to run `npm ci && npm run build`. Nothing ever enforced the first
statement against the second: `npm run build` alone never set
`VITE_DATA_BACKEND`, no `.env.production` shipped in the repo, and
`src/services/dataBackend.js` silently defaulted a missing value to
`'supabase'`. `VITE_*` values are baked into the bundle at Vite build time,
not read at runtime, so this was never visible from Hostinger's `.env` or
from Laravel's side at all. Every retained Production release was built this
way: the deployed frontend called Supabase's `auth-login` Edge Function for
every login, never Laravel's `/api/v1/auth/login`, while every Laravel-side
signal (user rows, active status, password hashes) looked completely normal
because Laravel was never in the login request path. Root-caused and fixed in
M2.17.5.6.

**The rule:** a checklist bullet is not a control. A Production build
contract must be an executable gate that fails the release, not a sentence a
human has to remember while running an ad-hoc command.

## Release sequence

Run in this order. A gate that fails stops the release - do not skip ahead.

1. **Backup gate.** On the Production host, from the *current* release
   directory: `scripts/deployment/production-backup.sh <milestone-slug>
   backend <public_html-dir> <backups-root>`. Refuse to proceed on anything
   but `BACKUP_GATE=PASS`. See BACKUP_INTEGRITY_RUNBOOK.md.

2. **Frontend build (enforced).** From the approved commit:
   `scripts/deployment/build-frontend.sh [dist-dir]`. This exports
   `VITE_DATA_BACKEND=laravel` and `VITE_API_BASE_URL=/api/v1` itself - it
   does not rely on the operator's shell already having them - runs
   `npm run build`, and stamps `dist/build-manifest.json` recording what was
   actually built.

3. **Frontend backend verification gate.**
   `scripts/deployment/verify-frontend-backend.sh <dist-dir>` must print
   `BACKEND_VERIFIED=laravel` before the artifact is packaged. It reads
   `build-manifest.json`, not the operator's shell environment and not a
   grep for the string "supabase" in the bundle (the Supabase adapter
   legitimately remains present as a split/dead chunk for the DEV/staging
   behavioral oracle - its presence proves nothing). A missing manifest
   fails closed: it means the artifact was not built via step 2.

4. **Backend build.** `composer install --no-dev --classmap-authoritative`
   in `backend/` if remote Composer is unavailable.

5. **Release identity.** Before `php artisan config:cache` on the release:
   `scripts/deployment/set-release-identity.sh <env-file> <exact-40-hex-sha>`,
   then `scripts/deployment/verify-release-identity.sh <release-backend-dir>
   <expected-sha>` against the CLI-resolved config.

6. **Shared session storage.**
   `scripts/deployment/link-shared-storage.sh <release_dir> <shared_dir>` so
   file-based sessions survive the symlink swap instead of every open tab
   getting a dropped login/419 the instant `current` repoints.

7. **Sync into public_html.**
   `scripts/deployment/sync-public-html.sh <release_dist_dir>
   <public_html_dir>` - copies the verified `dist/` output in place without
   touching `.htaccess`, `.a3-active`, or `index.php`, and without replacing
   the `public_html` directory's inode.

8. **Atomic `current` symlink swap** to the new release directory.

9. **OPcache reset (one-shot, over HTTP).** Upload
   `scripts/deployment/reset-opcache.php` to `public_html` under a random
   filename, `curl` it once, confirm `OPCACHE_RESET_OK`, then delete it
   immediately - LiteSpeed's web PHP is a separate OPcache instance from the
   CLI binary used for the steps above, and `opcache.revalidate_path` is Off
   and not overridable on this host, so a bare symlink repoint alone leaves
   already-warmed web workers serving the previous release.

10. **Post-swap verification.** `GET /api/v1/version` matches the exact
    deployed SHA; a real browser login (Incognito) resolves through Laravel,
    not Supabase, before declaring the release accepted.

## Why frontend backend verification is its own gate, not folded into build

`build-frontend.sh` sets the right environment and would normally be
sufficient on its own. It is deliberately paired with a second,
independent script that inspects the *artifact* rather than trusting the
build step's own environment, because trusting the build step's environment
is exactly the failure mode this incident was: a step that was supposed to
set `VITE_DATA_BACKEND` and silently didn't. Anyone can hand
`verify-frontend-backend.sh` a `dist/` directory from any source - a CI
artifact, a hand-built one, an old release being re-verified - and it will
not pass without proof, recorded at build time, that the artifact is
Laravel-backed with the correct Production API base URL.
