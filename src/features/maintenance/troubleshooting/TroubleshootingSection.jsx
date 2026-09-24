import { useEffect, useRef, useState } from 'react'
import { AlertCircle, ArrowRight, BookOpenText, LoaderCircle, Search, Wrench } from 'lucide-react'
import { userErrorMessage } from '../../../lib/appErrors.js'
import { searchOfficialErrorEntries } from '../../../services/maintenance.js'
import { applicabilityLabels, normalizeErrorCodeInput } from './troubleshootingModel.js'

function ApplicabilityBadge({ children }) {
  return <span className="troubleshooting-applicability">{children}</span>
}

function ErrorKnowledgeResult({ entry, onOpen }) {
  return (
    <article className="troubleshooting-result-card">
      <div className="troubleshooting-result-identity">
        <span className="troubleshooting-code">{entry.code}</span>
        <h3>{entry.classification || 'Official troubleshooting procedure'}</h3>
      </div>
      <div className="troubleshooting-applicabilities" aria-label="Applicability">
        {applicabilityLabels(entry).map((label) => <ApplicabilityBadge key={label}>{label}</ApplicabilityBadge>)}
      </div>
      <button className="secondary-button" type="button" onClick={onOpen}>Lihat Penanganan <ArrowRight size={16} /></button>
    </article>
  )
}

export function TroubleshootingSection({ initialQuery = '', navigate }) {
  const [input, setInput] = useState(initialQuery)
  const [submittedCode, setSubmittedCode] = useState('')
  const [results, setResults] = useState([])
  const [status, setStatus] = useState('idle')
  const [error, setError] = useState(null)
  const requestSequence = useRef(0)

  async function runSearch(rawValue) {
    const normalized = normalizeErrorCodeInput(rawValue)
    setInput(rawValue)
    setSubmittedCode(normalized || rawValue.trim().toUpperCase())
    setError(null)
    if (!normalized) { setResults([]); setStatus('empty'); return }
    const sequence = ++requestSequence.current
    setStatus('loading')
    try {
      const found = await searchOfficialErrorEntries(normalized, 20)
      if (sequence !== requestSequence.current) return
      setResults(found)
      setStatus(found.length > 0 ? 'success' : 'empty')
    } catch (nextError) {
      if (sequence !== requestSequence.current) return
      setResults([])
      setError(nextError)
      setStatus('error')
    }
  }

  useEffect(() => {
    let cancelled = false
    if (initialQuery) queueMicrotask(() => { if (!cancelled) runSearch(initialQuery) })
    // The URL query is the search state; run only when navigation changes it.
    return () => { cancelled = true; requestSequence.current += 1 }
  }, [initialQuery])

  function submit(event) {
    event.preventDefault()
    const normalized = normalizeErrorCodeInput(input)
    if (!normalized) { runSearch(input); return }
    const target = `/maintenance/troubleshooting?q=${encodeURIComponent(normalized)}`
    if (!navigate(target)) runSearch(normalized)
  }

  const sameCodeVariants = results.length > 1 && results.every((entry) => entry.code === results[0].code)

  return (
    <section className="troubleshooting-shell glass-surface" aria-labelledby="troubleshooting-heading">
      <div className="troubleshooting-intro">
        <span className="troubleshooting-icon"><Wrench size={24} /></span>
        <div><span className="card-kicker">Official manufacturer knowledge</span><h2 id="troubleshooting-heading">Cari kode error mesin</h2><p>Masukkan kode error yang tampil pada mesin.</p></div>
      </div>
      <form className="troubleshooting-search" role="search" onSubmit={submit}>
        <Search size={22} aria-hidden="true" />
        <input value={input} onChange={(event) => setInput(event.target.value)} placeholder="Contoh: C-3913" aria-label="Kode error mesin" autoCapitalize="characters" autoComplete="off" enterKeyHint="search" />
        <button className="primary-button" type="submit" disabled={status === 'loading'}>{status === 'loading' ? <LoaderCircle className="spin" size={18} /> : <Search size={18} />} Cari</button>
      </form>
      <p className="troubleshooting-format-hint">Format setara seperti 3913, C3913, dan C-3913 akan dicari sebagai kode yang sama.</p>

      {status === 'loading' && <div className="troubleshooting-state" role="status"><LoaderCircle className="spin" size={28} /><strong>Mencari penanganan resmi…</strong></div>}
      {status === 'error' && <div className="troubleshooting-state troubleshooting-error" role="alert"><AlertCircle size={30} /><strong>Pencarian belum dapat dimuat.</strong><p>{userErrorMessage(error, 'Layanan troubleshooting sementara tidak tersedia.')}</p><button className="secondary-button" type="button" onClick={() => runSearch(submittedCode)}>Coba lagi</button></div>}
      {status === 'empty' && <div className="troubleshooting-state"><BookOpenText size={34} /><strong>Kode error tidak ditemukan di manual yang tersedia.</strong><p>Periksa kembali kode pada mesin atau ubah pencarian.</p><button className="secondary-button" type="button" onClick={() => { setInput(''); setStatus('idle') }}>Ubah pencarian</button></div>}
      {status === 'success' && (
        <div className="troubleshooting-results" aria-live="polite">
          <header><div><span className="card-kicker">Hasil pencarian</span><h2>{results.length} prosedur ditemukan</h2></div></header>
          {sameCodeVariants && <div className="troubleshooting-variant-note"><strong>{results[0].code} tersedia untuk beberapa unit.</strong><span>Pilih unit atau aksesori yang sesuai. Semua varian tetap ditampilkan.</span></div>}
          <div className="troubleshooting-result-list">{results.map((entry) => <ErrorKnowledgeResult key={entry.id} entry={entry} onOpen={() => navigate(`/maintenance/troubleshooting/${entry.id}`)} />)}</div>
        </div>
      )}
    </section>
  )
}
