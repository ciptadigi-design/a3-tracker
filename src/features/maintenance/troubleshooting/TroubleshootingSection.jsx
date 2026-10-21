import { useEffect, useMemo, useState } from 'react'
import { AlertCircle, ArrowRight, BookOpenText, ClipboardList, Info, LoaderCircle, Printer, Search, Wrench } from 'lucide-react'
import { machineIdPattern } from '../../../hooks/useAppRoute.js'
import { searchFieldExperiences, searchOfficialErrorEntryPage } from '../../../services/maintenance.js'
import { useTenant } from '../../account/useTenant.js'
import { useMachine } from '../../machines/useMachine.js'
import { useMachines } from '../../machines/useMachines.js'
import { formatElapsedMinutes } from '../../downtime/downtimeModel.js'
import { formatMaintenanceDate } from '../maintenanceUtils.js'
import { applicabilityLabels, classifyTroubleshootingQuery, fieldTestResultLabels, fieldExperienceTarget, fieldTestResultTone, machineApplicability, prioritizeForMachine, troubleshootingDetailUrl, troubleshootingSearchUrl } from './troubleshootingModel.js'
import { createTroubleshootingSearch, initialSearchState } from './troubleshootingSearch.js'

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

// Read-only summary of one completed repair. Only the server's safe projection is rendered; the action opens
// the ticket or the read-only experience detail, as decided by fieldExperienceTarget().
function FieldExperienceResult({ item, timezone, onOpen }) {
  const tone = fieldTestResultTone[item.test_result]
  const meta = [
    [item.machine_name, item.model_name].filter(Boolean).join(' · '),
    item.branch_name && `Cabang ${item.branch_name}`,
    item.incident_at && formatMaintenanceDate(item.incident_at, timezone, { dateOnly: true }),
    item.downtime_minutes != null && `Downtime ${formatElapsedMinutes(item.downtime_minutes)}`,
  ].filter(Boolean)
  return (
    <article className="field-experience-card">
      <div className="field-experience-head">
        <span className="field-experience-source">Pengalaman Lapangan</span>
        {item.error_code && <span className="troubleshooting-code field-experience-code">{item.error_code}</span>}
        {item.matched_by === 'code_in_text' && <small>Kode tertulis di catatan</small>}
        {item.model_match && <span className="troubleshooting-match troubleshooting-match-match">Sesuai model</span>}
        {item.same_machine && <span className="troubleshooting-match troubleshooting-match-match">Mesin yang sama</span>}
        {tone && <span className={`incident-status-pill ${tone}`}>{fieldTestResultLabels[item.test_result]}</span>}
      </div>
      <h3>{item.title}</h3>
      {item.problem && <p className="field-experience-problem">{item.problem}</p>}
      <dl className="field-experience-resolution">
        <div><dt>Penyebab</dt><dd>{item.cause}</dd></div>
        <div><dt>Tindakan</dt><dd>{item.action}</dd></div>
      </dl>
      {meta.length > 0 && <p className="field-experience-meta">{meta.join(' · ')}</p>}
      <button className="secondary-button compact-button" type="button" onClick={onOpen}>Lihat Penanganan <ArrowRight size={16} /></button>
    </article>
  )
}

function GroupState({ icon, title, children, tone, role }) {
  return <div className={`troubleshooting-state troubleshooting-state-compact${tone ? ` troubleshooting-${tone}` : ''}`} role={role}>{icon}<strong>{title}</strong>{children}</div>
}

function LoadMore({ group, onMore }) {
  if (group.status !== 'success') return null
  return (
    <div className="troubleshooting-more">
      <small>Menampilkan {group.items.length} dari {group.total}</small>
      {group.moreError && <p className="field-error" role="alert">Halaman berikutnya belum dapat dimuat. Hasil yang sudah tampil tetap aman.</p>}
      {(group.hasMore || group.moreError) && <button className="secondary-button compact-button" type="button" onClick={onMore} disabled={group.loadingMore}>{group.loadingMore ? 'Memuat…' : group.moreError ? 'Coba lagi' : 'Muat lebih banyak'}</button>}
    </div>
  )
}

