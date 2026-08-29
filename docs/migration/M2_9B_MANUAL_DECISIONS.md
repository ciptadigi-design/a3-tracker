# M2.9B Manual Decisions

| Decision ID | Domain / affected rows | Evidence and recommendation | Alternative / risk | Approver | Status |
|---|---|---|---|---|---|
| MIG-ADMIN-001 | Complete legacy catalog/Auth/private Storage | LEGACY absent from authorized CLI org; accept bounded limitation only after confirming five UI tables are authoritative | Obtain owner/admin read access; risk is hidden business data | Owner | PENDING |
| MIG-COUNTER-001 | `54da82a6-…`, `82d3b425-…` | POSSIBLE_DUPLICATE; review source/target timestamps and keep distinct by default | Merge only with business confirmation; risk double event vs lost evidence | Operations owner | PENDING |
| MIG-PIC-001 | normalized `oan`, `sri bulan` | Confirm Akmal/Bulan aliases; otherwise preserve snapshot with null person ID | Create separate Person; risk misattribution | Operations owner | PENDING |
| MIG-PIC-002 | Epri, Bigel, Bulan, Daniel, Dhea, Lydia, Marsya, Nabilah, Pinkan, Ramdani | Approve bounded `CREATE_PERSON` list, Tuparev eligibility only, no Auth | Archive text only; risk unnecessary masters vs weak reporting | Operations owner | PENDING |
| MIG-COMPONENT-001 | replacements `e8e26ec3-…`, `d959e9d2-…` | Charging Corona lacks color/slot; archive event unless physical slot proven | Choose C/M/Y/K; risk corrupt lifecycle | Machine owner | PENDING |
| MIG-COMPONENT-002 | replacements `2fb16092-…`, `41342cc0-…` | Drum Unit lacks color/slot; archive event unless physical slot proven | Choose C/M/Y/K; risk corrupt lifecycle | Machine owner | PENDING |
| MIG-COMPONENT-003 | replacement `73543d0f-…` | Fuser Unit likely FUSER_BELT but label alone is insufficient; approve mapping or archive | Arbitrary map risks false lifecycle | Machine owner | PENDING |
| MIG-COMPONENT-004 | purchase `301`, inventory `47` | Other Part is unidentified; archive purchase text/value and exclude stock unless identified | Create unidentified noncanonical item; never TEST_COMPONENT | Finance/inventory owner | PENDING |
| MIG-LIFECYCLE-001 | 18 first-per-slot boundaries | Keep ARCHIVE_ONLY; no predecessor start. Approve audit-artifact retention as sufficient evidence | Add reviewed legacy evidence domain separately; risk fabricated history | Machine owner | PENDING |
| MIG-PURCHASE-001 | all 161 purchases | Approve historical acquisition representation; no receipt/movement/FIFO. Prefer dedicated historical representation | `draft` + explicit notes is least assertive current enum but may look outstanding | Finance owner | PENDING |
| MIG-STOCK-001 | all 22 inventory rows | Perform physical stock opname at cutover; fill count, location `b629…`, cost, reviewer, approval | Ignore mutable snapshot; risk wrong opening stock | Inventory owner | PENDING |
| MIG-FREEZE-001 | final source | Name owner/window and use full write freeze/fresh snapshot/fingerprint | Timestamp delta forbidden; risk missed edits/deletes | Owner | PENDING |

Approved and closed: Account Cipta Grafika; Branch Tuparev; Machine `CG-TUP-A3-01`; Graha receives NONE.
