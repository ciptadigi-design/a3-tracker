# Production GO / NO-GO Checklist

All boxes are mandatory unless explicitly marked as a scheduled-value gate.

- [ ] Dedicated Production Supabase and Vercel projects provisioned and independently identified.
- [ ] Exact deploy SHA has green build, clean reconstruction, pgTAP, schema lint, migration, and rehearsal tests.
- [ ] Production ledger exactly ends at `20260830000100`; schema/RLS/functions/storage/Auth audit passes.
- [ ] Real Account/Branch/Machine/master/profile crosswalk approved; 28 profile slots and assignments are unique.
- [ ] Production users and explicit UUID-bound Platform Superuser strategy approved; no DEV test accounts copied.
- [ ] Freeze/cutover/database/application/business/rollback owners named.
- [ ] Legacy writes disabled and independently confirmed before a fresh complete snapshot.
- [ ] Final per-table source fingerprints match the approved manifest at apply time.
- [ ] Every fresh source row has an approved disposition; unexplained remainder is zero.
- [ ] Production target fingerprint is unchanged after backup/preflight.
- [ ] Native backup/PITR availability is truthfully recorded and logical backup restore is proven.
- [ ] Physical stock manifest is complete, mapped, hashed, reviewed, and approved; unknown cost stays unknown.
- [ ] Apply uses Production ref, production flag, execution UUID, backup/freeze/stock gates, and human token.
- [ ] Reconciliation passes with zero Graha leakage and no legacy-purchase receipt/stock/FIFO.
- [ ] Signed-in smoke, responsive, pagination, Machine Cost, PIC, Settings, and Branch-isolation checks pass.
- [ ] Business acceptance owner approves and rollback owner records GO.

Automatic NO-GO/rollback triggers: source fingerprint/disposition mismatch; unexplained target change or row loss; FK/constraint failure; Graha leakage; counter chronology corruption; lifecycle collision; purchases creating stock; stock quantity/cost mismatch; login/Auth failure; major frontend/runtime/API failure; reconciliation mismatch; or business acceptance rejection.

Current M2.11 classification is **CONDITIONAL** because Production infrastructure, named owners/window, actual frozen snapshot, physical counts/approval, domain choice, and explicit Production apply approval remain outstanding.
