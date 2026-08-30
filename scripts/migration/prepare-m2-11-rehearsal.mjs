#!/usr/bin/env node
import { execFileSync } from 'node:child_process'
import { readFileSync } from 'node:fs'

const root = new URL('../../', import.meta.url)
const container = 'supabase_db_konica-tracker-next'
const args = process.argv.slice(2)
if (args.length !== 1 || args[0] !== '--reset-disposable') throw new Error('Explicit --reset-disposable is required')

const original = readFileSync(new URL('./fixtures/m2-10a-target-baseline.sql', import.meta.url), 'utf8')
const fixture = original
  .replaceAll('M2.10A', 'M2.11 NON_PRODUCTION_REHEARSAL')
  .replaceAll('m210a-owner@test.invalid', 'm211-rehearsal-owner@test.invalid')
  .replaceAll('M2.10A Fixture Owner', 'M2.11 Rehearsal Owner')
  .replace("<> '20260829000600'", "<> '20260830000100'")
  .replace(/\ninsert into public\.counter_readings[\s\S]*?\n\ncommit;/, '\n\ncommit;')

execFileSync('supabase', ['db', 'reset'], { cwd: root, stdio: 'inherit' })
execFileSync('docker', ['exec', '-i', container, 'psql', '-U', 'postgres', '-d', 'postgres', '-X', '-v', 'ON_ERROR_STOP=1'], { input: fixture, stdio: ['pipe', 'inherit', 'inherit'] })
process.stdout.write('M2.11 disposable Production-like baseline ready: current ledger, deterministic Account/Branches/Machine, 28 assignments, zero operational evidence.\n')
