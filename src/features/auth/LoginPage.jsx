import { useState } from 'react'
import { ArrowRight, Eye, EyeOff, LockKeyhole, Printer } from 'lucide-react'
import { useAuth } from './useAuth.js'
import { consumePostLoginNotice } from './postLoginNotice.js'

export function LoginPage() {
  const { signIn, configurationError } = useAuth()
  const [identifier, setIdentifier] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [error, setError] = useState(configurationError)
  const [notice] = useState(() => consumePostLoginNotice())

  async function handleSubmit(event) {
    event.preventDefault()
    setError(null)
    setIsSubmitting(true)
    try {
      await signIn(identifier.trim(), password)
    } catch {
      setError('Invalid username/email or password.')
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <main className="login-page">
      <div className="ambient-orb ambient-orb-one" />
      <div className="ambient-orb ambient-orb-two" />

      <section className="login-story" aria-label="A3 Tracker introduction">
        <div className="brand-lockup">
          <span className="brand-mark"><Printer size={24} strokeWidth={1.8} /></span>
          <span>A3 Tracker</span>
        </div>
        <div className="login-story-copy">
          <span className="eyebrow">Machine operations, clearly managed</span>
          <h1>Your production floor.<br />One calm workspace.</h1>
          <p>Track every machine, branch, and operating signal with a secure workspace built for print teams.</p>
        </div>
        <div className="login-proof glass-surface">
          <div className="proof-pulse"><span /></div>
          <div>
            <strong>Secure tenant workspace</strong>
            <p>Access is controlled by your authenticated account membership.</p>
          </div>
        </div>
      </section>

      <section className="login-panel-wrap">
        <div className="login-panel glass-surface">
          <div className="mobile-brand brand-lockup">
            <span className="brand-mark"><Printer size={21} strokeWidth={1.8} /></span>
            <span>A3 Tracker</span>
          </div>
          <div className="login-heading">
            <span className="login-icon"><LockKeyhole size={22} /></span>
            <div><h2>Welcome back</h2><p>Sign in to your operations workspace.</p></div>
          </div>

          <form onSubmit={handleSubmit} className="login-form">
            <label>
              <span>Email or username</span>
              <input type="text" value={identifier} onChange={(event) => setIdentifier(event.target.value)} placeholder="name@example.com or admin.tuparev" autoComplete="username" required />
            </label>
            <label>
              <span>Password</span>
              <div className="password-field">
                <input type={showPassword ? 'text' : 'password'} value={password} onChange={(event) => setPassword(event.target.value)} placeholder="Enter your password" autoComplete="current-password" required />
                <button type="button" className="icon-button password-toggle" onClick={() => setShowPassword((visible) => !visible)} aria-label={showPassword ? 'Hide password' : 'Show password'}>
                  {showPassword ? <EyeOff size={18} /> : <Eye size={18} />}
                </button>
              </div>
            </label>
            {notice && !error && <div className="success-banner" role="status"><span>{notice}</span></div>}
            {error && <div className="auth-error" role="alert">{error}</div>}
            <button className="primary-button login-button" type="submit" disabled={isSubmitting || Boolean(configurationError)}>
              <span>{isSubmitting ? 'Signing in…' : 'Sign in securely'}</span>
              {!isSubmitting && <ArrowRight size={18} />}
            </button>
          </form>
          <p className="login-help">Access is available to invited team members. Public signup is disabled.</p>
        </div>
      </section>
    </main>
  )
}
