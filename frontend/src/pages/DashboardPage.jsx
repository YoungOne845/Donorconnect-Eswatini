import { Activity, Bell, CalendarHeart, CheckCircle2, Clock3, Droplets, HeartPulse, Lock, ShieldCheck, TrendingUp, UserCheck, Users } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api/client'
import EmptyState from '../components/EmptyState'
import FormMessage from '../components/FormMessage'
import Modal from '../components/Modal'
import StatCard from '../components/StatCard'
import StatusBadge from '../components/StatusBadge'
import DonorBenefitsCard from '../components/DonorBenefitsCard'
import { useAuth } from '../context/AuthContext'
import { formatDate, number } from '../utils/format'

export default function DashboardPage() {
  const { user, refresh, statusRevealed, setStatusRevealed } = useAuth()
  const [data, setData] = useState(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [revealModalOpen, setRevealModalOpen] = useState(false)
  const [revealPassword, setRevealPassword] = useState('')
  const [revealState, setRevealState] = useState({ loading: false, message: '', errors: null, type: 'error' })

  const load = () => api('/dashboard').then(setData).catch((err) => setError(err.message))
  useEffect(() => { void load() }, [])

  const handleRevealStatus = async (event) => {
    event.preventDefault()
    setRevealState({ loading: true, message: '', errors: null, type: 'error' })
    try {
      await api('/donor/verify-password', { method: 'POST', body: { password: revealPassword } })
      setStatusRevealed(true)
      setRevealModalOpen(false)
      setRevealPassword('')
      setRevealState({ loading: false, message: '', errors: null, type: 'error' })
    } catch (err) {
      setRevealState({ loading: false, message: err.message, errors: err.errors, type: 'error' })
    }
  }

  if (error) return <div className="form-message error"><strong>{error}</strong></div>
  if (!data) return <div className="panel-loading"><div className="blood-loader" />Loading dashboard…</div>

  if (user.role === 'donor') {
    const { profile, blood_request_alerts: alerts, upcoming_campaigns: campaigns, recent_donations: donations, appointments = [], journey } = data
    const hasAcceptedMatch = alerts.some((alert) => alert.donor_response === 'accepted')
    const recognition = profile.recognition || { level: 'New donor', estimated_lives_impacted: 0, family_support_note: '' }
    const updateAvailability = async () => {
      setBusy(true)
      const next = profile.availability_status === 'available' ? 'not_available' : 'available'
      try {
        await api('/donor/availability', { method: 'PATCH', body: { availability_status: next } })
        await refresh(); await load()
      } finally { setBusy(false) }
    }
    const respond = async (matchId, response) => {
      setBusy(true)
      try { await api(`/donor/matches/${matchId}/respond`, { method: 'POST', body: { response } }); await load() }
      catch (err) { setError(err.message) }
      finally { setBusy(false) }
    }

    const isPermanentlyDeferred = profile.eligibility_status === 'permanently_deferred'
    const bloodBanks = data.blood_banks || []
    const passwordUnset = profile.password_status === 'unset'

    const getEligibilityDetail = () => {
      if (isPermanentlyDeferred) return 'Valued community member'
      if (!profile.next_eligible_date) return 'Check assessment status'
      
      const now = new Date()
      now.setHours(0,0,0,0)
      const nextDate = new Date(profile.next_eligible_date)
      nextDate.setHours(0,0,0,0)
      
      const diffTime = nextDate.getTime() - now.getTime()
      const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24))
      
      if (diffDays > 0) {
        return `Eligible in ${diffDays} day${diffDays === 1 ? '' : 's'} (Next: ${formatDate(profile.next_eligible_date)})`
      }
      return 'Eligible to donate now!'
    }

    return (
      <div className="dashboard-stack">
        <section className="welcome-banner donor-welcome">
          <div><span className="eyebrow">Donor ID {profile.donor_code}</span><h2>Welcome back, {profile.display_name || profile.full_name?.split(' ')[0]}.</h2><p>{(statusRevealed && isPermanentlyDeferred) ? 'You remain an important member of the DonorConnect community. Support blood donation through campaigns, advocacy, and donor referrals.' : 'Your next donation begins with a profile that stays accurate, eligible and connected.'}</p></div>
          {!isPermanentlyDeferred && (
            <button className={`availability-toggle ${profile.availability_status}`} onClick={updateAvailability} disabled={busy}><span /><div><small>Availability</small><strong>{profile.availability_status.replace('_', ' ')}</strong></div></button>
          )}
        </section>

        <div className="stats-grid four">
          <StatCard icon={Droplets} label="Blood type" value={profile.blood_type} detail={profile.blood_type_verified_at ? 'Confirmed' : 'Awaiting confirmation'} />
          <StatCard icon={ShieldCheck} label="Documentation verification" value={profile.verification_status.replace('_', ' ')} detail="Identity and donor record" tone="blue" />
          <div className="stat-card" style={{ position: 'relative' }}>
            {statusRevealed ? (
              <StatCard icon={HeartPulse} label="Donation eligibility" value={isPermanentlyDeferred ? 'Community Supporter' : profile.eligibility_status.replace('_', ' ')} detail={getEligibilityDetail()} tone="green" />
            ) : (
              <button
                type="button"
                onClick={() => { setRevealState({ loading: false, message: '', errors: null, type: 'error' }); setRevealModalOpen(true) }}
                style={{ display: 'flex', flexDirection: 'column', alignItems: 'flex-start', gap: '6px', width: '100%', background: 'none', border: 'none', cursor: 'pointer', padding: '0' }}
              >
                <HeartPulse size={22} style={{ opacity: 0.5 }} />
                <span style={{ fontSize: '11px', textTransform: 'uppercase', letterSpacing: '0.06em', opacity: 0.6, fontWeight: 600 }}>Donation eligibility</span>
                <span style={{ fontSize: '13px', fontWeight: 600, display: 'flex', alignItems: 'center', gap: '5px' }}><Lock size={13} /> Hidden — tap to reveal</span>
              </button>
            )}
          </div>
          <StatCard icon={TrendingUp} label="Recognition" value={recognition.level} detail={`${number(profile.total_donations)} donations • ${number(recognition.estimated_lives_impacted)} lives impacted`} tone="gold" />
        </div>

        <DonorBenefitsCard recognition={recognition} />

        {statusRevealed && isPermanentlyDeferred && (
          <section className="panel" style={{ border: '1px solid #1d4ed8', background: '#eff6ff' }}>
            <div className="panel-header" style={{ marginBottom: '10px' }}>
              <h2 style={{ color: '#1d4ed8', fontSize: '18px' }}>Important Member of DonorConnect</h2>
            </div>
            <p style={{ color: '#1e293b', fontSize: '14px', fontWeight: '500', lineHeight: '1.6' }}>
              While you are no longer eligible to donate blood, you remain an important member of the DonorConnect community. You can continue supporting blood donation through campaigns, advocacy, and donor referrals.
            </p>
          </section>
        )}

        {statusRevealed && isPermanentlyDeferred && bloodBanks.length > 0 && (
          <section className="panel">
            <div className="panel-header">
              <div><span className="eyebrow">Need more information?</span><h2>Contact a blood bank</h2></div>
            </div>
            <p style={{ fontSize: '14px', color: 'var(--text-muted)', marginBottom: '16px', lineHeight: '1.6' }}>
              If you have questions about your deferral status or wish to discuss your situation further, please reach out to an ENBTS blood bank directly.
            </p>
            <div className="list-stack">
              {bloodBanks.map((bank) => (
                <div className="list-item" key={bank.id} style={{ alignItems: 'flex-start', gap: '14px' }}>
                  <span className="list-icon"><HeartPulse /></span>
                  <div style={{ flex: 1 }}>
                    <strong>{bank.name}</strong>
                    <p>{bank.town}, {bank.region}</p>
                    <div style={{ display: 'flex', gap: '12px', flexWrap: 'wrap', marginTop: '6px' }}>
                      {bank.phone && <a href={`tel:${bank.phone}`} style={{ fontSize: '13px', color: 'var(--color-primary)', fontWeight: 600, textDecoration: 'none' }}>📞 {bank.phone}</a>}
                      {bank.email && <a href={`mailto:${bank.email}`} style={{ fontSize: '13px', color: 'var(--color-primary)', fontWeight: 600, textDecoration: 'none' }}>✉️ {bank.email}</a>}
                      {!bank.phone && !bank.email && <span style={{ fontSize: '13px', color: 'var(--text-muted)' }}>Contact details not available</span>}
                    </div>
                  </div>
                </div>
              ))}
            </div>
          </section>
        )}

        <section className="content-grid journey-layout">
          <article className="panel">
            <div className="panel-header"><div><span className="eyebrow">Retention pathway</span><h2>Your donor journey</h2></div><Link to="/app/profile" className="text-link">View profile</Link></div>
            <div className="journey-track">
              {[
                ['Registered', journey.registered, Users], ['Verified', journey.verified, UserCheck],
                ['Assessed', journey.assessed, ShieldCheck], ['First donation', journey.has_donated, Droplets],
                ['Repeat donor', journey.repeat_donor, TrendingUp],
              ].map(([label, complete, Icon]) => <div key={label} className={complete ? 'complete' : ''}><span>{complete ? <CheckCircle2 /> : <Icon />}</span><strong>{label}</strong></div>)}
            </div>
          </article>
          <article className="panel impact-panel"><span className="eyebrow">Your impact</span><h2>{profile.total_donations > 0 ? `${recognition.level} recognition unlocked.` : 'Your first donation can start a lifelong habit.'}</h2><p>{(statusRevealed && isPermanentlyDeferred) ? 'While you are no longer eligible to donate, you remain a valued member of our community. Your past support is deeply appreciated.' : (recognition.family_support_note || 'DonorConnect focuses on keeping donors active beyond registration—through donation eligibility reminders, campaigns and clear donation history.')}</p></article>
        </section>

        {!isPermanentlyDeferred && (
          <section className="panel">
            <div className="panel-header"><div><span className="eyebrow">Mobilisation</span><h2>Blood donation opportunities</h2></div><span className="panel-count">{alerts.length} active</span></div>
            {hasAcceptedMatch && (
              <div style={{ backgroundColor: '#eff6ff', border: '1px solid #3b82f6', color: '#1e3a8a', padding: '12px 16px', borderRadius: '8px', fontSize: '13.5px', marginBottom: '16px', display: 'flex', alignItems: 'center', gap: '8px' }}>
                <CheckCircle2 size={18} style={{ color: '#3b82f6', flexShrink: 0 }} />
                <span>You have already accepted a donation request. Other requests are temporarily locked until your commitment is resolved.</span>
              </div>
            )}
            {alerts.length === 0 ? <EmptyState title="No active blood alerts" message="When your verified profile matches a request, the opportunity will appear here." /> : <div className="alert-grid">{alerts.map((alert) => <article className={`request-alert urgency-${alert.urgency_level}`} key={alert.match_id}><div className="alert-top"><StatusBadge value={alert.urgency_level} /><span>{alert.request_code}</span></div><div className="blood-type-orb"><span>{alert.blood_type_needed}</span></div><h3>{alert.hospital_name}</h3><p>{alert.town}, {alert.region} • {alert.units_required} unit(s)</p>{alert.donor_response === 'pending' ? <div className="button-row"><button disabled={busy || hasAcceptedMatch} className="button button-primary" onClick={() => respond(alert.match_id, 'accepted')} title={hasAcceptedMatch ? "You have already accepted another active request" : "Accept request"}>I can donate</button><button disabled={busy} className="button button-secondary" onClick={() => respond(alert.match_id, 'declined')}>Not available</button></div> : <StatusBadge value={alert.donor_response} />}</article>)}</div>}
          </section>
        )}

        {!isPermanentlyDeferred && (
          <section className="panel">
            <div className="panel-header"><div><span className="eyebrow">Donation appointment</span><h2>Tell ENBTS when you are free</h2></div><Link to="/app/profile" className="text-link">Book from profile</Link></div>
            {appointments.length ? <div className="list-stack">{appointments.slice(0, 3).map((item) => <div className="list-item" key={item.id}><span className="list-icon"><Clock3 /></span><div><strong>{formatDate(item.appointment_at, true)}</strong><p>{item.institution_name} • {item.reason || 'Donation appointment request'}</p></div><StatusBadge value={item.status} /></div>)}</div> : <EmptyState title="No appointment requests yet" message="Open your profile to say when you are free to donate." />}
          </section>
        )}

        <section className="content-grid two-columns">
          <article className="panel"><div className="panel-header"><div><span className="eyebrow">Stay engaged</span><h2>Upcoming campaigns</h2></div><Link className="text-link" to="/app/campaigns">See all</Link></div>{campaigns.length ? <div className="list-stack">{campaigns.map((campaign) => <div className="list-item" key={campaign.id}><span className="list-icon"><CalendarHeart /></span><div><strong>{campaign.title}</strong><p>{formatDate(campaign.starts_at, true)} • {campaign.venue}</p></div><StatusBadge value={campaign.my_status || campaign.status} /></div>)}</div> : <EmptyState title="No nearby campaigns" />}</article>
          <article className="panel"><div className="panel-header"><div><span className="eyebrow">Donation history</span><h2>Recent donations</h2></div></div>{donations.length ? <div className="list-stack">{donations.map((donation) => <div className="list-item" key={`${donation.donation_date}-${donation.donation_type}`}><span className="list-icon"><Droplets /></span><div><strong>{formatDate(donation.donation_date)}</strong><p>{donation.donation_type.replace('_', ' ')} • {donation.town}</p></div><StatusBadge value={donation.screening_status} /></div>)}</div> : <EmptyState title="No donations recorded yet" message="Once staff record a completed donation, it will appear here." />}</article>
        </section>

        <Modal open={revealModalOpen} onClose={() => setRevealModalOpen(false)} title="Verify Password">
          <FormMessage type={revealState.type} message={revealState.message} errors={revealState.errors} />
          {passwordUnset ? (
            <div className="otp-helper-card">
              <strong>Password Required</strong>
              <span>You have not set a password yet because you logged in using OTP. Please create a password under the "Account security" panel in your <Link to="/app/profile" style={{ textDecoration: 'underline', color: 'var(--color-primary)' }}>Profile</Link> first before you can reveal your donation eligibility status.</span>
            </div>
          ) : (
            <form className="form-section" onSubmit={handleRevealStatus}>
              <p>Please confirm your account password to reveal your donation eligibility status.</p>
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
      </div>
    )
  }

  if (user.role === 'hospital') {
    const summary = data.summary
    return <div className="dashboard-stack"><section className="welcome-banner"><div><span className="eyebrow">Hospital operations</span><h2>Blood request coordination</h2><p>Create structured requests, monitor donor responses and track fulfilment.</p></div><Link className="button button-primary" to="/app/requests">Manage requests</Link></section><div className="stats-grid four"><StatCard icon={Droplets} label="Total requests" value={number(summary.total_requests)} /><StatCard icon={Clock3} label="Active requests" value={number(summary.active_requests)} tone="gold" /><StatCard icon={CheckCircle2} label="Fulfilled" value={number(summary.fulfilled_requests)} tone="green" /><StatCard icon={Activity} label="Units fulfilled" value={`${number(summary.units_fulfilled)} / ${number(summary.units_requested)}`} tone="blue" /></div><section className="panel"><div className="panel-header"><h2>Recent requests</h2></div><RequestTable requests={data.recent_requests} /></section></div>
  }

  const summary = data.summary
  return (
    <div className="dashboard-stack">
      {summary.pending_profile_updates > 0 && (
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', backgroundColor: '#fef3c7', border: '1px solid #f59e0b', color: '#78350f', padding: '12px 20px', borderRadius: '12px', fontSize: '14px', fontWeight: '500' }}>
          <span>
            ⚠️ There are <strong>{summary.pending_profile_updates}</strong> pending donor profile update requests that require review.
          </span>
          <Link to="/app/donors" style={{ fontWeight: 'bold', textDecoration: 'underline', color: '#78350f' }}>
            Go to Donor Pool
          </Link>
        </div>
      )}
      <section className="welcome-banner"><div><span className="eyebrow">Donor pool command centre</span><h2>Build the pool before it is needed.</h2><p>Monitor recruitment, documentation verification, donation eligibility, campaigns and current blood requests from one operational view.</p></div><Link className="button button-primary" to="/app/reports">Open growth reports</Link></section>
      <div className="stats-grid six"><StatCard icon={Users} label="Total donor pool" value={number(summary.total_donors)} /><StatCard icon={UserCheck} label="Pending doc verification" value={number(summary.pending_verifications)} tone="gold" /><StatCard icon={HeartPulse} label="Donation-eligible donors" value={number(summary.eligible_donors)} tone="green" /><StatCard icon={Droplets} label="Active requests" value={number(summary.active_requests)} /><StatCard icon={CalendarHeart} label="Active campaigns" value={number(summary.active_campaigns)} tone="blue" /><StatCard icon={TrendingUp} label="New in 30 days" value={number(summary.new_donors_30_days)} tone="purple" /></div>
      <section className="content-grid two-columns"><article className="panel"><div className="panel-header"><div><span className="eyebrow">Pool growth</span><h2>Newest donors</h2></div><Link to="/app/donors" className="text-link">Manage pool</Link></div><div className="table-wrap"><table><thead><tr><th>Donor</th><th>Blood</th><th>Location</th><th>Status</th></tr></thead><tbody>{data.recent_donors.map((donor) => <tr key={donor.id}><td><Link to={`/app/donors/${donor.id}`}><strong>{donor.full_name}</strong><small>{donor.donor_code}</small></Link></td><td>{donor.blood_type}</td><td>{donor.town}</td><td><StatusBadge value={donor.verification_status} /></td></tr>)}</tbody></table></div></article><article className="panel"><div className="panel-header"><div><span className="eyebrow">Current demand</span><h2>Urgent blood requests</h2></div><Link to="/app/requests" className="text-link">Open requests</Link></div><RequestTable requests={data.urgent_requests} /></article></section>

      {data.pending_appointments && data.pending_appointments.length > 0 && (
        <section className="panel">
          <div className="panel-header">
            <div>
              <span className="eyebrow">Pending queue</span>
              <h2>Donation Appointment Requests</h2>
            </div>
            <span className="panel-count">{summary.pending_appointments} pending</span>
          </div>
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Donor</th>
                  <th>Appointment Time</th>
                  <th>Reason / Message</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                {data.pending_appointments.map((item) => (
                  <tr key={item.id}>
                    <td>
                      <Link to={`/app/donors/${item.donor_id}`}>
                        <strong>{item.full_name}</strong>
                        <small>{item.donor_code}</small>
                      </Link>
                    </td>
                    <td>{formatDate(item.appointment_at, true)}</td>
                    <td>{item.reason || '—'}</td>
                    <td>
                      <Link to={`/app/donors/${item.donor_id}`} className="button button-ghost" style={{ border: '1px solid #dce1e8', padding: '6px 12px', fontSize: '13px' }}>
                        Review Appointment
                      </Link>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}
    </div>
  )
}

function RequestTable({ requests = [] }) {
  if (!requests.length) return <EmptyState title="No requests recorded" />
  return <div className="table-wrap"><table><thead><tr><th>Request</th><th>Blood</th><th>Hospital</th><th>Urgency</th><th>Status</th></tr></thead><tbody>{requests.map((item) => <tr key={item.id}><td><Link to={`/app/requests/${item.id}`}><strong>{item.request_code}</strong><small>{formatDate(item.created_at)}</small></Link></td><td><strong>{item.blood_type_needed}</strong></td><td>{item.hospital_name}</td><td><StatusBadge value={item.urgency_level} /></td><td><StatusBadge value={item.status} /></td></tr>)}</tbody></table></div>
}
