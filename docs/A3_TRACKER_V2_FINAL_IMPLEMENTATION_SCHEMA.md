# A3 Tracker V2 — Final Implementation Schema V1

Status: design and implementation planning only
Scope: digital A3 production printers
Database target: PostgreSQL / Supabase
Schema version: V1 for implementation approval

This document revises `A3_TRACKER_V2_ARCHITECTURE_PROPOSAL.md` using the approved V1 simplifications. It defines the intended implementation contract but contains no executable SQL.

---

## 1. Final architecture principles

1. An account is the tenant boundary. Branches are locations within an account, never separate tenants.
2. A Supabase Auth user may belong to several accounts through `account_memberships`.
3. Tenant operational tables carry `account_id` explicitly, even where it could be derived, to simplify RLS and enforce composite tenant foreign keys.
4. Manufacturers, machine models, component categories, components, counter types, and error codes are shared V1 catalogs. There is no global/account scope mechanism in V1.
5. Shared catalog writes must be restricted to a platform/catalog administrator. A tenant owner must not be able to modify definitions used by other tenants.
6. Physical machines are distinct from shared machine-model definitions. Manufacturer is derived from the model.
7. Counter readings preserve raw history. Daily usage is derived and is never an authoritative stored field.
8. Counter corrections supersede old readings. Counter resets are explicit events rather than unexplained regressions.
9. Parts, consumables, and service materials use one component catalog. Commercial classification is separate from per-model lifecycle behavior.
10. Machine-model component profiles define compatibility, machine slot, tracking behavior, lifecycle counter, expected life, and thresholds.
11. Installations snapshot lifecycle configuration so later profile edits do not rewrite history.
12. Component usage, remaining life, health percentage, and alert state are derived projections.
13. Inventory movements are the source of truth. V1 has no authoritative mutable stock column.
14. A V1 purchase represents a completed purchase/stock receipt after posting. It is not a procurement or accounts-payable system.
15. Multi-row business actions such as replacement, purchase posting, inventory reversal, and counter correction belong in atomic database functions.
16. Master data uses create/read/update/archive. Posted operational facts use correction, superseding records, voids, or reversals.
17. RLS protects tenant access; constraints protect structural facts; RPCs protect cross-row business invariants; frontend checks improve UX only.
18. V1 remains specific enough for digital A3 operations and does not introduce generic asset/EAV abstractions for possible large-format use.

### Data conventions

- Primary keys: UUID.
- Auth references: UUID FK to `auth.users.id` where appropriate.
- Timestamps: `timestamptz`; business display uses machine, branch, or account IANA timezone.
- Dates without time: `date` only where the business fact is truly date-only.
- Counter values and quantities: `numeric`, not floating point.
- Money: `numeric(18,2)` plus ISO currency code.
- Codes used for lookup should be normalized for uniqueness, preferably with `citext` or unique indexes on `lower(code)`.
- Shared catalog records have no `account_id` in V1.
- Tenant parents should expose `unique(id, account_id)` so children can use composite tenant FKs.

---

## 2. Final ASCII ERD

```text
auth.users
    │
    ├── 1:1 ── profiles
    │
    └── 1:N ── account_memberships ── N:1 ── accounts
                                                │
                    ┌───────────────────────────┼──────────────────────────┐
                    │                           │                          │
                    ▼                           ▼                          ▼
                 branches                  suppliers             inventory_locations
                    │                           │                          │
                    │                           ▼                          ▼
                    │                       purchases ── 1:N ── purchase_lines
                    │                                                      │
                    ▼                                                      ▼
                 machines                                          inventory_items
                    │                                                      │
                    │                                                      ▼
                    │                                              inventory_movements
                    │                                                 ▲          ▲
                    │                                                 │          │
                    │                                           purchase    replacement
                    │
                    ├── N:1 ── machine_models ── N:1 ── manufacturers
                    │                │
                    │                ├── 1:N ── error_codes
                    │                │
                    │                └── 1:N ── machine_model_component_profiles
                    │                                  │          │
                    │                                  │          ▼
                    │                                  │      components
                    │                                  │          │
                    │                                  │          └── N:1 ── component_categories
                    │                                  │
                    │                                  └── N:1 ── counter_types
                    │
                    ├── 1:N ── counter_readings ── N:1 ── counter_types
                    │                │
                    │                ├── supersedes / previous reading
                    │                │
                    │                └── counter_reset_events
                    │
                    ├── 1:N ── component_installations
                    │                ▲             │
                    │                │             │ removed/new installation
                    │                │             ▼
                    │      component_replacement_events
                    │                │
                    │                └── inventory OUT movement
                    │
                    ├── 1:N ── maintenance_records
                    │                ├── optional component
                    │                └── optional replacement event
                    │
                    ├── 1:N ── machine_faults ── optional N:1 ── error_codes
                    │
                    └── optional 1:N ── operational_incidents


Component lifecycle and stock path
----------------------------------

components: Drum Cyan
    │
    ▼
machine_model_component_profiles: C1070 / DRUM_C / 300,000 color impressions
    │
    ├── inventory_items at Tuparev Store
    │       │
    │       └── inventory_movements: SUM(quantity) = stock on hand
    │
    └── component_replacement_events (atomic RPC)
            ├── closes previous component_installation
            ├── creates new component_installation with counter/life snapshots
            └── creates inventory OUT movement
                    │
                    ▼
          machine_component_health (derived view concept)
```

---

## 3. Final table list

V1 contains exactly 25 application tables, excluding `auth.users` and future SQL views/functions.

| # | Table | Domain | Classification |
|---:|---|---|---|
| 1 | `profiles` | Auth | Master |
| 2 | `accounts` | Tenancy | Master |
| 3 | `account_memberships` | Tenancy/security | Master |
| 4 | `branches` | Tenancy/location | Master |
| 5 | `manufacturers` | Machine catalog | Shared master |
| 6 | `machine_models` | Machine catalog | Shared master |
| 7 | `machines` | Machine | Tenant master |
| 8 | `counter_types` | Counter catalog | Shared master |
| 9 | `counter_readings` | Counter | Transaction |
| 10 | `counter_reset_events` | Counter | Transaction |
| 11 | `component_categories` | Component catalog | Shared master |
| 12 | `components` | Component catalog | Shared master |
| 13 | `machine_model_component_profiles` | Lifecycle configuration | Shared master |
| 14 | `component_installations` | Lifecycle | Transaction/history |
| 15 | `component_replacement_events` | Lifecycle | Transaction |
| 16 | `inventory_locations` | Inventory | Tenant master |
| 17 | `inventory_items` | Inventory | Tenant master |
| 18 | `inventory_movements` | Inventory | Immutable transaction |
| 19 | `suppliers` | Purchasing | Tenant master |
| 20 | `purchases` | Purchasing | Draft/posted transaction |
| 21 | `purchase_lines` | Purchasing | Transaction detail |
| 22 | `maintenance_records` | Maintenance | Draft/completed transaction |
| 23 | `error_codes` | Fault catalog | Shared master |
| 24 | `machine_faults` | Fault | Transaction |
| 25 | `operational_incidents` | Operations | Transaction |

`counter_reset_events` is retained because it is the smallest robust way to distinguish a legitimate reset from corrupted or regressed data and to preserve lifecycle usage across a reset. Reset metadata embedded only on one reading would make before/after validation, correction, and audit less explicit.

No `maintenance_record_components`, `machine_model_error_codes`, universal `audit_log`, machine-meter, or meter-epoch table is included.

---

## 4. Exact important columns for each table

The types below are implementation targets, not SQL migration text.

### 4.1 `profiles`

- `user_id uuid` — PK and FK → `auth.users.id`, cascade on Auth user deletion only after product policy approval.
- `display_name text` — required.
- `phone text` — optional.
- `avatar_path text` — optional Storage path.
- `locale text` — default application locale.
- `created_at timestamptz` — required, database default.
- `updated_at timestamptz` — required.

No `account_id`; account access comes from membership.

### 4.2 `accounts`

- `id uuid` — PK.
- `code text` — required stable tenant code.
- `name text` — required.
- `default_timezone text` — required IANA timezone.
- `default_currency char(3)` — required, initially `IDR`.
- `status text` — `active`, `suspended`, or `archived`.
- `notes text` — optional.
- `created_at`, `created_by`.
- `updated_at`, `updated_by`.
- `archived_at`, `archived_by`.

### 4.3 `account_memberships`

- `id uuid` — PK.
- `account_id uuid` — FK → accounts.
- `user_id uuid` — FK → `auth.users`.
- `role text` — `owner`, `admin`, `technician`, `operator`.
- `status text` — `invited`, `active`, `suspended`, `revoked`.
- `invited_at timestamptz`.
- `accepted_at timestamptz`.
- `created_at`, `created_by`.
- `updated_at`, `updated_by`.

