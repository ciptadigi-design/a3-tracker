# M2.12F Component configuration patch review

Status: **READY — final offline operator-reviewable plan; not executed.**

This review uses only the accepted immutable captures. It proposes 126 compare-and-set field mutations across 60 existing Production rows. The field count is intentionally larger than the row count because each field has its own classification, rollback value, and fail-closed precondition.

## Capture and scope

- NEW_SUPABASE_CAPTURE_SHA256=94bfe30b539fb490b59e349e2d271ba594de882863f2f17dc34351482786bc5e
- PRODUCTION_MARIADB_CAPTURE_SHA256=6c79b0be60bc62e622f348c660a29aaf9191062b20793ada304222fd886e5e16
- CAPTURE_IDENTITY_VERIFICATION=PASS
- Counter backfill is deferred. The voided test selling price is rejected. Neither appears in either plan.
- No IDs, timestamps, lifecycle rows, counters, inventory, stock, branch/machine identity, or reference/DEV UUIDs are proposed for update.

## Component catalog review

| Semantic identity | Production ID | Production value | Reference value | Differing field | Runtime consumers | Behavioral impact | Action | Reason |
|---|---|---:|---:|---|---|---|---|---|
| TONER_C (Konica Minolta C1070 toner) | c42fe878-ab48-5188-9d28-16e33cad0e46 | counter_based | consumption_based | tracking_method | Catalog defaults; configuration UI; replacement/yield labels and reporting | Toner would otherwise be presented and inherited as click-life counter tracking instead of consumable yield tracking | UPDATE · RUNTIME_REQUIRED | Canonical enum value; no unsupported tracking mode is introduced |
| TONER_M (Konica Minolta C1070 toner) | a4c7ff16-d0cd-5721-b250-5b71eba5781e | counter_based | consumption_based | tracking_method | Catalog defaults; configuration UI; replacement/yield labels and reporting | Toner would otherwise be presented and inherited as click-life counter tracking instead of consumable yield tracking | UPDATE · RUNTIME_REQUIRED | Canonical enum value; no unsupported tracking mode is introduced |
| TONER_Y (Konica Minolta C1070 toner) | a642ca29-0cc1-5e84-965e-c68c9b920521 | counter_based | consumption_based | tracking_method | Catalog defaults; configuration UI; replacement/yield labels and reporting | Toner would otherwise be presented and inherited as click-life counter tracking instead of consumable yield tracking | UPDATE · RUNTIME_REQUIRED | Canonical enum value; no unsupported tracking mode is introduced |
| TONER_K (Konica Minolta C1070 toner) | 9bb09fd5-ca96-5a49-ad1d-8b7b6204e38a | counter_based | consumption_based | tracking_method | Catalog defaults; configuration UI; replacement/yield labels and reporting | Toner would otherwise be presented and inherited as click-life counter tracking instead of consumable yield tracking | UPDATE · RUNTIME_REQUIRED | Canonical enum value; no unsupported tracking mode is introduced |

Allowed canonical application vocabulary is `counter_based`, `consumption_based`, `inspection_based`. This patch proposes only `consumption_based` for toner; it invents no tracking mode.

## Profile-slot field review (28 rows)

- baseline_expected_clicks: 28 differing row(s): RUNTIME_REQUIRED. Affects lifecycle initialization inputs, replacement expectations, reporting, and health projection; it does not change slot identity or component assignment.
- tracking_method: 4 differing row(s): RUNTIME_REQUIRED. Affects tracking, replacement/yield semantics, reporting, and health presentation; it does not change identity.
- display_order: 15 differing row(s): CANONICAL_METADATA. Affects deterministic display order/UI only. It is intentional because the canonical C1070 seed orders each color family C/M/Y/K and both API/UI consumers sort this field.
- Semantic profile/slot/component identity and active state already match. Reference UUIDs, wrapper IDs, timestamps, adaptive-only schema fields, and harmless null/default serialization are representational and excluded.

## Machine-component field review (28 rows)

- baseline_expected_clicks: 28 differing row(s): RUNTIME_REQUIRED. Restores initialization and expected-life/health fallback without altering any lifecycle snapshot.
- tracking_method: 4 differing row(s): RUNTIME_REQUIRED. Changes toner replacement/refill labels, default reason, yield reporting, and projection.
- source_type: 28 differing row(s): RUNTIME_REQUIRED. `inherited` is Laravel's runtime value for these already profile-linked rows; `configured` is a legacy-import vocabulary leak.
- display_order: 15 differing row(s): CANONICAL_METADATA. Aligns deterministic UI order with the profile and does not affect lifecycle state.
- Every operation preserves the Production machine UUID, machine-component UUID, profile-slot UUID/link, slot/component assignment, account/branch identity, status, active key, thresholds, notes, and all lifecycle children.
- The 47 historical lifecycles remain byte-for-byte outside patch scope; no active lifecycle is created, no lifecycle state is reset, and counters/inventory/stock are absent from the plan.

