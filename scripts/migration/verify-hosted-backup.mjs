#!/usr/bin/env node
import { execFileSync } from 'node:child_process'
import { createHash } from 'node:crypto'
import { readFileSync } from 'node:fs'
import { IDENTITY } from './lib/m2-10a-engine.mjs'

const values=process.argv.slice(2)
const index=values.indexOf('--data-dump')
if(index<0||!values[index+1]) throw new Error('Usage: verify-hosted-backup.mjs --data-dump <public-data.sql>')
const path=values[index+1]
const dump=readFileSync(path,'utf8')
if(!dump.includes('COPY "public"."accounts"')||!dump.includes('COPY "public"."counter_readings"')) throw new Error('Recovery dump does not contain required public data domains')
const container='supabase_db_konica-tracker-next'
const tables=execFileSync('docker',['exec',container,'psql','-U','postgres','-d','postgres','-X','-At','-c',"select string_agg(format('%I.%I',schemaname,tablename),',') from pg_tables where schemaname='public'"],{encoding:'utf8'}).trim()
if(!tables) throw new Error('Disposable exact-schema database is unavailable')
const sql=`\\set ON_ERROR_STOP on
begin;
set local session_replication_role=replica;
truncate table ${tables} cascade;
${dump}
set local session_replication_role=origin;
select jsonb_build_object(
  'account',(select count(*) from public.accounts where id='${IDENTITY.accountId}' and name='Cipta Grafika'),
  'branch',(select count(*) from public.branches where id='${IDENTITY.branchId}' and name='Tuparev'),
  'machine',(select count(*) from public.machines where id='${IDENTITY.machineId}' and branch_id='${IDENTITY.branchId}'),
  'counters',(select count(*) from public.counter_readings where machine_id='${IDENTITY.machineId}'),
  'people',(select count(*) from public.operational_people where account_id='${IDENTITY.accountId}'),
  'assignments',(select count(*) from public.machine_component_assignments where machine_id='${IDENTITY.machineId}'),
  'lifecycles',(select count(*) from public.machine_component_lifecycles where machine_id='${IDENTITY.machineId}'),
  'replacements',(select count(*) from public.component_replacement_events where machine_id='${IDENTITY.machineId}'),
  'purchases',(select count(*) from public.inventory_purchases where account_id='${IDENTITY.accountId}'),
  'receipts',(select count(*) from public.inventory_receipts where account_id='${IDENTITY.accountId}'),
  'movements',(select count(*) from public.inventory_movements where account_id='${IDENTITY.accountId}'),
  'incidents',(select count(*) from public.operational_incidents where account_id='${IDENTITY.accountId}'),
  'graha_operational',(select count(*) from public.operational_incidents where branch_id='${IDENTITY.grahaId}')+(select count(*) from public.machine_component_lifecycles where branch_id='${IDENTITY.grahaId}')
)::text;
rollback;
`
const output=execFileSync('docker',['exec','-i',container,'psql','-U','postgres','-d','postgres','-X','-q','-t','-A','-v','ON_ERROR_STOP=1'],{input:sql,encoding:'utf8',maxBuffer:64*1024*1024}).trim()
const state=JSON.parse(output.split('\n').at(-1))
const expected={account:1,branch:1,machine:1,counters:3,people:3,assignments:28,lifecycles:30,replacements:2,purchases:2,receipts:2,movements:5,incidents:2,graha_operational:0}
if(Object.entries(expected).some(([key,value])=>state[key]!==value)) throw new Error(`Recovery verification mismatch: ${JSON.stringify(state)}`)
process.stdout.write(`${JSON.stringify({verified:true,method:'transactional restore into disposable exact-schema database followed by rollback',sha256:createHash('sha256').update(dump).digest('hex'),state},null,2)}\n`)
