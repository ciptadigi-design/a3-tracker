# A3 Tracker V2 — Domain & Database Architecture Proposal

## Executive recommendation

A3 Tracker V2 should use:

- Account-level tenancy with branches beneath each account.
- Supabase Auth plus many-to-many account memberships.
- Separate machine-model definitions from physical machines.
- Immutable raw counter observations, with corrections and meter epochs.
- A shared component catalog with explicit model compatibility profiles.
- Component installations and replacements as historical events.
- An append-only inventory ledger rather than mutable stock.
- PostgreSQL RPCs for all multi-record operations that must be atomic.
- Archive/deactivate for master data; void/reversal/correction for transactional data.
- Derived lifecycle health, usage, stock, and financial totals—not mutable authoritative percentages or totals.

The most important simplification is this:

> Do not create separate systems for parts, consumables, and “usable” components. Use one component catalog, then separately describe classification, inventory behavior, and lifecycle tracking behavior.

The most important integrity decision is:

> Posted counter, inventory, purchase, replacement, and maintenance records should not be freely edited or deleted.

Repository verification remained read-only. `develop` is clean and still at `d1c11c5429f11b2deb27e5162a62eb627aaf9097`.

---

## 1. Domain boundaries

### Identity and tenancy

Responsible for:

- Supabase users
- Profiles
- Accounts
- Memberships and roles
- Tenant authorization

### Organization and locations

Responsible for:

- Branches
- Inventory locations
- Account-level operational structure

A branch is never an account.

### Machine master

Responsible for:

- Manufacturers
- Machine models
- Physical machines
- Machine status and installation information
- Physical/logical meters

### Counter tracking

Responsible for:

- Counter definitions
- Raw observations
- Meter values
- Counter corrections
- Meter resets/replacements
- Derived usage

### Component catalog and compatibility

Responsible for:

- Component categories
- Components and variants
- Machine-model compatibility
- Lifecycle configuration

### Machine component lifecycle

Responsible for:

- Installation history
- Replacement events
- Removal and failure reasons
- Lifecycle baselines
- Derived health

### Inventory and purchasing

Responsible for:

- Suppliers
- Stocking records
- Purchases and purchase lines
- Immutable inventory movements
- Reproducible on-hand balances

### Maintenance

Responsible for:

- Inspections
- Preventive/corrective work
- Repair, cleaning, calibration
- Affected components and counter snapshots

### Incidents and errors

Two separate domains:

- Machine-generated faults
- Operational/human incidents and losses

They may be related but should not share one overloaded table.

### Audit and integrity

Responsible for:

- Who changed master data
- Transaction corrections and voids
- Sensitive before/after audit records
- Idempotency
- Cross-tenant protections

---

## 2. Proposed ERD in ASCII

```text
auth.users
    │
    ├── 1:1 ── profiles
    │
    └── 1:N ── account_memberships ── N:1 ── accounts
                                                │
                 ┌──────────────────────────────┼─────────────────────────┐
                 │                              │                         │
                 ▼                              ▼                         ▼
             branches                      suppliers             inventory_locations
                 │                                                        │
                 ▼                                                        ▼
              machines                                              inventory_items
                 │                                                        │
                 │                                                        ▼
                 │                                                inventory_movements
                 │                                                   ▲          ▲
                 │                                                   │          │
                 │                                              purchase   replacement
                 │
                 ├── N:1 ── machine_models ── N:1 ── manufacturers
                 │                │
                 │                ▼
                 │      machine_model_component_profiles
                 │                │
                 │                ▼
                 │            components ── N:1 ── component_categories
                 │                │
                 │                └──────── N:1 ── manufacturers
                 │
                 ├── 1:N ── machine_meters ── N:1 ── counter_types
                 │                │
                 │                ▼
                 │         machine_meter_epochs
                 │                │
                 │                ▼
                 │     counter_observations ── 1:N ── counter_values
                 │
                 ├── 1:N ── component_installations
                 │                ▲
                 │                │ new/removed installation
                 │                │
                 │      component_replacement_events
                 │                │
                 │                └── inventory OUT movement
                 │
                 ├── 1:N ── maintenance_records
                 │                │
                 │                └── maintenance_record_components
                 │
                 ├── 1:N ── machine_faults
                 │                │
                 │                └── error_codes
                 │                        │
                 │                        └── machine_model_error_codes
                 │
                 └── 1:N ── operational_incidents (optional machine reference)


accounts ── 1:N ── purchases ── 1:N ── purchase_lines
                                  │
                                  └── inventory IN movements


Component Catalog
    Drum Cyan
        │
        ▼
Machine Model Component Profile
    C1070 / DRUM_C / expected 300,000 color impressions
        │
        ▼
Inventory Item
    Drum Cyan at Tuparev store / 3 units derived on hand
        │
        ▼
Replacement Event
    consumes 1 unit atomically
        │
        ▼
Component Installation
    installed on CG-TUP-A3-01 at counter observation X
        │
        ▼
Derived Lifecycle
    usage since installation / expected life / health state
```

---

## 3–6. Proposed tables, columns, keys, and classification

All identifiers should normally be UUIDs. Monetary fields should use `numeric`, never floating point. Timestamps should use `timestamptz`.

Account-scoped tables should carry `account_id` even when it is technically derivable. This makes RLS, composite foreign keys, and tenant queries safer.

### Identity and organization masters

#### `profiles` — master

- PK: `user_id`, also FK → `auth.users.id`
- `display_name`
- `phone`
- `avatar_path`
- `locale`
- `created_at`, `updated_at`

Must not contain a permanent `account_id`.

