# M2.10B Hosted DEV Reconciliation

Execution: `958bb2b3-3110-410f-9c03-d8355a2f9be7`
Target: Supabase DEV `sxitqjxljoqsnpepymrl`
Identity: Cipta Grafika / Tuparev / `CG-TUP-A3-01`

## Source and disposition

- Source: 526 rows across the five approved tables.
- IMPORT: 476; MERGE: 1; SKIP_DUPLICATE: 2; ARCHIVE_ONLY: 45; APPROVED_EXCLUDE: 2; MANUAL_REVIEW: 0.
- Unexplained remainder: 0; unresolved staging approvals: 0.

## First apply

- Window: `2026-08-29T21:00:48.718Z`–`2026-08-29T21:00:58.799Z`.
- Fingerprint: `5bae4aff63e1ab38d59421c0a758406307b8f2d23ed9751e63f724ffea835dfb` → `200a579fdab0b4425545e5aa24c1b0f5f48429108b020d87def9714406317292`.
- Created: 10 People, 179 counters, 47 lifecycles, 161 purchases, 89 incidents, 9 suppliers, and 4 Inventory Items.
- Reused: 18 Inventory Items; merged: 1 counter; skipped expected differences: 49; failed: 0.

## Second apply

- Window: `2026-08-29T21:01:14.616Z`–`2026-08-29T21:01:28.861Z`.
- Fingerprint remained `200a579fdab0b4425545e5aa24c1b0f5f48429108b020d87def9714406317292`.
- Created: 0; all eligible evidence already present; merged: 1 counter; skipped expected differences: 49; failed: 0.

## Integrity assertions

- Purchases: 161 rows, 208 units, IDR 371,029,998 acquisition value.
- Legacy purchase effects: 0 receipts, 0 movements, 0 FIFO layers, 0 stock increase.
- Legacy opening stock: 0.
- Incidents: 89 imported, IDR 455,962.36 assessed loss.
- Synthetic-time counters: 27; approved excluded counters: 2.
- Verified closed lifecycle intervals: 47; five ambiguous replacements remain archive-only.
- Existing target lifecycle rows preserved: 30 (77 total after apply).
- Auth identities created: 0; aggregate legacy Machine Cost rows created: 0.
- Graha legacy leakage: 0.

Machine-readable evidence is in the sibling `first-apply` and `second-apply` directories. Each contains the manifest, crosswalk, Expected Difference Register, mutation summary, target snapshots, and reconciliation output.
