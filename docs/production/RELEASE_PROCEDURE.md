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
This is the canonical current procedure, not a partial checklist - every step
below is either an executable script or an explicit manual command; nothing
here should ever be "remembered" instead of run.

1. **Exact source SHA.** Decide the exact 40-hex commit SHA being released.
   Everything below is keyed off this one value - never a branch name, never
   "latest".

2. **Local canonical frontend build.** From that exact commit, on the
   operator's machine (Hostinger has no node/npm at all - confirmed live on
   the host during M2.17.5.6 - only PHP/Composer):
   `scripts/deployment/build-frontend.sh [dist-dir]`. This exports
   `VITE_DATA_BACKEND=laravel` and `VITE_API_BASE_URL=/api/v1` itself - it
   does not rely on the operator's shell already having them - runs
   `npm run build`, and stamps `dist/build-manifest.json` recording what was
   actually built.

3. **Frontend backend verification gate.**
   `scripts/deployment/verify-frontend-backend.sh <dist-dir>` must print
   `BACKEND_VERIFIED=laravel` before the artifact goes anywhere near
   Production. It reads `build-manifest.json`, not the operator's shell
   environment and not a grep for the string "supabase" in the bundle (the
   Supabase adapter legitimately remains present as a split/dead chunk for
   the DEV/staging behavioral oracle - its presence proves nothing). A
   missing manifest fails closed: it means the artifact was not built via
   step 2.

4. **CI exact-SHA green.** Both `Database CI` and `Laravel MySQL Target CI`
   must report `success` for this exact commit SHA before it is eligible for
   Production - never a nearby commit, never "CI was green yesterday".

5. **Production backup gate.** On the Production host, from the *current*
   release directory: `scripts/deployment/production-backup.sh
   <milestone-slug> backend <public_html-dir> <backups-root>`. Refuse to
   proceed on anything but `BACKUP_GATE=PASS`. See
   [BACKUP_INTEGRITY_RUNBOOK.md](BACKUP_INTEGRITY_RUNBOOK.md).

6. **Fresh release creation.** `git clone` the repository into a new
   `releases/<sha>/` directory and `git checkout <sha>` inside it. Never
   `cp -al` or hard-link a release from another one - each release is an
   independent, fully-checked-out tree. Copy the already-verified `dist/`
   from step 3 into `releases/<sha>/dist/` (frontend is never built on the
   Hostinger host itself).

7. **Composer install.** `composer install --no-dev
   --classmap-authoritative --no-interaction --no-progress` inside
   `releases/<sha>/backend`.

7a. **Pinned official-extraction runtime.** The reviewed V2.1 full-manual
    extractor never uses ambient `PATH` or a system-wide Python package. Its
    immutable runtime identity is
    `runtimes/official-knowledge/python311-pypdf-6.14.2-crypto-50.0.1-v1`, outside
    `public_html`. Provision or idempotently verify it with:

    ```bash
    scripts/deployment/provision-official-python-runtime.sh \
      /opt/alt/python311/bin/python3 \
      <app-root>/runtimes/official-knowledge/python311-pypdf-6.14.2-crypto-50.0.1-v1 \
      <release>/backend/runtime/official-knowledge/requirements.txt
    ```

    The requirements manifest contains the reviewed `pypdf==6.14.2` universal
    wheel plus the minimal AES provider closure needed by the verified PDF;
    every dependency and supported Linux wheel is exact-version/hash pinned.
    The provisioner creates a private virtual environment in an adjacent
    temporary directory, verifies Python 3.11.x, pypdf 6.14.2, and
    cryptography 50.0.1, and atomically installs the versioned directory.
    It never changes the Hostinger base interpreter or global site-packages.
    Set the shared environment value
    `MAINTENANCE_OFFICIAL_PYTHON_EXECUTABLE` to the absolute
    `<runtime>/bin/python` path before config caching. Never point it at the
    bare base interpreter, `python3`, an unversioned `latest` link, or any path
    beneath `public_html`.

8. **Release identity.** `scripts/deployment/set-release-identity.sh
   <shared-env-file> <exact-40-hex-sha>`, then `php artisan config:clear &&
   php artisan config:cache` in the release's `backend/`, then
   `scripts/deployment/verify-release-identity.sh <release-backend-dir>
   <expected-sha>` against the CLI-resolved config.

