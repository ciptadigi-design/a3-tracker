#!/usr/bin/env node
import { readFileSync, writeFileSync } from 'node:fs'
import { spawnSync } from 'node:child_process'

const args = Object.fromEntries(process.argv.slice(2).map((v, i, a) => v.startsWith('--') ? [v.slice(2), a[i + 1]] : null).filter(Boolean))
if (!args.source || !args.output) throw new Error('Usage: generate-m2-12e-manifest.mjs --source <frozen.json> --output <manifest.json>')
const temp = `${args.output}.plan-${process.pid}`
try {
  const result = spawnSync(process.execPath, ['.m2-12e-2b8-plan.mjs', '--source', args.source, '--output', temp], { stdio: 'inherit' })
  if (result.status !== 0) process.exit(result.status ?? 1)
  const plan = JSON.parse(readFileSync(temp, 'utf8'))
  if (!plan.manifest?.fingerprint) throw new Error('Planner did not emit neutral manifest')
  writeFileSync(args.output, `${JSON.stringify(plan.manifest, null, 2)}\n`, { mode: 0o600 })
} finally {
  try { await (await import('node:fs/promises')).unlink(temp) } catch {}
}
