#!/usr/bin/env node
import { writeFile } from 'node:fs/promises'
import { fetchLegacyData } from './legacy-audit.mjs'

const outputIndex = process.argv.indexOf('--output')
if (outputIndex < 0 || !process.argv[outputIndex + 1]) throw new Error('Usage: capture-legacy-snapshot.mjs --output <path> (GET-only)')
const url = process.env.LEGACY_SUPABASE_URL; const key = process.env.LEGACY_SUPABASE_ANON_KEY
if (!url || !key) throw new Error('Set LEGACY_SUPABASE_URL and LEGACY_SUPABASE_ANON_KEY; neither is printed or stored in the snapshot')
const data = await fetchLegacyData({ url, key })
await writeFile(process.argv[outputIndex + 1], `${JSON.stringify({ source_project: 'wtslqxjwjqyjgcapfrrz', captured_at: new Date().toISOString(), tables: data }, null, 2)}\n`, { mode: 0o600 })
process.stdout.write(`Read-only legacy snapshot captured: ${Object.values(data).reduce((sum, rows) => sum + rows.length, 0)} rows.\n`)
