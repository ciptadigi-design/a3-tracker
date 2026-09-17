import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { extractionStatusLabels } from '../maintenanceUtils.js'

const detail = readFileSync(new URL('./DocumentDetail.jsx', import.meta.url), 'utf8')
const modal = readFileSync(new URL('./ExtractionModal.jsx', import.meta.url), 'utf8')
const progress = readFileSync(new URL('./ExtractionProgress.jsx', import.meta.url), 'utf8')
const pagesViewer = readFileSync(new URL('./ExtractedPagesViewer.jsx', import.meta.url), 'utf8')
const useExtraction = readFileSync(new URL('./useDocumentExtraction.js', import.meta.url), 'utf8')
const useExtractedPagesSource = readFileSync(new URL('./useExtractedPages.js', import.meta.url), 'utf8')
const lib = readFileSync(new URL('../../../lib/api/maintenance.js', import.meta.url), 'utf8')
const services = readFileSync(new URL('../../../services/maintenance.js', import.meta.url), 'utf8')

// --- Extract button rendering ---

test('DocumentDetail shows an "Extract Knowledge" button (canManage-gated) when no extraction exists yet, not a disabled placeholder', () => {
  assert.match(detail, /canManage && <button className="secondary-button" type="button" onClick=\{\(\) => setShowModal\(true\)\}><Sparkles size=\{16\} \/> Extract Knowledge<\/button>/)
})

test('extraction status labels cover the full PENDING -> PROCESSING -> COMPLETED\\/FAILED lifecycle', () => {
  assert.deepEqual(extractionStatusLabels, { PENDING: 'Pending', PROCESSING: 'Processing', COMPLETED: 'Completed', FAILED: 'Failed' })
})

// --- Start extraction action (modal) ---

test('ExtractionModal shows the document title and a Start Extraction action', () => {
  assert.match(modal, /Extract Knowledge From Document/)
  assert.match(modal, /\{document\?\.title\}/)
  assert.match(modal, /Background process/)
  assert.match(modal, /Start Extraction/)
  assert.match(modal, /onClick=\{onStart\}/)
})

test('DocumentDetail wires the modal to useDocumentExtraction.start and closes on completion', () => {
  assert.match(detail, /const \{ extraction, isLoading, start, isStarting, startError \} = useDocumentExtraction\(document\.id\)/)
  assert.match(detail, /async function handleStart\(\) \{\s*await start\(\)\s*setShowModal\(false\)/)
  assert.match(detail, /<ExtractionModal document=\{document\} isStarting=\{isStarting\} startError=\{startError\} onClose=\{\(\) => setShowModal\(false\)\} onStart=\{handleStart\} \/>/)
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

// --- Status display ---

test('ExtractionProgress renders the status badge and a processing message while running', () => {
  assert.match(progress, /extractionStatusLabels\[status\] \?\? status/)
  assert.match(progress, /status === 'PROCESSING' && <span>Processing PDF…<\/span>/)
})

test('ExtractionProgress shows a Pages N \\/ total readout and a progress bar while processing or completed', () => {
  assert.match(progress, /Pages: \{processedPages\} \/ \{totalPages\}/)
  assert.match(progress, /maintenance-progress-bar-fill/)
  assert.match(progress, /showBar = \(status === 'PROCESSING' \|\| status === 'COMPLETED'\) && totalPages != null/)
})

test('ExtractionProgress surfaces the error message on a failed extraction', () => {
  assert.match(progress, /status === 'FAILED' && errorMessage/)
})

test('DocumentDetail polls extraction status only while PENDING\\/PROCESSING and stops on a terminal status', () => {
  assert.match(useExtraction, /const isActive = extraction && \(extraction\.status === 'PENDING' \|\| extraction\.status === 'PROCESSING'\)/)
  assert.match(useExtraction, /if \(!isActive\) return undefined/)
  assert.match(useExtraction, /setTimeout\(refresh, POLL_INTERVAL_MS\)/)
})

// --- Completed extraction state ---

test('DocumentDetail only renders the extracted pages viewer once the extraction is COMPLETED', () => {
  assert.match(detail, /const isCompleted = extraction\?\.status === 'COMPLETED'/)
  assert.match(detail, /\{isCompleted && \(/)
  assert.match(detail, /<ExtractedPagesViewer documentId=\{document\.id\} \/>/)
})

test('a FAILED extraction offers a Retry Extraction action, gated behind canManage', () => {
  assert.match(detail, /const canRetry = extraction\?\.status === 'FAILED'/)
  assert.match(detail, /canManage && canRetry && <button className="secondary-button" type="button" onClick=\{\(\) => setShowModal\(true\)\}.*Retry Extraction/)
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

test('ExtractedPagesViewer shows a loading/empty state instead of an empty page when there is nothing to show yet', () => {
  assert.match(pagesViewer, /No extracted content yet\./)
  assert.match(pagesViewer, /Loading extracted content…/)
})