### 4.4 `branches`

- `id uuid` — PK.
- `account_id uuid` — FK → accounts.
- `code text` — required within account.
- `name text` — required.
- `address text` — optional V1 free-form address.
- `timezone text` — nullable; account timezone is fallback.
- `is_active boolean` — required.
- `notes text`.
- master audit/archive columns.

### 4.5 `manufacturers`

- `id uuid` — PK.
- `code text` — required shared catalog code.
- `name text` — required.
- `website text` — optional.
- `notes text`.
- `is_active boolean`.
- master audit/archive columns.

No tenant ownership or catalog scope fields.

### 4.6 `machine_models`

- `id uuid` — PK.
- `manufacturer_id uuid` — FK → manufacturers.
- `model_code text` — required within manufacturer.
- `name text` — required, e.g. AccurioPress C1070.
- `machine_category text` — V1 value `digital_a3`; constrained list permits future additions.
- `color_capability text` — `color` or `monochrome`.
- `notes text`.
- `is_active boolean`.
- master audit/archive columns.

### 4.7 `machines`

- `id uuid` — PK.
- `account_id uuid` — FK → accounts.
- `branch_id uuid` — composite FK with account → branches.
- `machine_model_id uuid` — FK → machine_models.
- `machine_code text` — required within account.
- `display_name text` — required.
- `serial_number text` — optional until known.
- `installed_on date` — optional.
- `status text` — `active`, `down`, `maintenance`, `retired`.
- `timezone text` — nullable; branch/account fallback.
- `notes text`.
- `is_active boolean`.
- master audit/archive columns.

Manufacturer is not duplicated here.

### 4.8 `counter_types`

- `id uuid` — PK.
- `code text` — unique, e.g. `total_impressions`, `color_impressions`, `bw_impressions`, `operating_hours`.
- `name text` — required.
- `unit text` — e.g. `impression`, `hour`, `cycle`.
- `decimal_scale smallint` — default zero; permits fractional hours.
- `is_monotonic boolean` — normally true.
- `description text`.
- `is_active boolean`.
- master audit/archive columns.

### 4.9 `counter_readings`

- `id uuid` — PK.
- `account_id uuid` — FK → accounts.
- `machine_id uuid` — composite FK with account → machines.
- `counter_type_id uuid` — FK → counter_types.
- `reading_value numeric(20,3)` — required, nonnegative.
- `observed_at timestamptz` — business observation time.
- `entered_by uuid` — nullable FK → `auth.users`; nullable for controlled legacy import.
- `entered_by_name_snapshot text` — optional legacy/external operator name.
- `source text` — `manual`, `import`, `device`, `installation_baseline`.
- `previous_reading_id uuid` — optional self-FK; predecessor resolved when accepted, for audit only.
- `supersedes_reading_id uuid` — optional self-FK for a correction.
- `status text` — `effective`, `superseded`, `voided`.
- `correction_reason text` — required when superseding or voiding.
- `notes text`.
- `client_request_id uuid` — required for application submissions.
- `created_at`, `created_by`.
- `voided_at`, `voided_by`, `void_reason`.

Chronological calculations use effective rows and window ordering, not `previous_reading_id`, because later backfills can change the chronological neighbor.

### 4.10 `counter_reset_events`

- `id uuid` — PK.
- `account_id uuid` — FK → accounts.
- `machine_id uuid` — composite FK with account → machines.
- `counter_type_id uuid` — FK → counter_types.
- `reset_at timestamptz` — required.
- `event_type text` — `counter_reset` or `meter_replacement`.
- `reading_before_id uuid` — optional FK → counter_readings.
- `reading_after_id uuid` — optional FK → counter_readings.
- `value_before numeric(20,3)` — required when known.
- `value_after numeric(20,3)` — required when known.
- `reason text` — required.
- `status text` — `effective` or `voided`.
- `client_request_id uuid`.
- `created_at`, `created_by`.
- `voided_at`, `voided_by`, `void_reason`.

### 4.11 `component_categories`

- `id uuid` — PK.
- `code text` — unique.
- `name text` — required, e.g. Drum Unit, Fusing, Toner.
- `description text`.
- `is_active boolean`.
- master audit/archive columns.

V1 omits category hierarchy until a real reporting need appears.

### 4.12 `components`

- `id uuid` — PK.
- `component_category_id uuid` — FK → component_categories.
- `manufacturer_id uuid` — nullable FK → manufacturers; null for generic material.
- `code text` — unique shared component code.
- `name text` — required.
- `component_kind text` — `part`, `consumable`, `service_material`.
- `manufacturer_part_number text` — optional.
- `base_unit text` — e.g. `piece`, `bottle`, `kit`.
- `color_variant text` — nullable constrained value `cyan`, `magenta`, `yellow`, `black`.
- `description text`.
- `is_active boolean`.
- master audit/archive columns.

Stock-critical CMYK variants are separate component rows. `color_variant` is explicit, not arbitrary JSON.

### 4.13 `machine_model_component_profiles`

- `id uuid` — PK.
- `machine_model_id uuid` — FK → machine_models.
- `component_id uuid` — FK → components.
- `slot_code text` — required, e.g. `DRUM_C`.
- `slot_name text` — required, e.g. Drum Cyan.
- `tracking_mode text` — `lifecycle`, `consumption`, `inspection`.
- `counter_type_id uuid` — nullable FK → counter_types.
- `expected_life numeric(20,3)` — nullable.
- `warning_usage_pct numeric(5,2)` — nullable.
- `critical_usage_pct numeric(5,2)` — nullable.
- `required_quantity numeric(18,3)` — default 1.
- `replacement_behavior text` — `replace_existing`, `consume_only`, `inspection_only`.
- `notes text`.
- `is_active boolean`.
- master audit/archive columns.

`tracking_mode` belongs here rather than on `components`: the same component may be lifecycle-tracked on one model, consumption-only on another, or merely inspected in a different slot.

### 4.14 `component_installations`

- `id uuid` — PK.
- `account_id uuid` — FK → accounts.
- `machine_id uuid` — composite FK with account → machines.
- `profile_id uuid` — FK → machine_model_component_profiles.
- `component_id uuid` — FK → components; duplicated as historical snapshot identity and validated against profile.
- `slot_code_snapshot text`.
- `slot_name_snapshot text`.
- `tracking_mode_snapshot text`.
- `counter_type_id_snapshot uuid` — nullable FK → counter_types.
- `baseline_counter_reading_id uuid` — nullable FK → counter_readings.
- `baseline_counter_value numeric(20,3)` — nullable snapshot.
- `expected_life_snapshot numeric(20,3)` — nullable.
- `warning_usage_pct_snapshot numeric(5,2)` — nullable.
- `critical_usage_pct_snapshot numeric(5,2)` — nullable.
- `quantity numeric(18,3)` — required.
- `installed_at timestamptz`.
- `installed_by uuid` — nullable FK → `auth.users`.
- `installed_by_name_snapshot text` — for legacy/external technician.
- `status text` — `installed`, `removed`, `failed`, `voided`.
- `removed_at timestamptz` — nullable.
- `removal_counter_reading_id uuid` — nullable FK → counter_readings.
- `removal_counter_value numeric(20,3)` — nullable snapshot.
- `removal_reason text` — nullable until removal.
- `notes text`.
- `created_at`, `created_by`.
- `voided_at`, `voided_by`, `void_reason`.

### 4.15 `component_replacement_events`

- `id uuid` — PK.
- `account_id uuid` — FK → accounts.
- `machine_id uuid` — composite FK with account → machines.
- `profile_id uuid` — FK → machine_model_component_profiles.
- `component_id uuid` — FK → components.
- `removed_installation_id uuid` — nullable FK → component_installations.
- `new_installation_id uuid` — required FK → component_installations.
- `inventory_item_id uuid` — nullable composite tenant FK → inventory_items.
- `quantity_consumed numeric(18,3)` — required.
- `inventory_mode text` — `consume_stock`, `external_supply`, `no_stock_record`.
- `replacement_reason text` — `scheduled`, `worn`, `failed`, `damaged`, `diagnostic`, `other`.
- `occurred_at timestamptz`.
- `performed_by uuid` — nullable FK → `auth.users`.
- `performed_by_name_snapshot text`.
- `unit_cost_snapshot numeric(18,2)` — nullable.
- `currency char(3)` — nullable with cost.
- `notes text`.
- `status text` — `posted` or `voided`.
- `client_request_id uuid`.
- `created_at`, `created_by`.
- `voided_at`, `voided_by`, `void_reason`.

