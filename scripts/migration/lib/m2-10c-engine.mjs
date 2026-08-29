import { readFileSync } from 'node:fs'
import { deterministicFingerprint } from '../legacy-audit.mjs'
import { HOSTED_DEV_REF } from './m2-10a-engine.mjs'

export const CLEANUP_EXECUTION_ID = '4acccbb8-4429-4a15-95cc-12c3e1e52ae6'
export const CLEANUP_IDS = Object.freeze({
  purchases: ['aaecec6a-6ee4-4c6c-a535-30dbeda2d1dd','74526bbf-4554-47c6-89c9-d2304c0ffa69'],
  purchaseLines: ['5d7fcc82-e308-479e-ba59-4541fc0f9f1b','2f15065a-0745-415f-b0ad-6bb34ce3205f'],
  receipts: ['4bbdc220-f8fd-42cd-8cab-1e9b7cc19cbc','5ec56801-53c0-4b63-b382-b1c15bc7680d'],
  receiptLines: ['50b7313a-5128-4cc9-b0fa-9f1b724ab425','f650d7ad-9a3c-4dec-9327-bfa40679f82c'],
  movements: ['bccd98d8-0ebc-462c-8f2e-e51e1f73f56e','40060e2d-b4bb-4324-b660-23f49023be21','244ff6bb-6221-486d-8df3-fe2bba5b9665','7cbe8df1-1d33-4e06-b2ab-f735839abeeb','59c5c076-b8b6-42a6-8fba-463a5c75b394'],
  lots: ['80d2f995-893e-43af-88f2-7208407d6707','5edfcd16-a5a4-4af5-b9fe-166595cbc992','945ef4b9-42f3-49d4-a630-ddaba0e72b76'],
  allocations: ['c1ef8c31-f2d4-4c0c-9e43-31ec1b9e7992','e3f31911-8599-455a-a84f-66ee2aa8b2d8'],
  replacements: ['392ffdb5-7ccf-4304-9d9c-db9727a73cfd','d063cc89-ec0d-4164-8746-bf2618cdedb5'],
  dummyLifecycles: ['1472d84f-54bc-452c-9d01-05fe66005a35','ea382ac6-3b06-48be-a69f-b649a209df24'],
  restoredLifecycles: ['07c1752e-db9b-463c-b8ad-ddb0c718cadd','a3be3d31-0980-495a-ab9a-0397c32a638d'],
  itemsPreserved: ['83edc69c-4c86-42f4-8e91-a18be0fdf758','f5e24ff8-e1d1-4de5-9cf9-496a6f5ccac7'],
  location: 'b6296488-5479-4dd0-9463-091891b4cbe4',
})

export const removalIds = () => new Set(Object.entries(CLEANUP_IDS)
  .filter(([key, value]) => Array.isArray(value) && !['restoredLifecycles','itemsPreserved'].includes(key))
  .flatMap(([, value]) => value))

export function loadProtectedIds(path) {
  const artifact = JSON.parse(readFileSync(path, 'utf8'))
  const protectedIds = new Set()
  for (const row of artifact.rows ?? []) {
    if (row.target_id) protectedIds.add(row.target_id)
    if (row.actor_target_id) protectedIds.add(row.actor_target_id)
  }
  return protectedIds
}

export function protectedIntersection(protectedIds, candidates = removalIds()) {
  return [...candidates].filter((id) => protectedIds.has(id)).sort()
}

export function assertCleanupSafety({ targetType, targetProjectRef, linkedRef, apply, executionId, suppliedIds, protectedIds }) {
  if (targetType !== 'DEV') throw new Error(`M2.10C cleanup refused: ${targetType || 'UNKNOWN'} is not DEV`)
  if (targetProjectRef !== HOSTED_DEV_REF || linkedRef !== HOSTED_DEV_REF) throw new Error('M2.10C cleanup refused: exact linked DEV project required')
  if (executionId !== CLEANUP_EXECUTION_ID) throw new Error('M2.10C cleanup refused: execution UUID mismatch')
  const expected = [...removalIds()].sort()
  if (JSON.stringify([...new Set(suppliedIds ?? [])].sort()) !== JSON.stringify(expected)) throw new Error('M2.10C cleanup refused: exact dummy target IDs required')
  const intersection = protectedIntersection(protectedIds)
  if (intersection.length) throw new Error(`M2.10C cleanup refused: protected intersection ${intersection.join(',')}`)
  if (apply !== true && apply !== false) throw new Error('M2.10C cleanup refused: explicit apply mode required')
  return true
}