8a. **Official-extraction runtime preflight.** From the release backend run
    `php artisan maintenance:v2-extractor-runtime-preflight`. It must print
    `CONFIGURED_EXECUTABLE_ABSOLUTE=PASS`, Python 3.11.x,
    `PYPDF_VERSION=6.14.2`, `CRYPTOGRAPHY_VERSION=50.0.1`, and
    `OFFICIAL_EXTRACTION_RUNTIME_PREFLIGHT=PASS`. This check performs no
    extraction and writes nothing. A missing/relative/non-executable runtime,
    wrong Python version, missing module, or version drift stops deployment.

9. **Shared `.env` linkage (M2.18.2 / closes H9).**
   `scripts/deployment/link-shared-env.sh <release_dir> <shared_dir>` -
   symlinks `releases/<sha>/backend/.env` to the canonical `shared/.env`.
   Before this script existed, this step was manual and undocumented (the
   H9 finding from the M2.18 audit) and had to be done by hand with
   `ln -sfn` during both the M2.17.5.6 and M2.18.1 deploys. Never prints or
   copies the `.env` contents - only paths.

10. **Shared session storage linkage.**
    `scripts/deployment/link-shared-storage.sh <release_dir> <shared_dir>`
    so file-based sessions survive the symlink swap instead of every open
    tab getting a dropped login/419 the instant `current` repoints.

