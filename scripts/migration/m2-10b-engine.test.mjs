import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { buildApplySql, HOSTED_DEV_REF, IDENTITY, stagingDispositionRows } from './lib/m2-10a-engine.mjs'
import { assertHostedDevSafety, HOSTED_TARGET_STATE_SQL } from './lib/m2-10b-engine.mjs'

const manifest = JSON.parse(readFileSync(new URL('./manifests/m2-10b.json',import.meta.url)))

test('M2.10B disposition locks all 526 rows with zero unresolved approvals', () => {
  const rows = stagingDispositionRows()
  const totals = Object.fromEntries(Object.keys(manifest.disposition_totals).map((key)=>[key,rows.filter((row)=>row.disposition===key).length]))
  assert.equal(rows.length,526)
  assert.deepEqual(totals,manifest.disposition_totals)
  assert.equal(totals.MANUAL_REVIEW,0)
  assert.deepEqual(rows.filter((row)=>row.disposition==='APPROVED_EXCLUDE').map((row)=>row.legacy_id).sort(),[
    '54da82a6-b723-442c-880f-19215e1f35bb','82d3b425-7273-415d-8c49-338d92405962',
  ])
  assert.equal(rows.filter((row)=>row.legacy_table==='part_replacements'&&row.disposition==='ARCHIVE_ONLY').length,23)
  assert.equal(rows.filter((row)=>row.legacy_table==='inventory_parts'&&row.disposition==='ARCHIVE_ONLY').length,22)
})

test('hosted safety permits only the exact linked DEV project and hard blocks Production/unknown', () => {
  assert.equal(assertHostedDevSafety({targetType:'DEV',targetProjectRef:HOSTED_DEV_REF,linkedRef:HOSTED_DEV_REF,apply:true}),true)
  for(const targetType of ['PRODUCTION','STAGING',undefined]) assert.throws(()=>assertHostedDevSafety({targetType,targetProjectRef:HOSTED_DEV_REF,linkedRef:HOSTED_DEV_REF,apply:true}),/not DEV/)
  assert.throws(()=>assertHostedDevSafety({targetType:'DEV',targetProjectRef:'unknown',linkedRef:HOSTED_DEV_REF,apply:true}),/exact DEV project ref/)
  assert.throws(()=>assertHostedDevSafety({targetType:'DEV',targetProjectRef:HOSTED_DEV_REF,linkedRef:'unknown',apply:true}),/exact DEV project ref/)
})

test('hosted transaction omits psql meta commands and preserves no-stock purchase contract', () => {
  const sql=buildApplySql({operations:{people:[],counters:[],lifecycles:[],suppliers:[],items:[],purchases:[],incidents:[]}},{dryRun:true,psqlMeta:false})
  assert.equal(sql,'begin;\nrollback;\n')
  assert.doesNotMatch(sql,/\\set|\bdelete\b|\btruncate\b|\bdrop\b/i)
})

test('hosted target fingerprint covers locked identity, Graha, auth count, stock, lifecycle and cost evidence', () => {
  for(const value of [IDENTITY.accountId,IDENTITY.branchId,IDENTITY.machineId,IDENTITY.grahaId]) assert.match(HOSTED_TARGET_STATE_SQL,new RegExp(value))
  for(const domain of ['auth_user_count','graha_counts','balances','fifo_lots','lifecycles','replacements','operating_costs']) assert.match(HOSTED_TARGET_STATE_SQL,new RegExp(domain))
})
