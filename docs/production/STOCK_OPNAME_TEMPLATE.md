# Physical Stock Opname Manifest

Classification: `PRODUCTION_PHYSICAL_STOCK_OPNAME`

Cutover execution UUID: `TBD`

Account stable identity / resolved ID: `TBD / TBD`

Branch stable identity / resolved ID: `Tuparev / TBD`

Location stable identity / resolved ID: `CG Digital Print / TBD`

Candidate historical location ID (do not copy blindly): `b6296488-5479-4dd0-9463-091891b4cbe4`

Approval status: `PENDING`
Approved by / at: `TBD / TBD`

Complete one row per physical item. Do not derive counts from the legacy 22-row/11-unit display snapshot and do not copy rehearsal quantities.

| Inventory Item ID | Display name | SKU | Component ID/link | Branch | Location | Counted quantity | Unit | Cost evidence | Cost status | Unit cost | Source/counterparty | Counted by | Verified by | Timestamp | Approval | Notes |
|---|---|---|---|---|---|---:|---|---|---|---:|---|---|---|---|---|---|
| TBD | TBD | TBD | null permitted | Tuparev | TBD | TBD | TBD | TBD | `KNOWN`/`UNKNOWN` | null when unknown | TBD | TBD | TBD | TBD | PENDING | TBD |

Rules: quantities must be physical counts; `UNKNOWN` cost stores `NULL`, never Rp0; each item/location has one idempotent opening operation; IDs must resolve against the exact Production target; every row needs counter-signature and manifest approval. Other Part and generic Drum Unit, Charging Corona, and Developing Unit remain unlinked unless exact identity is proven.