### 4.16 `inventory_locations`

- `id uuid` — PK.
- `account_id uuid` — FK → accounts.
- `branch_id uuid` — nullable composite FK with account → branches.
- `code text` — required within account.
- `name text` — required.
- `location_type text` — `branch_store`, `warehouse`, `service_vehicle`, `other`.
- `is_default_for_branch boolean` — default false.
- `notes text`.
- `is_active boolean`.
- master audit/archive columns.

V1 UI should automatically create or suggest one default branch store and hide advanced location management during normal branch onboarding.

### 4.17 `inventory_items`

- `id uuid` — PK.
- `account_id uuid` — FK → accounts.
- `inventory_location_id uuid` — composite tenant FK → inventory_locations.
- `component_id uuid` — FK → components.
- `internal_sku text` — optional account-local SKU.
- `stock_unit text` — required and normally equal to component base unit.
- `reorder_point numeric(18,3)` — nullable.
- `reorder_quantity numeric(18,3)` — nullable.
- `preferred_supplier_id uuid` — nullable composite tenant FK → suppliers.
- `notes text`.
- `is_active boolean`.
- master audit/archive columns.

There is no `stock` or `on_hand` column.

### 4.18 `inventory_movements`

- `id uuid` — PK.
- `account_id uuid` — FK → accounts.
- `inventory_item_id uuid` — composite tenant FK → inventory_items.
- `occurred_at timestamptz`.
- `movement_type text` — `purchase`, `replacement`, `consumable_usage`, `adjustment`, `damaged`, `transfer_in`, `transfer_out`, `opening_balance`, `reversal`.
- `quantity numeric(18,3)` — signed, nonzero.
- `unit_cost_snapshot numeric(18,2)` — nullable.
- `currency char(3)` — nullable with cost.
- `purchase_line_id uuid` — nullable FK → purchase_lines.
- `replacement_event_id uuid` — nullable FK → component_replacement_events.
- `reverses_movement_id uuid` — nullable self-FK.
- `reason text` — required for adjustment, damage, opening balance, or reversal.
- `client_request_id uuid`.
- `created_at`, `created_by`.

Rows are immutable. There is no update/void flag; an incorrect movement is countered by a reversal row.

### 4.19 `suppliers`

- `id uuid` — PK.
- `account_id uuid` — FK → accounts.
- `code text` — required within account.
- `name text` — required.
- `contact_name text`.
- `phone text`.
- `email text`.
- `address text`.
- `tax_identifier text`.
- `notes text`.
- `is_active boolean`.
- master audit/archive columns.

### 4.20 `purchases`

- `id uuid` — PK.
- `account_id uuid` — FK → accounts.
- `supplier_id uuid` — composite tenant FK → suppliers.
- `inventory_location_id uuid` — composite tenant FK → inventory_locations.
- `purchase_number text` — required within account.
- `supplier_invoice_number text` — optional.
- `purchase_date date` — required.
- `currency char(3)` — required.
- `status text` — `draft`, `posted`, `voided`.
- `notes text`.
- `client_request_id uuid` — for posting idempotency.
- `created_at`, `created_by`, `updated_at`, `updated_by` while draft.
- `posted_at`, `posted_by`.
- `voided_at`, `voided_by`, `void_reason`.

### 4.21 `purchase_lines`

- `id uuid` — PK.
- `account_id uuid` — tenant key matching purchase.
- `purchase_id uuid` — composite tenant FK → purchases.
- `inventory_item_id uuid` — composite tenant FK → inventory_items.
- `quantity numeric(18,3)` — positive.
- `unit_cost numeric(18,2)` — nonnegative.
- `discount_amount numeric(18,2)` — default zero.
- `tax_amount numeric(18,2)` — default zero.
- `line_total numeric(18,2)` — database-generated or view-derived from the fields above.
- `notes text`.
- `created_at`, `created_by`, `updated_at`, `updated_by` while parent is draft.

### 4.22 `maintenance_records`

- `id uuid` — PK.
- `account_id uuid` — FK → accounts.
- `machine_id uuid` — composite tenant FK → machines.
- `maintenance_type text` — `preventive`, `corrective`, `inspection`, `cleaning`, `calibration`, `repair`.
- `started_at timestamptz`.
- `completed_at timestamptz` — nullable while draft/in progress.
- `technician_id uuid` — nullable FK → `auth.users`.
- `technician_name_snapshot text` — optional external/legacy name.
- `component_id uuid` — nullable FK → components; primary affected component only.
- `replacement_event_id uuid` — nullable composite tenant FK → component_replacement_events.
- `counter_reading_id uuid` — nullable FK → counter_readings.
- `counter_value_snapshot numeric(20,3)` — nullable.
- `description text` — required.
- `findings text`.
- `actions_taken text`.
- `downtime_minutes integer` — default zero.
- `labor_cost numeric(18,2)` — default zero.
- `other_cost numeric(18,2)` — default zero.
- `currency char(3)`.
- `notes text`.
- `status text` — `draft`, `completed`, `voided`.
- created/updated/completed/void audit columns as appropriate.

V1 intentionally supports only one primary component reference. Additional affected components may be described in notes. Add a join table later only if multi-component structured reporting is demonstrated.

### 4.23 `error_codes`

- `id uuid` — PK.
- `machine_model_id uuid` — FK → machine_models.
- `code text` — required within machine model.
- `title text` — required.
- `subsystem text` — optional controlled text initially.
- `description text`.
- `is_active boolean`.
- master audit/archive columns.

V1 duplicates a code row for each model when the same printed code applies to several models. This avoids a join table and permits model-specific wording. If cross-model diagnostic knowledge later needs a canonical code identity, introduce a canonical error definition plus model mapping in V2+.

### 4.24 `machine_faults`

- `id uuid` — PK.
- `account_id uuid` — FK → accounts.
- `machine_id uuid` — composite tenant FK → machines.
- `error_code_id uuid` — nullable FK → error_codes.
- `raw_error_code text` — optional snapshot when not cataloged.
- `occurred_at timestamptz`.
- `cleared_at timestamptz` — nullable.
- `severity text` — `info`, `warning`, `critical`.
- `description text` — required.
- `component_id uuid` — nullable FK → components.
- `reported_by uuid` — nullable FK → `auth.users`.
- `reported_by_name_snapshot text`.
- `source text` — `manual`, `device`, `import`.
- `machine_stopped boolean`.
- `downtime_minutes integer` — default zero.
- `resolution_summary text`.
- `status text` — `open`, `resolved`, `voided`.
- `client_request_id uuid`.
- created/resolved/void audit columns.

The selected error code must belong to the physical machine's model; an RPC or validation trigger enforces this cross-table rule.

### 4.25 `operational_incidents`

- `id uuid` — PK.
- `account_id uuid` — FK → accounts.
- `branch_id uuid` — composite tenant FK → branches.
- `machine_id uuid` — nullable composite tenant FK → machines.
- `occurred_at timestamptz`.
- `incident_type text` — `human_error`, `print_test`, `process_failure`, `other`.
- `invoice_number text`.
- `customer_name_snapshot text`.
- `product_name_snapshot text`.
- `division_snapshot text`.
- `quantity_affected numeric(18,3)` — default zero.
- `material_loss numeric(18,2)` — default zero.
- `service_loss numeric(18,2)` — default zero.
- `penalty_multiplier numeric(8,2)` — default one.
- `assessed_loss numeric(18,2)` — stored assessment snapshot.
- `currency char(3)`.
- `responsible_user_id uuid` — nullable FK → `auth.users`.
- `responsible_name_snapshot text`.
- `description text` — required.
- `cause text`.
- `prevention_action text`.
- `resolution text`.
- `status text` — `open`, `resolved`, `voided`.
- `client_request_id uuid`.
- created/resolved/void audit columns.

The assessed loss is preserved because it is an approved business assessment, not merely a live mathematical projection. A check can verify it is nonnegative; policy decides whether it must equal `(material_loss + service_loss) × penalty_multiplier`.

---

## 5. PK/FK relationships

### Tenant chain

```text
accounts.id
  ├── account_memberships.account_id
  ├── branches.account_id
  ├── machines.account_id
  ├── inventory_locations.account_id
  ├── suppliers.account_id
  └── all tenant transactions.account_id
```

For tenant integrity, add `unique(id, account_id)` on `branches`, `machines`, `inventory_locations`, `inventory_items`, `suppliers`, `purchases`, `component_installations`, `component_replacement_events`, and other referenced tenant parents as needed.

Then use composite FKs such as:

