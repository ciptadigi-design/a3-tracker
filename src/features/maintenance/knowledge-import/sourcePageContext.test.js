import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

// V1.9 - Source Page Context. Same static source-inspection convention as the other
// knowledge-import tests (no component-rendering harness exists in this project).
const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8')
const code = (source) => source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '')
const context = read('./SourcePageContext.jsx')
const detail = read('./CodeGroupDetailDialog.jsx')
const lib = read('../../../lib/api/maintenance.js')
const services = read('../../../services/maintenance.js')
const routes = read('../../../../backend/routes/api.php')
const css = read('../../../App.css')

// 1. renders inside the code-group detail view

test('Source Page Context is rendered inside the code-group detail dialog, after supporting evidence', () => {
  assert.match(detail, /<SourcePageContext importId=\{importId\} code=\{code\} sourcePages=\{group\.source_pages\} truncated=\{group\.source_pages_truncated\}/)
  const evidenceIndex = detail.indexOf('Supporting evidence')
  const contextIndex = detail.indexOf('<SourcePageContext')
  assert.ok(evidenceIndex > -1 && contextIndex > evidenceIndex, 'Source Page Context follows the supporting-evidence section')
  assert.match(context, /Source Page Context/)
})

// 2/3. correct page number, full text shown (not the bounded excerpt)

test('each source page clearly shows its page number and its full extracted text', () => {
  assert.match(context, /Page \{activeDirect\.page_number\}/)
  assert.match(context, /<PageBody text=\{activeDirect\.raw_text\} code=\{code\} \/>/)
  assert.doesNotMatch(code(context), /\.description\b/, 'the full page body, never the bounded per-candidate excerpt field')
})

// 4/5. multiple-page selector, active page switches

test('a multi-page group offers a page selector and switches the active page on click', () => {
  assert.match(context, /directPages\.length > 1 &&/)
  assert.match(context, /role="tablist"/)
  assert.match(context, /directPages\.map\(\(p\) => \(/)
  assert.match(context, /onClick=\{\(\) => setActivePage\(p\.page_number\)\}/)
  assert.match(context, /aria-selected=\{activePage === p\.page_number\}/)
})

// 6. line breaks preserved

test('extracted text preserves line breaks via the same convention as the existing extracted-pages viewer', () => {
  assert.match(context, /className="maintenance-extracted-page"/)
  assert.match(css, /\.maintenance-extracted-page p \{ margin: 0; font-size: 12px; white-space: pre-wrap;/)
})

// 7. raw text is rendered safely as text, never as HTML

test('raw page text is never rendered as HTML', () => {
  assert.doesNotMatch(code(context), /dangerouslySetInnerHTML/)
  assert.match(context, /function PageBody\(\{ text, code \}\)/)
  assert.match(context, /<p className="maintenance-source-page-text">\{highlightCode\(text, code\)\}<\/p>/)
})

test('the deterministic code highlight is an exact substring split, never a regex or semantic parse', () => {
  assert.match(context, /function highlightCode\(text, code\)/)
  assert.match(context, /text\.split\(code\)/)
  assert.doesNotMatch(code(context), /new RegExp|Cause|Action|semantic/i)
})

// 8. long content wraps, no forced horizontal layout

test('long page text wraps and the layout stacks on narrow screens', () => {
  assert.match(css, /\.maintenance-source-page-nav \{ display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; \}/)
  assert.match(css, /@media \(max-width: 640px\) \{\s*\.maintenance-source-page-nav \{ flex-direction: column;/)
})

// 9. loading state

test('an adjacent-page fetch shows a loading state', () => {
  assert.match(context, /activeAdjacent\?\.loading/)
  assert.match(context, /Loading page \{activePage\}/)
  assert.match(context, /loading: true, error: null, raw_text: null/)
})

// 10. empty/missing state

test('a page with no extractable text and a group with no source pages both render an honest empty state', () => {
  assert.match(context, /No extractable text on this page\./)
  assert.match(context, /No extracted page text is available for this group's supporting pages\./)
})

// 11. API failure state

test('a failed adjacent-page request surfaces a real error, not a silent failure', () => {
  assert.match(context, /catch \(error\) \{/)
  assert.match(context, /mapMaintenanceError\(error\)/)
  assert.match(context, /role="alert"/)
})

// 12/13. existing review actions and publish/reject controls are unchanged

test('existing review actions (approve/reject/restore/use-as-canonical) and the publish button are unchanged', () => {
  assert.match(detail, /onApprove=\{\(o\) => run\(o, \(\) => updateKnowledgeEntry\(o\.id, \{ status: 'APPROVED' \}\)\)\}/)
  assert.match(detail, /onReject=\{\(o\) => run\(o, \(\) => updateKnowledgeEntry\(o\.id, \{ status: 'REJECTED' \}\)\)\}/)
  assert.match(detail, /onRestore=\{\(o\) => run\(o, \(\) => bulkReviewEntries\(importId, \{ entryIds: \[o\.id\], action: 'restore' \}\)\)\}/)
  assert.match(detail, /onClick=\{requestPublishReview\}/)
  assert.match(detail, /<Rocket size=\{16\} \/> Review Publish/)
})

// 14. no new mutation path is introduced by this feature

test('Source Page Context and its API calls are strictly read-only: no POST/PATCH/DELETE anywhere', () => {
  assert.doesNotMatch(code(context), /apiClient\.(post|patch|delete)|\.post\(|\.patch\(|\.delete\(/i)
  assert.match(lib, /codeGroupPage: \(importId, code, pageNumber\) => apiClient\.get\(`[^`]*code-groups\/\$\{encodeURIComponent\(code\)\}\/pages\/\$\{encodeURIComponent\(pageNumber\)\}`\)/)
  assert.match(services, /export const loadCodeGroupPage = /)
  assert.match(routes, /Route::get\('maintenance\/document-imports\/\{id\}\/code-groups\/\{code\}\/pages\/\{pageNumber\}'/)
})

test('direct source pages and adjacent context pages are visibly distinguished', () => {
  assert.match(context, /Direct source page/)
  assert.match(context, /Adjacent context page/)
  assert.match(context, /is_direct_source/)
})
