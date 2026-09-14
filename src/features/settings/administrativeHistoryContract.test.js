import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const component = readFileSync(new URL('./AdministrativeHistory.jsx', import.meta.url), 'utf8')
const css = readFileSync(new URL('../../App.css', import.meta.url), 'utf8')

test('audit rows expose a stable four-column desktop structure without raw UUID body copy', () => {
  for (const label of ['Action', 'Actor / Time', 'Target', 'Changes']) assert.match(component, new RegExp(`>${label}<`))
  assert.match(css, /\.settings-audit-head,\.settings-audit-row[^}]*grid-template-columns:\s*minmax\(0,\.8fr\)\s+minmax\(0,\.96fr\)\s+minmax\(0,1\.28fr\)\s+minmax\(0,\.96fr\)/)
  assert.match(css, /\.settings-audit-cell\s*\{\s*min-width:\s*0/)
  assert.match(css, /\.settings-audit-table\s*\{[^}]*overflow:\s*hidden/)
  assert.doesNotMatch(component, />\{event\.target\.id\}</)
  assert.match(component, /title=\{target\.fullIdentifier\}/)
})

test('long target labels clamp to two lines and tablet/mobile rows stack without horizontal overflow', () => {
  assert.match(css, /\.settings-audit-target > strong[^}]*-webkit-line-clamp:\s*2/)
  const responsive = css.match(/@media \(max-width: 900px\) \{[\s\S]*?\n\}/)?.[0] ?? ''
  assert.match(responsive, /\.settings-audit-row\s*\{[^}]*grid-template-columns:\s*minmax\(0,1fr\)/)
  assert.match(responsive, /\.settings-audit-field-label\s*\{\s*display:\s*block/)
  const narrow = css.slice(css.lastIndexOf('@media (max-width: 430px)'))
  assert.match(narrow, /\.settings-audit-pagination[^}]*grid-template-columns:\s*repeat\(2,minmax\(0,1fr\)\)/)
})

test('pagination keeps clear newer, page, and older controls with disabled styling', () => {
  assert.match(component, />← Newer</)
  assert.match(component, /Page \{page\} of \{result\.last_page\}/)
  assert.match(component, />Older →</)
  assert.match(css, /\.settings-audit-pagination button:disabled\s*\{[^}]*opacity:/)
})

test('audit presentation stays theme-compatible through existing surface tokens', () => {
  const auditCss = css.slice(css.indexOf('.settings-audit-table'), css.indexOf('.advanced-setting-card'))
  for (const token of ['--text', '--text-muted', '--surface-muted', '--border-subtle']) assert.match(auditCss, new RegExp(token))
  assert.doesNotMatch(auditCss, /#[0-9a-f]{3,8}\b/i)
})
