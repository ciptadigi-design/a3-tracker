# M2.12F Component configuration Production apply

Status: **READY — approved component configuration patch committed and validated.**

The approved 126 field-level compare-and-set updates were applied to exactly 60 existing Production rows. All 126 preconditions matched the captured old values; no field was already at target and no unexpected state was found. The transaction committed with 126 affected field updates and zero compare-and-set failures.

No component, lifecycle, counter, inventory, incident, selling-price, or auth row was created or otherwise mutated outside the approved fields. All database table row counts and fingerprints of prohibited tables/immutable target columns were unchanged. `public_html` retained the same read-only fingerprint and no application code was deployed.

## Backups

- Pre-patch: `/home/u777904340/m2-12f-backups/component-config-20260903T212931Z/production-pre-component-config.sql.gz`
  - UTC: `2026-09-03T21:29:33Z`
  - Size: `65,899` bytes
  - SHA256: `e189d0c7de6e61d9acda7f1f41e3c3a8510cfc557b650ea2bac590ee38a6ab4f`
- Post-patch: `/home/u777904340/m2-12f-backups/component-config-20260903T212931Z/production-post-component-config.sql.gz`
  - UTC: `2026-09-03T21:29:33Z`
  - Size: `65,998` bytes
  - SHA256: `3c03569c036fd37d4fd9e736b13ab2ee1c5c7e4b47b9d832110ecf77384229a1`

Both backups are private, non-empty, SHA256-identified gzip files that passed integrity checks. Neither is under `public_html`.

## Runtime-shape validation

The currently deployed Laravel backend was bootstrapped in a read-only transaction after the commit. Its model projection loaded all 28 configured C1070 machine components and their profile-slot relationships, preserved 47 closed lifecycle rows and zero active lifecycles, returned latest Total Impressions `1,441,597`, and retained machine UUID `708e199e-7f77-4219-b278-37d0b94821d4` / code `CG-TUP-A3-01`.

## Final report

```text
CHECKPOINT_M2_12F_COMPONENT_CONFIG_PRODUCTION_APPLY=READY

PATCH_ARTIFACT_VALIDATION=PASS
ROLLBACK_ARTIFACT_VALIDATION=PASS

PRE_PATCH_DATABASE_BACKUP=PASS
PRE_PATCH_DATABASE_BACKUP_SHA256=e189d0c7de6e61d9acda7f1f41e3c3a8510cfc557b650ea2bac590ee38a6ab4f

PATCH_PRECONDITION_AUDIT=PASS

PRECONDITION_MATCH_COUNT=126
PRECONDITION_ALREADY_TARGET_COUNT=0
PRECONDITION_UNEXPECTED_COUNT=0

PATCH_TRANSACTION=COMMITTED
PATCH_APPLIED_FIELD_COUNT=126
PATCH_ALREADY_TARGET_COUNT=0
PATCH_FAILED_COMPARE_AND_SET_COUNT=0

COMPONENT_CATALOG_COUNT=28
MODEL_PROFILE_COUNT=1
PROFILE_SLOT_COUNT=28
MACHINE_COMPONENT_COUNT=28

LIFECYCLE_COUNT=47
ACTIVE_LIFECYCLE_COUNT=0
CLOSED_LIFECYCLE_COUNT=47

LATEST_COUNTER=1441597

POST_PATCH_TARGET_VALUE_MATCH_COUNT=126
PRODUCTION_ROW_COUNT_CHANGE=0

ROLLBACK_PLAN_READY=YES

POST_PATCH_DATABASE_BACKUP=PASS
POST_PATCH_DATABASE_BACKUP_SHA256=3c03569c036fd37d4fd9e736b13ab2ee1c5c7e4b47b9d832110ecf77384229a1

COUNTER_BACKFILL=DEFERRED
SELLING_PRICE_WRITE=REJECTED

PUBLIC_HTML_MUTATION=NO
APPLICATION_DEPLOYMENT=NO

LEGACY_FREEZE=REMAINS_ACTIVE
```

STOP