#### `accounts` — master

- PK: `id`
- `name`
- `code`
- `default_timezone`
- `default_currency`
- `status`
- `created_at`, `created_by`
- `updated_at`, `updated_by`
- `archived_at`, `archived_by`

Unique normalized account code.

#### `account_memberships` — master/security

- PK: `id`
- FK: `account_id` → `accounts`
- FK: `user_id` → `auth.users`
- `role`: owner/admin/technician/operator
- `status`: invited/active/suspended/revoked
- `invited_at`, `accepted_at`
- `created_at`, `created_by`
- `updated_at`, `updated_by`

Unique `(account_id, user_id)`.

Role must be enforced server-side, not accepted from arbitrary client payloads.

#### `branches` — master

- PK: `id`
- FK: `account_id`
- `code`
- `name`
- `address`
- `timezone_override`
- `is_active`
- archive/audit fields

Unique `(account_id, normalized_code)`.

#### `inventory_locations` — master

- PK: `id`
- FK: `account_id`
- Optional composite FK: `(branch_id, account_id)` → `branches`
- `code`, `name`
- `location_type`: warehouse/branch_store/service_vehicle/other
- `is_active`
- archive/audit fields

This supports account-wide warehouses as well as branch storage. A branch itself should not double as an inventory ledger identity.

### Machine and catalog masters

#### `manufacturers` — master/catalog

- PK: `id`
- Optional `owner_account_id`
- `scope`: global/account
- `name`
- `normalized_name`
- `website`
- `is_active`
- archive/audit fields

Global records are curated platform data. Account-scoped records support uncommon or local manufacturers.

A check constraint must ensure:

- `scope = global` → `owner_account_id IS NULL`
- `scope = account` → `owner_account_id IS NOT NULL`

#### `machine_models` — master/catalog

- PK: `id`
- FK: `manufacturer_id`
- Optional `owner_account_id`
- `scope`
- `name`
- `model_code`
- `machine_category`, initially constrained to `digital_a3`
- `color_capability`
- `notes`
- `is_active`
- archive/audit fields

Do not add large-format-specific properties now. Future category-specific specifications can be added separately.

#### `machines` — master

- PK: `id`
- FK: `account_id`
- Composite FK: `(branch_id, account_id)` → `branches`
- FK: `machine_model_id`
- `machine_code`
- `display_name`
- `serial_number`
- `installed_at`
- `operational_status`: active/down/maintenance/retired
- `timezone`
- `notes`
- `is_active`
- archive/audit fields

Recommendations:

- Manufacturer is derived from the model; do not duplicate it on the machine.
- Do not store an authoritative `initial_counter` column.
- Record the initial counter as the first counter observation with source `installation_baseline`.
- Keep `installed_at` separate from the first reading because they may not occur at the same time.

#### `counter_types` — master/catalog

- PK: `id`
- `code`: total_impressions/color_impressions/bw_impressions/operating_hours/etc.
- `name`
- `unit`
- `value_scale`
- `is_monotonic`
- `description`
- `is_active`

Manufacturer-specific counter types can be added without altering counter tables.

#### `machine_meters` — machine configuration master

- PK: `id`
- FK: `(machine_id, account_id)` → machines
- FK: `counter_type_id`
- `meter_code`
- `display_name`
- `is_primary`
- `is_active`
- `installed_at`, `retired_at`
- audit fields

A physical machine may expose several meters of the same broad type. The meter gives the raw channel an identity.

#### `component_categories` — master/catalog

- PK: `id`
- Optional `owner_account_id`
- `scope`
- `code`
- `name`
- Optional `parent_category_id`
- `description`
- `is_active`
- archive/audit fields

Examples: imaging, fusing, transfer, toner, cleaning, sensor.

#### `components` — master/catalog

- PK: `id`
- Optional `owner_account_id`
- FK: `component_category_id`
- Optional FK: `manufacturer_id`
- `scope`
- `name`
- `component_kind`: spare_part/consumable/service_material
- `manufacturer_part_number`
- `base_unit`
- `variant_code`
- `variant_label`
- `color`: cyan/magenta/yellow/black/none
- `is_serialized`
- `description`
- `is_active`
- archive/audit fields

“Usable” is not good domain terminology. Use:

- `component_kind` for commercial/stock classification.
- Profile `tracking_mode` for lifecycle behavior.

A drum can be a spare part and life-tracked. Toner can be a consumable and consumption-only. These are separate concepts.

#### `machine_model_component_profiles` — master/configuration

- PK: `id`
- Optional `account_id` for an account override
- FK: `machine_model_id`
- FK: `component_id`
- Optional FK: `counter_type_id`
- Optional FK: `overrides_profile_id`
- `slot_code`, e.g. `DRUM_C`, `FUSER_MAIN`
- `slot_name`
- `tracking_mode`: installed_lifecycle/consumption_only/inspection_only
- `lifecycle_basis`: counter/time/none
- `expected_life`
- `warning_at_usage_pct`
- `critical_at_usage_pct`
- `required_quantity`
- `replacement_behavior`: replace_existing/additive/consume_only
- `is_compatible`
- `notes`
- `is_active`
- archive/audit fields

Account-specific profiles can override global recommendations without changing canonical manufacturer data.

### Inventory and purchasing masters

#### `suppliers` — master

- PK: `id`
- FK: `account_id`
- `code`, `name`
- contact/address/tax fields
- `notes`
- `is_active`
- archive/audit fields

#### `inventory_items` — master/stocking configuration