- `machines(branch_id, account_id)` → `branches(id, account_id)`.
- `counter_readings(machine_id, account_id)` → `machines(id, account_id)`.
- `inventory_items(inventory_location_id, account_id)` → `inventory_locations(id, account_id)`.
- `purchases(supplier_id, account_id)` → `suppliers(id, account_id)`.
- `purchase_lines(purchase_id, account_id)` → `purchases(id, account_id)`.
- `component_replacement_events(inventory_item_id, account_id)` → `inventory_items(id, account_id)`.
- `maintenance_records(replacement_event_id, account_id)` → `component_replacement_events(id, account_id)`.
- `operational_incidents(branch_id, account_id)` → `branches(id, account_id)`.
- `operational_incidents(machine_id, account_id)` → `machines(id, account_id)`.

### Shared catalog chain

```text
manufacturers ──< machine_models ──< error_codes
       │                │
       └──< components  └──< machine_model_component_profiles >── components
                                    │
                                    └── counter_types
```

### Lifecycle chain

```text
machine_model_component_profiles
       └──< component_installations
                └── component_replacement_events
                        └── inventory_movements
```

Delete behavior should normally be `restrict` for historical parents. Archiving replaces deletion.

---

## 6. Unique and check constraints

### Unique constraints/indexes

- Accounts: normalized `code` unique.
- Memberships: `(account_id, user_id)` unique.
- Branches: `(account_id, lower(code))` unique.
- Manufacturers: normalized `code` and normalized `name` unique where practical.
- Machine models: `(manufacturer_id, lower(model_code))` unique.
- Machines: `(account_id, lower(machine_code))` unique, including archived machines unless code reuse is explicitly approved.
- Machines: partial unique normalized serial per account/model or account/manufacturer when serial is nonblank; final scope requires approval.
- Counter types: normalized `code` unique.
- Effective counter reading: partial unique `(machine_id, counter_type_id, observed_at)` where status is effective.
- Counter idempotency: `(machine_id, counter_type_id, client_request_id)` unique when request ID is present.
- Reset idempotency: `(machine_id, counter_type_id, client_request_id)` unique when present.
- Component categories: normalized `code` unique.
- Components: normalized `code` unique.
- Profiles: `(machine_model_id, slot_code, component_id)` unique.
- Only one installed lifecycle row per `(machine_id, slot_code_snapshot)` via partial unique index where status is installed and tracking mode is lifecycle.
- Replacement request: `(account_id, client_request_id)` unique when present.
- Inventory locations: `(account_id, lower(code))` unique.
- At most one default location per branch via partial unique index `(branch_id)` where active and default.
- Inventory items: `(account_id, inventory_location_id, component_id)` unique.
- Suppliers: `(account_id, lower(code))` unique.
- Purchases: `(account_id, lower(purchase_number))` unique.
- Purchase posting request: `(account_id, client_request_id)` unique when present.
- Error codes: `(machine_model_id, lower(code))` unique.
- Fault/incident idempotency: `(account_id, client_request_id)` unique when present.

### Check constraints

- Enum-like status, role, kind, type, source, and behavior fields accept only documented V1 values.
- Counter reading values are `>= 0`.
- Counter decimal scale is within an approved small range, e.g. 0–3.
- Profile quantity is `> 0`.
- Profile expected life is null or `> 0`.
- Warning and critical percentages are between 0 and 100.
- When both thresholds exist: `warning_usage_pct < critical_usage_pct`.
- Lifecycle profile requires counter type, expected life, warning, and critical values.
- Consumption or inspection profiles may leave lifecycle fields null.
- `replace_existing` is valid only with lifecycle tracking.
- Installation removal time is not before installation time.
- Removed/failed installation requires removal timestamp and reason.
- Replacement quantity is `> 0`.
- `consume_stock` replacement requires inventory item.
- External/no-stock replacement requires explanatory notes or reason.
- Inventory movement quantity is nonzero.
- Movement sign matches movement type: purchase/opening/transfer-in positive; replacement/usage/damaged/transfer-out negative; adjustment/reversal either sign with reason.
- Purchase line quantity is `> 0`; cost, tax, and discount are nonnegative.
- Posted/voided purchase timestamps and actors match status.
- Downtime and incident quantities are nonnegative.
- Maintenance completion is not before start.
- Monetary losses and penalty multiplier are nonnegative.
- Reset before/after values are nonnegative when present.
- `reading_before_id` and `reading_after_id` cannot be the same.

Rules involving several rows or tables remain RPC/trigger responsibilities, not check constraints.

---

## 7. Master versus transaction classification

### Master data

- Profiles
- Accounts
- Memberships
- Branches
- Manufacturers
- Machine models
- Machines
- Counter types
- Component categories
- Components
- Machine-model component profiles
- Inventory locations
- Inventory items
- Suppliers
- Error codes

Master data may be corrected in place while active, with audit columns. Once referenced historically, it is archived rather than deleted.

### Transactional data

- Counter readings
- Counter reset events
- Component installations
- Component replacement events
- Inventory movements
- Purchases and purchase lines
- Maintenance records
- Machine faults
- Operational incidents

Transactions are not general CRUD records. Draft documents are editable; posted or effective facts use correction, superseding, void, or reversal workflows.

---

## 8. CRUD behavior for every final table

| Table | Create | Read | Update | Delete/archive/correction behavior |
|---|---|---|---|---|
| `profiles` | Auth signup/bootstrap | Self; authorized member display | User updates own safe fields | No normal delete; follows Auth lifecycle |
| `accounts` | Secure onboarding | Active members | Owner updates | Archive/suspend; never hard-delete with history |
| `account_memberships` | Owner/admin invitation under role rules | Account members as permitted | Owner/admin status/role rules | Revoke/suspend; no hard delete |
| `branches` | Owner/admin | Members | Owner/admin | Archive/deactivate |
| `manufacturers` | Platform catalog admin | Authenticated users | Platform catalog admin | Archive/deactivate |
| `machine_models` | Platform catalog admin | Authenticated users | Platform catalog admin | Archive/deactivate |
| `machines` | Owner/admin | Members | Owner/admin; technician limited status if approved | Retire/archive |
| `counter_types` | Platform catalog admin | Authenticated users | Platform catalog admin | Archive/deactivate |
| `counter_readings` | Operator+ through submit RPC | Account members | Never overwrite | Supersede or void with reason |
| `counter_reset_events` | Technician+ through RPC | Account members | No free edit | Void/correct through controlled workflow |
| `component_categories` | Platform catalog admin | Authenticated users | Platform catalog admin | Archive/deactivate |
| `components` | Platform catalog admin | Authenticated users | Platform catalog admin | Archive/deactivate |
| `machine_model_component_profiles` | Platform catalog admin | Authenticated users | Platform catalog admin | Archive; installations retain snapshots |
| `component_installations` | Replacement/bootstrap RPC | Account members | Closure only through RPC | Void through reversal workflow |
| `component_replacement_events` | Technician+ RPC | Account members | No normal edit | Void atomically and reverse stock/install state |
| `inventory_locations` | Owner/admin | Members | Owner/admin | Deactivate after stock is zero/transferred |
| `inventory_items` | Owner/admin | Members | Owner/admin | Deactivate after stock is zero |
| `inventory_movements` | Posting/replacement/adjustment RPC | Authorized members | Never | Reversal movement only |
| `suppliers` | Owner/admin | Authorized members | Owner/admin | Archive/deactivate |
| `purchases` | Owner/admin draft | Authorized members | Draft only | Posted: void plus ledger reversals |
| `purchase_lines` | With draft purchase | Authorized members | Parent draft only | Draft delete only; posted immutable |
| `maintenance_records` | Technician+ | Members | Draft; controlled completion | Completed: void/amend policy, no hard delete |
| `error_codes` | Platform catalog admin | Authenticated users | Platform catalog admin | Archive/deactivate |
| `machine_faults` | Technician+; operator may report if approved | Members | Resolve through controlled update | Void with reason |
| `operational_incidents` | Operator+ | Role-limited members | Resolve/correct under policy | Void with reason |

---

## 9. Role permissions

### Role intent

- Owner: full tenant administration, memberships, master data, and operational oversight.
- Admin: tenant operational administration, excluding ownership transfer and protected owner changes.
- Technician: machine operations, counter work, replacements, maintenance, and faults.
- Operator: counter entry and limited operational incident entry/read access.
- Platform/catalog admin: non-tenant application authority held in trusted Auth app metadata or server-side administration; manages shared catalogs.

Tenant roles do not imply platform catalog write permission.

### Domain permission summary

