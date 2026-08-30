# M2.10C Dummy Cleanup Preview

- Target: Supabase DEV `sxitqjxljoqsnpepymrl`
- Account / Branch / Machine: Cipta Grafika / Tuparev / CG-TUP-A3-01
- Protected M2.10B IDs: 490
- Protected intersection: 0
- Execution: `4acccbb8-4429-4a15-95cc-12c3e1e52ae6`

| Domain | Count | Identity / effect | Classification |
|---|---:|---|---|
| Purchases | 2 | `PUR-202608-0001`, `PUR-202608-0002` | User-created acceptance evidence; exact UUID/client provenance |
| Purchase lines | 2 | Cyan and Yellow one-unit lines | Exclusive child of the two dummy purchases |
| Receipts / receipt lines | 2 / 2 | One posted receipt per purchase | Exclusive child of the dummy purchase lines |
| Movements | 5 | 2 receipts, 2 issues, Cyan +4 opening | Exact receipt/replacement references; opening lot feeds Cyan dummy replacement |
| FIFO lots / allocations | 3 / 2 | Cyan opening + Cyan/Yellow receipt lots | Exclusive cost/consumption dependencies |
| Replacements | 2 | Cyan `392ffdb5…`, Yellow `d063cc89…` | Acceptance replacements tied to dummy issues |
| Dummy active lifecycles | 2 | Cyan `1472d84f…`, Yellow `ea382ac6…` | Created by dummy replacements |
| Previous lifecycles restored | 2 | Cyan `07c1752e…`, Yellow `a3be3d31…` | Preserve installation evidence; clear dummy removal only |
| Inventory Items | 0 deleted | Cyan and Yellow items retained | Reused by 55 protected M2.10B legacy purchase lines |

Expected Machine Cost effect: remove the Rp1,625,000 known Yellow consumption and the unknown-cost Cyan acceptance event. Total Clicks and Error/Waste must remain unchanged. Final figures come from post-cleanup database evidence.

Dependency order: allocations → replacement events → dummy lifecycles → restore previous lifecycles → lots → receipt lines → movements → verify derived stock → receipts → purchase lines → purchases. The exact transaction has already passed in rollback mode against a restored hosted-data copy.