- PK: `id`
- FK: `account_id`
- Composite FK: `(inventory_location_id, account_id)`
- FK: `component_id`
- `internal_sku`
- `stock_unit`
- `reorder_point`
- `reorder_quantity`
- `preferred_supplier_id`
- `is_active`
- archive/audit fields

This record means “this component is stocked at this location.” It does not contain authoritative stock quantity.

Unique active combination should normally be `(account_id, inventory_location_id, component_id)` unless separate lot/condition tracking is introduced later.

### Transactional tables

#### `machine_meter_epochs` — transaction/configuration history

- PK: `id`
- FK: `(machine_meter_id, account_id)`
- `epoch_number`
- `started_at`
- `ended_at`
- `event_type`: initial/reset/meter_replacement
- `reason`
- Optional `previous_final_value`
- Optional `new_initial_value`
- `created_by`, `created_at`

Each epoch is internally monotonic. A meter reset starts a new epoch rather than pretending a counter regression did not happen.

#### `counter_observations` — transactional header

- PK: `id`
- FK: `(machine_id, account_id)`
- `observed_at`
- `entered_at`
- `entered_by`
- `source`: manual/import/device/installation_baseline
- `client_request_id`
- `status`: effective/superseded/voided
- Optional FK: `supersedes_observation_id`
- `correction_reason`
- `notes`
- void/correction audit fields

`observed_at` is when the counter applied. `entered_at` is when it was submitted.

#### `counter_values` — transactional detail

- PK: `id`
- Composite FK: observation/account
- Composite FK: meter/account
- FK: `meter_epoch_id`
- `value`
- `created_at`

Unique `(counter_observation_id, machine_meter_id)`.

No `daily_clicks` column.

#### `purchases` — transactional document

- PK: `id`
- FK: `account_id`
- FK: `supplier_id`
- FK: `inventory_location_id`
- `purchase_number`
- `purchase_date`
- `currency`
- `status`: draft/posted/voided
- `supplier_invoice_number`
- `notes`
- created/posted/void audit fields

#### `purchase_lines` — transactional detail

- PK: `id`
- Composite FK: purchase/account
- FK: `inventory_item_id`
- `quantity`
- `unit_cost`
- `tax_amount`
- `discount_amount`
- Derived/generated `line_total`
- `notes`

The purchase total should be derived from lines. A snapshot total may be cached only if it is database-maintained.

#### `inventory_movements` — append-only transactional ledger

- PK: `id`
- FK: `account_id`
- FK: `inventory_item_id`
- `occurred_at`
- `movement_type`: purchase/component_replacement/consumable_usage/adjustment/damaged/transfer_in/transfer_out/reversal/opening_balance
- Signed `quantity`
- `unit_cost_snapshot`
- Optional FK: `purchase_line_id`
- Optional FK: `replacement_event_id`
- Optional FK: `reverses_movement_id`
- `reason`
- `client_request_id`
- `created_by`, `created_at`

Posted movements are never edited or deleted. Errors are reversed by a compensating movement.

#### `component_installations` — transactional lifecycle history

- PK: `id`
- FK: `(machine_id, account_id)`
- FK: `profile_id`
- FK: `component_id`
- `slot_code_snapshot`
- `installed_at`
- `installed_by`
- Optional FK: `baseline_observation_id`
- Optional FK: `baseline_meter_id`
- `baseline_value_snapshot`
- `expected_life_snapshot`
- `lifecycle_basis_snapshot`
- `counter_type_id_snapshot`
- threshold snapshots
- `quantity`
- `status`: installed/removed/failed/transferred/voided
- `removed_at`
- Optional `removal_observation_id`
- `removal_value_snapshot`
- `removal_reason`
- `notes`

Profile values are snapshotted here so later profile edits do not rewrite historical expectations.

#### `component_replacement_events` — transactional event

- PK: `id`
- FK: `(machine_id, account_id)`
- FK: `profile_id`
- Optional FK: `removed_installation_id`
- FK: `new_installation_id`
- Optional FK: `inventory_item_id`
- `quantity_consumed`
- `inventory_mode`: consume_stock/external_supply/no_stock_record
- `replacement_reason`: scheduled/worn/failed/damaged/diagnostic/other
- `occurred_at`
- `performed_by`
- `cost_snapshot`
- `notes`
- `client_request_id`
- void audit fields

This is the business event; `component_installations` represents the resulting lifecycle interval.

#### `maintenance_records` — transactional

- PK: `id`
- FK: `(machine_id, account_id)`
- `maintenance_type`: preventive/corrective/inspection/cleaning/calibration/repair
- `started_at`, `completed_at`
- `technician_user_id`
- Optional `external_technician_name`
- Optional `counter_observation_id`
- `counter_snapshot`
- `description`
- `findings`
- `actions_taken`
- `downtime_minutes`
- `labor_cost`, `other_cost`, `currency`
- `status`: draft/completed/voided
- notes and audit fields

#### `maintenance_record_components` — transactional relation

- PK: `id`
- FK: maintenance record/account
- FK: component
- Optional FK: component installation
- `relationship_type`: inspected/cleaned/repaired/replaced/affected
- `notes`

#### `error_codes` — master/catalog foundation

- PK: `id`
- Optional FK: manufacturer
- `code`
- `title`
- `description`
- `subsystem`
- `is_active`
- archive/audit fields

Do not build manuals, causes, procedures, or diagnostic trees in V2’s first implementation.

#### `machine_model_error_codes` — master compatibility

- PK: `id`
- FK: machine model
- FK: error code
- Optional component category/component
- `notes`
- `is_active`

This supports future manual and diagnostic knowledge without polluting operational fault records.

#### `machine_faults` — transactional

