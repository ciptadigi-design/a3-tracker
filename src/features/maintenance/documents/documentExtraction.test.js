import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { extractionErrorMessages, extractionStatusLabels, permanentExtractionErrorCodes } from '../maintenanceUtils.js'

const detail = readFileSync(new URL('./DocumentDetail.jsx', import.meta.url), 'utf8')
const modal = readFileSync(new URL('./ExtractionModal.jsx', import.meta.url), 'utf8')
const progress = readFileSync(new URL('./ExtractionProgress.jsx', import.meta.url), 'utf8')
const pagesViewer = readFileSync(new URL('./ExtractedPagesViewer.jsx', import.meta.url), 'utf8')
const useExtraction = readFileSync(new URL('./useDocumentExtraction.js', import.meta.url), 'utf8')
const useExtractedPagesSource = readFileSync(new URL('./useExtractedPages.js', import.meta.url), 'utf8')
const lib = readFileSync(new URL('../../../lib/api/maintenance.js', import.meta.url), 'utf8')
const services = readFileSync(new URL('../../../services/maintenance.js', import.meta.url), 'utf8')

// --- V1.5.3: state labels / error-code contract (mirrors PdfExtractionErrorCode) ---

test('extraction status labels cover the full PENDING -> PROCESSING -> COMPLETED\\/FAILED lifecycle', () => {
  assert.deepEqual(extractionStatusLabels, { PENDING: 'Pending', PROCESSING: 'Processing', COMPLETED: 'Completed', FAILED: 'Failed' })
})

test('extractionErrorMessages covers every backend PdfExtractionErrorCode, safe text only (no paths/stack traces)', () => {
  const codes = ['UNSUPPORTED_PDF_SECURITY', 'INVALID_OR_CORRUPT_PDF', 'FILE_MISSING', 'RESOURCE_LIMIT', 'EXTRACTION_RUNTIME_FAILURE']
  for (const code of codes) {
    assert.equal(typeof extractionErrorMessages[code], 'string')
    assert.ok(extractionErrorMessages[code].length > 0)
    assert.doesNotMatch(extractionErrorMessages[code], /\/|\\|Stack trace|\.php/)
  }
})

test('permanentExtractionErrorCodes marks every classification except EXTRACTION_RUNTIME_FAILURE as permanent, mirroring PdfExtractionErrorCode::isPermanent()', () => {
  assert.deepEqual(permanentExtractionErrorCodes.sort(), ['FILE_MISSING', 'INVALID_OR_CORRUPT_PDF', 'RESOURCE_LIMIT', 'UNSUPPORTED_PDF_SECURITY'].sort())
  assert.ok(!permanentExtractionErrorCodes.includes('EXTRACTION_RUNTIME_FAILURE'))
})

// --- NONE state ---

