import { useEffect, useState } from 'react'
import { Save } from 'lucide-react'
import { userErrorMessage } from '../../lib/appErrors.js'
import { loadMachineFinancialRates, saveMachineFinancialRates } from '../../services/downtime.js'
import { FINANCIAL_ESTIMATE_NOTE } from '../downtime/downtimeModel.js'
import { ratesFromForm, ratesToForm } from './financialRatesModel.js'

/** Configured hourly estimates behind Owner "Dampak Finansial". Blank = Belum dikonfigurasi (never Rp0). */
export function FinancialRatesPanel({ machineId, canManage }) {
  const [form, setForm] = useState(null)
  const [error, setError] = useState(null)
  const [saved, setSaved] = useState(false)
  const [isSaving, setIsSaving] = useState(false)

  useEffect(() => {
    let current = true
    setForm(null); setError(null); setSaved(false)
    loadMachineFinancialRates(machineId).then((rates) => { if (current) setForm(ratesToForm(rates)) }).catch((loadError) => { if (current) setError(loadError) })
    return () => { current = false }
  }, [machineId])

  async function submit(event) {
    event.preventDefault()
    const payload = ratesFromForm(form)
    if (!payload) { setError(new Error('Isi tarif dengan bilangan bulat Rupiah (≥ 0), atau kosongkan jika belum dikonfigurasi.')); return }
    setIsSaving(true); setError(null); setSaved(false)
    try {
      setForm(ratesToForm(await saveMachineFinancialRates(machineId, payload)))
      setSaved(true)
    } catch (saveError) {
      setError(saveError)
    } finally {
      setIsSaving(false)
    }
  }

  const field = (key, label) => <label><span>{label}</span><input type="number" inputMode="numeric" min="0" step="1" placeholder="Belum dikonfigurasi" value={form?.[key] ?? ''} disabled={!form || !canManage} onChange={(event) => { setSaved(false); setForm((current) => ({ ...current, [key]: event.target.value })) }} /></label>

  return (
    <section className="machine-cost-panel glass-surface financial-rates-panel" aria-label="Tarif per jam">
      <header><div><span className="card-kicker">Dampak Finansial</span><h2>Tarif Per Jam (Estimasi)</h2><p>Dipakai untuk Biaya Operasional Terdampak dan Potensi Pendapatan Hilang di Overview. {FINANCIAL_ESTIMATE_NOTE}</p></div></header>
      <form onSubmit={submit}>
        {field('operatingCostPerHour', 'Biaya operasional per jam (Rp)')}
        {field('estimatedRevenuePerHour', 'Estimasi pendapatan per jam (Rp)')}
        {canManage && <button className="primary-button" type="submit" disabled={!form || isSaving}><Save size={15} />{isSaving ? 'Menyimpan…' : 'Simpan'}</button>}
      </form>
      {saved && <p role="status">Tarif disimpan.</p>}
      {error && <div className="inline-error" role="alert">{userErrorMessage(error, 'Tarif per jam tidak dapat diproses.')}</div>}
    </section>
  )
}