- PK: `id`
- FK: `(machine_id, account_id)`
- Optional FK: `error_code_id`
- `raw_error_code`
- `occurred_at`
- `cleared_at`
- `severity`
- `description`
- Optional affected component/profile
- `reported_by`
- `source`: operator/device/import
- `machine_stopped`
- `downtime_minutes`
- `resolution_summary`
- `status`
- void/correction audit fields

#### `operational_incidents` — transactional

- PK: `id`
- FK: `account_id`
- Optional branch and machine composite FKs
- `occurred_at`
- `incident_type`: human_error/print_test/process_failure/other
- `invoice_number`
- `customer_name_snapshot`
- `product_name_snapshot`
- `division_snapshot`
- `quantity_affected`
- `material_loss`
- `service_loss`
- `penalty_multiplier`
- `assessed_total_loss`
- `currency`
- Optional `responsible_user_id`
- `responsible_name_snapshot`
- `description`
- `cause`
- `prevention_action`
- `customer_resolution`
- `status`
- correction/void/audit fields

Repeated-error penalty must not rely on string equality between descriptions. Preserve an explicitly assessed multiplier and the policy/reason used at that time.

#### `audit_log` — immutable security/audit transaction

- PK: `id`
- Optional `account_id`
- `table_name`
- `record_id`
- `action`
- `actor_user_id`
- `occurred_at`
- `request_id`
- `old_values`
- `new_values`
- `reason`
- `source`

Limit full before/after capture to sensitive records. Avoid duplicating every high-volume counter value unnecessarily.

---

## 7. CRUD behavior for master entities

| Entity | Create | Read | Update | Archive/deactivate |
|---|---|---|---|---|
| Accounts | Platform/onboarding workflow | Members | Owner/admin | Yes; never delete with history |
| Branches | Owner/admin | Account members | Owner/admin | Yes |
| Memberships | Owner/admin invitation | Authorized members | Owner/admin, guarded role rules | Revoke/suspend |
| Machines | Owner/admin | Account members | Owner/admin; technicians for limited fields | Retire/archive |
| Manufacturers | Platform admin or account custom | Global + own-account | Owner of catalog record | Deactivate |
| Machine models | Platform admin or account custom | Global + own-account | Owner of catalog record | Deactivate |
| Component categories | Platform or account admin | Global + own-account | Owner | Deactivate |
| Components | Platform or account admin | Global + own-account | Owner | Deactivate |
| Model component profiles | Platform or account admin | Visible profiles | Owner/admin | Deactivate/version through replacement |
| Suppliers | Owner/admin | Account members as needed | Owner/admin | Archive |
| Inventory items | Owner/admin | Account roles | Owner/admin | Deactivate only at zero stock |
| Inventory locations | Owner/admin | Account roles | Owner/admin | Deactivate only after operational checks |

Renaming a component must not alter historical snapshots such as purchase descriptions or installed slot context where those snapshots matter.

Transactional behavior:

- Draft purchases and maintenance records may be edited.
- Posted purchases are corrected through void/reversal and reposting.
- Counter observations are superseded or voided.
- Inventory movements are reversed.
- Replacement events are voided only through an atomic reversal workflow.
- Completed maintenance records should be amended or voided, not silently rewritten.
- Faults/incidents may be corrected with recorded reason and audit history.
- Hard delete should be reserved for abandoned drafts with no dependent records.

---

## 8. Counter model

### Raw observations

One operator submission creates:

```text
counter_observations
  machine = CG-TUP-A3-01
  observed_at = 2026-08-14 17:00 Asia/Jakarta
  entered_by = user X
  client_request_id = UUID

counter_values
  TOTAL    = 1,450,000
  COLOR    = 1,100,000
  BW       =   350,000
```

The observation is the event; values are its meter readings.

### Usage calculation

For a monotonic meter in one epoch:

```text
usage = current effective value - previous effective value
```

Do not store `daily_clicks` as authoritative. Reporting should derive differences from effective readings.

A “daily” figure is exact only when readings align with the day boundary. Otherwise it is usage between observations, not proof of when every impression occurred.

### Regression detection

Submission should be through an RPC that:

1. Locks the machine or affected meters.
2. Finds effective chronological neighbors within the meter epoch.
3. Checks `previous_value <= new_value <= next_value`.
4. Rejects an unexplained regression.
5. Allows a regression only when opening a reset/replacement epoch.
6. Handles backdated entries correctly.

Comparing only with the latest inserted row is insufficient.

### Corrections

Never overwrite the original reading.

A correction should:

- Insert a new observation.
- Point `supersedes_observation_id` to the old observation.
- Mark the old observation `superseded`.
- Require a correction reason.
- Recalculate affected usage reports and lifecycle views.

### Meter resets and replacements

A reset/replacement:

- Closes the existing `machine_meter_epoch`.
- Captures the prior final value where available.
- Opens a new epoch with an initial value.
- Records event type, reason, actor, and time.

Usage over a reset is the sum of valid deltas across epochs, not `latest raw value - ancient raw value`.

### Uniqueness and idempotency

- Unique `(account_id, client_request_id)` for submission idempotency.
- Unique `(observation_id, meter_id)`.
- An exact duplicate machine/time/value fingerprint should be detected by the RPC.
- Do not enforce unconditional uniqueness on `(machine_id, observed_at)` because multiple legitimate submissions or corrections may occur at the same timestamp.

---

## 9. Component catalog model

Use one `components` table.

Recommended distinctions:

- `component_kind`
  - spare part
  - consumable
  - service material
- `tracking_mode`, held on the compatibility profile
  - installed lifecycle
  - consumption only
  - inspection only
