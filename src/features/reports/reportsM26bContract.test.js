import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const page = readFileSync(new URL('../../pages/ReportsPage.jsx', import.meta.url), 'utf8')
const service = readFileSync(new URL('../../services/supabase/reports.js', import.meta.url), 'utf8')
test('Reports remains read-only and backend-switch compatible', () => { assert.doesNotMatch(page, /insert\(|update\(|delete\(/); assert.match(service, /apiBackend === 'laravel'/); assert.match(page, /loadOperationalReport/) })
test('charts include textual operational context and mobile-safe navigation', () => { assert.match(page, /aria-label=\{`\$\{title\}/); assert.match(page, /report-tabs/) })
