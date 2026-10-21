# M2.12F Write-plan review

Status: **READY — strict offline review complete; no write executed.**

## Capture identity

- NEW_SUPABASE_CAPTURE_SHA256_VERIFIED=94bfe30b539fb490b59e349e2d271ba594de882863f2f17dc34351482786bc5e
- PRODUCTION_MARIADB_CAPTURE_SHA256_VERIFIED=6c79b0be60bc62e622f348c660a29aaf9191062b20793ada304222fd886e5e16
- CAPTURE_IDENTITY_VERIFICATION=PASS
- The invalid 66-character display was a rendering/concatenation defect. The reconciliation input path, embedded digest, script expectation, and recomputed private-capture digest all identify the intended capture.
- `docs/evidence/m2-12f-new-supabase-capture.json` is an earlier public evidence capture and is not the input named by the reconciliation artifact.

## Proposed writes

### 1. counters: CG-TUP-A3-01:total_impressions:2026-08-26T11:15:00+00:00:1437283

- Proposed operation: CREATE historical effective counter fact (not executed)
- Source value/effective time: 1437283 at 2026-08-26T11:15:00+00:00
- Production target/state: CG-TUP-A3-01 / total_impressions; ABSENT; exact machine/type/value/effective-time fact is not present
- Not represented: No Production counter has this semantic machine, type, value, and effective date/time; the exact value is absent.
- Not legacy duplicate: The fact is a unique manual source row, and no legacy-import Production row has its value or source semantic identity.
- Not DEV/test: It belongs to the canonical Tuparev machine, is effective/manual, contains no test marker, and is inside the accepted operational chronology.
- Canonical validity: The machine and monotonic counter type resolve semantically; the value lies strictly between surrounding effective Production values.
- If applied: With an explicit atomic predecessor/successor relink, restores the missing historical reading while preserving total click continuity; CREATE alone is unsafe.
- If skipped: Production remains two facts short of source history, while its existing aggregate counter progression remains internally consistent.
- Confidence: HIGH_VALID_FACT_HIGH_UNSAFE_CREATE_ONLY
- Recommendation: **DEFER**

### 2. counters: CG-TUP-A3-01:total_impressions:2026-08-27T15:08:00+00:00:1438992

- Proposed operation: CREATE historical effective counter fact (not executed)
- Source value/effective time: 1438992 at 2026-08-27T15:08:00+00:00
- Production target/state: CG-TUP-A3-01 / total_impressions; ABSENT; exact machine/type/value/effective-time fact is not present
- Not represented: No Production counter has this semantic machine, type, value, and effective date/time; the exact value is absent.
- Not legacy duplicate: The fact is a unique manual source row, and no legacy-import Production row has its value or source semantic identity.
- Not DEV/test: It belongs to the canonical Tuparev machine, is effective/manual, contains no test marker, and is inside the accepted operational chronology.
- Canonical validity: The machine and monotonic counter type resolve semantically; the value lies strictly between surrounding effective Production values.
- If applied: With an explicit atomic predecessor/successor relink, restores the missing historical reading while preserving total click continuity; CREATE alone is unsafe.
- If skipped: Production remains two facts short of source history, while its existing aggregate counter progression remains internally consistent.
- Confidence: HIGH_VALID_FACT_HIGH_UNSAFE_CREATE_ONLY
- Recommendation: **DEFER**

### Selling-price finding

- Source owner: public.machine_selling_prices / machine selling-price domain
- Identity/value/effective time: CG-TUP-A3-01 / selling price; 1000 IDR per click; 2026-08-28T12:55:00+00:00
- State: voided; void_reason="test set selling price"
- Production: Production machine_selling_prices is empty; no equivalent posted or voided value exists elsewhere in the captured machine-cost fact tables.
- Runtime: Laravel reads this table for selling-price history, but the current Laravel period summary does not apply it to current price/revenue/contribution calculations. A voided row would only add test history, not runtime parity.
- Recommendation: **REJECT**. The invalid row was removed from the write plan.