| Domain | Owner | Admin | Technician | Operator |
|---|---|---|---|---|
| Account settings | Manage | Read/limited update | Read | Read limited |
| Memberships | Manage, including owner rules | Invite/manage non-owner if approved | Read limited | Self only |
| Branches/machines | Manage | Manage | Read; limited status | Read assigned/allowed |
| Shared catalogs | Read | Read | Read | Read needed selections |
| Counter readings | Read/correct | Read/correct | Submit/correct/reset | Submit; no reset/correction by default |
| Component profiles/health | Read | Read | Read | Read machine health |
| Replacements | Read/void | Execute/read/void | Execute/read | Read or none |
| Inventory masters | Manage | Manage | Read | Read limited |
| Inventory adjustments | Approve/execute | Execute | No direct adjustment by default | None |
| Purchasing | Manage/post/void | Manage/post/void | Read | None |
| Maintenance | Read/manage | Read/manage | Create/complete | Read limited |
| Machine faults | Read/manage | Read/manage | Create/resolve | Report/read limited if approved |
| Operational incidents | Read/manage | Read/manage | Create/read | Create own/read limited |

---

## 10. Counter design

### One row per machine and counter type

Each raw value is a separate `counter_readings` row. A screen may submit total, color, and BW together, but the database stores three independently validated readings.

Usage between two valid chronological readings is:

```text
usage = current.reading_value - previous.reading_value
```

Only rows with `status = effective` participate. Reports use `lag(reading_value)` partitioned by machine and counter type, ordered by `observed_at`, with a deterministic ID tie-breaker.

### Regression handling

The submit-reading RPC should:

1. Authenticate the caller and resolve the machine/account.
2. Lock the relevant `(machine, counter_type)` stream.
3. Find effective previous and next chronological readings.
4. Require `previous <= new <= next` for a monotonic counter.
5. Reject unexplained regression.
6. Recognize an atomically created effective reset event as the only normal regression exception.
7. Enforce idempotency and return the existing row on a safe retry.

Backdated insertion must validate both neighbors. Checking only the latest reading is incorrect.

### Corrections

A correction RPC should:

1. Lock the original and stream.
2. Verify correction permission.
3. Mark the original `superseded` with a reason.
4. Insert a new effective row referencing `supersedes_reading_id`.
5. Revalidate chronological neighbors.
6. Re-evaluate any affected reset event and lifecycle projection.

Original values remain recoverable.

### Reset strategy

`counter_reset_events` is a lightweight bridge across a discontinuity. It records old and new meter values and optional before/after reading references.

For an interval crossing one reset:

```text
usage before reset = value_before - prior_effective_value
usage after reset  = current_effective_value - value_after
interval usage     = usage before reset + usage after reset
```

For several resets, sum each continuous segment. Negative segment deltas are invalid.

The reset event must be created with its first post-reset reading in one RPC transaction. V1 does not support silent reset inference.

### V1 limitations

- No physical meter inventory or separate meter serial tracking.
- No multiple simultaneous physical meters for the same machine/counter type.
- No automatic IoT counter ingestion beyond the `source` field and future RPC use.
- Reset-aware usage requires approved reset events with reliable boundary values.

These limitations are acceptable for operator-entered digital A3 counters.

---

## 11. Component catalog design

One catalog covers all stockable and service components.

```text
component_categories
    Drum Unit
        ├── Drum Cyan
        ├── Drum Magenta
        ├── Drum Yellow
        └── Drum Black
```

`component_kind` describes what the item is commercially:

- `part`
- `consumable`
- `service_material`

`tracking_mode` describes how a component behaves in one model/slot and therefore belongs on `machine_model_component_profiles`:

- `lifecycle`
- `consumption`
- `inspection`

Examples:

| Component | Kind | Profile tracking mode |
|---|---|---|
| Drum Cyan | part | lifecycle |
| Fuser | part | lifecycle |
| Toner Cyan | consumable | consumption |
| Cleaning Fluid | service_material | consumption |

CMYK records are separate whenever part number, compatibility, or inventory differs. No JSON attribute determines stock identity.

---

## 12. Component lifecycle profile design

A profile is the compatibility and lifecycle contract for one component in one machine-model slot.

```text
AccurioPress C1070
  + slot DRUM_C
  + component Drum Cyan
  + tracking lifecycle
  + color impressions
  + expected 300,000
  + warning at 80% used
  + critical at 95% used
```

### Profile behavior

- Lifecycle: expects a baseline counter, one active installation in the slot, and derived health.
- Consumption: posting usage consumes inventory but does not produce long-lived health.
- Inspection: compatible/inspectable component with no counter lifetime.

### Profile edits

Editing expected life changes future installations only. Existing installations retain:

- Tracking mode snapshot
- Counter type snapshot
- Expected life snapshot
- Warning and critical snapshots
- Slot snapshots

Do not implement inheritance, tenant override, model-family inheritance, or effective-dated profile versions in V1.

---

## 13. Installation and replacement model

`component_installations` represents the period a component occupies a machine slot. `component_replacement_events` represents the business act that closes one installation, begins another, and potentially consumes stock.

### First installation/bootstrap

An existing installed component may be initialized without consuming inventory when onboarding a machine. This must use a controlled bootstrap operation with:

- Reason `initial_system_setup`.
- Known or explicitly unknown baseline.
- No fabricated historical stock movement.
- Actor and timestamp.

### Normal replacement

The replacement transaction:

- Closes the active installation in the slot.
- Stores removal counter/time/reason.
- Creates a posted replacement event.
- Creates a new installation with profile snapshots.
- Creates inventory OUT movement when using stock.

### No-stock replacement

`external_supply` or `no_stock_record` is allowed only by approved roles with a reason. The system must show that stock was not consumed; it must not invent an inventory movement.

### Void

Voiding a replacement is itself atomic. It must reverse the stock movement, void the new installation, and restore or recreate the prior active state only if no later dependent transaction makes that unsafe. Otherwise the workflow must reject the void and require a compensating replacement.

---

## 14. Health calculation

### Authoritative inputs

- Active lifecycle installation.
- Snapshot counter type.
- Effective baseline reading/value.
- Effective current compatible reading.
- Effective reset events between baseline and current.
- Expected-life and threshold snapshots.

### Counter-based calculation without reset

```text
usage = current_reading_value - effective_baseline_value
remaining = expected_life_snapshot - usage
health_pct = clamp((remaining / expected_life_snapshot) × 100, 0, 100)
usage_pct = (usage / expected_life_snapshot) × 100
```

State:

```text
if usage >= expected life                 -> overdue
else if usage_pct >= critical threshold   -> critical
else if usage_pct >= warning threshold    -> replace_soon
else                                      -> healthy
```

The UX example `245,000 / 300,000, Health 18%` is rounded from 18.33% remaining.

### Corrections

The installation stores the originally observed baseline value as evidence. For the live projection, the view should resolve the effective successor in the correction chain for the baseline reading. If the baseline is voided without a replacement, health becomes `unknown` rather than silently using bad data.

Corrections to later readings automatically change the current effective reading and derived health.

### Resets

When resets occur, usage is the sum of valid positive deltas across continuous counter segments from installation to current reading. `current - baseline` alone is not valid across a reset.

If reset boundary values are incomplete, health is `unknown` and the dashboard should display “Counter reset requires review.” V1 must not guess.

### Time and other bases

V1 profiles use counter types, including operating-hours counters. Pure calendar-day lifecycle is deferred. `expected_life` is expressed in the selected counter type's unit.

### Early replacement

For a removed installation, achieved usage is derived at the removal point. Removal reason `failed` versus scheduled/worn supports later reliability analysis.

---

## 15. `machine_component_health` view concept

Do not implement this view until schema approval. The recommended view returns one row per active lifecycle installation:

```text
account_id
branch_id
machine_id
machine_code
machine_display_name
installation_id
profile_id
component_id
component_name
slot_code
slot_name
counter_type_id
counter_type_code
baseline_reading_id
baseline_value_effective
current_reading_id
current_value
usage
expected_life
remaining
usage_pct
health_pct
health_state
warning_usage_pct
critical_usage_pct
installed_at
data_quality_state
```

Conceptual query stages:

1. Select active `component_installations` with lifecycle tracking.
2. Resolve corrected effective baseline reading.
3. Select the latest effective compatible counter reading at or after installation.
4. Calculate reset-aware accumulated usage.
5. Apply snapshotted expected life and thresholds.
6. Return `unknown` data-quality/health state when baseline, current reading, or reset boundary is insufficient.

Indexes needed for this query:

- Effective counter stream on `(machine_id, counter_type_id, observed_at desc)`.
- Active installations on `(machine_id, status, slot_code_snapshot)`.
- Effective resets on `(machine_id, counter_type_id, reset_at)`.

