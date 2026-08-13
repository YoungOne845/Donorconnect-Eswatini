import { CalendarCheck, CalendarClock, CalendarX, Clock3, Droplets, Plus, X } from 'lucide-react'
import { useEffect, useState } from 'react'
import { api } from '../api/client'
import EmptyState from '../components/EmptyState'
import FormMessage from '../components/FormMessage'
import StatusBadge from '../components/StatusBadge'
import { formatDate } from '../utils/format'

export default function AppointmentsPage() {
  const [data, setData]             = useState(null)
  const [showForm, setShowForm]     = useState(false)
  const [form, setForm]             = useState({ institution_id: '', appointment_at: '', reason: '' })
  const [state, setState]           = useState({ loading: false, message: '', errors: null, type: 'error' })

  const load = () => api('/donor/appointments').then(setData).catch(() => setData({ appointments: [], blood_banks: [] }))
  useEffect(() => { void load() }, [])

  const minDateTime = new Date(Date.now() + 60 * 60 * 1000).toISOString().slice(0, 16)

  const submit = async (e) => {
    e.preventDefault()
    setState({ loading: true, message: '', errors: null, type: 'error' })
    try {
      await api('/donor/appointments', { method: 'POST', body: form })
      setState({ loading: false, message: 'Appointment request submitted! ENBTS staff will confirm it shortly.', errors: null, type: 'success' })
      setShowForm(false)
      setForm({ institution_id: '', appointment_at: '', reason: '' })
      void load()
    } catch (err) {
      setState({ loading: false, message: err.message, errors: err.errors, type: 'error' })
    }
  }

  if (!data) return <div className="panel-loading"><div className="blood-loader" />Loading appointments…</div>

  const { appointments = [], blood_banks = [] } = data
  const pending   = appointments.filter(a => a.status === 'pending')
  const approved  = appointments.filter(a => a.status === 'approved')
  const past      = appointments.filter(a => !['pending','approved'].includes(a.status))

  const statusIcon = (s) => {
    if (s === 'approved')  return <CalendarCheck size={16} style={{ color: 'var(--color-success, #16a34a)' }} />
    if (s === 'rejected' || s === 'cancelled') return <CalendarX size={16} style={{ color: '#dc2626' }} />
    return <CalendarClock size={16} style={{ color: '#f59e0b' }} />
  }

  return (
    <div className="dashboard-stack">
      {/* Hero header */}
      <section className="welcome-banner donor-welcome" style={{ alignItems: 'flex-start' }}>
        <div>
          <span className="eyebrow">Donation scheduling</span>
          <h2>Book a donation appointment</h2>
          <p>Let ENBTS know when you are free to donate. Staff will confirm your slot and prepare for your visit.</p>
        </div>
        <button className="button button-primary" style={{ whiteSpace: 'nowrap' }} onClick={() => { setShowForm(v => !v); setState({ loading: false, message: '', errors: null, type: 'error' }) }}>
          {showForm ? <><X size={16} /> Cancel</> : <><Plus size={16} /> Request appointment</>}
        </button>
      </section>

      {/* Booking form */}
      {showForm && (
        <section className="panel">
          <div className="panel-header"><div><span className="eyebrow">New request</span><h2>Choose a blood bank and time</h2></div></div>
          <FormMessage type={state.type} message={state.message} errors={state.errors} />
          <form className="form-section" onSubmit={submit}>
            <label>
              Blood bank
              <select value={form.institution_id} onChange={e => setForm(f => ({ ...f, institution_id: e.target.value }))} required>
                <option value="">Select an ENBTS blood bank…</option>
                {blood_banks.map(b => <option key={b.id} value={b.id}>{b.name} — {b.town}, {b.region}</option>)}
              </select>
            </label>
            <label>
              Preferred date &amp; time
              <input type="datetime-local" min={minDateTime} value={form.appointment_at} onChange={e => setForm(f => ({ ...f, appointment_at: e.target.value }))} required />
            </label>
            <label>
              Notes for staff <span style={{ fontWeight: 400, color: 'var(--text-muted)' }}>(optional)</span>
              <textarea rows={3} placeholder="e.g. I am available any time after 9am, or I have a question about my eligibility…" value={form.reason} onChange={e => setForm(f => ({ ...f, reason: e.target.value }))} />
            </label>
            <button className="button button-primary" disabled={state.loading}>
              {state.loading ? 'Submitting…' : 'Submit appointment request'}
            </button>
          </form>
        </section>
      )}

      {state.type === 'success' && state.message && !showForm && (
        <div className="form-message success"><strong>{state.message}</strong></div>
      )}

      {/* Pending */}
      {pending.length > 0 && (
        <section className="panel">
          <div className="panel-header"><div><span className="eyebrow">Awaiting confirmation</span><h2>Pending requests</h2></div><span className="panel-count">{pending.length}</span></div>
          <div className="list-stack">
            {pending.map(a => (
              <div className="list-item" key={a.id}>
                <span className="list-icon"><Clock3 /></span>
                <div>
                  <strong>{formatDate(a.appointment_at, true)}</strong>
                  <p>{a.institution_name} — {a.reason || 'Donation appointment'}</p>
                </div>
                <StatusBadge value={a.status} />
              </div>
            ))}
          </div>
        </section>
      )}

      {/* Approved */}
      {approved.length > 0 && (
        <section className="panel">
          <div className="panel-header"><div><span className="eyebrow">Confirmed</span><h2>Approved appointments</h2></div><span className="panel-count">{approved.length}</span></div>
          <div className="list-stack">
            {approved.map(a => (
              <div className="list-item" key={a.id}>
                <span className="list-icon" style={{ background: '#dcfce7', color: '#16a34a' }}>{statusIcon(a.status)}</span>
                <div>
                  <strong>{formatDate(a.appointment_at, true)}</strong>
                  <p>{a.institution_name} • {a.review_notes || a.reason || 'Confirmed — please arrive on time.'}</p>
                </div>
                <StatusBadge value={a.status} />
              </div>
            ))}
          </div>
        </section>
      )}

      {/* Blood banks contact */}
      {blood_banks.length > 0 && (
        <section className="panel">
          <div className="panel-header"><div><span className="eyebrow">Contact us</span><h2>ENBTS blood banks</h2></div></div>
          <div className="list-stack">
            {blood_banks.map(b => (
              <div className="list-item" key={b.id} style={{ alignItems: 'flex-start' }}>
                <span className="list-icon"><Droplets /></span>
                <div style={{ flex: 1 }}>
                  <strong>{b.name}</strong>
                  <p>{b.town}, {b.region}</p>
                  <div style={{ display: 'flex', gap: '14px', marginTop: '6px', flexWrap: 'wrap' }}>
                    {b.phone && <a href={`tel:${b.phone}`} style={{ fontSize: '13px', color: 'var(--color-primary)', fontWeight: 600, textDecoration: 'none' }}>📞 {b.phone}</a>}
                    {b.email && <a href={`mailto:${b.email}`} style={{ fontSize: '13px', color: 'var(--color-primary)', fontWeight: 600, textDecoration: 'none' }}>✉️ {b.email}</a>}
                  </div>
                </div>
              </div>
            ))}
          </div>
        </section>
      )}

      {/* Past */}
      {past.length > 0 && (
        <section className="panel">
          <div className="panel-header"><div><span className="eyebrow">History</span><h2>Past appointment requests</h2></div></div>
          <div className="list-stack">
            {past.slice(0, 10).map(a => (
              <div className="list-item" key={a.id}>
                <span className="list-icon">{statusIcon(a.status)}</span>
                <div>
                  <strong>{formatDate(a.appointment_at, true)}</strong>
                  <p>{a.institution_name} • {a.review_notes || a.reason || '—'}</p>
                </div>
                <StatusBadge value={a.status} />
              </div>
            ))}
          </div>
        </section>
      )}

      {appointments.length === 0 && !showForm && (
        <EmptyState title="No appointments yet" message="Use the button above to request your first donation appointment with an ENBTS blood bank." />
      )}
    </div>
  )
}
