import { useEffect, useRef, useState } from 'react'
import { AlertCircle, ArrowRight, BookOpenText, LoaderCircle, Printer, Search, Wrench } from 'lucide-react'
import { userErrorMessage } from '../../../lib/appErrors.js'
import { searchOfficialErrorEntries } from '../../../services/maintenance.js'
import { useMachine } from '../../machines/useMachine.js'
import { useMachines } from '../../machines/useMachines.js'
import { applicabilityLabels, machineApplicability, normalizeErrorCodeInput, prioritizeForMachine, troubleshootingDetailUrl, troubleshootingSearchUrl } from './troubleshootingModel.js'

function ApplicabilityBadge({ children }) {
  return <span className="troubleshooting-applicability">{children}</span>
}

const matchLabels = { match: 'Sesuai model', possible: 'Kemungkinan', not_applicable: 'Tidak sesuai model' }

function ErrorKnowledgeResult({ entry, machine, onOpen }) {
  const match = machineApplicability(entry, machine)
  return (
    <article className={`troubleshooting-result-card${match ? ` troubleshooting-result-${match}` : ''}`}>
      <div className="troubleshooting-result-identity">
        <div className="troubleshooting-result-code"><span className="troubleshooting-code">{entry.code}</span>{match && <span className={`troubleshooting-match troubleshooting-match-${match}`}>{matchLabels[match]}</span>}</div>
        <h3>{entry.classification || 'Official troubleshooting procedure'}</h3>
      </div>
      <div className="troubleshooting-applicabilities" aria-label="Applicability">
        {applicabilityLabels(entry).map((label) => <ApplicabilityBadge key={label}>{label}</ApplicabilityBadge>)}
      </div>
      <button className="secondary-button" type="button" onClick={onOpen}>Lihat Penanganan <ArrowRight size={16} /></button>
    </article>
  )
}

export function TroubleshootingSection({ initialQuery = '', initialMachineId = '', accountId, branchId, navigate }) {
  const [input, setInput] = useState(initialQuery)
  const [submittedCode, setSubmittedCode] = useState('')
  const [results, setResults] = useState([])
  const [status, setStatus] = useState('idle')
  const [error, setError] = useState(null)
  const requestSequence = useRef(0)
  const machinesState = useMachines(accountId, branchId)
  const selectedMachineState = useMachine(accountId, branchId, initialMachineId)
  const selectedMachine = initialMachineId ? selectedMachineState.machine : null
  const visibleResults = prioritizeForMachine(results, selectedMachine)

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
    const target = troubleshootingSearchUrl(normalized, selectedMachine?.id)
    if (!navigate(target)) runSearch(normalized)
  }

  function selectMachine(machineId) {
    navigate(troubleshootingSearchUrl(submittedCode || initialQuery, machineId))
  }

  const sameCodeVariants = results.length > 1 && results.every((entry) => entry.code === results[0].code)

  return (
    <section className="troubleshooting-shell glass-surface" aria-labelledby="troubleshooting-heading">
      <div className="troubleshooting-intro">
        <span className="troubleshooting-icon"><Wrench size={24} /></span>
        <div><span className="card-kicker">Official manufacturer knowledge</span><h2 id="troubleshooting-heading">Cari kode error mesin</h2><p>Masukkan kode error yang tampil pada mesin.</p></div>
      </div>
      <div className="troubleshooting-machine-picker">
        <div className="troubleshooting-machine-picker-copy"><Printer size={18} /><div><strong>Konteks mesin</strong><span>Opsional · membantu memprioritaskan prosedur yang sesuai model.</span></div></div>
        <label><span className="sr-only">Pilih mesin</span><select value={selectedMachine?.id || ''} onChange={(event) => selectMachine(event.target.value)} disabled={machinesState.isLoading || Boolean(initialMachineId && selectedMachineState.isLoading)}><option value="">Tanpa mesin</option>{selectedMachine && !machinesState.machines.some((machine) => machine.id === selectedMachine.id) && <option value={selectedMachine.id}>{selectedMachine.display_name} · {selectedMachine.machine_code}</option>}{machinesState.machines.map((machine) => <option key={machine.id} value={machine.id}>{machine.display_name} · {machine.machine_code}</option>)}</select></label>
      </div>
      {selectedMachine && <div className="troubleshooting-machine-context"><div><span>Untuk mesin</span><strong>{selectedMachine.machine_models?.manufacturers?.name} {selectedMachine.machine_models?.name} · {selectedMachine.display_name}</strong><small>{selectedMachine.machine_code}</small></div><button className="secondary-button compact-button" type="button" onClick={() => selectMachine('')}>Ganti mesin</button></div>}
      {initialMachineId && selectedMachineState.error && <div className="troubleshooting-machine-error" role="alert"><div><strong>Konteks mesin tidak tersedia.</strong><span>Mesin mungkin tidak tersedia untuk akun atau cabang aktif. Pencarian tanpa mesin tetap dapat digunakan.</span></div><button className="secondary-button compact-button" type="button" onClick={() => selectMachine('')}>Lanjut tanpa mesin</button></div>}
      {machinesState.error && !initialMachineId && <div className="troubleshooting-machine-error" role="alert"><strong>Daftar mesin belum dapat dimuat.</strong><span>Pencarian tanpa mesin tetap tersedia.</span></div>}
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
          <div className="troubleshooting-result-list">{visibleResults.map((entry) => <ErrorKnowledgeResult key={entry.id} entry={entry} machine={selectedMachine} onOpen={() => navigate(troubleshootingDetailUrl(entry.id, selectedMachine?.id))} />)}</div>
        </div>
      )}
    </section>
  )
}
