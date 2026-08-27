# M2.3B Active Machine Component Lifecycle

## Lifecycle and model profile

A model component profile is editable configuration for a machine model slot. A machine component lifecycle is the machine-scoped operational record for one installation/tracking period. M2.3B permits one open (`unknown` or `active`) lifecycle per physical machine and normalized slot code. `closed` rows are reserved for M2.3C replacement history.

The lifecycle stores installation facts and expected-life snapshots, not mutable current usage. Authenticated clients can read lifecycle data but cannot directly insert, update, or delete rows. Owner/admin initialization uses the controlled `initialize_machine_component_lifecycle` RPC.

## Daily Counter integration

`machine_component_health` selects the latest `effective` Total Impressions reading ordered by observation time, creation time, and ID. It derives:

```text
current_usage = latest_effective_counter - installed_counter
remaining_clicks = effective_expected - current_usage
remaining_percent = remaining_clicks / effective_expected * 100
estimated_replacement_counter = installed_counter + effective_expected
```

Counter corrections supersede/void readings in the existing append-only Daily Counter stream. Because lifecycle health is a view, the corrected latest effective reading changes usage without mutating lifecycle history.

## Expected-life snapshot and adaptive foundation

At initialization, `baseline_expected_clicks_snapshot` and `expected_at_install` are copied from the effective profile. Later profile edits do not rewrite these fields or move the lifecycle replacement target. The operational view may show the current profile baseline next to the install snapshot when they differ.

`adaptive_expected_snapshot` remains null in M2.3B. With no valid completed lifecycle samples, `effective_expected` is `expected_at_install` and the source is `Baseline only`. M2.3B creates no confidence score or fabricated adaptive estimate.

## Health and overdue behavior

Health boundaries come from the referenced model profile, not UI constants. With the current defaults, remaining above 30% is healthy, above 15% is watch, above 5% is warning, above 0% is critical, and 0% or below is overdue. Visual bars clamp to 0–100%, but derived negative remaining clicks are retained and displayed.

Consumption-based toner uses the same counter-derived numeric foundation with yield-oriented labels: Used Yield, Expected Yield, and Estimated Remaining.

## Unknown lifecycle semantics

`status = unknown` means the installation counter is not trustworthy. `installed_counter` and `installed_at` must be null, so usage, remaining, health, and estimated replacement counter derive as null/unknown. Counter zero is never substituted.

Owner/admin initialization supports either a known historical replacement counter (`manual_historical`) or tracking from the current counter (`tracking_start`). Tracking start explicitly does not claim the physical component is new and does not reconstruct earlier usage. Technician and operator roles remain read-only.

## Legacy bootstrap

The DEV-only script is `supabase/bootstrap/dev_c1070_legacy_lifecycles.sql`. It is intentionally outside migrations and `seed.sql`. Before writing, it verifies the unique `CG-TUP-A3-01` machine, approved C1070 model, account/branch relationship, current latest effective counter, effective profile set, and absence of any prior lifecycle bootstrap.

For each trusted row:

```text
installed_counter = legacy_estimated_replacement_counter - historical_expected
legacy_snapshot_usage = 1,437,911 - installed_counter
```

The script aborts unless the reconstructed snapshot usage equals the supplied legacy usage. It inserts 18 active lifecycles and 10 unknown sentinel lifecycles, and refuses to insert `TEST_COMPONENT`.

The 10 unknown slots are Cleaning Unit, Developer Black, Developing Unit Cyan, Developing Unit Magenta, Developing Unit Black, Drum Unit Black, Gear, Laser Unit, Roll Mesin, and Sensor.

### Toner Cyan exception

The old application predicted Toner Cyan with 14,000 clicks. DEV now has a workspace override of 13,500. The bootstrap therefore reconstructs the historical installed counter with 14,000 and snapshots `expected_at_install = 14,000`; it links the effective workspace profile and records a note explaining the 13,500 current-profile transition. This preserves the historical target instead of moving it by 500 clicks.

## Future M2.3C boundary

M2.3C may close active rows, persist removal facts, link previous/next lifecycles, and use eligible completed samples for adaptive estimates. M2.3B does not create replacement events, removal transactions, reasons, condition/PIC fields, inventory effects, costs, or machine faults.

