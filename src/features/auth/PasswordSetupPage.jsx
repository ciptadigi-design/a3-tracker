import { useState } from 'react'
import { LoaderCircle, LockKeyhole, Printer } from 'lucide-react'
import { useAuth } from './useAuth.js'
import { userErrorMessage } from '../../lib/appErrors.js'

export function PasswordSetupPage() {
  const { completePasswordSetup } = useAuth()
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  async function submit(event) {
    event.preventDefault()
    if (password.length < 8) return setError('Use at least 8 characters.')
    if (password !== confirmation) return setError('Passwords do not match.')
    setBusy(true); setError(null)
    try { await completePasswordSetup(password) } catch (caught) { setError(userErrorMessage(caught, 'Password could not be saved. Please request a new recovery link if this one expired.')) } finally { setBusy(false) }
  }
  return <main className="login-page"><section className="login-panel-wrap"><div className="login-panel glass-surface">
    <div className="mobile-brand brand-lockup"><span className="brand-mark"><Printer size={21} /></span><span>A3 Tracker</span></div>
    <div className="login-heading"><span className="login-icon"><LockKeyhole size={22} /></span><div><h2>Set your password</h2><p>Complete your secure workspace invitation.</p></div></div>
    <form className="login-form" onSubmit={submit}><label><span>New password</span><input type="password" autoComplete="new-password" value={password} onChange={(event) => setPassword(event.target.value)} required /></label><label><span>Confirm password</span><input type="password" autoComplete="new-password" value={confirmation} onChange={(event) => setConfirmation(event.target.value)} required /></label>{error && <div className="auth-error" role="alert">{error}</div>}<button className="primary-button login-button" disabled={busy}>{busy && <LoaderCircle className="spin" size={17} />}{busy ? 'Saving…' : 'Set password and continue'}</button></form>
  </div></section></main>
}