- `is_serialized`
  - whether physical unit identity may matter

This avoids three parallel catalogs with duplicated inventory, supplier, and compatibility logic.

### CMYK variants

For Drum Cyan, Drum Magenta, Drum Yellow, and Drum Black, separate component records are safest when:

- They have different part numbers.
- Inventory must be counted separately.
- Compatibility may differ.
- Replacement and lifecycle histories must be separate.

Use category/family plus explicit variant fields:

```text
Category: Drum Unit
Component: Drum Unit
Variant: Cyan
Color: cyan
Part number: ...
```

A generic JSON attribute alone is too weak for inventory and constraints.

A single component with a color attribute chosen at transaction time is simpler initially but creates ambiguity in stock, compatibility, and reporting. It is not recommended for CMYK stock.

---

## 10. Machine-component compatibility/profile model

A profile answers:

> Can this exact component be used in this machine-model slot, and how should it be tracked?

Example:

```text
Machine model: AccurioPress C1070
Slot: DRUM_C
Component: Drum Unit Cyan
Tracking mode: installed_lifecycle
Lifecycle basis: counter
Counter type: color_impressions
Expected life: 300,000
Warning usage: 80%
Critical usage: 95%
Replacement behavior: replace_existing
```

Profiles should support:

- Multiple valid component alternatives for one slot.
- OEM and compatible aftermarket parts.
- Different expected life for the same component on different models.
- Account-specific overrides without changing global defaults.

`expected_life` is nullable when no predefined lifetime exists.

Validation:

- Counter basis requires `counter_type_id` and positive expected life.
- Time basis uses a defined time unit and positive expected life.
- `none` requires no expected life.
- Warning must precede critical.
- Compatibility and lifecycle configuration changes must not rewrite past installations.

---

## 11. Component lifecycle calculation

### Stored facts

Store:

- Component and machine
- Profile used
- Slot
- Installation timestamp
- Installation actor
- Baseline observation/meter
- Raw baseline value snapshot
- Expected-life and threshold snapshots
- Removal time and reason
- Ending counter snapshot where available
- Failure/early replacement reason

### Derived values

Calculate:

- Usage since installation
- Expected remaining life
- Health percentage
- Warning/critical/overdue state
- Estimated replacement date, later

For counter-based lifecycle:

```text
usage =
  sum of effective positive meter deltas
  for the profile's counter type
  after installation and before removal/current time
  across all relevant meter epochs

remaining = expected_life_snapshot - usage

health_pct =
  clamp(remaining / expected_life_snapshot * 100, 0, 100)
```

For time-based lifecycle:

```text
usage = elapsed supported time units since installation
```

Do not store health percentage as authoritative. A cached projection is acceptable later, but it must be rebuildable.

### Unknown counter baseline

If replacement occurs without a suitable reading:

- Permit only through a privileged workflow.
- Mark baseline as unknown.
- Require a reason.
- Show lifecycle health as unknown rather than inventing a zero baseline.

### Early replacement and failure

Record:

- Removal reason
- Failure flag/reason
- Actual achieved life
- Whether warranty investigation may be needed
- Notes

This later supports reliability analytics.

### Replacement without inventory

Allow explicit modes:

- `consume_stock`
- `external_supply`
- `no_stock_record`

Non-stock replacement must require a reason and stronger role permission. It must not create a fake inventory deduction.

### Transfers

Do not support direct machine-to-machine component transfer in the first implementation.

Proper transfer requires physical component-unit identity, inspection state, remaining-life history, return-to-stock, and potentially refurbishment. Most A3 parts are not serialized in daily operations. Adding `component_assets` now would create significant complexity with little immediate value.

If later required, introduce serialized component assets and an atomic remove/inspect/transfer/install workflow.

---

## 12. Inventory ledger model

Authoritative on-hand quantity:

```sql
sum(inventory_movements.quantity)
```

Example:

```text
+5 purchase
-1 component replacement
-1 consumable usage
+2 adjustment
-1 damaged
```

### Rules

- Positive values move stock into an inventory item.
- Negative values move stock out.
- Posted movements are immutable.
- Corrections create reversal movements.
- Adjustment requires a reason.
- Negative stock should normally be rejected under a row lock.
- Transfers produce paired OUT and IN movements atomically.

### Cached balance

Do not add a mutable `stock` column in the first implementation.

Start with a database view grouped by `inventory_item_id`. If performance later requires caching, introduce `inventory_balances` maintained only by database functions/triggers with:

- Row locking
- Rebuild capability from the ledger
- Reconciliation checks

A cached balance must never become the only source of truth.

---

## 13. Purchasing model

Use purchase header plus lines.

Posting a purchase atomically:

1. Validates supplier, location, items, quantities, and costs.
2. Changes purchase status from draft to posted.
3. Creates one inventory IN movement per line.
4. Prevents repeat posting via status and idempotency key.
5. Preserves cost snapshots for inventory and analytics.

### Cost analytics

Preserve:

- Purchase currency
- Unit cost
- Quantity
- Discounts/tax as applicable
- Supplier
- Inventory location
- Component identity
- Purchase date

For V2, do not implement a complete procurement system with requisitions, approvals, purchase orders, partial receipts, invoices, and payments.

The proposed `purchases` document should mean a completed stock receipt. If partial receiving becomes real business scope, later split it into purchase orders and goods receipts.

---

## 14. Atomic replacement workflow

Recommended RPC concept:

```text
replace_machine_component(...)
```

Responsibilities:

