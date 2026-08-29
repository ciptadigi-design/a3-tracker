# M2.9C User Approvals

No approval is required to begin M2.10A against a disposable exact-schema database; its 29 unresolved rows are explicitly excluded.

Before hosted staging:

| ID | Recommended answer | If accepted | If rejected |
|---|---|---|---|
| MIG-ADMIN-001 | Accept five-table operational scope if admin inventory remains unavailable | Small risk of undiscovered hidden non-UI data | Wait for legacy owner/admin access |
| MIG-COUNTER-001 | Keep both possible duplicates excluded unless business evidence identifies them | Avoids uncertain duplicate/zero-usage rows | Import distinct, accepting possible duplicate evidence |
| MIG-PIC-001 | Map `oan` to Akmal; leave `sri bulan` snapshot-only pending confirmation | Better reporting with bounded alias risk | Keep both Person links null |
| MIG-PIC-002 | Approve 10 historical People: Epri, Bigel, Bulan, Daniel, Dhea, Lydia, Marsya, Nabilah, Pinkan, Ramdani | Creates People only—no Auth/login | Keep snapshots only; weaker reporting |
| MIG-COMPONENT-001/002/003 | Keep five generic replacements excluded unless their physical slots are proven | No fabricated lifecycle evidence | Supply reviewed slot mappings |
| MIG-PURCHASE-001 | Approve historical purchases as `draft` with explicit legacy/receipt-unknown notes, or request a narrow historical-status design | Preserves acquisition reporting without stock | Archive purchases outside domain tables |

Before Production only:

| ID | Recommended answer | If accepted | If rejected |
|---|---|---|---|
| MIG-STOCK-001 | Complete physical stock opname and approve opening manifest | Creates verified opening stock | Production starts without migrated opening stock |
| MIG-FREEZE-001 | Name freeze owner/window and approve full snapshot protocol | Protects final reconciliation | Production migration remains no-go |
