import { AlertTriangle, CheckCircle2, Droplets, ShieldAlert, ShieldCheck, User, X } from 'lucide-react'
import { useState } from 'react'
import { api } from '../api/client'
import FormMessage from '../components/FormMessage'

const TIER_COLORS = {
  Gold:       { bg: '#fef3c7', border: '#f59e0b', text: '#78350f', badge: '#f59e0b' },
  Silver:     { bg: '#f1f5f9', border: '#94a3b8', text: '#1e293b', badge: '#64748b' },
  Bronze:     { bg: '#fef9ee', border: '#d97706', text: '#451a03', badge: '#d97706' },
  'New donor':{ bg: '#f0fdf4', border: '#86efac', text: '#14532d', badge: '#22c55e' },
}

export default function PatientLookupPage() {
  const [query, setQuery]   = useState('')
  const [result, setResult] = useState(null)
  const [state, setState]   = useState({ loading: false, message: '', errors: null, type: 'error' })

  const lookup = async (e) => {
    e.preventDefault()
    if (!query.trim()) return
    setState({ loading: true, message: '', errors: null, type: 'error' })
    setResult(null)
    try {
      const data = await api(`/hospital/patient-lookup?national_id=${encodeURIComponent(query.trim())}`)
      setResult(data)
      setState({ loading: false, message: '', errors: null, type: 'success' })
    } catch (err) {
      setState({ loading: false, message: err.message, errors: err.errors, type: 'error' })
    }
  }

  const colors = result?.tier ? (TIER_COLORS[result.tier] || TIER_COLORS['New donor']) : null

  return (
    <div className="dashboard-stack">
      <section className="welcome-banner" style={{ background: 'linear-gradient(135deg,#1e3a5f 0%,#1d4ed8 100%)' }}>
        <div>
          <span className="eyebrow" style={{ color: '#bfdbfe' }}>Emergency identification</span>
          <h2 style={{ color: '#fff' }}>Patient Blood ID Lookup</h2>
          <p style={{ color: '#bfdbfe' }}>
            Enter a patient's national ID to instantly retrieve their blood type, donor tier, and emergency priority status —
            critical when a patient cannot communicate.
          </p>
        </div>
        <ShieldAlert size={56} style={{ opacity: 0.3, color: '#fff', flexShrink: 0 }} />
      </section>

      <section className="panel">
        <div className="panel-header">
          <div><span className="eyebrow">ID lookup</span><h2>Enter patient national ID</h2></div>
        </div>
        <FormMessage type={state.type} message={state.message} errors={state.errors} />
        <form className="form-section" onSubmit={lookup} style={{ maxWidth: '540px' }}>
          <label>
            National ID number
            <div style={{ display: 'flex', gap: '10px' }}>
              <input
                type="text"
                placeholder="e.g. 7001059500001"
                value={query}
                onChange={e => setQuery(e.target.value)}
                style={{ flex: 1, fontFamily: 'monospace', letterSpacing: '0.08em', fontSize: '16px' }}
                required
              />
              {query && (
                <button type="button" className="button button-ghost" onClick={() => { setQuery(''); setResult(null) }} style={{ padding: '0 12px' }}>
                  <X size={16} />
                </button>
              )}
            </div>
            <small style={{ marginTop: '4px', display: 'block', color: 'var(--text-muted)' }}>
              Enter digits only. The ID is hashed for privacy — no plain text is stored.
            </small>
          </label>
          <button className="button button-primary" disabled={state.loading} style={{ width: 'fit-content' }}>
            {state.loading ? 'Looking up…' : '🔍 Look up patient'}
          </button>
        </form>
      </section>

      {/* Not found */}
      {result && !result.found && (
        <section className="panel" style={{ border: '1px solid #e2e8f0' }}>
          <div style={{ display: 'flex', gap: '16px', alignItems: 'center', padding: '8px 0' }}>
            <User size={40} style={{ color: '#94a3b8', flexShrink: 0 }} />
            <div>
              <h3 style={{ margin: 0, fontSize: '18px' }}>Patient not in DonorConnect</h3>
              <p style={{ margin: '4px 0 0', color: 'var(--text-muted)', fontSize: '14px' }}>{result.message}</p>
              <p style={{ margin: '4px 0 0', color: 'var(--text-muted)', fontSize: '13px' }}>ID hint: {result.national_id_hint}</p>
            </div>
          </div>
        </section>
      )}

      {/* Found */}
      {result?.found && colors && (
        <section className="panel" style={{ border: `2px solid ${result.high_priority ? '#dc2626' : colors.border}`, background: result.high_priority ? '#fff5f5' : colors.bg }}>
          {result.high_priority && (
            <div style={{ display: 'flex', alignItems: 'center', gap: '10px', background: '#dc2626', color: '#fff', padding: '12px 20px', borderRadius: '10px 10px 0 0', margin: '-24px -24px 24px -24px', fontWeight: 700, fontSize: '15px' }}>
              <AlertTriangle size={20} />
              HIGH PRIORITY PATIENT — Gold-tier donor. Prioritise blood access immediately.
            </div>
          )}

          <div style={{ display: 'flex', gap: '24px', flexWrap: 'wrap', alignItems: 'flex-start' }}>
            {/* Blood type orb */}
            <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: '6px' }}>
              <div className="blood-type-orb" style={{ width: '80px', height: '80px', fontSize: '24px', background: result.high_priority ? '#dc2626' : 'var(--color-primary)' }}>
                <span>{result.blood_type_confirmed ? result.blood_type : '?'}</span>
              </div>
              <small style={{ textAlign: 'center', fontSize: '11px', color: 'var(--text-muted)' }}>
                {result.blood_type_confirmed ? '✓ Confirmed' : 'Unconfirmed'}
              </small>
            </div>

            {/* Details */}
            <div style={{ flex: 1, minWidth: '200px' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '8px', flexWrap: 'wrap' }}>
                <span style={{ background: colors.badge, color: '#fff', padding: '3px 12px', borderRadius: '99px', fontWeight: 700, fontSize: '13px' }}>
                  {result.tier}
                </span>
                <span style={{ fontSize: '13px', color: colors.text }}>{result.total_donations} donation(s) recorded</span>
                {result.high_priority
                  ? <span style={{ display: 'flex', alignItems: 'center', gap: '4px', color: '#dc2626', fontWeight: 700, fontSize: '13px' }}><ShieldAlert size={14} /> High priority</span>
                  : <span style={{ display: 'flex', alignItems: 'center', gap: '4px', color: '#16a34a', fontSize: '13px' }}><ShieldCheck size={14} /> Standard priority</span>
                }
              </div>
              <p style={{ margin: '0 0 6px', fontSize: '13px', color: colors.text }}>{result.priority_reason}</p>

              <div style={{ display: 'flex', gap: '20px', flexWrap: 'wrap', marginTop: '12px' }}>
                <div><small style={{ color: 'var(--text-muted)', fontSize: '11px', textTransform: 'uppercase', letterSpacing: '0.05em' }}>Donor code</small><p style={{ margin: '2px 0 0', fontWeight: 600, fontFamily: 'monospace' }}>{result.donor_code}</p></div>
                <div><small style={{ color: 'var(--text-muted)', fontSize: '11px', textTransform: 'uppercase', letterSpacing: '0.05em' }}>Region</small><p style={{ margin: '2px 0 0', fontWeight: 600 }}>{result.town}, {result.region}</p></div>
                <div><small style={{ color: 'var(--text-muted)', fontSize: '11px', textTransform: 'uppercase', letterSpacing: '0.05em' }}>Verification</small><p style={{ margin: '2px 0 0', fontWeight: 600 }}>{result.verification_status.replace('_', ' ')}</p></div>
              </div>
            </div>
          </div>

          {/* Blood type warning */}
          {!result.blood_type_confirmed && (
            <div style={{ display: 'flex', gap: '8px', alignItems: 'center', marginTop: '16px', padding: '10px 14px', background: '#fef3c7', borderRadius: '8px', color: '#78350f', fontSize: '13px' }}>
              <AlertTriangle size={16} />
              <span>Blood type is <strong>not yet confirmed</strong> by lab. Perform standard typing before transfusion.</span>
            </div>
          )}

          {/* Emergency contact */}
          {result.emergency_contact && (
            <div style={{ marginTop: '16px', padding: '12px 16px', background: '#f0fdf4', borderRadius: '8px', border: '1px solid #bbf7d0' }}>
              <div style={{ display: 'flex', gap: '8px', alignItems: 'center', marginBottom: '4px' }}>
                <CheckCircle2 size={15} style={{ color: '#16a34a' }} />
                <strong style={{ fontSize: '13px' }}>Emergency contact on file</strong>
              </div>
              <p style={{ margin: 0, fontSize: '13px', color: '#1e293b' }}>
                {result.emergency_contact.name}
                {result.emergency_contact.phone && (
                  <> — <a href={`tel:${result.emergency_contact.phone}`} style={{ color: 'var(--color-primary)', fontWeight: 600 }}>{result.emergency_contact.phone}</a></>
                )}
              </p>
            </div>
          )}
        </section>
      )}

      {/* How it works */}
      <section className="panel">
        <div className="panel-header"><div><span className="eyebrow">Guide</span><h2>How patient lookup works</h2></div></div>
        <div className="list-stack">
          {[
            ['🪪', 'Enter the patient\'s national ID', 'Type or scan the Eswatini national ID number. Digits only.'],
            ['🔒', 'Secure hash lookup', 'The ID is cryptographically hashed — the system never stores or displays the raw number.'],
            ['🩸', 'Blood type retrieval', 'If the patient is a registered donor, their blood type (if confirmed), tier and priority flag are returned instantly.'],
            ['🚨', 'High priority flag', 'Gold-tier donors (7+ donations) are automatically flagged as high priority — they have earned it by giving back to the blood supply.'],
          ].map(([icon, title, desc]) => (
            <div className="list-item" key={title} style={{ alignItems: 'flex-start' }}>
              <span className="list-icon" style={{ fontSize: '20px', minWidth: '40px', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>{icon}</span>
              <div><strong style={{ display: 'block' }}>{title}</strong><p style={{ margin: '2px 0 0', color: 'var(--text-muted)', fontSize: '13px' }}>{desc}</p></div>
            </div>
          ))}
        </div>
      </section>
    </div>
  )
}