const sqlIds = (values) => values.map((id) => `'${id}'::uuid`).join(',')

export function buildCleanupSql({ dryRun }) {
  const c = CLEANUP_IDS
  return `begin;
set local session_replication_role = replica;
do $guard$
begin
  if (select count(*) from public.inventory_purchases where id in (${sqlIds(c.purchases)})) <> 2 then raise exception 'purchase provenance changed'; end if;
  if (select count(*) from public.inventory_purchases where notes like '%LEGACY_IMPORT%') <> 161 then raise exception 'legacy purchase protected count changed'; end if;
  if exists(select 1 from public.inventory_purchases where id in (${sqlIds(c.purchases)}) and purchase_number not in ('PUR-202608-0001','PUR-202608-0002')) then raise exception 'purchase identity mismatch'; end if;
  if (select count(*) from public.component_replacement_events where id in (${sqlIds(c.replacements)})) <> 2 then raise exception 'replacement provenance changed'; end if;
end $guard$;
delete from public.inventory_cost_allocations where id in (${sqlIds(c.allocations)});
delete from public.component_replacement_events where id in (${sqlIds(c.replacements)});
delete from public.machine_component_lifecycles where id in (${sqlIds(c.dummyLifecycles)});
update public.machine_component_lifecycles
set status='active', removed_counter=null, removed_at=null, actual_usage=null
where id in (${sqlIds(c.restoredLifecycles)});
delete from public.inventory_cost_lots where id in (${sqlIds(c.lots)});
delete from public.inventory_receipt_lines where id in (${sqlIds(c.receiptLines)});
delete from public.inventory_movements where id in (${sqlIds(c.movements)});
delete from public.inventory_receipts where id in (${sqlIds(c.receipts)});
delete from public.inventory_purchase_lines where id in (${sqlIds(c.purchaseLines)});
delete from public.inventory_purchases where id in (${sqlIds(c.purchases)});
do $reconcile$
begin
  if exists(select 1 from public.inventory_purchases where id in (${sqlIds(c.purchases)})) then raise exception 'dummy purchases survived'; end if;
  if exists(select 1 from public.inventory_movements where id in (${sqlIds(c.movements)})) then raise exception 'dummy movements survived'; end if;
  if (select count(*) from public.inventory_purchases where notes like '%LEGACY_IMPORT%') <> 161 then raise exception 'legacy purchases changed'; end if;
  if exists(select 1 from public.inventory_stock_balances where inventory_item_id in (${sqlIds(c.itemsPreserved)}) and location_id='${c.location}'::uuid and quantity <> 0) then raise exception 'affected stock projection is not zero'; end if;
  if exists(select 1 from public.machine_component_lifecycles where id in (${sqlIds(c.restoredLifecycles)}) and (status <> 'active' or removed_at is not null)) then raise exception 'prior lifecycle restore failed'; end if;
end $reconcile$;
set local session_replication_role = origin;
${dryRun ? 'rollback;' : 'commit;'}
`
}

export function cleanupFingerprint(state) { return deterministicFingerprint(state) }

export const CLEANUP_DEPENDENCY_ORDER = [
  'inventory_cost_allocations', 'component_replacement_events',
  'dummy machine_component_lifecycles', 'restore previous lifecycles',
  'inventory_cost_lots', 'inventory_receipt_lines', 'inventory_movements',
  'verify inventory_stock_balances projection', 'inventory_receipts',
  'inventory_purchase_lines', 'inventory_purchases',
]