## Component field-level review

| Catalog key | Supabase | Production | Runtime behavior | Recommendation |
|---|---|---|---|---|
| TONER_C | consumption_based | counter_based | Changes the catalog default and rendered tracking classification from counter-based to toner yield/consumption-based. | UPDATE_TO_CANONICAL_SOURCE_VALUE |
| TONER_K | consumption_based | counter_based | Changes the catalog default and rendered tracking classification from counter-based to toner yield/consumption-based. | UPDATE_TO_CANONICAL_SOURCE_VALUE |
| TONER_M | consumption_based | counter_based | Changes the catalog default and rendered tracking classification from counter-based to toner yield/consumption-based. | UPDATE_TO_CANONICAL_SOURCE_VALUE |
| TONER_Y | consumption_based | counter_based | Changes the catalog default and rendered tracking classification from counter-based to toner yield/consumption-based. | UPDATE_TO_CANONICAL_SOURCE_VALUE |

Profile-slot differences by field: baseline_expected_clicks=28, display_order=15, tracking_method=4. UUID/profile-wrapper, timestamp precision, absent Production adaptive fields, and null/default representations are structural/representational and are not row-identity mismatches.

Machine-component differences by field: baseline_expected_clicks=28, source_type=28, display_order=15, tracking_method=4. Thresholds, status, active state, slot/component identity, and semantic assignment count match. Source `model_profile` maps to Production `inherited`; current `configured` blocks inherited-row lifecycle management paths.

- The baseline gaps block the frontend initialization capability and suppress expected-life display. Tracking and order differences change toner classification and deterministic UI ordering. These are canonical runtime-affecting fields.
- Existing UUIDs are preserved. No catalog/profile/slot/assignment/lifecycle CREATE is proposed. The 47 closed lifecycles and 28 ambiguous incomplete reference lifecycle states remain untouched.

### component_catalogs (4 UPDATE-only candidates)

- `TONER_C`: `{"tracking_method":"counter_based"}` → `{"tracking_method":"consumption_based"}`
- `TONER_K`: `{"tracking_method":"counter_based"}` → `{"tracking_method":"consumption_based"}`
- `TONER_M`: `{"tracking_method":"counter_based"}` → `{"tracking_method":"consumption_based"}`
- `TONER_Y`: `{"tracking_method":"counter_based"}` → `{"tracking_method":"consumption_based"}`

### model_profile_slots (28 UPDATE-only candidates)