1. Authenticate `auth.uid()`.
2. Resolve active account membership and permitted role.
3. Resolve the machine and its account; never trust client-supplied account identity.
4. Lock the machine/profile slot and inventory item.
5. Validate machine-model compatibility.
6. Validate profile status and replacement behavior.
7. Validate the inventory component matches the selected profile.
8. Calculate current stock from the ledger.
9. Reject insufficient stock unless an authorized non-stock mode is supplied.
10. Resolve and validate the lifecycle counter baseline.
11. Close the currently installed component in the slot when required.
12. Create the new `component_installation`.
13. Create the `component_replacement_event`.
14. Create the inventory OUT movement.
15. Return the complete resulting record.
16. Commit all or none.

### Security boundary

Prefer a carefully written `SECURITY DEFINER` function only where necessary, with:

- Fixed `search_path`
- Fully qualified table names
- Explicit `auth.uid()` authorization
- No role/account accepted blindly from the client
- Minimal execute grants
- Direct insert/update grants revoked on protected transaction tables
- Idempotency key
- Audit entry
- No dynamic SQL

The RPC is not merely frontend convenience. It is the integrity boundary.

The same principle should be used for:

- Posting purchases
- Correcting counters
- Opening meter-reset epochs
- Inventory adjustments/transfers
- Voiding replacements

---

## 15. Maintenance model

The proposed model supports immediate needs without becoming a full CMMS.

A maintenance record captures:

- Machine
- Type
- Technician
- Start/completion time
- Description/findings/actions
- Counter observation and snapshot
- Downtime
- Basic cost
- Affected components

Future attachments can use Supabase Storage plus an attachment metadata table. Do not add scheduling engines, work orders, service contracts, labor timesheets, or recurring-maintenance generation until required.

If a maintenance action includes a component replacement, it should reference the replacement event rather than separately manipulating inventory.

---

## 16. Machine error vs operational error model

### Machine faults

Examples:

- C-3102
- Fuser temperature fault
- Transfer subsystem error
- Sensor failure

These belong to `machine_faults`, optionally connected to:

- Error-code catalog
- Machine model
- Subsystem/component
- Maintenance resolution

### Operational incidents

Examples:

- Wrong artwork
- Wrong paper
- Incorrect quantity
- Operator printing mistake
- Customer reprint and financial loss

These belong to `operational_incidents`.

### Relationship

An operational incident may optionally reference a machine fault when a technical failure caused customer or production loss. They remain separate records because:

- A machine fault may cause no financial incident.
- A human error may involve no machine fault.
- Their fields, workflows, permissions, and future intelligence differ.

The legacy `jenis_kesalahan = Machine Error/Human Error/Print Test` approach is too coarse and should not survive as one table.

---

## 17. Supabase Auth and membership model

```text
auth.users
    └── profiles

auth.users
    └── account_memberships
            └── accounts
```

A user can have:

```text
User A
  owner      at Account 1
  technician at Account 2
```

Profiles contain identity/display information only.

Membership roles:

- `owner`: account lifecycle, membership/role management, all admin actions
- `admin`: operational master data and most tenant configuration
- `technician`: counters, replacements, maintenance, machine faults
- `operator`: counter submission and limited operational reporting/incidents

Role abilities should be defined as explicit database policies/functions, not inferred from UI visibility.

Prevent:

- Last active owner being removed accidentally.
- Admin promoting themselves to owner unless policy permits it.
- Users editing their own role.
- Suspended memberships passing RLS.

---

## 18. RLS strategy

Enable RLS on all tenant and sensitive catalog tables.

### Tenant predicate

Conceptually:

```sql
exists (
  select 1
  from account_memberships m
  where m.account_id = row.account_id
    and m.user_id = auth.uid()
    and m.status = 'active'
)
```

Use stable helper functions such as:

```text
is_account_member(account_id)
has_account_role(account_id, allowed_roles[])
```

Care must be taken to avoid RLS recursion and unsafe search paths.

### Policy outline

- Account members can read their account and allowed operational data.
- Owner/admin can manage branches, machines, suppliers, inventory masters.
- Technician can create maintenance, faults, counters, and authorized replacements.
- Operator can create counter observations and operational incidents but cannot rewrite posted history.
- Global catalog records are readable to authenticated users.
- Account-owned catalog records are readable only by that account.
- Only record owners/platform administration can modify global catalog entries.
- `audit_log` is not generally client-writable.
- Protected transaction tables are written through RPCs.

### RLS is not enough

RLS decides whether a user may access a row. It does not ensure:

- Counter monotonicity
- Stock sufficiency
- Compatibility
- Balanced transfer
- Atomic posting
- Last-owner protection

Those belong in constraints, triggers, or RPCs.

---

## 19. Audit strategy

### Master data

Use on material master tables:

- `created_at`, `created_by`
- `updated_at`, `updated_by`
- `archived_at`, `archived_by`

A database trigger may consistently maintain timestamps and actors.

### Transactional data

Prefer:

- Original immutable record
- Status
- `voided_at`, `voided_by`, `void_reason`
- Superseding/correcting record reference
- Reversal record reference
- Client/request id

Do not add `updated_by` to immutable ledger rows because those rows should not be updated.

### Full audit log

Use trigger-based before/after audit logging for:

- Membership and role changes
- Machine master changes
- Compatibility/lifecycle profiles
- Suppliers and inventory configuration
- Posted purchase state transitions
- Transaction void/correction operations
- Security-sensitive RPC activity

Avoid capturing secrets, tokens, or unrestricted large attachments in JSON audit fields.

---

## 20. Integrity constraints and enforcement layers

### Database constraints

Use constraints for facts that can be checked locally and deterministically:

