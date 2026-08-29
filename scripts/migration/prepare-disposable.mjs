#!/usr/bin/env node
import { execFileSync } from 'node:child_process'
import { readFileSync } from 'node:fs'

const root = new URL('../../', import.meta.url)
const container = 'supabase_db_konica-tracker-next'
const run = (file) => execFileSync('docker', ['exec','-i',container,'psql','-U','postgres','-d','postgres','-X','-v','ON_ERROR_STOP=1'], { input: readFileSync(new URL(file, import.meta.url)), stdio: ['pipe','inherit','inherit'] })

if (process.argv.slice(2).some((arg) => arg !== '--reset')) throw new Error('Usage: node scripts/migration/prepare-disposable.mjs --reset')
if (!process.argv.includes('--reset')) throw new Error('Explicit --reset is required; this command only resets the local disposable Supabase database')
execFileSync('supabase', ['db','reset'], { cwd: root, stdio: 'inherit' })
run('./fixtures/m2-10a-target-baseline.sql')
run('../../supabase/bootstrap/dev_c1070_legacy_lifecycles.sql')
run('./fixtures/m2-10a-target-collisions.sql')
process.stdout.write('M2.10A disposable target ready: ledger 20260829000600, 28 assignments, 30 lifecycles, 2 accepted replacement chains.\n')
