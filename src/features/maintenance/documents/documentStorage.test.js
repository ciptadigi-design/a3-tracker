import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { formatFileSize, MAX_DOCUMENT_FILE_SIZE_BYTES, validatePdfFile } from '../maintenanceUtils.js'

const uploadField = readFileSync(new URL('./PdfUploadField.jsx', import.meta.url), 'utf8')
const formDialog = readFileSync(new URL('./DocumentFormDialog.jsx', import.meta.url), 'utf8')
const detail = readFileSync(new URL('./DocumentDetail.jsx', import.meta.url), 'utf8')
const apiClient = readFileSync(new URL('../../../lib/api/apiClient.js', import.meta.url), 'utf8')

// --- Validation (maintenanceUtils.validatePdfFile / formatFileSize) ---

test('validatePdfFile accepts a PDF by MIME type or by .pdf extension', () => {
  assert.equal(validatePdfFile({ type: 'application/pdf', name: 'manual.pdf', size: 1024 }), null)
  assert.equal(validatePdfFile({ type: '', name: 'manual.PDF', size: 1024 }), null)
})

test('validatePdfFile rejects non-PDF files and missing selections', () => {
  assert.match(validatePdfFile({ type: 'image/png', name: 'photo.png', size: 1024 }), /Only PDF files/)
  assert.match(validatePdfFile(null), /Select a file/)
})

test('validatePdfFile enforces the same 250MB ceiling as DocumentStorageService::MAX_FILE_SIZE_BYTES (raised from 50MB in V1.4.1)', () => {
  assert.equal(MAX_DOCUMENT_FILE_SIZE_BYTES, 250 * 1024 * 1024)
  const oversized = { type: 'application/pdf', name: 'big.pdf', size: MAX_DOCUMENT_FILE_SIZE_BYTES + 1 }
  assert.match(validatePdfFile(oversized), /exceeds the maximum allowed size/)
  assert.match(validatePdfFile(oversized), /250\.0 MB/)
  const atLimit = { type: 'application/pdf', name: 'big.pdf', size: MAX_DOCUMENT_FILE_SIZE_BYTES }
  assert.equal(validatePdfFile(atLimit), null)
})

test('validatePdfFile accepts a 121.8MB PDF, well above the old 50MB limit and under the new 250MB one', () => {
  const largeFile = { type: 'application/pdf', name: 'large_service_manual.pdf', size: Math.round(121.8 * 1024 * 1024) }
  assert.equal(validatePdfFile(largeFile), null)
})

test('formatFileSize renders human-readable B/KB/MB and tolerates missing values', () => {
  assert.equal(formatFileSize(null), '—')
  assert.equal(formatFileSize(500), '500 B')
  assert.equal(formatFileSize(2048), '2 KB')
  assert.equal(formatFileSize(5 * 1024 * 1024), '5.0 MB')
})

// --- PdfUploadField (drag/drop + browse) ---

test('PdfUploadField validates every picked/dropped file client-side before accepting it', () => {
  assert.match(uploadField, /import \{ formatFileSize, validatePdfFile \} from '\.\.\/maintenanceUtils\.js'/)
  assert.match(uploadField, /const validationError = validatePdfFile\(selected\)/)
  assert.match(uploadField, /if \(!validationError\) onFileSelected\(selected\)/)
})

test('PdfUploadField renders a drop zone with drag state and a hidden file input restricted to PDFs', () => {
  assert.match(uploadField, /onDragOver=\{.*setIsDragging\(true\)/)
  assert.match(uploadField, /onDrop=\{handleDrop\}/)
  assert.match(uploadField, /accept="application\/pdf,\.pdf"/)
})

test('PdfUploadField shows the selected file (name/size) with a remove control once one is chosen', () => {
  assert.match(uploadField, /file \? \(/)
  assert.match(uploadField, /<strong>\{file\.name\}<\/strong><span>\{formatFileSize\(file\.size\)\}<\/span>/)
  assert.match(uploadField, /onClick=\{\(\) => onFileSelected\(null\)\}/)
})

test('PdfUploadField respects a disabled state and never selects/drops while disabled', () => {
  assert.match(uploadField, /disabled = false/)
  assert.match(uploadField, /if \(disabled\) return/)
  assert.match(uploadField, /disabled=\{disabled\}/)
})

// --- Capability gate (DocumentFormDialog + DocumentDetail) ---

test('DocumentFormDialog only offers the upload/external toggle for documents with no stored file yet', () => {
  assert.match(formDialog, /const hasStoredFile = Boolean\(document\?\.storage_disk\)/)
  assert.match(formDialog, /hasStoredFile \? \(/)
  assert.match(formDialog, /This document already has an uploaded PDF\. Manage it/)
})

test('DocumentFormDialog upload flow lets the storage endpoint own file metadata instead of sending it inline', () => {
  assert.match(formDialog, /const useUploadFlow = !hasStoredFile && fileMode === 'upload' && selectedFile/)
  assert.match(formDialog, /if \(useUploadFlow\) await uploadMaintenanceDocumentFile\(saved\.id, selectedFile\)/)
})

test('DocumentDetail file section gates upload and delete actions behind canManage, matching every other mutation in this dialog', () => {
  assert.match(detail, /function DocumentFileSection\(\{ document, canManage, onChanged \}\)/)
  assert.match(detail, /\{canManage && <button className="icon-button" type="button" onClick=\{handleDeleteFile\}/)
  assert.match(detail, /\{canManage && !document\.storage_disk && \(/)
})

test('DocumentDetail file section renders all three file-presence states', () => {
  assert.match(detail, /document\.storage_disk \? \(/)
  assert.match(detail, /: document\.file_path \? \(/)
  assert.match(detail, /No PDF uploaded yet/)
})

test('DocumentDetail exposes View/Download links straight to the download endpoint (no blob fetch) and a disabled Extract Knowledge placeholder', () => {
  assert.match(detail, /href=\{maintenanceDocumentFileUrl\(document\.id, \{ inline: true \}\)\}/)
  assert.match(detail, /href=\{maintenanceDocumentFileUrl\(document\.id\)\}/)
  assert.match(detail, /Extract Knowledge/)
  assert.match(detail, /button className="secondary-button" type="button" disabled title="Coming soon"/)
})

// --- apiClient FormData support (backs the upload button end-to-end) ---

test('apiClient skips the JSON Content-Type header for FormData bodies so multipart boundaries are set correctly', () => {
  assert.match(apiClient, /options\.body instanceof FormData/)
  assert.match(apiClient, /upload: \(path, formData, options\)/)
})
