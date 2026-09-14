# M2.20F operational time, inventory consumption, and currency contract

This is the canonical contract for the Indonesia-first controlled pilot. It does not reinterpret or rewrite historical timestamps.

## Operational timestamp input

For a user-entered local date and time, the frontend must resolve the operational IANA timezone from domain context and convert the pair to an ISO 8601 UTC instant before transport. A browser timezone is never an input to that conversion. The backend stores actual instants in UTC.

Timezone resolution is `machine -> branch -> account -> UTC` for machine operations. Inventory operations use the inventory location's branch, then account, then UTC; they do not inherit an unrelated machine timezone. Branch-only incidents use branch, then account, then UTC.

Valid account, branch, and machine timezone identifiers come from the platform IANA timezone database. Laravel validates with `timezone:all`; Supabase validates against `pg_timezone_names`. The three pilot zones (`Asia/Jakarta`, `Asia/Makassar`, and `Asia/Jayapura`) and other valid IANA zones remain supported.

| Domain | Input type | Timezone source | Transport format | Storage format | Display timezone | Report local date |
| --- | --- | --- | --- | --- | --- | --- |
| Opening stock | Local datetime | location branch -> account -> UTC | UTC ISO instant | UTC timestamp | location branch/account | location branch/account |
| Counter entry/correction | Local datetime | machine -> branch -> account -> UTC | UTC ISO instant | UTC timestamp | resolved machine timezone | resolved machine timezone |
| Incident create/update occurrence | Local datetime | selected machine, otherwise branch -> account -> UTC | UTC ISO instant | UTC timestamp | resolved incident context | resolved incident context |
| Incident resolution/status audit | Server-generated timestamp | server UTC | UTC instant | UTC timestamp | resolved incident context | resolved incident context |
| Replacement | Local datetime | machine -> branch -> account -> UTC | UTC ISO instant | UTC replacement and matching ledger timestamps | resolved machine timezone | resolved machine timezone |
| Receipt | Local datetime | receipt location branch -> account -> UTC | UTC ISO instant | UTC receipt and matching ledger timestamps | receipt location branch/account | receipt location branch/account |
| Purchase date | Date only | none | `YYYY-MM-DD` | database date | unchanged business date | unchanged business date |
| Manual adjustment/transfer | Server-generated timestamp | server UTC | no user datetime | UTC timestamp | location branch/account | location branch/account |
| One-time machine operating cost | Local datetime | machine -> branch -> account -> UTC | UTC ISO instant | UTC timestamp | resolved machine timezone | resolved machine timezone |
| Recurring cost/selling-price period | Date only | resolved machine context for evaluation | `YYYY-MM-DD` | database date | unchanged business date | resolved machine timezone |
| Report filters | Date only inclusive range | per-row machine context; branch/account for branch-only or inventory records | `YYYY-MM-DD` start/end | not stored | active operational context | buffered bounded UTC SQL range plus exact local-date filtering |
| Click targets/calendar exceptions | Date only | operational context only when evaluating the date | `YYYY-MM-DD` | database date | unchanged business date | resolved machine timezone |

Date-only values must not be converted to UTC midnight. Historical rows that were created through a browser-dependent `datetime-local` conversion may be ambiguous; correcting them requires a separately reviewed evidence-based remediation. M2.20F performs no backfill.

## Inventory consumption report

Inventory Consumption includes only an actual `replacement_consumption` inventory movement whose reference type is `component_replacement` and which is joined through `component_replacements.inventory_movement_id`. The replacement supplies the machine/component attribution and operational occurrence time; the outbound movement and its FIFO allocations supply stock and cost provenance.

The query enforces account scope, the authorized branch allowlist, optional selected branch and machine scope, and a bounded UTC superset before exact machine-local date filtering. Receipts, opening stock, transfers, and manual adjustments are excluded. Each replacement/movement pair produces one row. Machine Cost may aggregate the same consumed cost; it need not equal Inventory Consumption when the selected Machine Cost metric includes additional cost categories.

## Currency

The controlled pilot supports only `IDR`. Account `default_currency` and purchase `currency_code` remain in the schema for compatibility, but API validation rejects every other value with normal validation semantics. Money remains stored in existing decimal columns without FX conversion. The UI uses `Rp`/`IDR` and exposes no currency selector. Monetary report/export headers identify IDR. No exchange-rate or multi-currency engine exists.

CSV formula neutralization is not included: M2.20F does not otherwise touch report-export encoding, so that separate hardening finding should remain independently scoped.
