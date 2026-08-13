import {
  AlertTriangle,
  ArrowLeft,
  ArrowRight,
  CalendarDays,
  Check,
  Eye,
  EyeOff,
  HeartHandshake,
  ShieldCheck,
} from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { Link, Navigate, useNavigate } from 'react-router-dom'
import { api } from '../api/client'
import FormMessage from '../components/FormMessage'
import { useAuth } from '../context/AuthContext'
import {
  BLOOD_TYPES,
  ESWATINI_REGIONS,
  ESWATINI_TOWNS,
  GENDER_OPTIONS,
  RECRUITMENT_SOURCES,
} from '../data/eswatini'
import {
  NATIONAL_ID_LENGTH,
  formatLongDate,
  normalizeNationalId,
  parseNationalId,
} from '../utils/identity'

const initialForm = {
  full_name: '',
  national_id: '',
  phone: '',
  email: '',
  password: '',
  gender: '',
  blood_type: 'Unknown',
  region: '',
  town: '',
  address: '',
  availability_status: 'available',
  preferred_contact_method: 'sms',
  recruitment_source: '',
  recruitment_institution_id: '',
  referral_code: '',
  emergency_contact_name: '',
  emergency_contact_phone: '',
  consent_to_notifications: true,
}

export default function RegisterPage() {
  const { user, register } = useAuth()
  const navigate = useNavigate()
  const [form, setForm] = useState(initialForm)
  const [institutions, setInstitutions] = useState([])
  const [step, setStep] = useState(1)
  const [showPassword, setShowPassword] = useState(false)
  const [customTown, setCustomTown] = useState('')
  const [state, setState] = useState({ loading: false, message: '', errors: null })

  useEffect(() => {
    api('/institutions')
      .then(setInstitutions)
      .catch(() => setInstitutions([]))
  }, [])

  const identityDetails = useMemo(() => parseNationalId(form.national_id), [form.national_id])
  const townOptions = form.region ? ESWATINI_TOWNS[form.region] || [] : []
  const relevantInstitutions = useMemo(
    () => institutions
      .filter((item) => item.is_active == 1)
      .sort((a, b) => a.name.localeCompare(b.name)),
    [institutions],
  )

  if (user) return <Navigate to="/app/dashboard" replace />

  const update = (field, value) => {
    setForm((current) => ({ ...current, [field]: value }))
    if (state.message || state.errors) {
      setState((current) => ({ ...current, message: '', errors: null }))
    }
  }

  const updateNationalId = (value) => {
    update('national_id', normalizeNationalId(value))
  }

  const updateRegion = (region) => {
    setCustomTown('')
    setForm((current) => ({ ...current, region, town: '' }))
  }

  const updateTown = (value) => {
    setCustomTown('')
    update('town', value)
  }

  const validateCurrentStep = () => {
    if (step !== 1) return true

    if (!identityDetails.valid) {
      setState({
        loading: false,
        message: 'Please check your national ID number.',
        errors: {
          national_id: 'Enter all 13 digits. The first six digits must contain your birth date in YYMMDD format.',
        },
      })
      return false
    }

    if (!identityDetails.isEligible) {
      const eligibleDate = formatLongDate(identityDetails.eligibleOn)
      setState({
        loading: false,
        message: `You are under the required age to donate. Based on your details, you will be eligible to register on ${eligibleDate}. Please come back then — we will be happy to welcome you to DonorConnect.`,
        errors: null,
      })
      return false
    }

    return true
  }

  const submit = async (event) => {
    event.preventDefault()

    if (step < 3) {
      if (!validateCurrentStep()) return
      setStep((value) => value + 1)
      return
    }

    setState({ loading: true, message: '', errors: null })
    try {
      await register({
        ...form,
        town: form.town === 'Other / rural locality' ? customTown.trim() : form.town,
        recruitment_institution_id: form.recruitment_institution_id || null,
      })
      navigate('/app/dashboard', { replace: true })
    } catch (error) {
      setState({ loading: false, message: error.message, errors: error.errors })
    }
  }

  return (
    <div className="register-page">
      <header className="register-header">
        <Link to="/" className="landing-brand">
          <img src="/donor-mark.svg" alt="" />
          <span>DonorConnect</span>
        </Link>
        <Link to="/login" className="button button-ghost">Already registered? Sign in</Link>
      </header>

      <main className="register-shell">
        <aside className="register-aside">
          <Link to="/" className="back-link"><ArrowLeft size={17} /> Back home</Link>
          <span className="hero-kicker">Grow Eswatini&apos;s active donor pool</span>
          <h1>Join once. Stay connected. Donate again.</h1>
          <p>Registration begins your donor lifecycle. Blood-service staff will later verify your details and assess your donation eligibility.</p>
          <div className="register-benefits">
            <span><Check /> Track eligibility and next donation date</span>
            <span><Check /> Receive campaign and milestone updates</span>
            <span><Check /> Control your availability at any time</span>
            <span><Check /> Respond only when you are genuinely available</span>
          </div>
        </aside>

        <section className="register-card">
          <div className="stepper">
            {[1, 2, 3].map((item) => (
              <div key={item} className={item <= step ? 'active' : ''}>
                <span>{item < step ? <Check size={15} /> : item}</span>
                <small>{['Identity', 'Donor profile', 'Engagement'][item - 1]}</small>
              </div>
            ))}
          </div>

          <form onSubmit={submit}>
            <FormMessage message={state.message} errors={state.errors} />

            {step === 1 && (
              <div className="form-section">
                <div className="form-heading compact">
                  <h2>Your identity</h2>
                  <p>Your national ID is your main DonorConnect identifier. It is encrypted, and only a masked form is displayed.</p>
                </div>

                <div className="form-grid two">
                  <label>
                    Full name
                    <input
                      value={form.full_name}
                      onChange={(event) => update('full_name', event.target.value)}
                      autoComplete="name"
                      required
                    />
                  </label>

                  <label>
                    National ID number
                    <input
                      value={form.national_id}
                      onChange={(event) => updateNationalId(event.target.value)}
                      inputMode="numeric"
                      autoComplete="username"
                      maxLength={NATIONAL_ID_LENGTH}
                      placeholder="0412227100041"
                      required
                    />
                    <small>13 digits. The first six digits are your birth date in YYMMDD format.</small>
                  </label>

                  {identityDetails.valid && (
                    <div className={`identity-preview full-span ${identityDetails.isEligible ? 'eligible' : 'underage'}`}>
                      {identityDetails.isEligible ? <ShieldCheck /> : <AlertTriangle />}
                      <div>
                        <strong>
                          {identityDetails.isEligible
                            ? 'Age requirement confirmed'
                            : 'Registration is not open yet'}
                        </strong>
                        <p>
                          Birth date: {formatLongDate(identityDetails.birthDate)} · Current age: {identityDetails.age}
                        </p>
                        {!identityDetails.isEligible && (
                          <p>You can register from <b>{formatLongDate(identityDetails.eligibleOn)}</b>.</p>
                        )}
                        {identityDetails.requiresGuardianConsentNotice && (
                          <p>Because you are under 18, parental or signed guardian consent may be required when you present to donate.</p>
                        )}
                      </div>
                    </div>
                  )}

                  <label>
                    Phone number
                    <input
                      value={form.phone}
                      onChange={(event) => update('phone', event.target.value)}
                      placeholder="76123456"
                      inputMode="tel"
                      autoComplete="tel"
                      required
                    />
                  </label>

                  <label>
                    Email address <span>(optional)</span>
                    <input
                      type="email"
                      value={form.email}
                      onChange={(event) => update('email', event.target.value)}
                      autoComplete="email"
                    />
                  </label>

                  <label>
                    Sex
                    <select value={form.gender} onChange={(event) => update('gender', event.target.value)} required>
                      <option value="">Select sex</option>
                      {GENDER_OPTIONS.map((option) => (
                        <option key={option.value} value={option.value}>{option.label}</option>
                      ))}
                    </select>
                  </label>

                  <div className="derived-date-field">
                    <CalendarDays size={18} />
                    <div>
                      <span>Date of birth</span>
                      <strong>{identityDetails.valid ? formatLongDate(identityDetails.birthDate) : 'Calculated from your national ID'}</strong>
                    </div>
                  </div>

                  <label className="full-span">
                    Create password
                    <div className="password-field">
                      <input
                        type={showPassword ? 'text' : 'password'}
                        value={form.password}
                        onChange={(event) => update('password', event.target.value)}
                        minLength={10}
                        autoComplete="new-password"
                        required
                      />
                      <button type="button" onClick={() => setShowPassword((value) => !value)} aria-label="Toggle password visibility">
                        {showPassword ? <EyeOff size={18} /> : <Eye size={18} />}
                      </button>
                    </div>
                    <small>At least 10 characters with uppercase, lowercase and a number.</small>
                  </label>
                </div>
              </div>
            )}

            {step === 2 && (
              <div className="form-section">
                <div className="form-heading compact">
                  <h2>Your donor profile</h2>
                  <p>Not knowing your blood type should never block recruitment. Blood-service staff can confirm it later.</p>
                </div>

                <div className="form-grid two">
                  <label>
                    Blood type
                    <select value={form.blood_type} onChange={(event) => update('blood_type', event.target.value)}>
                      {BLOOD_TYPES.map((value) => <option key={value}>{value}</option>)}
                    </select>
                  </label>

                  <label>
                    Current availability
                    <select value={form.availability_status} onChange={(event) => update('availability_status', event.target.value)}>
                      <option value="available">Available</option>
                      <option value="not_available">Not available</option>
                    </select>
                  </label>

                  <label>
                    Region
                    <select value={form.region} onChange={(event) => updateRegion(event.target.value)} required>
                      <option value="">Select region</option>
                      {ESWATINI_REGIONS.map((value) => <option key={value}>{value}</option>)}
                    </select>
                  </label>

                  <label>
                    Town / locality
                    <select
                      value={form.town}
                      onChange={(event) => updateTown(event.target.value)}
                      disabled={!form.region}
                      required
                    >
                      <option value="">{form.region ? 'Select town or locality' : 'Select a region first'}</option>
                      {townOptions.map((value) => <option key={value}>{value}</option>)}
                    </select>
                  </label>

                  {form.town === 'Other / rural locality' && (
                    <label className="full-span">
                      Enter your town or locality
                      <input
                        value={customTown}
                        onChange={(event) => setCustomTown(event.target.value)}
                        placeholder="Type the locality name"
                        required
                      />
                    </label>
                  )}

                  <label className="full-span">
                    Address <span>(optional)</span>
                    <textarea value={form.address} onChange={(event) => update('address', event.target.value)} rows="2" />
                  </label>

                  <label>
                    Emergency contact name
                    <input
                      value={form.emergency_contact_name}
                      onChange={(event) => update('emergency_contact_name', event.target.value)}
                      required
                    />
                  </label>

                  <label>
                    Emergency contact phone
                    <input
                      value={form.emergency_contact_phone}
                      onChange={(event) => update('emergency_contact_phone', event.target.value)}
                      inputMode="tel"
                      placeholder="76123456"
                      required
                    />
                  </label>
                </div>
              </div>
            )}

            {step === 3 && (
              <div className="form-section">
                <div className="form-heading compact">
                  <h2>Recruitment and engagement</h2>
                  <p>This data shows which channels genuinely grow and retain the donor pool.</p>
                </div>

                <div className="form-grid two">
                  <label>
                    How did you join?
                    <select value={form.recruitment_source} onChange={(event) => update('recruitment_source', event.target.value)} required>
                      <option value="">Select source</option>
                      {RECRUITMENT_SOURCES.map((option) => (
                        <option key={option.value} value={option.value}>{option.label}</option>
                      ))}
                    </select>
                  </label>

                  <label>
                    Recruiting institution <span>(optional)</span>
                    <select value={form.recruitment_institution_id} onChange={(event) => update('recruitment_institution_id', event.target.value)}>
                      <option value="">None selected</option>
                      {relevantInstitutions.map((item) => (
                        <option key={item.id} value={item.id}>{item.name} — {item.town}</option>
                      ))}
                    </select>
                  </label>

                  <label>
                    Preferred contact
                    <select value={form.preferred_contact_method} onChange={(event) => update('preferred_contact_method', event.target.value)}>
                      <option value="sms">SMS</option>
                      <option value="web">Web notification</option>
                      <option value="phone">Phone</option>
                      <option value="email">Email</option>
                    </select>
                  </label>

                  <label>
                    Referral code <span>(optional)</span>
                    <input value={form.referral_code} onChange={(event) => update('referral_code', event.target.value)} />
                  </label>

                  <label className="checkbox-label full-span">
                    <input
                      type="checkbox"
                      checked={form.consent_to_notifications}
                      onChange={(event) => update('consent_to_notifications', event.target.checked)}
                    />
                    <span>I agree to receive donor eligibility, campaign, impact and blood-request notifications. I can change this later.</span>
                  </label>
                </div>

                <div className="registration-note">
                  <HeartHandshake />
                  <div>
                    <strong>Registration is not medical clearance.</strong>
                    <p>DonorConnect supports recruitment and coordination. Medical screening, blood testing and donation approval remain with qualified healthcare professionals.</p>
                  </div>
                </div>
              </div>
            )}

            <div className="form-navigation">
              {step > 1 ? (
                <button type="button" className="button button-secondary" onClick={() => setStep((value) => value - 1)}>Back</button>
              ) : <span />}

              <button
                className="button button-primary"
                disabled={state.loading || (step === 1 && identityDetails.valid && !identityDetails.isEligible)}
              >
                {state.loading
                  ? 'Creating account…'
                  : step < 3
                    ? <>Continue <ArrowRight size={17} /></>
                    : <>Join DonorConnect <HeartHandshake size={18} /></>}
              </button>
            </div>
          </form>
        </section>
      </main>
    </div>
  )
}