- Non-null foreign keys where required.
- Composite tenant FKs `(entity_id, account_id)`.
- Unique machine code per account.
- Unique branch/location codes per account.
- Unique membership `(account_id, user_id)`.
- Positive purchase and replacement quantities.
- Non-negative raw counter values.
- Non-negative money values where appropriate.
- Non-zero inventory movement quantities.
- Valid warning and critical percentages.
- Expected life positive or null.
- Lifecycle-basis field consistency.
- Catalog scope/owner consistency.
- Purchase status values.
- One meter value per observation.
- Only one open meter epoch per meter.
- Only one active installation per machine slot when replacement behavior requires it.
- Purchase line belongs to the same account and location context as its header.

### Serial number constraints

Recommended:

- Normalized non-empty serial unique within `(account_id, manufacturer_id)`.
- Do not enforce global public uniqueness initially.

Global uniqueness could leak the existence of another tenant’s machine and may block legitimate historical/account-transfer cases. Platform-level duplicate detection can be added separately.

### RLS

Use for:

- Tenant row visibility
- Role-based CRUD permissions
- Global versus account catalog visibility
- Preventing cross-account reads/writes

### Transactional RPC

Use for:

- Counter neighbor/regression validation
- Counter correction
- Meter reset/replacement
- Purchase posting
- Replacement with inventory consumption
- Inventory adjustments and transfers
- Replacement void/reversal
- Last-owner membership rules
- Account-visible catalog compatibility validation

### Frontend validation

Use for user experience only:

- Required fields
- Numeric formatting
- Helpful warnings
- Timezone-aware display
- Immediate compatibility filtering
- Confirmation dialogs

The database must still reject invalid requests.

---

## 21. Legacy-to-V2 mapping

The mapping below is based on the legacy application’s actual payloads. The old repository was read through Git without checkout or modification.

### `click_history`

Legacy fields observed:

- `id`
- `date_str`
- `date_for`
- `operator`
- `total_clicks`
- `daily_clicks`

Map to:

- One designated legacy account/branch/machine.
- `counter_observations.observed_at` from the best parse of `date_str` or `date_for`.
- `counter_values.value` for the total-impressions meter.
- `operator` to `entered_by` only when a reliable user match exists; otherwise preserve as an operator-name snapshot in import notes/metadata.
- Ignore `daily_clicks` as authoritative and recalculate.

Uncertain/manual decisions:

- The exact machine identity.
- Timezone and timestamp parsing.
- Whether multiple readings on one date reflect separate shifts.
- Whether edited/deleted historical readings are missing.
- Counter reset history.
- Whether `daily_clicks` was already incorrect after edits.

### `part_replacements`

Legacy fields observed:

- `id`
- `part_name`
- `replaced_at_click`
- `operator`
- `created_at`

Map to:

- Component by normalized name.
- Machine-model profile after compatibility review.
- `component_replacement_events`.
- `component_installations`.
- Baseline snapshot from `replaced_at_click`.

Uncertain/manual decisions:

- Exact machine and slot.
- Whether the part was actually installed or merely “reset.”
- Whether inventory was successfully deducted.
- Exact timestamp versus only creation time.
- User identity behind free-text operator.
- Removal reason and failed/early status.
- Whether hardcoded legacy lifetimes are valid.

Legacy hardcoded lifetime values should be treated as proposed import defaults requiring business validation, not authoritative manufacturer specifications.

### `inventory_parts`

Legacy fields observed:

- `id`
- `part_name`
- `stock`

Map to:

- Component
- Inventory item at a chosen default legacy location
- One `opening_balance` inventory movement

Do not reconstruct the current ledger by replaying old purchases and replacements and assume it will match. The legacy frontend allowed partial failures.

Manual decisions:

- Default warehouse/location
- Component-name normalization
- Negative or questionable stock handling
- Unit of measure
- Duplicate spelling/casing
- Whether stock was physically counted at cutover

### `part_purchases`

Legacy fields observed:

- `id`
- `tgl_pembelian`
- `part_name`
- `qty`
- `harga_satuan`
- `total_harga`
- `supplier`

Map to:

- Posted purchase header, usually one per legacy row
- One purchase line
- Supplier normalized from text
- Component/inventory item

Important migration rule:

- Preserve purchase history for cost analytics.
- Do not automatically generate historical stock movements that also contribute to the new opening balance.
- Either import historical movements outside the opening-balance ledger period or establish a clear ledger cutover timestamp.

Manual decisions:

- Currency
- Tax inclusion
- Receiving location
- Supplier duplicates
- Whether total should use stored value or recomputed `qty × unit_cost`
- Whether purchase and legacy stock increment both succeeded

### `error_logs`

Legacy fields observed:

- `tgl`
- `nomor_invoice`
- `divisi`
- `nama_konsumen`
- `nama_produk`
- `qty_kesalahan`
- `kerugian_bahan`
- `kerugian_jasa`
- `kategori_kesalahan`
- `jenis_kesalahan`
- `deskripsi_kesalahan`
- `penyebab`
- `pencegahan_solusi`
- `penyelesaian`
- `pic`
- `jumlah_kerugian`

Map according to `jenis_kesalahan`:

- Machine Error → candidate `machine_faults`
- Human Error / Print Test → `operational_incidents`
- Ambiguous values → manual review queue

Problems:

- Machine Error rows use an operational/customer-loss shape and may lack machine, model, or genuine fault code.
- `pic` is free text.
- Punishment multiplier is embedded partly in calculated loss and sometimes in solution text.
- Machine and branch are not reliable.
- Descriptions were used as an unstable repeated-error identity.

