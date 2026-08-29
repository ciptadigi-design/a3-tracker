import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { HOSTED_DEV_REF } from './lib/m2-10a-engine.mjs'
import { assertCleanupSafety, buildCleanupSql, CLEANUP_EXECUTION_ID, CLEANUP_IDS, loadProtectedIds, protectedIntersection, removalIds } from './lib/m2-10c-engine.mjs'

const crosswalk = new URL('./reconciliation/m2-10b/958bb2b3-3110-410f-9c03-d8355a2f9be7/first-apply/crosswalk.json', import.meta.url)
const protectedIds = loadProtectedIds(crosswalk)
const suppliedIds = [...removalIds()]

test('M2.10B target identities form a protected set disjoint from exact dummy cleanup IDs', () => {
  assert.equal(protectedIds.size, 490)
  assert.deepEqual(protectedIntersection(protectedIds), [])
})

test('cleanup permits only exact linked DEV and exact known IDs', () => {
  const valid = { targetType:'DEV', targetProjectRef:HOSTED_DEV_REF, linkedRef:HOSTED_DEV_REF, apply:true, executionId:CLEANUP_EXECUTION_ID, suppliedIds, protectedIds }
  assert.equal(assertCleanupSafety(valid), true)
  assert.throws(() => assertCleanupSafety({...valid,targetType:'PRODUCTION'}), /not DEV/)
  assert.throws(() => assertCleanupSafety({...valid,targetType:'UNKNOWN'}), /not DEV/)
  assert.throws(() => assertCleanupSafety({...valid,targetProjectRef:'wrong'}), /exact linked DEV/)
  assert.throws(() => assertCleanupSafety({...valid,suppliedIds:suppliedIds.slice(1)}), /exact dummy target IDs/)
})

test('cleanup transaction follows reverse dependencies, rolls dry-run back, and preserves legacy evidence', () => {
  const sql = buildCleanupSql({dryRun:true})
  assert.match(sql,/begin;[\s\S]*inventory_cost_allocations[\s\S]*component_replacement_events[\s\S]*inventory_purchases[\s\S]*rollback;/)
  assert.match(sql,/LEGACY_IMPORT%'\) <> 161/)
  assert.match(sql,/set local session_replication_role = replica/)
  assert.doesNotMatch(sql,new RegExp(CLEANUP_IDS.itemsPreserved.map((id)=>`delete from public.inventory_items.*${id}`).join('|'),'i'))
})

test('cleanup SQL contains every exact candidate and restores, rather than deletes, prior lifecycles', () => {
  const sql=buildCleanupSql({dryRun:false})
  for(const id of suppliedIds) assert.match(sql,new RegExp(id))
  for(const id of CLEANUP_IDS.restoredLifecycles) assert.match(sql,new RegExp(`update public.machine_component_lifecycles[\\s\\S]*${id}`))
  assert.match(sql,/commit;/)
})

test('historical artifacts remain explicit and machine-readable', () => {
  const artifact=JSON.parse(readFileSync(crosswalk,'utf8'))
  assert.equal(artifact.rows.length,526)
})