- `CHARGING_CORONA_C:CHARGING_CORONA_C`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":1}` → `{"tracking_method":"counter_based","baseline_expected_clicks":40000,"display_order":1}`
- `CHARGING_CORONA_M:CHARGING_CORONA_M`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":3}` → `{"tracking_method":"counter_based","baseline_expected_clicks":40000,"display_order":2}`
- `CHARGING_CORONA_Y:CHARGING_CORONA_Y`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":4}` → `{"tracking_method":"counter_based","baseline_expected_clicks":40000,"display_order":3}`
- `CHARGING_CORONA_K:CHARGING_CORONA_K`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":2}` → `{"tracking_method":"counter_based","baseline_expected_clicks":40000,"display_order":4}`
- `CLEANING_BLADE:CLEANING_BLADE`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":5}` → `{"tracking_method":"counter_based","baseline_expected_clicks":100000,"display_order":5}`
- `CLEANING_UNIT:CLEANING_UNIT`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":6}` → `{"tracking_method":"counter_based","baseline_expected_clicks":200000,"display_order":6}`
- `DEVELOPER_C:DEVELOPER_C`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":7}` → `{"tracking_method":"counter_based","baseline_expected_clicks":100000,"display_order":7}`
- `DEVELOPER_M:DEVELOPER_M`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":9}` → `{"tracking_method":"counter_based","baseline_expected_clicks":100000,"display_order":8}`
- `DEVELOPER_Y:DEVELOPER_Y`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":10}` → `{"tracking_method":"counter_based","baseline_expected_clicks":100000,"display_order":9}`
- `DEVELOPER_K:DEVELOPER_K`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":8}` → `{"tracking_method":"counter_based","baseline_expected_clicks":100000,"display_order":10}`
- `DEVELOPING_UNIT_C:DEVELOPING_UNIT_C`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":11}` → `{"tracking_method":"counter_based","baseline_expected_clicks":200000,"display_order":11}`
- `DEVELOPING_UNIT_M:DEVELOPING_UNIT_M`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":13}` → `{"tracking_method":"counter_based","baseline_expected_clicks":200000,"display_order":12}`
- `DEVELOPING_UNIT_Y:DEVELOPING_UNIT_Y`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":14}` → `{"tracking_method":"counter_based","baseline_expected_clicks":200000,"display_order":13}`
- `DEVELOPING_UNIT_K:DEVELOPING_UNIT_K`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":12}` → `{"tracking_method":"counter_based","baseline_expected_clicks":200000,"display_order":14}`
- `DRUM_C:DRUM_C`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":15}` → `{"tracking_method":"counter_based","baseline_expected_clicks":40000,"display_order":15}`
- `DRUM_M:DRUM_M`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":17}` → `{"tracking_method":"counter_based","baseline_expected_clicks":40000,"display_order":16}`
- `DRUM_Y:DRUM_Y`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":18}` → `{"tracking_method":"counter_based","baseline_expected_clicks":40000,"display_order":17}`
- `DRUM_K:DRUM_K`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":16}` → `{"tracking_method":"counter_based","baseline_expected_clicks":40000,"display_order":18}`
- `FUSER_BELT:FUSER_BELT`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":19}` → `{"tracking_method":"counter_based","baseline_expected_clicks":200000,"display_order":19}`
- `GEAR:GEAR`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":20}` → `{"tracking_method":"counter_based","baseline_expected_clicks":150000,"display_order":20}`
- `IBT:IBT`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":21}` → `{"tracking_method":"counter_based","baseline_expected_clicks":200000,"display_order":21}`
- `LASER_UNIT:LASER_UNIT`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":22}` → `{"tracking_method":"counter_based","baseline_expected_clicks":300000,"display_order":22}`
- `ROLL_MESIN:ROLL_MESIN`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":23}` → `{"tracking_method":"counter_based","baseline_expected_clicks":150000,"display_order":23}`
- `SENSOR:SENSOR`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":24}` → `{"tracking_method":"counter_based","baseline_expected_clicks":250000,"display_order":24}`
- `TONER_C:TONER_C`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":25}` → `{"tracking_method":"consumption_based","baseline_expected_clicks":14000,"display_order":25}`
- `TONER_M:TONER_M`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":27}` → `{"tracking_method":"consumption_based","baseline_expected_clicks":14000,"display_order":26}`
- `TONER_Y:TONER_Y`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":28}` → `{"tracking_method":"consumption_based","baseline_expected_clicks":14000,"display_order":27}`
- `TONER_K:TONER_K`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":26}` → `{"tracking_method":"consumption_based","baseline_expected_clicks":14000,"display_order":28}`

### machine_components (28 UPDATE-only candidates)

