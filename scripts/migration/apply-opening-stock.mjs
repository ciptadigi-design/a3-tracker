#!/usr/bin/env node
import { readFileSync } from 'node:fs'
import { applyStockFixture, assertM211Target, M211_TARGET, validateStockManifest } from './lib/m2-11-engine.mjs'
import { targetPreflight } from './lib/m2-10a-engine.mjs'

const values = process.argv.slice(2)
const args = { apply:false }
for (let index = 0; index < values.length; index += 1) {
  if (values[index] === '--apply') { args.apply = true; continue }
  if (!values[index].startsWith('--')) throw new Error(`Unknown argument: ${values[index]}`)
  args[values[index].slice(2).replaceAll('-', '_')] = values[++index]
}
for (const name of ['manifest','target_type','target_container','execution_id','expected_target_fingerprint']) if (!args[name]) throw new Error(`--${name.replaceAll('_','-')} is required`)
assertM211Target({ targetType:args.target_type, targetContainer:args.target_container, targetProjectRef:args.target_project_ref, productionMode:args.production_mode === 'true' })
const manifest = JSON.parse(readFileSync(args.manifest, 'utf8'))
validateStockManifest(manifest)
if (manifest.target.type !== M211_TARGET || manifest.execution_uuid !== args.execution_id) throw new Error('Manifest target or execution UUID mismatch')
const before = targetPreflight(args.target_container)
if (before.fingerprint !== args.expected_target_fingerprint) throw new Error(`Target fingerprint mismatch: expected ${args.expected_target_fingerprint}, received ${before.fingerprint}`)
applyStockFixture(args.target_container, manifest, { dryRun:!args.apply })
const after = targetPreflight(args.target_container)
if (!args.apply && before.fingerprint !== after.fingerprint) throw new Error('Opening stock dry run mutated target')
process.stdout.write(`${JSON.stringify({mode:args.apply?'APPLY':'DRY_RUN',target_before:before.fingerprint,target_after:after.fingerprint,rows:manifest.rows.length},null,2)}\n`)