The dashboard query is then straightforward:

```text
select rows where account_id = current account and machine_id = selected machine
order by health_pct ascending nulls last, slot_name
```

Health values remain derived, not stored on machines or installations.

---

## 16. Inventory ledger design

An inventory item identifies one component stocked at one account location. Its stock is the sum of immutable movements.

```text
+5 PURCHASE
-1 REPLACEMENT
-1 CONSUMABLE_USAGE
+2 ADJUSTMENT
-1 DAMAGED
--------------------
 4 ON HAND
```

### Ledger rules

- All movement creation uses controlled RPCs or trusted posting functions.
- Movements are immutable after insertion.
- Correction creates a linked reversal movement.
- Transfers create matched transfer-out and transfer-in movements atomically.
- Negative stock is rejected by default under an inventory-item lock.
- Opening balances require explicit import/cutover reason.
- Movement source FKs provide traceability to purchase lines or replacement events.

No mutable balance is stored in V1.

---

## 17. `inventory_on_hand` view concept

Do not implement until approval. The view groups movements by inventory item:

```text
account_id
inventory_location_id
inventory_location_name
branch_id
inventory_item_id
component_id
component_name
internal_sku
stock_unit
on_hand_quantity = SUM(inventory_movements.quantity)
reorder_point
is_below_reorder_point
last_movement_at
```

Recommended semantics:

- Include active and archived inventory items when they have nonzero balance.
- Return zero for active inventory items without movements using a left join.
- Do not hide negative values if an administrative override ever permits them; expose them as reconciliation errors.
- Index movements by `(inventory_item_id, occurred_at)`.

If performance later becomes inadequate, a database-maintained balance cache may be introduced, but it must be rebuildable from movements.

---

## 18. Purchase design

V1 purchasing means a completed purchase/stock receipt.

### Draft

Owner/admin may create a header and edit lines. Drafts produce no inventory movement and therefore no stock.

### Posting

A future `post_purchase` RPC should:

1. Authenticate owner/admin.
2. Lock the draft purchase.
3. Validate same-account supplier, location, and items.
4. Validate at least one positive line and valid costs.
5. Reject repeat posting.
6. Mark the header posted.
7. Create one positive purchase movement per line.
8. Return the posted purchase, totals, and movements.

### Voiding

Voiding a posted purchase should:

- Require owner/admin and reason.
- Lock affected inventory items.
- Create reversal movements.
- Reject the void if it would create forbidden negative stock, unless an approved reconciliation workflow resolves dependent consumption.
- Mark purchase voided only within the same transaction.

V1 excludes requests, approvals, orders, partial receipts, payment, and AP accounting.

---

## 19. Atomic replacement RPC contract

Proposed function name:

```text
replace_machine_component(...)
```

### Expected input

```text
p_machine_id uuid
p_profile_id uuid
p_inventory_item_id uuid nullable
p_inventory_mode text
p_quantity numeric
p_occurred_at timestamptz
p_baseline_counter_reading_id uuid nullable
p_baseline_counter_value numeric nullable
p_replacement_reason text
p_notes text nullable
p_client_request_id uuid
```

Do not accept authoritative `account_id`, component identity, expected life, thresholds, cost, role, or user ID from the client. Resolve them from protected records and `auth.uid()`.

### Authorization

- Allowed: active technician, admin, or owner membership for the machine account.
- Operator: denied.
- Non-stock mode may be restricted to admin/owner or explicitly granted to technician after approval.
- `SECURITY DEFINER` only if needed, with fixed search path, fully qualified names, internal membership checks, minimal execute grants, and no dynamic SQL.

### Transaction responsibilities

1. Authenticate user and active membership.
2. Lock machine slot/profile and inventory item.
3. Validate machine is active and profile belongs to its model.
4. Validate profile/component compatibility and lifecycle behavior.
5. Validate inventory item component/location/account and stock.
6. Resolve compatible effective counter baseline or require approved unknown-baseline reason.
7. Close existing active installation in slot.
8. Create new installation with snapshots.
9. Create replacement event connecting old/new installations.
10. Create negative inventory movement if consuming stock.
11. Preserve idempotency; a retry returns the original result.
12. Commit all or roll back all.

### Expected output

Return one structured result containing:

```text
replacement_event
removed_installation nullable
new_installation
inventory_movement nullable
inventory_on_hand_after nullable
health_projection_status
idempotent_replay boolean
```

Direct client insert/update privileges should be revoked for replacement events, lifecycle closures, and replacement inventory movements.

---

## 20. Maintenance design

V1 uses only `maintenance_records`.

An optional `component_id` identifies the main affected component. An optional `replacement_event_id` links a replacement performed during the work. This is sufficient for the first operational release because:

- Most maintenance can be summarized at machine level.
- Replacement events already identify exact lifecycle and stock effects.
- Notes can list secondary inspected parts.
- A many-to-many table would add UI and integrity work before a proven reporting need.

Maintenance completion should snapshot the selected counter value. Draft records may be edited. Completed records should not be silently rewritten; significant mistakes should use a controlled amendment or void with reason.

The design intentionally excludes schedules, work orders, recurring plans, technician timesheets, service contracts, attachments, and approval workflows.

---

## 21. Machine fault design

`error_codes` is model-specific in V1:

```text
machine_model_id + code -> title, subsystem, description
```

This allows the same printed code to mean different things on different models. The same code appearing on several models is represented by several rows. A join table is not justified until shared diagnostic content must be normalized across model families.

`machine_faults` stores the actual occurrence. `error_code_id` is optional because operators may encounter an uncataloged code; `raw_error_code` and description preserve the event.

Fault resolution is operational history, not an AI diagnostic system. Manuals, causes, procedures, knowledge graphs, and diagnosis are deferred.

Machine faults are distinct from operational incidents. A future nullable relationship could connect them if customer loss caused by a fault must be analyzed, but V1 does not require it.

---

## 22. Operational incident design

`operational_incidents` replaces the human/process portion of legacy `error_logs`.

It preserves customer/product/responsible-person names as snapshots because those external or organizational names can change. A responsible Auth user is linked when known.

Financial assessment fields are explicit:

```text
base loss = material_loss + service_loss
assessed loss = approved result after penalty policy
```

The system should not infer repeated mistakes from free-text description equality. Any penalty multiplier must be explicit, reviewable, and governed by an approved business policy.

Technical machine faults remain in `machine_faults`, even when an operational incident references the same real-world episode in future scope.

---

## 23. RLS policy matrix

RLS is enabled on all application tables. Anonymous access is denied by absence of policies. Shared catalog reads require an authenticated user. Shared catalog writes require trusted platform/catalog-admin status, not tenant ownership.

### Tenant policy predicate

Conceptually:

```text
active membership exists where
membership.user_id = auth.uid()
membership.account_id = row.account_id
membership.status = active
```

Use carefully written helper functions such as `is_account_member` and `has_account_role`. They must avoid recursive RLS and use fixed search paths.

| Domain/table group | Owner | Admin | Technician | Operator | Anonymous |
|---|---|---|---|---|---|
| Own profile | R/U | R/U | R/U | R/U | None |
| Accounts | R/U/archive | R/limited U | R | R limited | None |
| Memberships | R/C/U under owner safeguards | R/C/U non-owner if approved | R limited | Self only | None |
| Branches/machines | R/C/U/archive | R/C/U/archive | R; limited machine status | R | None |
| Shared catalogs | R | R | R | R | None |
| Shared catalog write | Platform permission only | Platform permission only | None | None | None |
| Counter readings | R/correct/void | R/correct/void | R/C/correct/reset | R/C | None |
| Reset events | R/C/void RPC | R/C/void RPC | R/C RPC | R | None |
| Profiles/installations/health | R | R | R | R | None |
| Replacement events | R/C/void RPC | R/C/void RPC | R/C RPC | None or R limited | None |
| Inventory masters | R/C/U/archive | R/C/U/archive | R | R limited | None |
| Inventory movements | R/RPC adjustment | R/RPC adjustment | R; replacement RPC only | None | None |
| Suppliers/purchases | R/C/U/post/void | R/C/U/post/void | R | None | None |
| Maintenance | R/C/U/complete/void | R/C/U/complete/void | R/C/U/complete | R limited | None |
| Machine faults | R/C/U/resolve/void | R/C/U/resolve/void | R/C/U/resolve | C report/R limited if approved | None |
| Operational incidents | R/manage | R/manage | R/C | C and own/limited R | None |

Legend: R read, C create, U update. RPC indicates direct table writes should be denied.

### Membership safeguards