## Dependency and apply contract

1. Catalog tracking defaults (upstream catalog semantics).
2. Profile-slot canonical fields (authoritative model configuration).
3. Machine-component fields (existing physical projections).

There is no ordering uniqueness constraint, so C/M/Y/K display-order swaps are safe as separate updates. For each field, the future operator must first read the row: already proposed means idempotent no-op; captured old value permits `UPDATE ... WHERE id = ? AND field <=> expected_old`; anything else fails closed. Each executed statement must affect exactly one intended row. Apply must run transactionally with the invariant checks below before commit.

Rollback reverses machine components, then profile slots, then catalogs. It uses the exact captured old value and the same compare-and-set/fail-closed rule.

## Expected invariants

| Domain | Before | Expected after |
|---|---:|---:|
| component catalogs | 28 | 28 |
| model profiles | 1 | 1 |
| profile slots | 28 | 28 |
| machine components | 28 | 28 |
| lifecycles | 47 | 47 |
| active lifecycles | 0 | 0 |
| closed lifecycles | 47 | 47 |
| latest counter | 1,441,597 | 1,441,597 |

## Final checkpoint

CHECKPOINT_M2_12F_COMPONENT_CONFIG_PATCH=READY

COMPONENT_CATALOG_REVIEWED=4
PROFILE_SLOT_REVIEWED=28
MACHINE_COMPONENT_REVIEWED=28

RUNTIME_REQUIRED_UPDATE_COUNT=96
CANONICAL_METADATA_UPDATE_COUNT=30
REPRESENTATIONAL_ONLY_EXCLUDED_COUNT=0
AMBIGUOUS_DEFERRED_COUNT=0

The two exclusion counts apply to the 126 actual candidate field differences. Reference UUIDs, timestamps, schema wrappers, and equivalent null/default serialization were screened out before candidate counting and remain explicitly excluded by policy.

FINAL_COMPONENT_UPDATE_COUNT=126
FINAL_COMPONENT_DISTINCT_ROW_UPDATE_COUNT=60

COMPONENT_CATALOG_UPDATE_COUNT=4
PROFILE_SLOT_UPDATE_COUNT=47
MACHINE_COMPONENT_UPDATE_COUNT=75

COMPONENT_CATALOG_DISTINCT_ROW_UPDATE_COUNT=4
PROFILE_SLOT_DISTINCT_ROW_UPDATE_COUNT=28
MACHINE_COMPONENT_DISTINCT_ROW_UPDATE_COUNT=28

COMPONENT_CATALOG_CREATE_COUNT=0
PROFILE_SLOT_CREATE_COUNT=0
MACHINE_COMPONENT_CREATE_COUNT=0
LIFECYCLE_CREATE_COUNT=0

PRODUCTION_ROW_COUNT_CHANGE_EXPECTED=0

COMPONENT_PATCH_UPDATE_ONLY_TEST=PASS
NO_COMPONENT_CREATE_TEST=PASS
NO_LIFECYCLE_MUTATION_TEST=PASS
NO_DEV_UUID_WRITE_TEST=PASS
COMPARE_AND_SET_PRECONDITION_TEST=PASS
UNEXPECTED_PRODUCTION_STATE_FAIL_CLOSED_TEST=PASS
COMPONENT_PATCH_ROLLBACK_PLAN_TEST=PASS
COMPONENT_ROW_COUNTS_UNCHANGED_TEST=PASS

COMPONENT_PATCH_ARTIFACT=
docs/evidence/m2-12f-component-config-patch.json

COMPONENT_ROLLBACK_ARTIFACT=
docs/evidence/m2-12f-component-config-rollback.json

COUNTER_BACKFILL=DEFERRED
SELLING_PRICE_WRITE=REJECTED

NETWORK_ACCESS=NO
DATABASE_ACCESS=NO
PRODUCTION_MUTATION=NO
PRODUCTION_DATABASE_MUTATION=NO
PUBLIC_HTML_MUTATION=NO
PRODUCTION_DEPLOYMENT=NO

STOP