11. **Release preflight (M2.18.2, closes H2's remaining gap).**
    `scripts/deployment/verify-release.sh <release_dir> <shared_dir>
    <expected_sha>` must print `RELEASE_PREFLIGHT=PASS` before the release
    is allowed anywhere near the `current` symlink. This is the single
    consolidated gate: backend structure, both shared links (steps 9-10),
    the frontend artifact (step 3's result), and release identity (step 8)
    all proven together, not trusted individually. A release missing shared
    environment linkage or built against the wrong frontend backend is
    rejected here, before it can ever become live.

12. **Migration status / preflight.** `php artisan migrate:status` against
    the intended Production database, read-only, before any write.

13. **Migrations, only if required.** `php artisan migrate --force` -
    migrations are forward-only and must be additive/compatible (nullable or
    defaulted columns, no drops/renames of anything already in use) or
    explicitly planned as a breaking change with its own rollout plan.

14. **Sync into public_html, preserving inode/infrastructure.** Canonical
    source: `<release>/dist`, produced by `build-frontend.sh`.

    ```bash
    # NEVER (M2.19.1 blank-page incident):
    sync-public-html.sh "$RELEASE" "$PUBLIC"
    # CORRECT:
    scripts/deployment/sync-public-html.sh "$RELEASE/dist" "$PUBLIC"
    ```

    The script validates the artifact structure, not its directory name. Before
    touching public_html it requires regular `index.html` and
    `build-manifest.json`, an `assets/` directory, a hashed `/assets/` JavaScript
    module entry, and existing local JS/CSS references confined to the artifact.
    It rejects source/dev URLs (`/src/`, `/@vite/client`, localhost, 127.0.0.1),
    traversal/encoded/remote asset URLs, base overrides, inline scripts,
    repository material, nested `dist/`, symlinks/special files, and source
    infrastructure collisions. Both manifest validation and the canonical
    `verify-frontend-backend.sh` gate require Laravel + `/api/v1`.

    Empty/root/equal/nested paths and symlink source/destination directories
    fail closed. Spaces and trailing slashes are supported. The destination
    must already contain regular `.htaccess`, `index.php`, and `.a3-active`;
    their bytes/metadata and the public_html inode/permissions are preserved.
    No directory replacement occurs. Post-sync repeats artifact/backend and
    infrastructure checks; a failure returns non-zero with
    `PARTIAL_SYNC_FAILURE`, never `SYNC_OK`. There is no automatic rollback.

    **INVALID SOURCE → NON-ZERO EXIT → PUBLIC_HTML UNCHANGED.** The permanent
    M2.19.1 fixture regression is in `sync-public-html.test.mjs`; run
    `node --test scripts/deployment/*.test.mjs`. Never test an invalid source
    against live public_html. Keep artifacts immutable during sync and serialize
    deployment operations. Hostinger's private `deploy-tools` installation must
    include `sync-public-html.sh`, `lib/frontend-sync-contract.php`, and
    `verify-frontend-backend.sh` from the same reviewed commit (PHP with DOM).

15. **Atomic `current` symlink swap** to the new release directory.

16. **OPcache reset (one-shot, over HTTP).** Upload
    `scripts/deployment/reset-opcache.php` to `public_html` under a random
    filename, `curl` it once, confirm `OPCACHE_RESET_OK`, then delete it
    immediately - LiteSpeed's web PHP is a separate OPcache instance from
    the CLI binary used for the steps above, and `opcache.revalidate_path`
    is Off and not overridable on this host, so a bare symlink repoint
    alone leaves already-warmed web workers serving the previous release.

17. **Smoke tests.** `GET /` = 200, `GET /login` reachable, `GET
    /api/v1/health` healthy, `GET /sanctum/csrf-cookie` = 204, `GET
    /api/v1/me` unauthenticated = 401.

18. **Exact version verification.** `GET /api/v1/version` matches the exact
    deployed SHA.

19. **Post-deploy acceptance.** A real browser login (Incognito) resolves
    through Laravel, not Supabase, and the authenticated workspace loads,
    before declaring the release accepted.

20. **Release retention (V1.10, optional, AFTER acceptance only).**
    `scripts/deployment/prune-old-releases.sh <releases_root> <current_symlink>
    <keep_count> [--apply]` - never run before step 19 has passed for the
    release just activated, and never as part of activation itself. Defaults
    to dry run (no `--apply`): reports exactly what it would keep/remove
    without touching anything. Always preserves the active release regardless
    of its position in the mtime ordering, fails closed on any path that
    doesn't look like `.../a3-production-app/releases`, and only removes
    directories directly under that root. This exists because the real V1.9
    deployment hit a genuine Hostinger account-level disk quota (separate
    from the physical filesystem) after 56 releases had accumulated with no
    retention mechanism at all; the oldest 46 were pruned by hand with
    explicit operator approval mid-deployment. A reasonable `keep_count` is
    10 - enough for a realistic rollback window (see ROLLBACK_RUNBOOK.md)
    while keeping `releases/` well clear of quota pressure.

## NEVER

- `cp -al` or hard-link a release directory from another one (step 6 - every
  release is an independent, fully-checked-out tree).
- Replace the `public_html` directory itself (only its contents, via
  `sync-public-html.sh` - its inode must never change).
- Casually replace `.htaccess` - it is excluded from every sync on purpose.
- Ad-hoc `mysqldump` - always `production-backup.sh`, which validates the
  dump before ever reporting `BACKUP_GATE=PASS` (see
  [BACKUP_INTEGRITY_RUNBOOK.md](BACKUP_INTEGRITY_RUNBOOK.md) for the incident
  that made this mandatory).
- A Production `npm`/`node` build on Hostinger - the host has no node/npm at
  all, only PHP/Composer (confirmed live during M2.17.5.6). The frontend is
  always built locally or in CI and uploaded as a finished artifact.
- Deploying a frontend artifact without `verify-frontend-backend.sh` (or the
  consolidated `verify-release.sh`) passing first.
- Exposing secrets - no script here ever reads or prints `.env` contents,
  only paths and existence checks.
- Installing `pypdf` globally, adding a system Python directory to Production
  `PATH`, or allowing automatic interpreter discovery/fallback. The extractor
  must use only the configured absolute private interpreter.
- Running a rollback's DB restore casually - see
  [ROLLBACK_RUNBOOK.md](ROLLBACK_RUNBOOK.md). `rollback-release.sh` never
  restores the database or runs a migration; it only repoints `current` and
  re-syncs the frontend, and only after an explicit compatibility
  confirmation when it cannot prove the target release's migrations are a
  strict subset of what's already applied.

Application rollback does not mutate or remove a pinned Python runtime. Before
repointing `current`, verify that the target release's configured extraction
contract is compatible with the selected versioned runtime. The V2.1 runtime
directory is immutable and may be shared by releases that require exactly
Python 3.11.x, pypdf 6.14.2, and cryptography 50.0.1; a future dependency
revision must use a new versioned sibling directory, never modify this one in
place.

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
