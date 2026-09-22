import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

// V1.10 - Knowledge Review Context Triage. Same static source-inspection convention as the
// other knowledge-import tests (no component-rendering harness exists in this project).
const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8')
const code = (source) => source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '')
const panel = read('./CodeGroupsPanel.jsx')
const detail = read('./CodeGroupDetailDialog.jsx')
const utils = read('./codeGroupUtils.js')
const lib = read('../../../lib/api/maintenance.js')
const css = read('../../../App.css')

// 1/2. the hint renders on the list row, only for CONTEXT_RECOMMENDED (self-contained is the
// silent majority - no badge noise for the common case)

test('a CONTEXT_RECOMMENDED group shows a compact badge on its list row; a self-contained group shows nothing extra', () => {
  assert.match(panel, /group\.context_hint === 'CONTEXT_RECOMMENDED' && \(/)
  assert.match(panel, /className="maintenance-context-hint-badge"/)
  assert.doesNotMatch(code(panel), /context_hint === 'SELF_CONTAINED'.*&&.*<span/, 'no badge is rendered for the self-contained majority')
})

// 3/4. explanation is understandable and never implies correctness

test('the badge and filter both carry an explanation that this is structure, not correctness', () => {
  assert.match(utils, /export const CONTEXT_HINT_HELP = 'Describes document structure, not candidate correctness\./)
  assert.match(panel, /title=\{`\$\{CONTEXT_REASON_LABELS\[group\.context_reason\] \?\? CONTEXT_HINT_LABELS\.CONTEXT_RECOMMENDED\} \$\{CONTEXT_HINT_HELP\}`\}/)
  // Scoped to the V1.10 vocabulary itself, not the whole file (which legitimately uses words
  // like "invalid" elsewhere, e.g. token/filter validation messages unrelated to this feature).
  const v110Text = [
    utils.match(/export const CONTEXT_HINT_LABELS = .*/)[0],
    utils.match(/export const CONTEXT_REASON_LABELS = \{[\s\S]*?\n\}/)[0],
    utils.match(/export const CONTEXT_HINT_HELP = .*/)[0],
  ].join('\n')
  assert.doesNotMatch(v110Text, /\b(good|bad|safe|unsafe|valid|invalid|accurate|inaccurate)\b/i, 'no misleading correctness-implying label is ever used in the V1.10 vocabulary')
})

test('the context reason explains itself in plain language, keyed by the backend-owned enum value', () => {
  assert.match(utils, /PAGE_END_CONTINUATION: /)
  assert.match(utils, /PAGE_START_CONTINUATION: /)
  assert.match(utils, /CONTEXT_HINT_LABELS = \{ SELF_CONTAINED: 'Quick review', CONTEXT_RECOMMENDED: 'Check context' \}/)
})

// 5/6. filter behavior, existing filters remain functional

test('a context filter exists and composes with the existing filter row without replacing it', () => {
  assert.match(panel, /value=\{filters\.context\} onChange=\{set\('context'\)\}/)
  assert.match(panel, /Object\.entries\(CONTEXT_HINT_LABELS\)\.map/)
  // existing filters still present, untouched
  assert.match(panel, /value=\{filters\.evidence\} onChange=\{set\('evidence'\)\}/)
  assert.match(panel, /value=\{filters\.collisionStatus\} onChange=\{set\('collisionStatus'\)\}/)
  assert.match(panel, /value=\{filters\.reviewState\} onChange=\{set\('reviewState'\)\}/)
  assert.match(panel, /value=\{filters\.code\} onChange=\{set\('code'\)\}/)
})

test('the context filter is wired into the query string and the empty-filters shape, never sent to bulk triage', () => {
  assert.match(utils, /context: '',/)
  assert.match(utils, /if \(f\.context\) params\.set\('context', f\.context\)/)
  assert.doesNotMatch(code(utils).match(/export function toBulkFilters[\s\S]*?\n\}/)[0], /f\.context/, 'context never becomes a bulk-triage criterion')
  assert.match(utils, /if \(f\.context\) ignored\.push\('Context hint'\)/)
})

// 7. opening a group still uses the V1.9 detail flow

test('opening a group (from any hint state) still opens the same CodeGroupDetailDialog', () => {
  assert.match(panel, /onOpen=\{setOpenCode\}/)
  assert.match(panel, /<CodeGroupDetailDialog importId=\{importId\} code=\{openCode\}/)
  assert.match(detail, /<SourcePageContext importId=\{importId\} code=\{code\} sourcePages=\{group\.source_pages\}/, 'V1.9 source page context is unchanged')
})

test('the detail header optionally shows the same context hint, for consistency when a group is opened directly', () => {
  assert.match(detail, /data-testid="context-hint"/)
  assert.match(detail, /group\.context_hint === 'CONTEXT_RECOMMENDED'/)
})

// 8. review actions unchanged

test('approve/reject/restore/publish actions are byte-for-byte unchanged by this feature', () => {
  assert.match(detail, /onApprove=\{\(o\) => run\(o, \(\) => updateKnowledgeEntry\(o\.id, \{ status: 'APPROVED' \}\)\)\}/)
  assert.match(detail, /onReject=\{\(o\) => run\(o, \(\) => updateKnowledgeEntry\(o\.id, \{ status: 'REJECTED' \}\)\)\}/)
  assert.match(detail, /onRestore=\{\(o\) => run\(o, \(\) => bulkReviewEntries\(importId, \{ entryIds: \[o\.id\], action: 'restore' \}\)\)\}/)
  assert.match(detail, /onClick=\{requestPublishReview\}/)
})

// 9. responsive - the badge is an inline element inside the existing wrapping facts row, not a
// fixed-width block that could force horizontal scroll

test('the badge sits inside the existing wrapping facts row and uses no fixed width', () => {
  assert.match(css, /\.maintenance-code-group-facts \{ min-width: 0; display: flex; flex-wrap: wrap;/)
  assert.match(css, /\.maintenance-context-hint-badge \{ display: inline-flex; align-items: center; gap: 4px; color: var\(--text-soft\); \}/)
  assert.doesNotMatch(css.match(/\.maintenance-context-hint-badge \{[^}]*\}/)[0], /width:|position: fixed|position: absolute/)
})

// 10. no raw source text added to the list

test('the list view never requests or renders raw page text - only the hint and reason', () => {
  assert.doesNotMatch(code(panel), /raw_text/)
  assert.doesNotMatch(code(utils), /raw_text/)
  assert.doesNotMatch(lib, /pages\/\$\{encodeURIComponent\(pageNumber\)\}.*codeGroups\b/, 'the list endpoint never calls the adjacent-page endpoint')
})