- `CHARGING_CORONA_C:CHARGING_CORONA_C`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":1,"source_type":"configured"}` → `{"tracking_method":"counter_based","baseline_expected_clicks":40000,"display_order":1,"source_type":"inherited"}`
- `CHARGING_CORONA_M:CHARGING_CORONA_M`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":3,"source_type":"configured"}` → `{"tracking_method":"counter_based","baseline_expected_clicks":40000,"display_order":2,"source_type":"inherited"}`
- `CHARGING_CORONA_Y:CHARGING_CORONA_Y`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":4,"source_type":"configured"}` → `{"tracking_method":"counter_based","baseline_expected_clicks":40000,"display_order":3,"source_type":"inherited"}`
- `CHARGING_CORONA_K:CHARGING_CORONA_K`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":2,"source_type":"configured"}` → `{"tracking_method":"counter_based","baseline_expected_clicks":40000,"display_order":4,"source_type":"inherited"}`
- `CLEANING_BLADE:CLEANING_BLADE`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":5,"source_type":"configured"}` → `{"tracking_method":"counter_based","baseline_expected_clicks":100000,"display_order":5,"source_type":"inherited"}`
- `CLEANING_UNIT:CLEANING_UNIT`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":6,"source_type":"configured"}` → `{"tracking_method":"counter_based","baseline_expected_clicks":200000,"display_order":6,"source_type":"inherited"}`
- `DEVELOPER_C:DEVELOPER_C`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":7,"source_type":"configured"}` → `{"tracking_method":"counter_based","baseline_expected_clicks":100000,"display_order":7,"source_type":"inherited"}`
- `DEVELOPER_M:DEVELOPER_M`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":9,"source_type":"configured"}` → `{"tracking_method":"counter_based","baseline_expected_clicks":100000,"display_order":8,"source_type":"inherited"}`
- `DEVELOPER_Y:DEVELOPER_Y`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":10,"source_type":"configured"}` → `{"tracking_method":"counter_based","baseline_expected_clicks":100000,"display_order":9,"source_type":"inherited"}`
- `DEVELOPER_K:DEVELOPER_K`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":8,"source_type":"configured"}` → `{"tracking_method":"counter_based","baseline_expected_clicks":100000,"display_order":10,"source_type":"inherited"}`
- `DEVELOPING_UNIT_C:DEVELOPING_UNIT_C`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":11,"source_type":"configured"}` → `{"tracking_method":"counter_based","baseline_expected_clicks":200000,"display_order":11,"source_type":"inherited"}`
- `DEVELOPING_UNIT_M:DEVELOPING_UNIT_M`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":13,"source_type":"configured"}` → `{"tracking_method":"counter_based","baseline_expected_clicks":200000,"display_order":12,"source_type":"inherited"}`
- `DEVELOPING_UNIT_Y:DEVELOPING_UNIT_Y`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":14,"source_type":"configured"}` → `{"tracking_method":"counter_based","baseline_expected_clicks":200000,"display_order":13,"source_type":"inherited"}`
- `DEVELOPING_UNIT_K:DEVELOPING_UNIT_K`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":12,"source_type":"configured"}` → `{"tracking_method":"counter_based","baseline_expected_clicks":200000,"display_order":14,"source_type":"inherited"}`
- `DRUM_C:DRUM_C`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":15,"source_type":"configured"}` → `{"tracking_method":"counter_based","baseline_expected_clicks":40000,"display_order":15,"source_type":"inherited"}`
- `DRUM_M:DRUM_M`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":17,"source_type":"configured"}` → `{"tracking_method":"counter_based","baseline_expected_clicks":40000,"display_order":16,"source_type":"inherited"}`
- `DRUM_Y:DRUM_Y`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":18,"source_type":"configured"}` → `{"tracking_method":"counter_based","baseline_expected_clicks":40000,"display_order":17,"source_type":"inherited"}`
- `DRUM_K:DRUM_K`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":16,"source_type":"configured"}` → `{"tracking_method":"counter_based","baseline_expected_clicks":40000,"display_order":18,"source_type":"inherited"}`
- `FUSER_BELT:FUSER_BELT`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":19,"source_type":"configured"}` → `{"tracking_method":"counter_based","baseline_expected_clicks":200000,"display_order":19,"source_type":"inherited"}`
- `GEAR:GEAR`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":20,"source_type":"configured"}` → `{"tracking_method":"counter_based","baseline_expected_clicks":150000,"display_order":20,"source_type":"inherited"}`
- `IBT:IBT`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":21,"source_type":"configured"}` → `{"tracking_method":"counter_based","baseline_expected_clicks":200000,"display_order":21,"source_type":"inherited"}`
- `LASER_UNIT:LASER_UNIT`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":22,"source_type":"configured"}` → `{"tracking_method":"counter_based","baseline_expected_clicks":300000,"display_order":22,"source_type":"inherited"}`
- `ROLL_MESIN:ROLL_MESIN`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":23,"source_type":"configured"}` → `{"tracking_method":"counter_based","baseline_expected_clicks":150000,"display_order":23,"source_type":"inherited"}`
- `SENSOR:SENSOR`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":24,"source_type":"configured"}` → `{"tracking_method":"counter_based","baseline_expected_clicks":250000,"display_order":24,"source_type":"inherited"}`
- `TONER_C:TONER_C`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":25,"source_type":"configured"}` → `{"tracking_method":"consumption_based","baseline_expected_clicks":14000,"display_order":25,"source_type":"inherited"}`
- `TONER_M:TONER_M`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":27,"source_type":"configured"}` → `{"tracking_method":"consumption_based","baseline_expected_clicks":14000,"display_order":26,"source_type":"inherited"}`
- `TONER_Y:TONER_Y`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":28,"source_type":"configured"}` → `{"tracking_method":"consumption_based","baseline_expected_clicks":14000,"display_order":27,"source_type":"inherited"}`
- `TONER_K:TONER_K`: `{"tracking_method":"counter_based","baseline_expected_clicks":null,"display_order":26,"source_type":"configured"}` → `{"tracking_method":"consumption_based","baseline_expected_clicks":14000,"display_order":28,"source_type":"inherited"}`