test('ExtractionProgress renders a clean CTA card (not a disabled placeholder) for the NONE state, canManage-gated', () => {
  assert.match(progress, /if \(status === 'NONE'\) \{/)
  assert.match(progress, /No extracted knowledge yet/)
  assert.match(progress, /canManage && \(\s*<button className="primary-button" type="button" onClick=\{onStart\}/)
  assert.match(progress, /Extract Knowledge/)
})

test('DocumentDetail no longer special-cases NONE itself - ExtractionProgress owns every lifecycle state', () => {
  assert.match(progress, /const status = extraction\?\.status \?\? 'NONE'/)
  assert.match(detail, /<ExtractionProgress extraction=\{extraction\} canManage=\{canManage\} isStarting=\{isStarting\} onStart=\{\(\) => setShowModal\(true\)\} onReExtract=\{\(\) => setShowModal\(true\)\} \/>/)
})

// --- PENDING state ---

test('ExtractionProgress renders a queued indicator for PENDING, with no fake progress percentage', () => {
  assert.match(progress, /if \(status === 'PENDING'\) \{/)
  assert.match(progress, /Waiting for extraction worker/)
  assert.match(progress, /Extraction has been queued and will start shortly\./)
})

// --- PROCESSING state ---

test('ExtractionProgress renders a Pages N \\/ total readout with a determinate bar only when total_pages is known', () => {
  assert.match(progress, /if \(status === 'PROCESSING'\) \{/)
  assert.match(progress, /Extracting PDF/)
  assert.match(progress, /const showDeterminate = totalPages != null && totalPages > 0/)
  assert.match(progress, /Pages: \{processedPages\} \/ \{totalPages\}/)
  assert.match(progress, /maintenance-progress-bar-fill/)
})

test('ExtractionProgress falls back to an indeterminate bar when total_pages is not yet known', () => {
  assert.match(progress, /maintenance-progress-bar-indeterminate/)
  assert.match(progress, /maintenance-progress-bar-fill-indeterminate/)
  assert.match(progress, /Reading document structure…/)
})

// --- COMPLETED state ---

test('ExtractionProgress shows pages extracted and completion time, with a canManage-gated Re-extract action', () => {
  assert.match(progress, /if \(status === 'COMPLETED'\) \{/)
  assert.match(progress, /Extraction complete/)
  assert.match(progress, /\{totalPages\} page\{totalPages === 1 \? '' : 's'\} extracted/)
  assert.match(progress, /canManage && \(\s*<button className="secondary-button" type="button" onClick=\{onReExtract\}/)
  assert.match(progress, /Re-extract/)
})

test('DocumentDetail only renders the Extracted Content section, and only the extracted pages viewer, once the extraction is COMPLETED - no giant empty section before that', () => {
  assert.match(detail, /const isCompleted = extraction\?\.status === 'COMPLETED'/)
  assert.match(detail, /\{isCompleted && \(/)
  assert.match(detail, /<strong>Extracted content<\/strong>/)
  assert.match(detail, /<ExtractedPagesViewer documentId=\{document\.id\} \/>/)
})

// --- FAILED state ---

test('ExtractionProgress renders a safe, specific explanation for FAILED, keyed off error_code (not the raw message)', () => {
  assert.match(progress, /const errorCode = extraction\.error_code/)
  assert.match(progress, /const explanation = extractionErrorMessages\[errorCode\] \?\? extraction\.error_message/)
  assert.match(progress, /Extraction failed/)
})

test('ExtractionProgress never offers a meaningless Retry action for a permanent failure (e.g. UNSUPPORTED_PDF_SECURITY)', () => {
  assert.match(progress, /const isPermanent = permanentExtractionErrorCodes\.includes\(errorCode\)/)
  assert.match(progress, /canManage && !isPermanent && \(/)
  assert.match(progress, /Retry Extraction/)
})

// --- Extract/Retry action wiring ---

test('DocumentDetail wires the modal to useDocumentExtraction.start and closes on completion', () => {
  assert.match(detail, /const \{ extraction, isLoading, start, isStarting, startError \} = useDocumentExtraction\(document\.id\)/)
  assert.match(detail, /async function handleStart\(\) \{\s*await start\(\)\s*setShowModal\(false\)/)
  assert.match(detail, /<ExtractionModal document=\{document\} extraction=\{extraction\} isStarting=\{isStarting\} startError=\{startError\} onClose=\{\(\) => setShowModal\(false\)\} onStart=\{handleStart\} \/>/)
})

test('useDocumentExtraction posts to the extract endpoint via the maintenance service layer, not a raw fetch', () => {
  assert.match(useExtraction, /import \{ loadDocumentExtraction, startDocumentExtraction \} from '\.\.\/\.\.\/\.\.\/services\/maintenance\.js'/)
  assert.match(useExtraction, /const created = await startDocumentExtraction\(documentId\)/)
})

test('service/lib layers expose the three V1.5 endpoints matching the backend routes exactly', () => {
  assert.match(lib, /startDocumentExtraction: \(documentId\) => apiClient\.post\(`\/maintenance\/documents\/\$\{documentId\}\/extract`\)/)
  assert.match(lib, /documentExtraction: \(documentId\) => apiClient\.get\(`\/maintenance\/documents\/\$\{documentId\}\/extraction`\)/)
  assert.match(lib, /documentPages: \(documentId, params = ''\) => apiClient\.get\(`\/maintenance\/documents\/\$\{documentId\}\/pages/)
  assert.match(services, /export const startDocumentExtraction = async \(documentId\) => unwrapData/)
  assert.match(services, /export const loadDocumentExtraction = async \(documentId\) => unwrapData/)
})

test('DocumentDetail polls extraction status only while PENDING\\/PROCESSING and stops on a terminal status', () => {
  assert.match(useExtraction, /const isActive = extraction && \(extraction\.status === 'PENDING' \|\| extraction\.status === 'PROCESSING'\)/)
  assert.match(useExtraction, /if \(!isActive\) return undefined/)
  assert.match(useExtraction, /setTimeout\(refresh, POLL_INTERVAL_MS\)/)
})

// --- Extraction modal: compact state, duplicate-start guard, permanent-failure retry ---

test('ExtractionModal is compact: title, document name, background-process explanation, no fixed completion-time promise', () => {
  assert.match(modal, /Extract Knowledge<\/h2>/)
  assert.match(modal, /\{document\?\.title\}/)
  assert.match(modal, /The PDF will be processed in the background\. You can leave this page while extraction runs\./)
  assert.match(modal, /there is no fixed completion time/)
  assert.match(modal, /Start Extraction/)
  assert.match(modal, /onClick=\{onStart\}/)
})

test('ExtractionModal blocks starting a duplicate extraction while one is PENDING\\/PROCESSING', () => {
  assert.match(modal, /const BLOCKING_STATUSES = \['PENDING', 'PROCESSING'\]/)
  assert.match(modal, /const isBlocked = BLOCKING_STATUSES\.includes\(extraction\?\.status\)/)
  assert.match(modal, /disabled=\{isStarting \|\| isBlocked\}/)
})

// --- Pagination rendering ---

test('useExtractedPages fetches one small page of rows at a time via the backend paginator, never everything at once', () => {
  assert.match(useExtractedPagesSource, /const PER_PAGE = 5/)
  assert.match(useExtractedPagesSource, /loadDocumentPages\(documentId, \{ page: pageNumber, perPage: PER_PAGE \}\)/)
})

test('ExtractedPagesViewer renders each page with its number and text, and Previous\\/Next controls only when there is more than one page', () => {
  assert.match(pagesViewer, /<strong>Page \{page\.page_number\}<\/strong>/)
  assert.match(pagesViewer, /\{page\.raw_text\?\.trim\(\) \|\| 'No extractable text on this page\.'\}/)
  assert.match(pagesViewer, /lastPage > 1 && \(/)
  assert.match(pagesViewer, /disabled=\{currentPage <= 1 \|\| isLoading\}/)
  assert.match(pagesViewer, /disabled=\{currentPage >= lastPage \|\| isLoading\}/)
})

// --- Document header / metadata polish ---

test('DocumentDetail shows View PDF/Download actions in the document header once a file is stored', () => {
  assert.match(detail, /document-header-actions/)
  assert.match(detail, /View PDF/)
  assert.match(detail, /maintenanceDocumentFileUrl\(state\.document\.id, \{ inline: true \}\)/)
})

test('DocumentDetail shows a document metadata grid with manufacturer, machine model, file size, uploaded and updated', () => {
  assert.match(detail, /document-meta-grid/)
  assert.match(detail, /state\.document\.manufacturer\?\.name/)
  assert.match(detail, /state\.document\.machine_model\?\.name/)
  assert.match(detail, /formatFileSize\(state\.document\.file_size\)/)
})

// --- Compact empty states (Related Error Knowledge / Knowledge Imports) ---

test('Related error knowledge uses a compact empty state, not the large machine-empty-state placeholder', () => {
  assert.match(detail, /maintenance-compact-empty/)
  assert.doesNotMatch(detail, /machine-empty-state/)
})

// --- Capability gates unchanged ---

test('Extract/Retry/Re-extract actions and reference/import management stay canManage-gated', () => {
  assert.match(progress, /canManage && \(/)
  assert.match(detail, /canManage && \(showReferenceForm \? \(/)
  assert.match(detail, /canManage && \(showCreateImport \? \(/)
  assert.match(detail, /\{!canManage && <div className="permission-banner">/)
})
