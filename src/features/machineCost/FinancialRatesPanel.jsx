import { useEffect, useState } from 'react'
import { Save } from 'lucide-react'
import { userErrorMessage } from '../../lib/appErrors.js'
import { loadMachineFinancialRates, saveMachineFinancialRates } from '../../services/downtime.js'
import { InfoTip } from '../../components/ui/InfoTip.jsx'
import { formatRateInput, parseRateInput, ratesFromForm, ratesToForm } from './financialRatesModel.js'

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
    if (!payload) { setError(new Error('Tarif harus berupa bilangan bulat Rupiah.')); return }
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

  const field = (key, label) => <label><span>{label}</span><input type="text" inputMode="numeric" autoComplete="off" placeholder="Belum diatur" value={formatRateInput(form?.[key])} disabled={!form || !canManage} onChange={(event) => { setSaved(false); setForm((current) => ({ ...current, [key]: parseRateInput(event.target.value) })) }} /></label>

  return (
    <section className="machine-cost-panel glass-surface financial-rates-panel" aria-label="Tarif per jam">
      <header><div><h2>Tarif Per Jam <small className="estimate-badge">Estimasi</small><InfoTip text="Perhitungan menggunakan tarif per jam saat ini." /></h2></div></header>
      <form onSubmit={submit}>
        {field('operatingCostPerHour', 'Biaya Operasional')}
        {field('estimatedRevenuePerHour', 'Potensi Pendapatan')}
        {canManage && <button className="primary-button" type="submit" disabled={!form || isSaving}><Save size={15} />{isSaving ? 'Menyimpan…' : 'Simpan'}</button>}
      </form>
      {saved && <small role="status">Tarif disimpan.</small>}
      {error && <div className="inline-error" role="alert">{userErrorMessage(error, 'Tarif per jam tidak dapat diproses.')}</div>}
    </section>
  )
}
