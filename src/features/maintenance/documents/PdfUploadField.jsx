import { useRef, useState } from 'react'
import { AlertCircle, FileText, UploadCloud, X } from 'lucide-react'
import { formatFileSize, validatePdfFile } from '../maintenanceUtils.js'

/**
 * Controlled drag/drop + browse PDF picker. Client-side validation (extension/MIME/
 * size) is a fast-fail UX convenience only - DocumentStorageService on the backend
 * remains the sole authority on what is actually accepted.
 */
export function PdfUploadField({ file, onFileSelected, disabled = false }) {
  const [isDragging, setIsDragging] = useState(false)
  const [error, setError] = useState(null)
  const inputRef = useRef(null)

  function handleFiles(fileList) {
    const selected = fileList?.[0]
    if (!selected) return
    const validationError = validatePdfFile(selected)
    setError(validationError)
    if (!validationError) onFileSelected(selected)
  }

  function handleDrop(event) {
    event.preventDefault()
    setIsDragging(false)
    if (disabled) return
    handleFiles(event.dataTransfer.files)
  }

  return (
    <div
      className={`maintenance-pdf-dropzone ${isDragging ? 'dragging' : ''}`}
      onDragOver={(event) => { event.preventDefault(); if (!disabled) setIsDragging(true) }}
      onDragLeave={() => setIsDragging(false)}
      onDrop={handleDrop}
    >
      {file ? (
        <div className="maintenance-pdf-selected">
          <FileText size={22} />
          <div><strong>{file.name}</strong><span>{formatFileSize(file.size)}</span></div>
          {!disabled && <button type="button" className="icon-button" onClick={() => onFileSelected(null)} aria-label="Remove selected file"><X size={15} /></button>}
        </div>
      ) : (
        <>
          <UploadCloud size={28} strokeWidth={1.5} />
          <p>Drag PDF here, or</p>
          <button type="button" className="secondary-button" onClick={() => inputRef.current?.click()} disabled={disabled}>Choose File</button>
        </>
      )}
      <input
        ref={inputRef}
        type="file"
        accept="application/pdf,.pdf"
        hidden
        disabled={disabled}
        onChange={(event) => { handleFiles(event.target.files); event.target.value = '' }}
      />
      {error && <small className="field-error"><AlertCircle size={13} />{error}</small>}
    </div>
  )
}