- A user cannot promote themselves through direct update.
- Admin cannot grant owner unless explicitly approved.
- The last active owner cannot be revoked, suspended, or demoted.
- Suspended/revoked membership grants no tenant access.
- Service role usage is restricted to trusted backend/import operations.

---

## 24. Integrity enforcement matrix

| Rule | PostgreSQL constraint | RLS | RPC/business transaction | Frontend UX |
|---|:---:|:---:|:---:|:---:|
| Unique machine code per account | Primary | No | No | Early duplicate message |
| Branch belongs to account | Composite FK | Tenant access | Resolve account | Filter branch list |
| Machine belongs to branch/account | Composite FK | Tenant access | Validate state | Filter selections |
| Shared catalog record validity | FK/check | Auth read/platform write | Optional trusted catalog workflow | Controlled selectors |
| Positive purchase quantity | Check | No | Posting recheck | Input validation |
| Nonnegative counter | Check | No | Submission validation | Numeric limits |
| Warning below critical | Check | No | Profile validation | Inline feedback |
| Lifecycle fields match tracking mode | Check | No | Replacement recheck | Conditional form |
| Counter chronological order | Limited | Access only | Primary | Warning preview |
| Counter regression/reset | Nonnegative only | Access only | Primary | Explain reset workflow |
| Duplicate counter submission | Unique idempotency/index | Access only | Primary | Disable duplicate submit |
| Correction preserves history | Status/FKs | Role access | Primary | Correction UI, reason |
| Stock sufficiency | Cannot be simple check | Access only | Primary under row lock | Display current balance |
| Component compatibility | FKs partly | Access only | Primary | Filter by profile |
| Replacement atomicity | FKs/checks partly | Execute access | Primary | Single action/progress |
| Purchase posting atomicity | FKs/checks partly | Execute access | Primary | Confirmation |
| Movement immutability | Revoke update/delete | Read/write policies | Reversal RPC | No edit/delete controls |
| Purchase/item same account/location | Composite FKs | Tenant access | Posting validation | Filter items |
| Error code matches machine model | FK insufficient | Tenant access | RPC/validation trigger | Model-filtered code list |
| Role permission | No | Primary row access | Recheck inside definer RPC | Hide/disable actions |
| Last active owner protection | No simple check | Restrict writes | Primary membership RPC | Confirmation/error |
| Valid timezone | Trigger/reference validation | No | Optional | IANA selector |
| Assessed incident loss policy | Basic numeric checks | Role access | Business validation if strict | Show calculation/override reason |

Frontend validation is never the sole integrity layer.

---

## 25. Legacy migration mapping

All legacy records initially map to one approved account, branch, physical machine, and inventory location. No migration occurs until those identities and cutover rules are approved.

### `click_history` → `counter_readings`

| Legacy | V1 |
|---|---|
| `total_clicks` | `reading_value` for total-impressions counter type |
| `date_str` / `date_for` | best validated `observed_at` |
| `operator` | matched `entered_by` or `entered_by_name_snapshot` |
| `daily_clicks` | not imported as authoritative; recompute |
| `id` | preserved in import mapping/log outside core schema or notes during controlled import |

Unknown timezone, duplicate dates, regressions, and edited history require staging review. Legitimate known resets become `counter_reset_events`; unexplained regressions are quarantined.

### `part_replacements` → lifecycle tables

- Normalize `part_name` to component and model profile.
- Create replacement event and installation for the designated machine.
- Map `replaced_at_click` to baseline value and link a matching reading when reliable.
- Map operator to Auth user or name snapshot.
- Preserve creation timestamp.
- Use a bootstrap/no-stock import mode; do not create historical inventory deductions unless reconciled.

Ambiguous part names, slots, timestamps, and whether a legacy “reset” was a real installation require manual mapping.

### `inventory_parts` → inventory opening balance

- Normalize part name to component.
- Create one inventory item at the designated location.
- Create one `opening_balance` movement equal to approved physical/cutover stock.
- Preserve legacy origin in reason/import metadata.

Do not reconstruct current stock by replaying legacy purchases and replacements because the old frontend used separate calls that could partially fail.

### `part_purchases` → purchases and purchase lines

- Normalize supplier text to account supplier.
- Create a posted historical purchase and one line per legacy row, or group only when invoice/date/supplier identity is certain.
- Map quantity, unit cost, stored total, and purchase date.
- Use IDR unless another currency is proven.
- Historical purchases before ledger cutover must not also inflate the opening balance.

Stored total versus recomputed total, tax inclusion, supplier duplicates, and receiving location require import rules.

### `error_logs` → faults or operational incidents

- Clear technical machine errors with reliable machine context may map to `machine_faults` using raw error text/code.
- Human Error and Print Test map to `operational_incidents`.
- Ambiguous Machine Error rows should default to imported operational incidents with legacy classification rather than inventing a technical fault.
- Customer, product, division, PIC, loss, cause, prevention, and resolution map to snapshot/detail fields.
- Free-text PIC maps to Auth user only with an approved identity match; otherwise preserve snapshot.
- Preserve assessed legacy loss; do not recompute punishment silently.

### Import reconciliation outputs

Before final import, produce counts and exception lists for:

- Unmapped component names.
- Unmapped suppliers and people.
- Duplicate/regressed counters.
- Ambiguous error classification.
- Invalid dates/currency/quantities.
- Opening balance differences from physical stock.

---

## 26. Recommended SQL migration sequence

This is future sequencing only; no SQL is created by this document.

1. Required PostgreSQL extensions, enums/domains or check strategy, timestamp helpers.
2. `profiles`, `accounts`, `account_memberships` and membership helper functions.
3. `branches` and tenant composite-key foundations.
4. Shared `manufacturers`, `machine_models`, `counter_types`.
5. Tenant `machines`.
6. `counter_readings`, `counter_reset_events`, indexes, then counter RPC contracts.
7. Shared `component_categories`, `components`, profiles.
8. `inventory_locations`, `suppliers`, `inventory_items`.
9. `purchases`, `purchase_lines`, `inventory_movements`, posting/reversal functions.
10. `component_installations`, `component_replacement_events`, replacement/void functions.
11. `maintenance_records`.
12. Shared `error_codes`, then `machine_faults`.
13. `operational_incidents`.
14. Proposed read views: inventory on hand and machine component health.
15. RLS policies and grants for every table/function; direct-write revocations.
16. Seed shared catalogs and initial account/branch/machine configuration.
17. Automated database integrity/RLS/RPC tests.
18. Legacy staging, dry-run transformation, reconciliation, and separately approved import.

Each migration stage should be independently reviewable and reversible before remote application.

---

## 27. CRUD screen map

### Administration

| Screen | Tables | Owner | Admin | Technician | Operator | Platform catalog admin |
|---|---|:---:|:---:|:---:|:---:|:---:|
| Accounts | accounts | Manage | Limited | View | Limited view | Support only |
| Branches | branches | Manage | Manage | View | View | No |
| Users & Memberships | profiles, memberships | Manage | Non-owner manage if approved | Limited view | Self | No |
| Manufacturers | manufacturers | View | View | View | View | Manage |
| Machine Models | machine_models | View | View | View | View | Manage |
| Machines | machines | Manage | Manage | View/limited status | View | No |
| Component Categories | component_categories | View | View | View | View | Manage |
| Components | components | View | View | View | View | Manage |
| Component Lifecycles / Profiles | profiles table for model components | View | View | View | View | Manage |
| Suppliers | suppliers | Manage | Manage | View | No | No |
| Inventory Locations | inventory_locations | Manage | Manage | View | Limited view | No |
| Inventory Items | inventory_items | Manage | Manage | View | Limited view | No |
| Error Codes | error_codes | View | View | View | View | Manage |

The “Component Lifecycles / Profiles” label refers to `machine_model_component_profiles`, not Auth profiles.

### Operational screens

| Screen | Main data | Owner | Admin | Technician | Operator |
|---|---|:---:|:---:|:---:|:---:|
| Dashboard | derived views and summaries | Full | Full | Operational | Assigned/limited |
| Machine Tracking | machines, counters | Full | Full | Full | View |
| Counter Entry | counter_readings | Submit/correct | Submit/correct | Submit/correct/reset | Submit |
| Component Health | health view | Full | Full | Full | View |
| Replacement | replacement RPC | Execute/void | Execute/void | Execute | No |
| Inventory | on-hand and movements | Full/adjust | Full/adjust | View | Limited/no access |
| Purchasing | purchases/lines | Full | Full | View | No |
| Maintenance | maintenance records | Full | Full | Create/complete | Limited view |
| Machine Faults | machine_faults | Full | Full | Create/resolve | Report/limited view if approved |
| Operational Incidents | incidents | Full | Full | Create/view | Create own/limited view |

