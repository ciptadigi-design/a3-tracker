import { useEffect, useState } from 'react'
import { Eye, EyeOff, KeyRound, LoaderCircle, Mail, RotateCcw, UserRound } from 'lucide-react'
import { PageHeader } from '../components/ui/PageHeader.jsx'
import { useTenant } from '../features/account/useTenant.js'
import { useAuth } from '../features/auth/useAuth.js'
import { updateMyEmail, updateMyPassword, updateMyProfile } from '../services/supabase/account.js'

const requestId = () => crypto.randomUUID()

function Message({ error, notice }) {
  if (error) return <div className="form-error" role="alert">{error.message || error}</div>
  return notice ? <div className="success-banner" role="status"><span>{notice}</span></div> : null
}

function PasswordInput({ label, value, onChange, autoComplete }) {
  const [visible, setVisible] = useState(false)
  return <label className="form-field form-field-wide"><span>{label}</span><div className="password-input-wrap"><input type={visible ? 'text' : 'password'} value={value} onChange={onChange} autoComplete={autoComplete} /><button type="button" className="icon-button" onClick={() => setVisible((current) => !current)} aria-label={visible ? 'Hide password' : 'Show password'}>{visible ? <EyeOff size={16} /> : <Eye size={16} />}</button></div></label>
}

export function MyAccountPage() {
  const { user } = useAuth()
  const tenant = useTenant()
  const [profile, setProfile] = useState({ displayName: tenant.profile?.display_name ?? '', username: tenant.profile?.username ?? '' })
  const [email, setEmail] = useState({ value: user?.email ?? '', currentPassword: '' })
  const [password, setPassword] = useState({ current: '', next: '', confirm: '' })
  const [busy, setBusy] = useState(null)
  const [message, setMessage] = useState({})

  useEffect(() => setProfile({ displayName: tenant.profile?.display_name ?? '', username: tenant.profile?.username ?? '' }), [tenant.profile])
  useEffect(() => setEmail((current) => ({ ...current, value: user?.email ?? '' })), [user?.email])

  async function saveProfile(event) {
    event.preventDefault(); setMessage({})
    if (!profile.displayName.trim() || !profile.username.trim()) return setMessage({ error: 'Display name and username are required.' })
    setBusy('profile')
    try {
      await updateMyProfile({ ...profile, clientRequestId: requestId() })
      await tenant.refresh()
      setMessage({ notice: 'Profile updated. Your role, Branch access, and platform privilege were unchanged.' })
    } catch (error) { setMessage({ error }) } finally { setBusy(null) }
  }

  async function saveEmail(event) {
    event.preventDefault(); setMessage({})
    if (!email.value.trim() || !email.currentPassword) return setMessage({ error: 'Email and current password are required.' })
    setBusy('email')
    try {
      await updateMyEmail({ email: email.value, currentPassword: email.currentPassword, clientRequestId: requestId() })
      setEmail((current) => ({ ...current, currentPassword: '' }))
      setMessage({ notice: 'Email updated and your session refreshed.' })
    } catch (error) { setMessage({ error }) } finally { setBusy(null) }
  }

  async function savePassword(event) {
    event.preventDefault(); setMessage({})
    if (!password.current || !password.next || password.next !== password.confirm) return setMessage({ error: 'Current password and matching new passwords are required.' })
    if (password.next.length < 10) return setMessage({ error: 'New password must be at least 10 characters.' })
    setBusy('password')
    try {
      await updateMyPassword({ currentPassword: password.current, password: password.next, clientRequestId: requestId() })
      setPassword({ current: '', next: '', confirm: '' })
      setMessage({ notice: 'Password changed. Existing role, Branch access, and platform privilege were unchanged.' })
    } catch (error) { setMessage({ error }) } finally { setBusy(null) }
  }

  return <div className="page-stack my-account-page">
    <PageHeader eyebrow="Personal identity" title="My Account" description="Manage your own login identity. Workspace roles, Branch access, and platform privileges remain governance-controlled." />
    <Message {...message} />
    <section className="settings-card glass-surface"><header><span className="settings-feature-icon"><UserRound size={20} /></span><div><span className="card-kicker">Profile</span><h2>Display name & username</h2><p>Your username and email continue to use one Supabase Auth password.</p></div></header>
      <form className="machine-form" onSubmit={saveProfile}><div className="machine-form-body"><div className="form-grid"><label className="form-field"><span>Display name *</span><input value={profile.displayName} onChange={(event) => setProfile((current) => ({ ...current, displayName: event.target.value }))} /></label><label className="form-field"><span>Username *</span><input value={profile.username} onChange={(event) => setProfile((current) => ({ ...current, username: event.target.value }))} autoComplete="username" /></label></div></div><footer className="dialog-actions form-action-footer"><button type="button" className="draft-reset-button" aria-label="Reset draft" onClick={() => setProfile({ displayName: tenant.profile?.display_name ?? '', username: tenant.profile?.username ?? '' })}><RotateCcw size={15} />Reset draft</button><button className="primary-button" disabled={busy !== null}>{busy === 'profile' && <LoaderCircle className="spin" size={16} />}Save profile</button></footer></form>
    </section>
    <section className="settings-card glass-surface"><header><span className="settings-feature-icon"><Mail size={20} /></span><div><span className="card-kicker">Authentication</span><h2>Email</h2><p>Current-password verification protects the authoritative Supabase Auth email change.</p></div></header>
      <form className="machine-form" onSubmit={saveEmail}><div className="machine-form-body"><div className="form-grid"><label className="form-field form-field-wide"><span>Email *</span><input type="email" value={email.value} onChange={(event) => setEmail((current) => ({ ...current, value: event.target.value }))} autoComplete="email" /></label><PasswordInput label="Current password *" value={email.currentPassword} onChange={(event) => setEmail((current) => ({ ...current, currentPassword: event.target.value }))} autoComplete="current-password" /></div></div><footer className="dialog-actions form-action-footer"><button className="primary-button" disabled={busy !== null}>{busy === 'email' && <LoaderCircle className="spin" size={16} />}Change email</button></footer></form>
    </section>
    <section className="settings-card glass-surface"><header><span className="settings-feature-icon"><KeyRound size={20} /></span><div><span className="card-kicker">Credential</span><h2>Password</h2><p>The current password is verified before replacement. Password content is never stored in application tables or audit evidence.</p></div></header>
      <form className="machine-form" onSubmit={savePassword}><div className="machine-form-body"><div className="form-grid"><PasswordInput label="Current password *" value={password.current} onChange={(event) => setPassword((current) => ({ ...current, current: event.target.value }))} autoComplete="current-password" /><PasswordInput label="New password *" value={password.next} onChange={(event) => setPassword((current) => ({ ...current, next: event.target.value }))} autoComplete="new-password" /><PasswordInput label="Confirm new password *" value={password.confirm} onChange={(event) => setPassword((current) => ({ ...current, confirm: event.target.value }))} autoComplete="new-password" /></div></div><footer className="dialog-actions form-action-footer"><button className="primary-button" disabled={busy !== null}>{busy === 'password' && <LoaderCircle className="spin" size={16} />}Change password</button></footer></form>
    </section>
  </div>
}