export function TroubleshootingSection({ initialQuery = '', initialMachineId = '', accountId, branchId, navigate }) {
  const { branch, account } = useTenant()
  const timezone = branch?.timezone || account?.default_timezone || 'UTC'
  const [input, setInput] = useState(initialQuery)
  const [search, setSearch] = useState(initialSearchState)
  const machinesState = useMachines(accountId, branchId)
  const selectedMachineState = useMachine(accountId, branchId, initialMachineId)
  const selectedMachine = initialMachineId ? selectedMachineState.machine : null
  const machineId = machineIdPattern.test(initialMachineId) ? initialMachineId : ''
  // Field experiences are ranked server-side by machine/model, so wait for the machine to resolve and
  // search without it when it is unavailable (Official results are only re-sorted client-side).
  const machineReady = !machineId || !selectedMachineState.isLoading
  const contextMachineId = machineId && !selectedMachineState.error ? machineId : ''
  const visibleOfficial = prioritizeForMachine(search.official.items, selectedMachine)

  const controller = useMemo(() => createTroubleshootingSearch({ searchOfficial: searchOfficialErrorEntryPage, searchField: searchFieldExperiences, accountId, onChange: setSearch }), [accountId])
  useEffect(() => { controller.attach(); return () => controller.dispose() }, [controller])

  useEffect(() => {
    // The URL query is the search state; run only when navigation changes it (or the machine context resolves).
    let cancelled = false
    if (initialQuery && machineReady) queueMicrotask(() => { if (!cancelled) controller.run(initialQuery, { machineId: contextMachineId }) })
    return () => { cancelled = true }
  }, [controller, initialQuery, machineReady, contextMachineId])

  function submit(event) {
    event.preventDefault()
    const classified = classifyTroubleshootingQuery(input)
    if (classified.kind === 'empty') { controller.run(''); return }
    const target = troubleshootingSearchUrl(classified.query, selectedMachine?.id)
    if (!navigate(target)) controller.run(input, { machineId: contextMachineId })
  }

  function selectMachine(nextMachineId) {
    navigate(troubleshootingSearchUrl(search.query || initialQuery, nextMachineId))
  }

  const { official, field } = search
  const sameCodeVariants = official.items.length > 1 && official.items.every((entry) => entry.code === official.items[0].code)

  return (
    <section className="troubleshooting-shell glass-surface" aria-labelledby="troubleshooting-heading">
      <div className="troubleshooting-intro">
        <span className="troubleshooting-icon"><Wrench size={24} /></span>
        <div><span className="card-kicker">Pengetahuan resmi &amp; pengalaman lapangan</span><h2 id="troubleshooting-heading">Cari kode error mesin</h2><p>Cari dengan kode error atau gejala. Hasil resmi pabrikan dan pengalaman lapangan ditampilkan terpisah.</p></div>
      </div>
      <div className="troubleshooting-machine-picker">
        <div className="troubleshooting-machine-picker-copy"><Printer size={18} /><div><strong>Konteks mesin</strong><span>Opsional · membantu memprioritaskan prosedur dan pengalaman yang sesuai model.</span></div></div>
        <label><span className="sr-only">Pilih mesin</span><select value={selectedMachine?.id || ''} onChange={(event) => selectMachine(event.target.value)} disabled={machinesState.isLoading || Boolean(initialMachineId && selectedMachineState.isLoading)}><option value="">Tanpa mesin</option>{selectedMachine && !machinesState.machines.some((machine) => machine.id === selectedMachine.id) && <option value={selectedMachine.id}>{selectedMachine.display_name} · {selectedMachine.machine_code}</option>}{machinesState.machines.map((machine) => <option key={machine.id} value={machine.id}>{machine.display_name} · {machine.machine_code}</option>)}</select></label>
      </div>
      {selectedMachine && <div className="troubleshooting-machine-context"><div><span>Untuk mesin</span><strong>{selectedMachine.machine_models?.manufacturers?.name} {selectedMachine.machine_models?.name} · {selectedMachine.display_name}</strong><small>{selectedMachine.machine_code}</small></div><button className="secondary-button compact-button" type="button" onClick={() => selectMachine('')}>Ganti mesin</button></div>}
      {initialMachineId && selectedMachineState.error && <div className="troubleshooting-machine-error" role="alert"><div><strong>Konteks mesin tidak tersedia.</strong><span>Mesin mungkin tidak tersedia untuk akun atau cabang aktif. Pencarian tanpa mesin tetap dapat digunakan.</span></div><button className="secondary-button compact-button" type="button" onClick={() => selectMachine('')}>Lanjut tanpa mesin</button></div>}
      {machinesState.error && !initialMachineId && <div className="troubleshooting-machine-error" role="alert"><strong>Daftar mesin belum dapat dimuat.</strong><span>Pencarian tanpa mesin tetap tersedia.</span></div>}
      <form className="troubleshooting-search" role="search" onSubmit={submit}>
        <Search size={22} aria-hidden="true" />
        <input value={input} onChange={(event) => setInput(event.target.value)} placeholder="C-0213, J-2113, hasil kotor, paper exit" aria-label="Kode error atau gejala mesin" autoCapitalize="none" autoComplete="off" enterKeyHint="search" />
        <button className="primary-button" type="submit" disabled={official.status === 'loading' || field.status === 'loading'}>{official.status === 'loading' || field.status === 'loading' ? <LoaderCircle className="spin" size={18} /> : <Search size={18} />} Cari</button>
      </form>
      <p className="troubleshooting-format-hint">Kode seperti C-0213 atau J-2113 dicari di kedua sumber. Teks bebas hanya mencari pengalaman lapangan.</p>

      {search.kind !== 'empty' && (
        <div className="troubleshooting-groups">
          {search.kind === 'numeric' && (
            <div className="troubleshooting-numeric-note" role="note"><Info size={16} /><span>Angka <strong>{search.query}</strong> bisa berarti kode C atau J. Hasil <strong>{search.codes.join(' dan ')}</strong> ditampilkan secara terpisah.</span></div>
          )}

          <section className="troubleshooting-group" aria-label="Pengetahuan Resmi">
            <header className="troubleshooting-group-head">
              <div><span className="card-kicker">Pengetahuan Resmi</span><h2>{official.status === 'success' ? `${official.total} prosedur ditemukan` : 'Manual dan prosedur resmi pabrikan'}</h2></div>
            </header>
            {official.status === 'skipped' && <p className="troubleshooting-group-note"><BookOpenText size={16} />Pengetahuan resmi dicari berdasarkan kode error. Masukkan kode seperti C-0213 untuk melihat prosedur pabrikan.</p>}
            {official.status === 'loading' && <GroupState icon={<LoaderCircle className="spin" size={22} />} title="Mencari penanganan resmi…" role="status" />}
            {official.status === 'error' && (
              <GroupState icon={<AlertCircle size={24} />} title="Pencarian belum dapat dimuat." tone="error" role="alert">
                <p>Layanan pengetahuan resmi sementara tidak tersedia. Pengalaman lapangan tetap ditampilkan.</p>
                <button className="secondary-button compact-button" type="button" onClick={() => controller.retry('official')}>Coba lagi</button>
              </GroupState>
            )}
            {official.status === 'empty' && <GroupState icon={<BookOpenText size={24} />} title="Kode error tidak ditemukan di manual yang tersedia."><p>Periksa kembali kode pada mesin atau ubah pencarian.</p></GroupState>}
            {official.status === 'success' && (
              <div className="troubleshooting-results" aria-live="polite">
                {sameCodeVariants && <div className="troubleshooting-variant-note"><strong>{official.items[0].code} tersedia untuk beberapa unit.</strong><span>Pilih unit atau aksesori yang sesuai. Semua varian tetap ditampilkan.</span></div>}
                <div className="troubleshooting-result-list">{visibleOfficial.map((entry) => <ErrorKnowledgeResult key={entry.id} entry={entry} machine={selectedMachine} onOpen={() => navigate(troubleshootingDetailUrl(entry.id, selectedMachine?.id))} />)}</div>
                <LoadMore group={official} onMore={() => controller.loadMore('official')} />
              </div>
            )}
          </section>

          <section className="troubleshooting-group troubleshooting-group-field" aria-label="Pengalaman Lapangan">
            <header className="troubleshooting-group-head">
              <div><span className="card-kicker">Pengalaman Lapangan</span><h2>{field.status === 'success' ? `${field.total} pengalaman ditemukan` : 'Penanganan yang pernah dilakukan'}</h2></div>
            </header>
            <p className="troubleshooting-group-disclaimer">Berdasarkan penanganan sebelumnya, bukan prosedur resmi pabrikan.</p>
            {field.status === 'skipped' && <p className="troubleshooting-group-note"><Info size={16} />Kata kunci terlalu pendek. Gunakan minimal satu kata dengan 2 huruf atau lebih.</p>}
            {field.status === 'loading' && <GroupState icon={<LoaderCircle className="spin" size={22} />} title="Mencari pengalaman lapangan…" role="status" />}
            {field.status === 'error' && (
              <GroupState icon={<AlertCircle size={24} />} title="Pencarian belum dapat dimuat." tone="error" role="alert">
                <p>Pengalaman lapangan sementara tidak tersedia. Pengetahuan resmi tetap ditampilkan.</p>
                <button className="secondary-button compact-button" type="button" onClick={() => controller.retry('field')}>Coba lagi</button>
              </GroupState>
            )}
            {field.status === 'empty' && <GroupState icon={<ClipboardList size={24} />} title="Belum ada pengalaman lapangan untuk pencarian ini."><p>Hanya perbaikan yang sudah selesai dengan hasil pengujian Normal atau Perlu Monitoring yang tampil di sini.</p></GroupState>}
            {field.status === 'success' && (
              <div className="troubleshooting-results" aria-live="polite">
                <div className="troubleshooting-result-list">{field.items.map((item) => <FieldExperienceResult key={item.id} item={item} timezone={timezone} onOpen={() => navigate(fieldExperienceTarget(item, { query: search.query || initialQuery, machineId: contextMachineId }))} />)}</div>
                <LoadMore group={field} onMore={() => controller.loadMore('field')} />
              </div>
            )}
          </section>
        </div>
      )}
    </section>
  )
}
