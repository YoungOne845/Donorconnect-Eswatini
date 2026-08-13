import { ArrowLeft, Eye, EyeOff, Fingerprint, KeyRound, LogIn, MessageSquareText, Mail, ShieldAlert } from 'lucide-react'
import { useState } from 'react'
import { Link, Navigate, useLocation, useNavigate } from 'react-router-dom'
import FormMessage from '../components/FormMessage'
import { useAuth } from '../context/AuthContext'
import { NATIONAL_ID_LENGTH, normalizeNationalId } from '../utils/identity'

export default function LoginPage() {
  const { user, login, requestOtp, verifyOtp, forgotRequest, forgotSend, forgotReset } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [mode, setMode] = useState('password')
  const [form, setForm] = useState({ national_id: '', phone: '', password: '', otp: '' })
  const [showPassword, setShowPassword] = useState(false)
  const [otpState, setOtpState] = useState({ sent: false, maskedPhone: '', demoOtp: '', expiresAt: '' })
  const [forgotState, setForgotState] = useState({
    national_id: '',
    maskedPhone: '',
    maskedEmail: '',
    hasEmail: false,
    method: 'phone',
    demoOtp: '',
    code: '',
    newPassword: '',
    confirmPassword: ''
  })
  const [state, setState] = useState({ loading: false, message: '', errors: null, type: 'error' })

  if (user) return <Navigate to="/app/dashboard" replace />

  const destination = location.state?.from || '/app/dashboard'

  const submitPassword = async (event) => {
    event.preventDefault()
    setState({ loading: true, message: '', errors: null, type: 'error' })

    try {
      await login({ national_id: form.national_id, password: form.password })
      navigate(destination, { replace: true })
    } catch (error) {
      setState({ loading: false, message: error.message, errors: error.errors, type: 'error' })
    }
  }

      const sendOtp = async () => {
    setState({ loading: true, message: '', errors: null, type: 'error' })
    try {
      const result = await requestOtp({ national_id: form.national_id, phone: form.phone })
      setOtpState({
        sent: true,
        maskedPhone: result.masked_phone || '',
        expiresAt: result.expires_at || '',
      })
      setState({
        loading: false,
            message: `SMS OTP sent${result.masked_phone ? ` to ${result.masked_phone}` : ''}.`,
        errors: null,
        type: 'success',
      })
    } catch (error) {
      setState({ loading: false, message: error.message, errors: error.errors, type: 'error' })
    }
  }

  const submitOtp = async (event) => {
    event.preventDefault()
    setState({ loading: true, message: '', errors: null, type: 'error' })
    try {
      await verifyOtp({ national_id: form.national_id, otp: form.otp })
      navigate(destination, { replace: true })
    } catch (error) {
      setState({ loading: false, message: error.message, errors: error.errors, type: 'error' })
    }
  }

  const handleForgotRequest = async (event) => {
    event.preventDefault()
    setState({ loading: true, message: '', errors: null, type: 'error' })
    try {
      const result = await forgotRequest({ national_id: form.national_id })
      setForgotState({
        national_id: form.national_id,
        maskedPhone: result.masked_phone,
        maskedEmail: result.masked_email,
        hasEmail: result.has_email,
        method: 'phone',
        demoOtp: '',
        code: '',
        newPassword: '',
        confirmPassword: ''
      })
      switchMode('forgot_send')
    } catch (error) {
      setState({ loading: false, message: error.message, errors: error.errors, type: 'error' })
    }
  }

      const handleForgotSend = async (event) => {
    event.preventDefault()
    setState({ loading: true, message: '', errors: null, type: 'error' })
    try {
      const result = await forgotSend({ national_id: forgotState.national_id, method: forgotState.method })
      setForgotState(prev => ({
        ...prev,
            demoOtp: '',
        code: ''
      }))
      setState({
        loading: false,
            message: `Code sent to your chosen recovery method. Check your messages.`,
        errors: null,
        type: 'success'
      })
      switchMode('forgot_reset')
    } catch (error) {
      setState({ loading: false, message: error.message, errors: error.errors, type: 'error' })
    }
  }

  const handleForgotReset = async (event) => {
    event.preventDefault()
    setState({ loading: true, message: '', errors: null, type: 'error' })
    try {
      await forgotReset({
        national_id: forgotState.national_id,
        code: forgotState.code,
        new_password: forgotState.newPassword,
        confirm_password: forgotState.confirmPassword
      })
      setState({
        loading: false,
        message: 'Password reset successfully. You can now login.',
        errors: null,
        type: 'success'
      })
      setForgotState({
        national_id: '',
        maskedPhone: '',
        maskedEmail: '',
        hasEmail: false,
        method: 'phone',
        demoOtp: '',
        code: '',
        newPassword: '',
        confirmPassword: ''
      })
      switchMode('password')
    } catch (error) {
      setState({ loading: false, message: error.message, errors: error.errors, type: 'error' })
    }
  }

  const switchMode = (nextMode) => {
    setMode(nextMode)
    setState({ loading: false, message: '', errors: null, type: 'error' })
  }

  const handleFormSubmit = (event) => {
    if (mode === 'password') return submitPassword(event)
    if (mode === 'otp') return submitOtp(event)
    if (mode === 'forgot_request') return handleForgotRequest(event)
    if (mode === 'forgot_send') return handleForgotSend(event)
    if (mode === 'forgot_reset') return handleForgotReset(event)
  }

  return (
    <div className="auth-page">
      <section className="auth-panel auth-story">
        <Link to="/" className="back-link"><ArrowLeft size={17} /> Back to DonorConnect</Link>
        <div className="auth-story-copy">
          <img src="/donor-mark.svg" alt="" />
          <span>Welcome back</span>
          <h1>Your donor journey continues here.</h1>
          <p>Track eligibility, manage availability, join campaigns and stay connected to Eswatini&apos;s donor community.</p>
        </div>
        <div className="auth-quote">
          <strong>One registration should become a lifetime of impact.</strong>
          <span>That is what donor retention looks like.</span>
        </div>
      </section>

      <section className="auth-panel auth-form-panel">
        <form className="auth-form" onSubmit={handleFormSubmit}>
          <div className="form-heading">
            <span className="form-icon"><Fingerprint size={22} /></span>
            <h2>{mode.startsWith('forgot') ? 'Reset password' : 'Sign in'}</h2>
            <p>{
              mode === 'forgot_request' ? 'Enter your National ID to locate your account.' :
              mode === 'forgot_send' ? 'Select where to send your verification reset code.' :
              mode === 'forgot_reset' ? 'Enter the verification code and your new password.' :
              'Use your National ID with either your password or an SMS OTP. SMS OTP works for every donor account.'
            }</p>
          </div>

          {!mode.startsWith('forgot') && (
            <div className="login-mode-toggle" role="tablist" aria-label="Choose sign in method">
              <button type="button" className={mode === 'password' ? 'active' : ''} onClick={() => switchMode('password')}>
                <KeyRound size={17} /> Password
              </button>
              <button type="button" className={mode === 'otp' ? 'active' : ''} onClick={() => switchMode('otp')}>
                <MessageSquareText size={17} /> SMS OTP
              </button>
            </div>
          )}

          <FormMessage type={state.type} message={state.message} errors={state.errors} />

          {(mode === 'password' || mode === 'otp' || mode === 'forgot_request') && (
            <label>
              National ID number
              <input
                value={form.national_id}
                onChange={(event) => setForm({ ...form, national_id: normalizeNationalId(event.target.value) })}
                placeholder="0412227100041"
                inputMode="numeric"
                maxLength={NATIONAL_ID_LENGTH}
                autoComplete="username"
                required
                disabled={state.loading}
              />
            </label>
          )}

          {mode === 'password' && (
            <div style={{ display: 'grid', gap: '7px' }}>
              <label>
                Password
                <div className="password-field">
                  <input
                    type={showPassword ? 'text' : 'password'}
                    value={form.password}
                    onChange={(event) => setForm({ ...form, password: event.target.value })}
                    placeholder="Enter your password"
                    autoComplete="current-password"
                    required
                    disabled={state.loading}
                  />
                  <button type="button" onClick={() => setShowPassword((value) => !value)} aria-label="Toggle password visibility">
                    {showPassword ? <EyeOff size={18} /> : <Eye size={18} />}
                  </button>
                </div>
              </label>
              <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: '-2px', marginBottom: '8px' }}>
                <button
                  type="button"
                  style={{
                    fontSize: '13px',
                    color: 'var(--red-700, #b91c1c)',
                    background: 'transparent',
                    border: 'none',
                    cursor: 'pointer',
                    fontWeight: '600',
                    padding: 0
                  }}
                  onClick={() => {
                    setForgotState(prev => ({
                      ...prev,
                      national_id: form.national_id || ''
                    }))
                    switchMode('forgot_request')
                  }}
                >
                  Forgot Password?
                </button>
              </div>
            </div>
          )}

          {mode === 'otp' && (
            <>
              <div className="otp-helper-card">
                <strong>SMS OTP works for all donors</strong>
                <span>Enter your National ID and the phone number linked to your account. A 6-digit code will be sent to that number via SMS.</span>
              </div>
              <label>
                Phone number used during registration
                <input
                  value={form.phone}
                  onChange={(event) => setForm({ ...form, phone: event.target.value })}
                  placeholder="76123456"
                  inputMode="tel"
                  autoComplete="tel"
                  required
                  disabled={state.loading}
                />
              </label>
              <div className="button-row wrap">
                <button type="button" className="button button-secondary" onClick={sendOtp} disabled={state.loading || form.national_id.length !== NATIONAL_ID_LENGTH || form.phone.trim().length < 8}>
                  {state.loading && !otpState.sent ? 'Sending SMS OTP…' : 'Send SMS OTP'}
                </button>
                {otpState.maskedPhone ? <small>Sent to {otpState.maskedPhone}</small> : null}
              </div>
              <label>
                SMS OTP code
                <input
                  value={form.otp}
                  onChange={(event) => setForm({ ...form, otp: event.target.value.replace(/\D+/g, '').slice(0, 6) })}
                  placeholder="Enter 6-digit code"
                  inputMode="numeric"
                  maxLength={6}
                  autoComplete="one-time-code"
                  required
                  disabled={state.loading || !otpState.sent}
                />
              </label>
            </>
          )}

          {mode === 'forgot_send' && (
            <div>
              <div className="forgot-helper">
                Choose how you want to receive your 6-digit reset verification code:
              </div>
              <div className="recovery-options-grid">
                <div 
                  className={`recovery-option-card ${forgotState.method === 'phone' ? 'active' : ''}`}
                  onClick={() => setForgotState({ ...forgotState, method: 'phone' })}
                >
                  <div className="recovery-option-icon">
                    <MessageSquareText size={20} />
                  </div>
                  <div className="recovery-option-info">
                    <span className="recovery-option-title">SMS / Phone</span>
                    <span className="recovery-option-detail">Send code to {forgotState.maskedPhone}</span>
                  </div>
                  <input
                    type="radio"
                    name="forgot_method"
                    checked={forgotState.method === 'phone'}
                    readOnly
                    className="recovery-option-radio"
                  />
                </div>

                {forgotState.hasEmail && (
                  <div 
                    className={`recovery-option-card ${forgotState.method === 'email' ? 'active' : ''}`}
                    onClick={() => setForgotState({ ...forgotState, method: 'email' })}
                  >
                    <div className="recovery-option-icon">
                      <Mail size={20} />
                    </div>
                    <div className="recovery-option-info">
                      <span className="recovery-option-title">Email Address</span>
                      <span className="recovery-option-detail">Send code to {forgotState.maskedEmail}</span>
                    </div>
                    <input
                      type="radio"
                      name="forgot_method"
                      checked={forgotState.method === 'email'}
                      readOnly
                      className="recovery-option-radio"
                    />
                  </div>
                )}
              </div>
            </div>
          )}

          {mode === 'forgot_reset' && (
            <div style={{ display: 'grid', gap: '16px' }}>
              <label>
                6-Digit Verification Code
                <input
                  value={forgotState.code}
                  onChange={(event) => setForgotState({ ...forgotState, code: event.target.value.replace(/\D+/g, '').slice(0, 6) })}
                  placeholder="123456"
                  inputMode="numeric"
                  maxLength={6}
                  style={{ letterSpacing: '0.15em', fontWeight: '700', fontSize: '16px', textAlign: 'center' }}
                  required
                  disabled={state.loading}
                />
              </label>

              <label>
                New Password
                <div className="password-field">
                  <input
                    type={showPassword ? 'text' : 'password'}
                    value={forgotState.newPassword}
                    onChange={(event) => setForgotState({ ...forgotState, newPassword: event.target.value })}
                    placeholder="At least 10 chars (A-Z, a-z, 0-9)"
                    required
                    disabled={state.loading}
                  />
                  <button type="button" onClick={() => setShowPassword((value) => !value)} aria-label="Toggle password visibility">
                    {showPassword ? <EyeOff size={18} /> : <Eye size={18} />}
                  </button>
                </div>
              </label>

              <label>
                Confirm New Password
                <input
                  type="password"
                  value={forgotState.confirmPassword}
                  onChange={(event) => setForgotState({ ...forgotState, confirmPassword: event.target.value })}
                  placeholder="Re-enter new password"
                  required
                  disabled={state.loading}
                />
              </label>
            </div>
          )}

          {mode === 'forgot_request' && (
            <button className="button button-primary button-full" disabled={state.loading || form.national_id.length !== NATIONAL_ID_LENGTH}>
              {state.loading ? 'Searching…' : 'Find Account'}
            </button>
          )}

          {mode === 'forgot_send' && (
            <button className="button button-primary button-full" disabled={state.loading}>
              {state.loading ? 'Sending code…' : 'Send Reset Code'}
            </button>
          )}

          {mode === 'forgot_reset' && (
            <button className="button button-primary button-full" disabled={state.loading || forgotState.code.length !== 6}>
              {state.loading ? 'Resetting…' : 'Reset Password'}
            </button>
          )}

          {(mode === 'password' || (mode === 'otp' && otpState.sent)) && (
            <button className="button button-primary button-full" disabled={state.loading || (mode === 'otp' && !otpState.sent)}>
              {state.loading ? 'Signing in…' : <>Sign in <LogIn size={18} /></>}
            </button>
          )}

          {mode.startsWith('forgot') ? (
            <p className="auth-switch">
              <button
                type="button"
                className="text-xs text-red-400 hover:text-red-300 underline bg-transparent border-0 cursor-pointer p-0"
                onClick={() => {
                  setForgotState({
                    national_id: '',
                    maskedPhone: '',
                    maskedEmail: '',
                    hasEmail: false,
                    method: 'phone',
                    demoOtp: '',
                    code: '',
                    newPassword: '',
                    confirmPassword: ''
                  })
                  switchMode('password')
                }}
              >
                Back to Sign In
              </button>
            </p>
          ) : (
            <p className="auth-switch">New to DonorConnect? <Link to="/register">Join the donor pool</Link></p>
          )}
        </form>
      </section>
    </div>
  )
}
