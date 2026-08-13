import { Filter, MessageSquareText, Search, UserPlus, Users } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api/client'
import EmptyState from '../components/EmptyState'
import FormMessage from '../components/FormMessage'
import Modal from '../components/Modal'
import StatusBadge from '../components/StatusBadge'
import { formatDate } from '../utils/format'
import { useAuth } from '../context/AuthContext'
import { NATIONAL_ID_LENGTH, normalizeNationalId } from '../utils/identity'

const emptyFilters = { search: '', blood_type: '', region: '', verification_status: '', eligibility_status: '', availability_status: '', page: 1 }
const bloodTypes = ['Unknown','A+','A-','B+','B-','AB+','AB-','O+','O-']
const regions = ['Hhohho','Manzini','Lubombo','Shiselweni']
const sources = ['school','university','church','workplace','community_campaign','hospital','social_media','referral','walk_in','other']

const emptyDonorForm = {
  full_name: '',
  national_id: '',
  phone: '',
  phone_secondary: '',
  email: '',
  password: '',
  gender: 'male',
  region: 'Hhohho',
  town: '',
  address: '',
  blood_type: 'Unknown',
  recruitment_source: 'school',
  preferred_contact_method: 'sms',
  availability_status: 'available',
  emergency_contact_name: '',
  emergency_contact_phone: '',
  notes: '',
}

const emptyMessageForm = {
  message_type: 'retention',
  title: 'We miss you at DonorConnect',
  message: 'Hi donor, ENBTS appreciates you. Please keep your profile updated and book a donation appointment when you are free.',
  blood_type: 'All',
  region: '',
  min_donations: 0,
  birthdays_this_month: false,
  send_sms: true,
  recently_donated_days: 30,
  idle_days_since_eligible: 30,
}