For ambiguous Machine Error rows, preserving them as imported operational incidents with a legacy classification may be safer than inventing technical fault data.

---

## 22. Recommended migration order

This is sequencing only; no migrations have been created.

1. Core enums/domains and shared audit utilities.
2. Profiles, accounts, memberships.
3. Branches and inventory locations.
4. Manufacturers and machine models.
5. Machines.
6. Counter types, meters, meter epochs.
7. Counter observations, values, correction/reset RPCs.
8. Component categories and components.
9. Machine-model component profiles.
10. Suppliers and inventory items.
11. Purchases, purchase lines, inventory ledger, posting RPC.
12. Component installations, replacements, atomic replacement RPC.
13. Maintenance.
14. Machine faults and operational incidents.
15. Error-code catalog foundation.
16. Audit triggers and final RLS tightening.
17. Seed canonical catalog/profile data.
18. Dry-run legacy transformation into staging.
19. Reconciliation and business review.
20. Controlled legacy import with an explicit inventory cutover.

RLS and grants should be tested before application CRUD is connected.

---

## 23. Risks and trade-offs

### Global and account-owned catalogs

A purely global catalog produces cleaner machine-health intelligence but prevents tenants from immediately adding uncommon models or aftermarket parts.

A purely account-scoped catalog creates duplicates and fragmented intelligence.

Recommended compromise:

- Global canonical records.
- Account-owned custom records.
- Later merge/promotion tooling.
- Account overrides for lifecycle profiles.

This is more complex than tenant-only tables but justified by the product’s future intelligence goals.

### UUID and redundant `account_id`

Redundant account keys add columns and composite constraints, but materially reduce cross-tenant mistakes. This is worthwhile.

### Append-only transactions

Corrections are more work than simple edit/delete buttons. That operational friction is appropriate for stock, counters, costs, and machine health.

### Counter health calculations

Backdated readings and corrections can change historical usage. Lifecycle health must therefore be treated as a projection, not a stored fact.

### Exact cost attribution

Inventory ledger quantities are straightforward; cost layers are not. FIFO/average-cost accounting would add complexity.

For the first implementation, preserve purchase and replacement cost snapshots. Do not promise formal accounting valuation yet.

### Catalog over-generalization

Do not create generic “asset,” “measurement,” or “entity-attribute-value” systems. They would make A3 operational workflows harder and constraints weaker.

### Attachments and diagnostic knowledge

The schema leaves room for them, but manuals, causes, procedures, predictive models, and AI recommendations should be separate later domains.

---

## 24. Questions requiring business decisions

1. Who may create account-owned manufacturers and machine models: owner only, admin, or platform approval?
2. Should machine code remain permanently reserved after archive, or may it be reused?
3. Is serial-number uniqueness expected per account, per manufacturer, or globally?
4. What inventory locations exist initially: one central warehouse, branch stores, or both?
5. May operators submit backdated counters, and how many days back?
6. Which counter types are mandatory for the first supported machines?
7. What is the expected behavior when a machine counter is reset by service?
8. Are lifecycle thresholds expressed as usage percentages, remaining percentages, or absolute units in the business UI?
9. Can technicians replace a component supplied externally, without inventory?
10. Does every replacement need owner/admin approval, or may technicians post directly?
11. Should toner create an installed lifecycle, or only a consumption event in V2?
12. Are early failures important enough to require structured failure categories immediately?
13. Is negative stock ever operationally permitted?
14. Are purchase prices tax-inclusive?
15. Is partial purchasing/receiving required soon, or is a posted purchase equivalent to a completed receipt?
16. Is operational penalty logic legally/business-approved, and who may assess or override it?
17. Which legacy `jenis_kesalahan` values should be treated as true machine faults?
18. Which account, branch, machine, inventory location, and timezone own all legacy data?
19. Are the hardcoded legacy component lifetimes accepted business defaults or merely UI estimates?
20. Must physical component transfer between machines be supported in the next 12 months?

---

## 25. Smallest safe V2 implementation

The smallest safe implementation should include:

### Include now

- Accounts, profiles, memberships, and branches.
- Manufacturers, machine models, and physical machines.
- Total-impressions counter type initially, while retaining the multi-meter structure.
- Immutable counter observations, corrections, and meter epochs.
- Component categories and component catalog.
- Model-component compatibility and lifecycle profiles.
- Inventory locations, inventory items, and append-only movements.
- Suppliers, purchase headers, and purchase lines.
- Atomic purchase posting.
- Component installations and atomic replacement workflow.
- Basic maintenance records.
- Separate machine-fault and operational-incident tables.
- RLS, audit fields, composite tenant FKs, and idempotency.

### Defer

- Predictive maintenance.
- Manuals and attachments.
- Diagnostic trees and recommended actions.
- Device/IoT ingestion.
- Serialized component assets and machine-to-machine transfers.
- Formal FIFO/average-cost accounting.
- Purchase requisitions, approvals, partial receipts, and accounts payable.
- Automated maintenance scheduling.
- Large-format machine abstractions.
- Complex error-code knowledge graphs.
- Stored lifecycle-health caches.

### Final architecture position

The safe core is not a large enterprise asset-management system. It is:

```text
Tenant and membership
→ branch and machine master
→ raw, correctable counter history
→ model-specific component lifecycle
→ immutable inventory ledger
→ atomic replacement/purchase workflows
→ auditable maintenance and incident records
```

That is enough to make A3 Tracker V2 operationally reliable and intelligence-ready without overbuilding the first release.

No files, database objects, remote Supabase state, code, or commits were changed.
