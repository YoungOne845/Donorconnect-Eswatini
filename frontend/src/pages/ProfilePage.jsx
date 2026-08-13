import { CalendarPlus, KeyRound, Save, ShieldCheck, Lock } from 'lucide-react'
import { useEffect, useState } from 'react'
import { api } from '../api/client'
import EmptyState from '../components/EmptyState'
import FormMessage from '../components/FormMessage'
import Modal from '../components/Modal'
import StatusBadge from '../components/StatusBadge'
import DonorBenefitsCard from '../components/DonorBenefitsCard'
import { useAuth } from '../context/AuthContext'
import { formatDate } from '../utils/format'

const defaultPasswordForm = { current_password: '', new_password: '', confirm_password: '' }

export default function ProfilePage() {
  const { refresh, statusRevealed, setStatusRevealed } = useAuth()
  const [form, setForm] = useState(null)
  const [appointments, setAppointments] = useState({ appointments: [], blood_banks: [] })
  const [appointmentForm, setAppointmentForm] = useState({ institution_id: '', appointment_at: '', reason: '' })
  const [passwordForm, setPasswordForm] = useState(defaultPasswordForm)
  const [state, setState] = useState({ loading: false, message: '', type: 'error', errors: null })
  const [passwordState, setPasswordState] = useState({ loading: false, message: '', type: 'error', errors: null })
  const [appointmentState, setAppointmentState] = useState({ loading: false, message: '', type: 'error', errors: null })
  const [revealPassword, setRevealPassword] = useState('')
  const [revealModalOpen, setRevealModalOpen] = useState(false)
  const [revealState, setRevealState] = useState({ loading: false, message: '', errors: null, type: 'error' })

  const [updateRequests, setUpdateRequests] = useState([])
  const [changeRequestModalOpen, setChangeRequestModalOpen] = useState(false)
  const [changeRequestForm, setChangeRequestForm] = useState({ field: 'phone', new_value: '', reason: '' })
  const [changeRequestState, setChangeRequestState] = useState({ loading: false, message: '', errors: null, type: 'error' })

  const handleRevealStatus = async (event) => {
    event.preventDefault()
    setRevealState({ loading: true, message: '', errors: null, type: 'error' })
    try {
      await api('/donor/verify-password', {
        method: 'POST',
        body: { password: revealPassword }
      })
      setStatusRevealed(true)
      setRevealModalOpen(false)
      setRevealPassword('')
      setRevealState({ loading: false, message: '', errors: null, type: 'error' })
    } catch (error) {
      setRevealState({ loading: false, message: error.message, errors: error.errors, type: 'error' })
    }
  }

  const load = async () => {
    try {
      const [profile, appts, updates] = await Promise.all([
        api('/donor/profile'),
        api('/donor/appointments'),
        api('/donor/profile-update-requests')
      ])
      setForm(profile)
      setAppointments(appts)
      setUpdateRequests(updates)
      if (!appointmentForm.institution_id && appts.blood_banks?.[0]) {
        setAppointmentForm((current) => ({ ...current, institution_id: String(appts.blood_banks[0].id) }))
      }
    } catch (error) {
      setState({ loading: false, message: error.message, type: 'error', errors: error.errors })
    }
  }

  const submitChangeRequest = async (event) => {
    event.preventDefault()
    setChangeRequestState({ loading: true, message: '', errors: null, type: 'error' })
    try {
      await api('/donor/profile-update-request', {
        method: 'POST',
        body: changeRequestForm
      })
      setChangeRequestModalOpen(false)
      setChangeRequestForm({ field: 'phone', new_value: '', reason: '' })
      await load()
      setState({ loading: false, message: 'Change request submitted successfully.', type: 'success', errors: null })
    } catch (error) {
      setChangeRequestState({ loading: false, message: error.message, errors: error.errors, type: 'error' })
    }
  }

  useEffect(() => { void load() }, [])

  if (!form) return <div className="panel-loading"><div className="blood-loader" />Loading profile…</div>

  const update = (field, value) => setForm((current) => ({ ...current, [field]: value }))
  const passwordUnset = form.password_status === 'unset'
  const recognition = { ...(form.recognition || { level: 'New donor', estimated_lives_impacted: 0, family_support_note: '' }) }
  if (statusRevealed && form.eligibility_status === 'permanently_deferred') {
    recognition.family_support_note = 'While you are no longer eligible to donate blood, you remain an important member of the DonorConnect community. Thank you for your support.'
  }

  const submit = async (event) => {
    event.preventDefault()
    setState({ loading: true, message: '', errors: null, type: 'error' })
    try {
      await api('/donor/profile', { method: 'PUT', body: form })
      await refresh()
      await load()
      setState({ loading: false, message: 'Profile updated successfully.', type: 'success', errors: null })
    } catch (error) {
      setState({ loading: false, message: error.message, errors: error.errors, type: 'error' })
    }
  }

  const submitPassword = async (event) => {
    event.preventDefault()
    setPasswordState({ loading: true, message: '', errors: null, type: 'error' })
    try {
      await api('/donor/password', { method: 'POST', body: passwordForm })
      setPasswordForm(defaultPasswordForm)
      await refresh()
      await load()
      setPasswordState({ loading: false, message: passwordUnset ? 'Password created successfully.' : 'Password changed successfully.', errors: null, type: 'success' })
    } catch (error) {
      setPasswordState({ loading: false, message: error.message, errors: error.errors, type: 'error' })
    }
  }

  const submitAppointment = async (event) => {
    event.preventDefault()
    setAppointmentState({ loading: true, message: '', errors: null, type: 'error' })
    try {
      await api('/donor/appointments', {
        method: 'POST',
        body: { ...appointmentForm, institution_id: Number(appointmentForm.institution_id) },
      })
      setAppointmentForm((current) => ({ ...current, appointment_at: '', reason: '' }))
      await load()
      setAppointmentState({ loading: false, message: 'Appointment request submitted to ENBTS.', errors: null, type: 'success' })
    } catch (error) {
      setAppointmentState({ loading: false, message: error.message, errors: error.errors, type: 'error' })
    }
  }

  return (
    <div className="dashboard-stack">
      <section className="profile-status-strip">
        <div>
          <span className="eyebrow">{form.donor_code}</span>
          <h2>{form.full_name}</h2>
          <p>National ID: {form.national_id_masked}</p>
        </div>
        <div style={{ display: 'flex', gap: '8px', alignItems: 'center', flexWrap: 'wrap' }}>
          <StatusBadge value={form.verification_status} />
          {statusRevealed ? (
            <>
              <StatusBadge value={form.eligibility_status === 'permanently_deferred' ? 'Community Supporter' : form.eligibility_status} />
              <StatusBadge value={form.availability_status} />
              {form.next_eligible_date && (() => {
                const now = new Date()
                now.setHours(0,0,0,0)
                const nextDate = new Date(form.next_eligible_date)
                nextDate.setHours(0,0,0,0)
                const diffTime = nextDate.getTime() - now.getTime()
                const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24))
                if (diffDays > 0) {
                  return (
                    <span className="badge" style={{ backgroundColor: 'rgba(245, 158, 11, 0.1)', color: '#fbbf24', borderColor: 'rgba(245, 158, 11, 0.2)', borderWidth: '1px', borderStyle: 'solid', display: 'inline-flex', alignItems: 'center', height: '24px', padding: '0 8px', borderRadius: '6px', fontSize: '11px', fontWeight: 600 }}>
                      Eligible in {diffDays} day{diffDays === 1 ? '' : 's'} (Next: {formatDate(form.next_eligible_date)})
                    </span>
                  )
                }
                if (form.eligibility_status !== 'permanently_deferred') {
                  return (
                    <span className="badge" style={{ backgroundColor: 'rgba(16, 185, 129, 0.1)', color: '#34d399', borderColor: 'rgba(16, 185, 129, 0.2)', borderWidth: '1px', borderStyle: 'solid', display: 'inline-flex', alignItems: 'center', height: '24px', padding: '0 8px', borderRadius: '6px', fontSize: '11px', fontWeight: 600 }}>
                      Eligible to donate now!
                    </span>
                  )
                }
                return null
              })()}
            </>
          ) : (
            <button
              type="button"
              className="button button-secondary"
              style={{ minHeight: '30px', height: '30px', padding: '0 10px', borderRadius: '8px', fontSize: '11px', gap: '5px' }}
              onClick={() => {
                setRevealState({ loading: false, message: '', errors: null, type: 'error' });
                setRevealModalOpen(true);
              }}
            >
              <Lock size={12} /> Reveal status
            </button>
          )}
        </div>
      </section>

      {statusRevealed && form.eligibility_status === 'permanently_deferred' && (
        <section className="panel" style={{ border: '1px solid #1d4ed8', background: '#eff6ff' }}>
          <div className="panel-header" style={{ marginBottom: '10px' }}>
            <h2 style={{ color: '#1d4ed8', fontSize: '18px' }}>Important Member of DonorConnect</h2>
          </div>
          <p style={{ color: '#1e293b', fontSize: '14px', fontWeight: '500', lineHeight: '1.6' }}>
            While you are no longer eligible to donate blood, you remain an important member of the DonorConnect community. You can continue supporting blood donation through campaigns, advocacy, and donor referrals.
          </p>
        </section>
      )}
      <DonorBenefitsCard recognition={recognition} />

      <section className="panel">
        <div className="panel-header"><div><span className="eyebrow">Donor record</span><h2>Profile and communication preferences</h2></div><ShieldCheck /></div>
        <form className="form-section" onSubmit={submit}>
          <FormMessage type={state.type} message={state.message} errors={state.errors} />
          <div className="form-grid two">
            <label>
              Legal name
              {form.verification_status === 'verified' ? (
                <>
                  <input value={form.full_name || ''} disabled />
                  <small style={{ color: 'var(--color-primary)', display: 'flex', alignItems: 'center', gap: '4px' }}>
                    🔒 Locked after verification. Contact ENBTS staff to correct your name.
                  </small>
                </>
              ) : (
                <>
                  <input value={form.full_name || ''} onChange={(e) => update('full_name', e.target.value)} />
                  <small>Will be locked once your identity is verified by staff.</small>
                </>
              )}
            </label>
            <label>
              Display name <span style={{ fontSize: '11px', color: 'var(--text-muted)', fontWeight: 400 }}>(optional nickname)</span>
              <input value={form.display_name || ''} onChange={(e) => update('display_name', e.target.value)} placeholder="e.g. Vuyo, V.D, or any name you prefer" maxLength={80} />
              <small>This is the name shown in the app instead of your legal name.</small>
            </label>
            <label>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <span>Primary phone</span>
                <button
                  type="button"
                  className="button-link"
                  style={{ fontSize: '12px', color: 'var(--color-primary)', background: 'none', border: 'none', cursor: 'pointer', padding: 0 }}
                  onClick={() => {
                    setChangeRequestForm({ field: 'phone', new_value: form.phone || '', reason: '' })
                    setChangeRequestState({ loading: false, message: '', errors: null, type: 'error' })
                    setChangeRequestModalOpen(true)
                  }}
                >
                  Request a change
                </button>
              </div>
              <input value={form.phone || ''} disabled />
              <small style={{ color: 'var(--text-muted)' }}>
                🔒 Managed by ENBTS staff.
              </small>
            </label>
            <label>
              Secondary phone
              <input value={form.phone_secondary || ''} onChange={(e) => update('phone_secondary', e.target.value)} placeholder="e.g. 76123456" />
              <small>You can update your alternative contact number here.</small>
            </label>
            <label>Email<input type="email" value={form.email || ''} onChange={(e) => update('email', e.target.value)} /></label>
            <label>Blood type<input value={form.blood_type || ''} disabled /><small>Only authorised staff can confirm or change blood type.</small></label>
            <label>Region<select value={form.region} onChange={(e) => update('region', e.target.value)}>{['Hhohho','Manzini','Lubombo','Shiselweni'].map((value) => <option key={value}>{value}</option>)}</select></label>
            <label>Town<input value={form.town || ''} onChange={(e) => update('town', e.target.value)} /></label>
            <label className="full-span">Address<textarea rows="2" value={form.address || ''} onChange={(e) => update('address', e.target.value)} /></label>
            <label>Preferred contact<select value={form.preferred_contact_method} onChange={(e) => update('preferred_contact_method', e.target.value)}><option value="sms">SMS</option><option value="web">Web</option><option value="phone">Phone</option><option value="email">Email</option></select></label>
            <label>Recruitment source<input value={form.recruitment_source?.replaceAll('_', ' ')} disabled /></label>
            <label>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <span>Emergency contact name</span>
                <button
                  type="button"
                  className="button-link"
                  style={{ fontSize: '12px', color: 'var(--color-primary)', background: 'none', border: 'none', cursor: 'pointer', padding: 0 }}
                  onClick={() => {
                    setChangeRequestForm({ field: 'emergency_contact_name', new_value: form.emergency_contact_name || '', reason: '' })
                    setChangeRequestState({ loading: false, message: '', errors: null, type: 'error' })
                    setChangeRequestModalOpen(true)
                  }}
                >
                  Request a change
                </button>
              </div>
              <input value={form.emergency_contact_name || ''} disabled />
              <small style={{ color: 'var(--text-muted)', display: 'flex', alignItems: 'center', gap: '4px' }}>
                🔒 Managed by ENBTS staff only. Contact your blood bank to update.
              </small>
            </label>
            <label>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <span>Emergency contact phone</span>
                <button
                  type="button"
                  className="button-link"
                  style={{ fontSize: '12px', color: 'var(--color-primary)', background: 'none', border: 'none', cursor: 'pointer', padding: 0 }}
                  onClick={() => {
                    setChangeRequestForm({ field: 'emergency_contact_phone', new_value: form.emergency_contact_phone || '', reason: '' })
                    setChangeRequestState({ loading: false, message: '', errors: null, type: 'error' })
                    setChangeRequestModalOpen(true)
                  }}
                >
                  Request a change
                </button>
              </div>
              <input value={form.emergency_contact_phone || ''} disabled />
              <small style={{ color: 'var(--text-muted)' }}>Staff-managed for emergency reliability.</small>
            </label>
            <label className="checkbox-label full-span"><input type="checkbox" checked={Boolean(Number(form.consent_to_notifications))} onChange={(e) => update('consent_to_notifications', e.target.checked)} /><span>Receive donor engagement, eligibility, campaign and mobilisation notifications.</span></label>
          </div>
          <div className="form-actions"><button className="button button-primary" disabled={state.loading}><Save size={18} /> {state.loading ? 'Saving…' : 'Save profile'}</button></div>
        </form>
      </section>


      <section className="content-grid two-columns">
        <article className="panel">
          <div className="panel-header"><div><span className="eyebrow">Account security</span><h2>{passwordUnset ? 'Create your password' : 'Change password'}</h2></div><KeyRound /></div>
          <form className="form-section" onSubmit={submitPassword}>
            <FormMessage type={passwordState.type} message={passwordState.message} errors={passwordState.errors} />
            {passwordUnset ? <div className="otp-helper-card"><strong>No password has been set yet.</strong><span>You logged in with OTP. Create a password here so you can use password login later.</span></div> : <label>Current password<input type="password" value={passwordForm.current_password} onChange={(e) => setPasswordForm({ ...passwordForm, current_password: e.target.value })} autoComplete="current-password" required /></label>}
            <label>New password<input type="password" value={passwordForm.new_password} onChange={(e) => setPasswordForm({ ...passwordForm, new_password: e.target.value })} autoComplete="new-password" required /></label>
            <label>Confirm new password<input type="password" value={passwordForm.confirm_password} onChange={(e) => setPasswordForm({ ...passwordForm, confirm_password: e.target.value })} autoComplete="new-password" required /></label>
            <button className="button button-primary" disabled={passwordState.loading}>{passwordState.loading ? 'Saving…' : passwordUnset ? 'Create password' : 'Change password'}</button>
          </form>
        </article>

        <article className="panel">
          <div className="panel-header"><div><span className="eyebrow">Donation availability</span><h2>Book when you are free</h2></div><CalendarPlus /></div>
          {statusRevealed && form.eligibility_status === 'permanently_deferred' ? (
            <div style={{ display: 'flex', flexDirection: 'column', gap: '18px', padding: '10px 0' }}>
              <p style={{ color: '#4b5563', fontSize: '14px', lineHeight: '1.5' }}>
                You remain an important member of the DonorConnect community. Although you cannot schedule donation appointments, you can support our mission by advocating, participating in campaigns, and referring prospective donors.
              </p>
              <a href="/app/campaigns" className="button button-primary" style={{ alignSelf: 'flex-start' }}>
                Explore Campaigns
              </a>
              {appointments.blood_banks.length > 0 && (
                <div style={{ borderTop: '1px solid var(--border)', paddingTop: '16px' }}>
                  <p style={{ fontSize: '13px', fontWeight: 700, marginBottom: '12px', color: 'var(--text-heading)' }}>Contact a blood bank for more details</p>
                  <div className="list-stack">
                    {appointments.blood_banks.map((bank) => (
                      <div className="list-item" key={bank.id} style={{ alignItems: 'flex-start' }}>
                        <span className="list-icon"><CalendarPlus size={16} /></span>
                        <div style={{ flex: 1 }}>
                          <strong style={{ fontSize: '13px' }}>{bank.name}</strong>
                          <p style={{ fontSize: '12px' }}>{bank.town}, {bank.region}</p>
                          <div style={{ display: 'flex', gap: '10px', flexWrap: 'wrap', marginTop: '5px' }}>
                            {bank.phone && <a href={`tel:${bank.phone}`} style={{ fontSize: '12px', color: 'var(--color-primary)', fontWeight: 600, textDecoration: 'none' }}>📞 {bank.phone}</a>}
                            {bank.email && <a href={`mailto:${bank.email}`} style={{ fontSize: '12px', color: 'var(--color-primary)', fontWeight: 600, textDecoration: 'none' }}>✉️ {bank.email}</a>}
                            {!bank.phone && !bank.email && <span style={{ fontSize: '12px', color: 'var(--text-muted)' }}>Contact details not on file</span>}
                          </div>
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </div>
          ) : (
            <form className="form-section" onSubmit={submitAppointment}>
              <FormMessage type={appointmentState.type} message={appointmentState.message} errors={appointmentState.errors} />
              <label>Preferred blood bank<select value={appointmentForm.institution_id} onChange={(e) => setAppointmentForm({ ...appointmentForm, institution_id: e.target.value })}>{appointments.blood_banks.map((bank) => <option key={bank.id} value={bank.id}>{bank.name} • {bank.town}</option>)}</select></label>
              <label>Date and time<input type="datetime-local" value={appointmentForm.appointment_at} onChange={(e) => setAppointmentForm({ ...appointmentForm, appointment_at: e.target.value })} min={(() => { const now = new Date(); const nowStr = now.toISOString().slice(0, 16); if (form.next_eligible_date) { const eligible = new Date(form.next_eligible_date.replace(' ', 'T')); if (!Number.isNaN(eligible.getTime())) { const eligibleStr = eligible.toISOString().slice(0, 16); return eligibleStr > nowStr ? eligibleStr : nowStr; } } return nowStr; })()} required /></label>
              <label>Note<textarea rows="3" value={appointmentForm.reason} onChange={(e) => setAppointmentForm({ ...appointmentForm, reason: e.target.value })} placeholder="Example: I am free after classes." /></label>
              <button className="button button-primary" disabled={appointmentState.loading}>{appointmentState.loading ? 'Submitting…' : 'Submit appointment request'}</button>
            </form>
          )}
          <div className="appointment-list">
            <h3>Recent appointment requests</h3>
            {!appointments.appointments.length ? <EmptyState title="No appointments yet" message="When you tell ENBTS when you are free, requests appear here." /> : appointments.appointments.slice(0, 4).map((item) => <div className="appointment-item" key={item.id}><strong>{formatDate(item.appointment_at, true)}</strong><span>{item.institution_name}</span><StatusBadge value={item.status} /></div>)}
          </div>
        </article>
      </section>

      <Modal open={revealModalOpen} onClose={() => setRevealModalOpen(false)} title="Verify Password">
        <FormMessage type={revealState.type} message={revealState.message} errors={revealState.errors} />
        {passwordUnset ? (
          <div className="otp-helper-card">
            <strong>Password Required</strong>
            <span>You have not set a password yet because you logged in using OTP. Please create a password under the "Account security" panel first before you can view your eligibility status.</span>
          </div>
        ) : (
          <form className="form-section" onSubmit={handleRevealStatus}>
            <p>Please confirm your account password to reveal your eligibility and availability status.</p>
            <label>
              Password
              <input
                type="password"
                value={revealPassword}
                onChange={(e) => setRevealPassword(e.target.value)}
                autoComplete="current-password"
                required
              />
            </label>
            <button className="button button-primary" disabled={revealState.loading}>
              {revealState.loading ? 'Verifying…' : 'Verify password'}
            </button>
          </form>
        )}
      </Modal>

      {updateRequests && updateRequests.length > 0 && (
        <section className="panel">
          <div className="panel-header">
            <div>
              <span className="eyebrow">Request history</span>
              <h2>Profile Update Requests</h2>
            </div>
          </div>
          <div className="table-responsive">
            <table className="table">
              <thead>
                <tr>
                  <th>Field</th>
                  <th>Requested value</th>
                  <th>Reason</th>
                  <th>Status</th>
                  <th>Date</th>
                  <th>Notes</th>
                </tr>
              </thead>
              <tbody>
                {updateRequests.map((req) => (
                  <tr key={req.id}>
                    <td style={{ fontWeight: 600, textTransform: 'capitalize' }}>
                      {req.field.replaceAll('_', ' ')}
                    </td>
                    <td>{req.new_value}</td>
                    <td className="muted-text">{req.reason || '—'}</td>
                    <td>
                      <StatusBadge value={req.status} />
                    </td>
                    <td>{formatDate(req.created_at)}</td>
                    <td className="muted-text">{req.review_notes || '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}

      <Modal open={changeRequestModalOpen} onClose={() => setChangeRequestModalOpen(false)} title="Request Profile Change">
        <FormMessage type={changeRequestState.type} message={changeRequestState.message} errors={changeRequestState.errors} />
        <form className="form-section" onSubmit={submitChangeRequest}>
          <p>Locked fields require ENBTS staff review. Submit your request with a reason, and staff will apply or reject the update.</p>
          <label>
            Field to update
            <select
              value={changeRequestForm.field}
              onChange={(e) => setChangeRequestForm({ ...changeRequestForm, field: e.target.value })}
            >
              <option value="phone">Primary Phone</option>
              <option value="emergency_contact_name">Emergency Contact Name</option>
              <option value="emergency_contact_phone">Emergency Contact Phone</option>
            </select>
          </label>
          <label>
            Requested value
            <input
              type="text"
              value={changeRequestForm.new_value}
              onChange={(e) => setChangeRequestForm({ ...changeRequestForm, new_value: e.target.value })}
              required
            />
          </label>
          <label>
            Reason for change
            <textarea
              rows="3"
              value={changeRequestForm.reason}
              onChange={(e) => setChangeRequestForm({ ...changeRequestForm, reason: e.target.value })}
              placeholder="e.g. My primary phone number changed."
            />
          </label>
          <button className="button button-primary" disabled={changeRequestState.loading}>
            {changeRequestState.loading ? 'Submitting…' : 'Submit request'}
          </button>
        </form>
      </Modal>
    </div>
  )
}