export default function DonorsPage() {
  const { user } = useAuth()
  const [filters, setFilters] = useState(emptyFilters)
  const [data, setData] = useState(null)
  const [error, setError] = useState('')
  const [modalOpen, setModalOpen] = useState(false)
  const [newDonor, setNewDonor] = useState(emptyDonorForm)
  const [createState, setCreateState] = useState({ loading: false, message: '', errors: null, type: 'error' })
  const [messageForm, setMessageForm] = useState(emptyMessageForm)
  const [messageState, setMessageState] = useState({ loading: false, message: '', errors: null, type: 'error' })

  const load = async (current = filters) => {
    const query = new URLSearchParams(Object.entries(current).filter(([, value]) => value !== '')).toString()
    try { setData(await api(`/donors${query ? `?${query}` : ''}`)); setError('') }
    catch (err) { setError(err.message) }
  }
  useEffect(() => { load(emptyFilters) }, [])
  const update = (field, value) => setFilters((current) => ({ ...current, [field]: value, page: 1 }))
  const updateDonor = (field, value) => setNewDonor((current) => ({ ...current, [field]: value }))
  const submit = (event) => { event.preventDefault(); load({ ...filters, page: 1 }) }
  const goToPage = (pageNumber) => {
    const next = { ...filters, page: pageNumber }
    setFilters(next)
    load(next)
  }
  const updateMessage = (field, value) => setMessageForm((current) => ({ ...current, [field]: value }))

  const handleMessageTypeChange = (type) => {
    let title = messageForm.title
    let message = messageForm.message
    if (type === 'impact_update') {
      title = 'Your donation saved a life!'
      message = 'Dear donor, we are pleased to inform you that your recent blood donation has been processed and delivered to a local clinic to help a patient in need. Thank you for your amazing impact!'
    } else if (type === 'we_miss_you') {
      title = 'We miss you at the donor bank'
      message = 'Hi donor, you are currently eligible to donate blood. We haven\'t seen you in a while — please visit your nearest donor clinic to make your next life-saving donation!'
    } else if (type === 'retention') {
      title = 'We miss you at DonorConnect'
      message = 'Hi donor, ENBTS appreciates you. Please keep your profile updated and book a donation appointment when you are free.'
    } else if (type === 'donation_reminder') {
      title = 'Upcoming donation clinic near you'
      message = 'Dear donor, there is an upcoming blood donation drive in your region. Since you are eligible, we hope to see you there!'
    } else if (type === 'general') {
      title = 'Important update from ENBTS'
      message = 'Dear donor, please keep your DonorConnect profile updated and check your eligibility status online.'
    }
    setMessageForm((current) => ({
      ...current,
      message_type: type,
      title,
      message
    }))
  }

  const createDonor = async (event) => {
    event.preventDefault()
    setCreateState({ loading: true, message: '', errors: null, type: 'error' })
    try {
      const payload = Object.fromEntries(Object.entries(newDonor).map(([key, value]) => [key, typeof value === 'string' ? value.trim() : value]))
      const result = await api('/donors', { method: 'POST', body: payload })
      setCreateState({ loading: false, message: `${result.donor_code} registered. ${result.login_guidance}`, errors: null, type: 'success' })
      setNewDonor(emptyDonorForm)
      await load()
    } catch (err) {
      setCreateState({ loading: false, message: err.message, errors: err.errors, type: 'error' })
    }
  }


  const sendMessages = async (event) => {
    event.preventDefault()
    setMessageState({ loading: true, message: '', errors: null, type: 'error' })
    try {
      const payload = {
        ...messageForm,
        min_donations: Number(messageForm.min_donations || 0),
        birthdays_this_month: Boolean(messageForm.birthdays_this_month),
        send_sms: Boolean(messageForm.send_sms),
        recently_donated_days: messageForm.message_type === 'impact_update' ? Number(messageForm.recently_donated_days || 30) : undefined,
        idle_days_since_eligible: messageForm.message_type === 'we_miss_you' ? Number(messageForm.idle_days_since_eligible || 30) : undefined,
      }
      const result = await api('/donors/messages', { method: 'POST', body: payload })
      setMessageState({ loading: false, message: `${result.sent} donor message(s) sent.${result.sms_requested ? ' Real SMS requested for SMS-preferred donors.' : ''}`, errors: null, type: 'success' })
    } catch (err) {
      setMessageState({ loading: false, message: err.message, errors: err.errors, type: 'error' })
    }
  }

  return <div className="dashboard-stack">
    <section className="welcome-banner compact-banner"><div><span className="eyebrow">Donor pool operations</span><h2>Manage people, not just records.</h2><p>Documentation verification, donation eligibility, availability and donation history determine the health of the active donor pool.</p></div><div className="banner-icon"><Users /></div></section>

    {user.role === 'admin' ? <section className="panel">
      <div className="panel-header table-heading"><div><span className="eyebrow">Mbabane donor engagement</span><h2>Send retention and impact messages</h2><p>Send web notifications and real SMS messages such as retention nudges, birthday wishes, and “your donation helped save a life” updates.</p></div><MessageSquareText /></div>
      <FormMessage type={messageState.type} message={messageState.message} errors={messageState.errors} />
      <form className="form-grid two" onSubmit={sendMessages}>
        <label>Message type<select value={messageForm.message_type} onChange={(e) => handleMessageTypeChange(e.target.value)}><option value="retention">Retention (General)</option><option value="we_miss_you">Retention (We Miss You)</option><option value="impact_update">Impact update</option><option value="donation_reminder">Donation reminder</option><option value="general">General</option></select></label>
        <label>Blood type target<select value={messageForm.blood_type} onChange={(e) => updateMessage('blood_type', e.target.value)}><option value="All">All blood types</option>{bloodTypes.map((value) => <option key={value}>{value}</option>)}</select></label>
        <label>Region target<select value={messageForm.region} onChange={(e) => updateMessage('region', e.target.value)}><option value="">All regions</option>{regions.map((value) => <option key={value}>{value}</option>)}</select></label>
        <label>Minimum donations<input type="number" min="0" value={messageForm.min_donations} onChange={(e) => updateMessage('min_donations', e.target.value)} /></label>
        {messageForm.message_type === 'impact_update' && (
          <label className="full-span">Target recently donated within (days)<input type="number" min="1" max="365" value={messageForm.recently_donated_days} onChange={(e) => updateMessage('recently_donated_days', e.target.value)} /><span style={{ fontSize: '12px', color: 'var(--text-muted)' }}>Targets only donors who made a successful donation in the last {messageForm.recently_donated_days || 30} days.</span></label>
        )}
        {messageForm.message_type === 'we_miss_you' && (
          <label className="full-span">Idle since eligible for at least (days)<input type="number" min="1" max="365" value={messageForm.idle_days_since_eligible} onChange={(e) => updateMessage('idle_days_since_eligible', e.target.value)} /><span style={{ fontSize: '12px', color: 'var(--text-muted)' }}>Targets donors who have been eligible to donate again for at least {messageForm.idle_days_since_eligible || 30} days but have not yet donated.</span></label>
        )}
        <label className="full-span">Title<input value={messageForm.title} onChange={(e) => updateMessage('title', e.target.value)} required /></label>
        <label className="full-span">Message<textarea rows="3" value={messageForm.message} onChange={(e) => updateMessage('message', e.target.value)} required /></label>
        <label className="checkbox-label"><input type="checkbox" checked={messageForm.send_sms} onChange={(e) => updateMessage('send_sms', e.target.checked)} /><span>Also send real SMS to SMS-preferred donors</span></label>
        <div className="form-actions full-span"><button className="button button-primary" disabled={messageState.loading}><MessageSquareText size={17} /> {messageState.loading ? 'Sending…' : 'Send donor messages'}</button></div>
      </form>
    </section> : null}

    <section className="panel">
      <div className="panel-header table-heading"><div><span className="eyebrow">ENBTS donor onboarding</span><h2>Donor registry</h2><p>All ENBTS admin and branch staff accounts can register donors, view history and record donations.</p></div><button className="button button-primary" onClick={() => { setModalOpen(true); setCreateState({ loading: false, message: '', errors: null, type: 'error' }) }}><UserPlus size={17} /> Register donor</button></div>
      <form className="filter-bar" onSubmit={submit}>
        <label className="search-field"><Search size={18} /><input value={filters.search} onChange={(e) => update('search', e.target.value)} placeholder="Search name, phone, donor code or town" /></label>
        <select value={filters.blood_type} onChange={(e) => update('blood_type', e.target.value)}><option value="">All blood types</option>{bloodTypes.map((value) => <option key={value}>{value}</option>)}</select>
        <select value={filters.region} onChange={(e) => update('region', e.target.value)}><option value="">All regions</option>{regions.map((value) => <option key={value}>{value}</option>)}</select>
        <select value={filters.verification_status} onChange={(e) => update('verification_status', e.target.value)}><option value="">Doc verification</option><option value="pending">Pending</option><option value="verified">Verified</option><option value="rejected">Rejected</option></select>
        <select value={filters.eligibility_status} onChange={(e) => update('eligibility_status', e.target.value)}><option value="">Donation eligibility</option><option value="eligible">Eligible</option><option value="not_assessed">Not assessed</option><option value="temporarily_deferred">Temporarily deferred</option><option value="permanently_deferred">Permanently deferred</option></select>
        <button className="button button-primary"><Filter size={17} /> Apply</button>
      </form>
      {error ? <div className="form-message error"><strong>{error}</strong></div> : null}
      {!data ? <div className="panel-loading"><div className="blood-loader" />Loading donor pool…</div> : data.items.length === 0 ? <EmptyState title="No donors match these filters" /> : <>
        <div className="panel-header table-heading"><div><h2>{data.pagination.total} donor records</h2><p>Page {data.pagination.page} of {data.pagination.pages || 1}</p></div><span className="panel-count"><UserPlus size={16} /> Active recruitment database</span></div>
        <div className="table-wrap"><table><thead><tr><th>Donor</th><th>Blood type</th><th>Location</th><th>Documentation verification</th><th>Donation eligibility</th><th>Availability</th><th>Donations</th><th>Joined</th></tr></thead><tbody>{data.items.map((donor) => <tr key={donor.id}><td><Link to={`/app/donors/${donor.id}`}><strong>{donor.full_name}</strong><small>{donor.donor_code} • {donor.phone}{donor.phone_secondary ? ` / ${donor.phone_secondary}` : ''}</small></Link></td><td><strong className="blood-text">{donor.blood_type}</strong></td><td>{donor.town}<small>{donor.region}</small></td><td><StatusBadge value={donor.verification_status} /></td><td><StatusBadge value={donor.eligibility_status} /></td><td><StatusBadge value={donor.availability_status} /></td><td>{donor.total_donations}</td><td>{formatDate(donor.created_at)}</td></tr>)}</tbody></table></div>
        {data.pagination.pages > 1 && (
          <div className="button-row" style={{ marginTop: '20px', justifyContent: 'center' }}>
            <button
              type="button"
              className="button button-secondary"
              disabled={data.pagination.page <= 1}
              onClick={() => goToPage(data.pagination.page - 1)}
            >
              Previous
            </button>
            <span style={{ fontSize: '14px', fontWeight: 600, color: 'var(--text-muted)' }}>
              Page {data.pagination.page} of {data.pagination.pages}
            </span>
            <button
              type="button"
              className="button button-secondary"
              disabled={data.pagination.page >= data.pagination.pages}
              onClick={() => goToPage(data.pagination.page + 1)}
            >
              Next
            </button>
          </div>
        )}
      </>}
    </section>

    <Modal open={modalOpen} title="Register donor as ENBTS staff" onClose={() => setModalOpen(false)} wide>
      <FormMessage type={createState.type} message={createState.message} errors={createState.errors} />
      <form className="form-section" onSubmit={createDonor}>
        <div className="otp-helper-card staff-register-note">
          <strong>Password is optional for staff-created donors.</strong>
          <span>Leave password blank for school/campaign donors. They will sign in using National ID + OTP. Set a password only when the donor chooses one on-site.</span>
        </div>
        <div className="form-grid two">
          <label>Full name<input value={newDonor.full_name} onChange={(e) => updateDonor('full_name', e.target.value)} placeholder="Donor full name" required /></label>
          <label>National ID<input value={newDonor.national_id} onChange={(e) => updateDonor('national_id', normalizeNationalId(e.target.value))} inputMode="numeric" maxLength={NATIONAL_ID_LENGTH} placeholder="0412227100041" required /></label>
          <label>Phone<input value={newDonor.phone} onChange={(e) => updateDonor('phone', e.target.value)} placeholder="76123456" required /></label>
          <label>Secondary phone <span>(optional)</span><input value={newDonor.phone_secondary} onChange={(e) => updateDonor('phone_secondary', e.target.value)} placeholder="76123456" /></label>
          <label>Email <span>(optional)</span><input type="email" value={newDonor.email} onChange={(e) => updateDonor('email', e.target.value)} placeholder="donor@example.com" /></label>
          <label>Password <span>(optional)</span><input type="text" value={newDonor.password} onChange={(e) => updateDonor('password', e.target.value)} placeholder="Leave blank for OTP login" /></label>
          <label>Sex<select value={newDonor.gender} onChange={(e) => updateDonor('gender', e.target.value)}><option value="male">Male</option><option value="female">Female</option></select></label>
          <label>Region<select value={newDonor.region} onChange={(e) => updateDonor('region', e.target.value)}>{regions.map((value) => <option key={value}>{value}</option>)}</select></label>
          <label>Town<input value={newDonor.town} onChange={(e) => updateDonor('town', e.target.value)} placeholder="Mbabane" required /></label>
          <label>Blood type<select value={newDonor.blood_type} onChange={(e) => updateDonor('blood_type', e.target.value)}>{bloodTypes.map((value) => <option key={value}>{value}</option>)}</select></label>
          <label>Recruitment source<select value={newDonor.recruitment_source} onChange={(e) => updateDonor('recruitment_source', e.target.value)}>{sources.map((value) => <option key={value} value={value}>{value.replaceAll('_', ' ')}</option>)}</select></label>
          <label>Preferred contact<select value={newDonor.preferred_contact_method} onChange={(e) => updateDonor('preferred_contact_method', e.target.value)}><option value="sms">SMS</option><option value="phone">Phone</option><option value="email">Email</option><option value="web">Web</option></select></label>
          <label>Availability<select value={newDonor.availability_status} onChange={(e) => updateDonor('availability_status', e.target.value)}><option value="available">Available</option><option value="not_available">Not available</option></select></label>
          <label>Emergency contact name<input value={newDonor.emergency_contact_name} onChange={(e) => updateDonor('emergency_contact_name', e.target.value)} placeholder="Contact person name" required /></label>
          <label>Emergency phone<input value={newDonor.emergency_contact_phone} onChange={(e) => updateDonor('emergency_contact_phone', e.target.value)} placeholder="76123456" required /></label>
          <label className="full-span">Address <span>(optional)</span><textarea rows="2" value={newDonor.address} onChange={(e) => updateDonor('address', e.target.value)} /></label>
          <label className="full-span">Staff notes <span>(optional)</span><textarea rows="2" value={newDonor.notes} onChange={(e) => updateDonor('notes', e.target.value)} placeholder="Example: Registered during school campaign" /></label>
        </div>
        <div className="button-row wrap"><button className="button button-primary" disabled={createState.loading}>{createState.loading ? 'Registering…' : 'Register donor'}</button><button type="button" className="button button-secondary" onClick={() => setNewDonor(emptyDonorForm)}>Clear</button></div>
      </form>
    </Modal>
  </div>
}