## Approval-count correction

The original 200 is a classification-review total, not a write count. After rejecting the test selling price: actual write candidates=2; deferred/excluded=62; ambiguous=76; audit-only=60. These categories sum to 200.

- Deferred/excluded: 61 Graha/Xerox-scope canonical/operational records plus the rejected voided test selling-price fact.
- Ambiguous: lifecycles=28, incidents=2, inventory items=18, machine identity/config=1, people=13, person-branch assignments=14.
- Audit-only: component catalogs=4, profile slots=28, machine components=28. These 60 UPDATE candidates are separate and are not in the approved write plan.

## Final checkpoint

CHECKPOINT_M2_12F_WRITE_PLAN_REVIEW=READY
CAPTURE_IDENTITY_VERIFICATION=PASS
NEW_SUPABASE_CAPTURE_SHA256_VERIFIED=94bfe30b539fb490b59e349e2d271ba594de882863f2f17dc34351482786bc5e
PRODUCTION_MARIADB_CAPTURE_SHA256_VERIFIED=6c79b0be60bc62e622f348c660a29aaf9191062b20793ada304222fd886e5e16
COUNTER_WRITE_1_RECOMMENDATION=DEFER
COUNTER_WRITE_2_RECOMMENDATION=DEFER
SELLING_PRICE_WRITE_RECOMMENDATION=REJECT
COMPONENT_CONFIG_PARITY_AUDIT=DIFF_FOUND
COMPONENT_CONFIG_RECONCILIATION_REQUIRED=YES
COMPONENT_CATALOG_RUNTIME_DIFF_COUNT=4
PROFILE_SLOT_RUNTIME_DIFF_COUNT=28
MACHINE_COMPONENT_RUNTIME_DIFF_COUNT=28
COMPONENT_UPDATE_CANDIDATE_COUNT=60
APPROVAL_REQUIRED_ACTUAL_WRITE_COUNT=2
APPROVAL_REQUIRED_DEFERRED_COUNT=62
APPROVAL_REQUIRED_AMBIGUOUS_COUNT=76
APPROVAL_REQUIRED_AUDIT_ONLY_COUNT=60
FINAL_PROPOSED_WRITE_COUNT=2
NETWORK_ACCESS=NO
DATABASE_ACCESS=NO
PRODUCTION_MUTATION=NO
PRODUCTION_DATABASE_MUTATION=NO
PUBLIC_HTML_MUTATION=NO
PRODUCTION_DEPLOYMENT=NO

STOP