The UI must not imply permissions are security. RLS and RPC authorization remain authoritative.

---

## 28. Deferred V2+ features

- Account-owned catalog records and account-specific profile overrides.
- Catalog merge, promotion, and deduplication workflows.
- Physical meter identity and meter epochs.
- Automatic IoT/device counter ingestion.
- Calendar-day lifecycle independent of a counter type.
- Serialized component assets and transfers between machines.
- Refurbishment and warranty workflows.
- Multi-component maintenance join table and structured work orders.
- Preventive maintenance scheduling and recurrence.
- Maintenance attachments and Supabase Storage metadata.
- Purchase requests, approvals, purchase orders, partial receiving, accounts payable, payments.
- FIFO, weighted-average inventory valuation, and formal accounting integration.
- Canonical cross-model error definitions and model mappings.
- Machine manuals, manual references, causes, diagnostic trees, procedures, and AI diagnosis.
- Predictive maintenance and failure forecasting.
- Materialized/cached health and inventory balances if proven necessary.
- Targeted universal security audit log.
- Direct machine-fault-to-operational-incident relationship if analytics requires it.
- Large-format-specific machine models, counters, and component behavior.

---

## 29. Remaining business decisions

1. Who receives platform/catalog-admin authority, and will it be managed in trusted Auth app metadata or a future internal admin system?
2. Are shared catalog screens read-only for tenant owners/admins, with catalog changes handled centrally?
3. May archived machine codes ever be reused, or are they reserved permanently?
4. Is serial uniqueness per account and model, per account and manufacturer, or only duplicate-warning in V1?
5. Which counter types are mandatory for every initial machine model?
6. How far back may operators enter readings, and who may correct them?
7. What evidence and roles are required to record a counter reset?
8. Can a reset be entered when exact before/after meter values are unknown, accepting unknown lifecycle health?
9. Are warning/critical thresholds definitively percentages of expected usage, with warning lower than critical?
10. Can technicians use `external_supply` or `no_stock_record`, or is that admin/owner only?
11. Is negative stock always forbidden, including administrative corrections?
12. Should each new branch automatically receive exactly one default branch-store location?
13. Are all V1 purchases tax-inclusive, and is IDR the only initial currency?
14. What policy governs voiding a purchase after its stock has already been consumed?
15. Should operators be allowed to report machine faults, or technicians and above only?
16. Who may see operational incident financial/responsibility details?
17. Must assessed incident loss equal the formula, or may authorized admins override it with a reason?
18. Is one primary component link on maintenance sufficient for V1?
19. Which designated account, branch, machine, location, and timezone own legacy data?
20. Are legacy hardcoded component lifetimes accepted starting values or subject to full review?
21. What is the authoritative physical inventory count and ledger cutover time?
22. Which legacy Machine Error rows are genuine technical faults?

---

## 30. Exact smallest implementation sequence

This sequence describes the smallest safe product build after schema approval.

1. Establish Auth profile, account, membership, and branch tenancy with RLS tests.
2. Seed shared manufacturer, machine-model, and counter-type catalogs through trusted administration.
3. Create machine master CRUD and one default total-impressions counter flow.
4. Add counter submission/correction/reset RPCs and historical counter reporting.
5. Seed component categories, CMYK-separated components, and model profiles for the first supported machine model.
6. Add active component installation bootstrap and the derived health query/view.
7. Add default branch inventory location, inventory items, opening balances, and on-hand view.
8. Add suppliers and draft/posted purchases with atomic stock receipt.
9. Add atomic component replacement, inventory consumption, lifecycle restart, and replacement void safeguards.
10. Add simplified maintenance records linked optionally to a component/replacement.
11. Add model-specific error codes and machine fault logging.
12. Add operational incident logging with role-limited financial visibility.
13. Complete cross-domain RLS, role matrix tests, idempotency tests, rollback tests, and reconciliation reports.
14. Perform a staging-only legacy dry run and obtain separate data-import approval.

Do not begin with advanced dashboards or legacy import. First prove tenancy, counters, ledger integrity, and the replacement transaction.

---

## IMPLEMENTATION APPROVAL CHECKLIST

SQL migration creation must not begin until every blocking item below is approved or explicitly deferred by the product/database owners.

### Schema scope

- [ ] Approve the exact 25-table V1 set.
- [ ] Approve inclusion of `counter_reset_events` and exclusion of meter/epoch tables.
- [ ] Approve exclusion of `maintenance_record_components`, `machine_model_error_codes`, and universal `audit_log`.
- [ ] Approve shared catalogs with no V1 account ownership/override mechanism.
- [ ] Approve digital A3 as the only modeled machine category behavior for V1.

### Tenancy and authorization

- [ ] Approve account → branch → machine tenancy.
- [ ] Approve multi-account memberships and the four roles.
- [ ] Approve the role permission and RLS matrices.
- [ ] Decide platform/catalog-admin identity and management mechanism.
- [ ] Approve last-owner, self-promotion, suspension, and membership invitation rules.
- [ ] Approve which operator data is branch-limited versus account-wide.

### Machine and catalogs

- [ ] Approve machine statuses, code uniqueness/reuse policy, and serial uniqueness scope.
- [ ] Approve shared manufacturer/model/component/error-code administration workflow.
- [ ] Approve initial machine models and canonical codes/names.

### Counters

- [ ] Approve initial counter types, units, and decimal scales.
- [ ] Approve backdating and correction permissions.
- [ ] Approve reset evidence, unknown-boundary behavior, and reset authorization.
- [ ] Approve effective-reading uniqueness and idempotency behavior.
- [ ] Approve that daily clicks are derived and never authoritative.

### Components and health

- [ ] Approve component kinds and profile tracking/replacement behaviors.
- [ ] Approve separate CMYK component rows.
- [ ] Approve initial model slots, compatible components, expected lives, counters, and thresholds.
- [ ] Confirm thresholds represent percentage used, not percentage remaining.
- [ ] Approve profile snapshot behavior for historical installations.
- [ ] Approve reset/correction-aware health calculation and `unknown` data-quality state.
- [ ] Approve `machine_component_health` view output contract.

### Inventory and purchasing

- [ ] Approve inventory location types and automatic default branch location behavior.
- [ ] Approve immutable movement types and sign rules.
- [ ] Decide whether negative stock is ever permitted.
- [ ] Approve `inventory_on_hand` view output contract.
- [ ] Approve V1 purchase as a completed receipt with no procurement/AP workflow.
- [ ] Approve tax, currency, numbering, posting, void, and dependent-stock rules.

### Replacement and maintenance

- [ ] Approve replacement RPC inputs, outputs, roles, locks, and idempotency.
- [ ] Decide who may perform non-stock/external-supply replacement.
- [ ] Approve replacement void/compensation rules.
- [ ] Approve one primary optional component and replacement link on maintenance.
- [ ] Approve maintenance statuses, cost visibility, completion, and void rules.

### Faults and incidents

- [ ] Approve model-specific duplicated error-code records instead of a join table.
- [ ] Approve fault severities, statuses, and operator reporting permission.
- [ ] Approve operational incident visibility by role.
- [ ] Approve penalty/assessed-loss formula and override policy.
- [ ] Confirm machine faults and operational incidents remain separate.

### Integrity, audit, and operations

- [ ] Approve composite tenant FKs and all listed unique/check constraints.
- [ ] Approve RPC-only writes for protected transactional operations.
- [ ] Approve master audit columns and transaction correction/void/reversal fields.
- [ ] Approve deferral of universal audit logging.
- [ ] Approve database test requirements for RLS, idempotency, concurrency, rollback, and cross-tenant isolation.

### Legacy import

- [ ] Name the designated legacy account, branch, machine, inventory location, and timezone.
- [ ] Approve component, supplier, and user mapping rules.
- [ ] Approve counter regression/reset exception handling.
- [ ] Approve the physical stock count and ledger cutover timestamp.
- [ ] Approve the rule that legacy inventory imports as opening balance.
- [ ] Approve error-log classification and ambiguous-record handling.
- [ ] Require staging dry-run counts and reconciliation before any production import.

### Final authorization gate

- [ ] Product owner approves functional scope and remaining decisions.
- [ ] Database owner approves table/constraint/RPC/view contracts.
- [ ] Security owner approves RLS and role boundaries.
- [ ] Operations owner approves inventory, replacement, maintenance, and correction workflows.
- [ ] Legacy-data owner approves mapping and reconciliation rules.
- [ ] Written authorization is given to begin SQL migration creation in a separate task.

Until this checklist reaches the agreed approval threshold, this document remains a design contract only.
