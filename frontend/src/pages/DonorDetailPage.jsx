import { CalendarCheck, Droplets, FileCheck2, PauseCircle, ShieldCheck, UserCheck } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { api } from '../api/client'
import EmptyState from '../components/EmptyState'
import FormMessage from '../components/FormMessage'
import Modal from '../components/Modal'
import StatCard from '../components/StatCard'
import StatusBadge from '../components/StatusBadge'
import { formatDate, titleCase } from '../utils/format'

const today = new Date().toISOString().slice(0, 10)

export default function DonorDetailPage() {
  const { id } = useParams()
  const [data, setData] = useState(null)
  const [modal, setModal] = useState(null)
  const [message, setMessage] = useState({ text: '', type: 'error', errors: null })
  const [verification, setVerification] = useState({ verification_status: 'verified', blood_type: 'Unknown', notes: '', eligibility_days: 90 })
  const [eligibility, setEligibility] = useState({ outcome: 'eligible', next_eligible_date: '', deferral_days: '', reason: '', notes: '' })
  const [donation, setDonation] = useState({ donation_date: today, donation_type: 'whole_blood', units: 1, region: 'Hhohho', town: '', next_eligible_date: '', eligibility_days: 90, screening_status: 'passed', institution_id: '', campaign_id: '', notes: '' })
  const [deferral, setDeferral] = useState({ deferral_type: 'temporary', reason: '', starts_on: today, ends_on: '', deferral_days: '', notes: '' })
  const [reviewingItem, setReviewingItem] = useState(null)
  const [reviewForm, setReviewForm] = useState({ status: 'approved', review_notes: '' })

  const load = () => api(`/donors/${id}`).then((result) => {
    setData(result)
    setVerification((current) => ({
      ...current,
      blood_type: result.donor.blood_type,
      verification_status: result.donor.verification_status === 'rejected' ? 'rejected' : 'verified',
      eligibility_days: result.donor.eligibility_days || 90
    }))
    setDonation((current) => ({
      ...current,
      region: result.donor.region,
      town: result.donor.town,
      eligibility_days: result.donor.eligibility_days || (result.donor.gender === 'male' ? 60 : 90)
    }))
  }).catch((error) => setMessage({ text: error.message, type: 'error' }))

  useEffect(() => { void load() }, [id])

  const handleReviewSubmit = async (e) => {
    e.preventDefault()
    if (!reviewingItem) return
    const path = reviewingItem.type === 'appointment'
      ? `/appointments/${reviewingItem.id}/review`
      : `/profile-update-requests/${reviewingItem.id}/review`
    await execute(path, {
      status: reviewForm.status,
      review_notes: reviewForm.review_notes
    }, 'PATCH')
    setReviewingItem(null)
    setReviewForm({ status: 'approved', review_notes: '' })
  }

  // Update donation next_eligible_date based on eligibility_days
  useEffect(() => {
    const donorObj = data?.donor
    if (modal === 'donation' && donorObj) {
      const donationDate = new Date(donation.donation_date || today)
      if (!isNaN(donationDate.getTime())) {
        const days = Number(donation.eligibility_days) || (donorObj.gender === 'male' ? 60 : 90)
        const nextDate = new Date(donationDate)
        nextDate.setDate(nextDate.getDate() + days)
        const expectedDateStr = nextDate.toISOString().slice(0, 10)
        setDonation((current) => {
          if (current.next_eligible_date === expectedDateStr) return current;
          return { ...current, next_eligible_date: expectedDateStr };
        })
      }
    }
  }, [modal, donation.donation_date, donation.eligibility_days, data])

  // Update eligibility next_eligible_date based on deferral_days
  useEffect(() => {
    if (modal === 'eligibility' && eligibility.outcome === 'temporarily_deferred') {
      const startsDate = new Date()
      if (eligibility.deferral_days) {
        const days = Number(eligibility.deferral_days)
        const nextDate = new Date(startsDate)
        nextDate.setDate(nextDate.getDate() + days)
        const expectedDateStr = nextDate.toISOString().slice(0, 10)
        setEligibility((current) => {
          if (current.next_eligible_date === expectedDateStr) return current;
          return { ...current, next_eligible_date: expectedDateStr };
        })
      }
    }
  }, [modal, eligibility.outcome, eligibility.deferral_days])

  // Update deferral ends_on based on deferral_days
  useEffect(() => {
    if (modal === 'deferral' && deferral.deferral_type === 'temporary' && deferral.deferral_days) {
      const startsDate = new Date(deferral.starts_on || today)
      if (!isNaN(startsDate.getTime())) {
        const days = Number(deferral.deferral_days)
        const nextDate = new Date(startsDate)
        nextDate.setDate(nextDate.getDate() + days)
        const expectedDateStr = nextDate.toISOString().slice(0, 10)
        setDeferral((current) => {
          if (current.ends_on === expectedDateStr) return current;
          return { ...current, ends_on: expectedDateStr };
        })
      }
    }
  }, [modal, deferral.deferral_type, deferral.starts_on, deferral.deferral_days])
  if (!data) return <div className="panel-loading"><div className="blood-loader" />Loading donor record…</div>
  const donor = data.donor

  const execute = async (path, body, method = 'POST') => {
    setMessage({ text: '', type: 'error', errors: null })
    try { await api(path, { method, body }); setModal(null); setMessage({ text: 'Record updated successfully.', type: 'success', errors: null }); await load() }
    catch (error) { setMessage({ text: error.message, type: 'error', errors: error.errors }) }
  }

  return <div className="dashboard-stack">
    <section className="profile-status-strip donor-detail-head"><div><span className="eyebrow">{donor.donor_code}</span><h2>{donor.full_name}</h2><p>{donor.phone}{donor.phone_secondary ? ` / ${donor.phone_secondary}` : ''} • {donor.email || 'No email'} • ID {donor.national_id_masked}</p></div><div><StatusBadge value={donor.verification_status} /><StatusBadge value={donor.eligibility_status} /><StatusBadge value={donor.availability_status} /></div></section>
    <FormMessage type={message.type} message={message.text} errors={message.errors} />
    <div className="stats-grid four"><StatCard icon={Droplets} label="Blood type" value={donor.blood_type} detail={donor.blood_type_verified_at ? `Confirmed ${formatDate(donor.blood_type_verified_at)}` : 'Unconfirmed'} /><StatCard icon={CalendarCheck} label="Next eligible" value={formatDate(donor.next_eligible_date)} detail={donor.last_donation_date ? `Last donated ${formatDate(donor.last_donation_date)}` : 'No donation recorded'} tone="green" /><StatCard icon={UserCheck} label="Total donations" value={donor.total_donations} detail={donor.total_donations > 1 ? 'Repeat donor' : 'Developing donor'} tone="gold" /><StatCard icon={ShieldCheck} label="Recruitment source" value={titleCase(donor.recruitment_source)} detail={donor.recruitment_institution_name || 'Direct recruitment'} tone="blue" /></div>
    <section className="panel"><div className="panel-header"><div><span className="eyebrow">Authorised actions</span><h2>Manage donor lifecycle</h2></div></div><div className="action-grid"><button className="action-card" onClick={() => setModal('verify')}><ShieldCheck /><strong>Verify documents</strong><span>Confirm identity and blood type</span></button><button className="action-card" onClick={() => setModal('eligibility')}><FileCheck2 /><strong>Assess donation eligibility</strong><span>Record current donation eligibility</span></button><button className="action-card" onClick={() => setModal('donation')}><Droplets /><strong>Record donation</strong><span>Update history and next eligible date</span></button><button className="action-card" onClick={() => setModal('deferral')}><PauseCircle /><strong>Add deferral</strong><span>Temporarily or permanently defer</span></button></div></section>
    <section className="content-grid two-columns"><article className="panel"><div className="panel-header"><h2>Donation history</h2></div>{data.donations.length ? <div className="list-stack">{data.donations.map((item) => <div className="list-item" key={item.id}><span className="list-icon"><Droplets /></span><div><strong>{formatDate(item.donation_date)} • {item.units} unit(s)</strong><p>{titleCase(item.donation_type)} at {item.town} • Next eligible {formatDate(item.next_eligible_date)}</p></div><StatusBadge value={item.screening_status} /></div>)}</div> : <EmptyState title="No donations recorded" />}</article><article className="panel"><div className="panel-header"><h2>Donation eligibility assessments</h2></div>{data.assessments.length ? <div className="list-stack">{data.assessments.map((item) => <div className="list-item" key={item.id}><span className="list-icon"><FileCheck2 /></span><div><strong>{titleCase(item.outcome)}</strong><p>{formatDate(item.assessment_date, true)} by {item.assessed_by_name}</p>{item.reason ? <small>{item.reason}</small> : null}</div><StatusBadge value={item.outcome} /></div>)}</div> : <EmptyState title="No assessments recorded" />}</article></section>
    <section className="panel"><div className="panel-header"><h2>Deferrals</h2></div>{data.deferrals.length ? <div className="table-wrap"><table><thead><tr><th>Type</th><th>Reason</th><th>Period</th><th>Status</th><th>Recorded by</th><th></th></tr></thead><tbody>{data.deferrals.map((item) => <tr key={item.id}><td>{titleCase(item.deferral_type)}</td><td>{item.reason}</td><td>{formatDate(item.starts_on)} — {formatDate(item.ends_on)}</td><td><StatusBadge value={item.status} /></td><td>{item.recorded_by_name}</td><td>{item.status === 'active' && item.deferral_type !== 'permanent' ? <button className="button button-ghost" onClick={() => execute(`/deferrals/${item.id}/close`, {}, 'PATCH')}>Close</button> : item.deferral_type === 'permanent' ? <span className="badge badge-permanent-lock" title="Permanent deferrals cannot be closed">Irreversible</span> : null}</td></tr>)}</tbody></table></div> : <EmptyState title="No deferrals recorded" />}</section>

    <section className="panel">
      <div className="panel-header">
        <h2>Appointment Requests</h2>
      </div>
      {data.appointments?.length ? (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Date & Time</th>
                <th>Institution</th>
                <th>Reason/Note</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {data.appointments.map((item) => (
                <tr key={item.id}>
                  <td>{formatDate(item.appointment_at, true)}</td>
                  <td>{item.institution_name}</td>
                  <td>{item.reason || '—'}</td>
                  <td>
                    <StatusBadge value={item.status} />
                  </td>
                  <td>
                    {item.status === 'pending' ? (
                      <div style={{ display: 'flex', gap: '8px' }}>
                        <button
                          className="button button-ghost"
                          style={{ borderColor: 'var(--color-success)', color: 'var(--color-success)', cursor: 'pointer' }}
                          onClick={() => {
                            setReviewingItem({ type: 'appointment', id: item.id })
                            setReviewForm({ status: 'approved', review_notes: '' })
                          }}
                        >
                          Approve
                        </button>
                        <button
                          className="button button-ghost"
                          style={{ borderColor: 'var(--color-danger)', color: 'var(--color-danger)', cursor: 'pointer' }}
                          onClick={() => {
                            setReviewingItem({ type: 'appointment', id: item.id })
                            setReviewForm({ status: 'rejected', review_notes: '' })
                          }}
                        >
                          Reject
                        </button>
                      </div>
                    ) : item.review_notes ? (
                      <small className="muted-text">Notes: {item.review_notes}</small>
                    ) : (
                      '—'
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <EmptyState title="No appointment requests" message="This donor hasn't requested any appointments." />
      )}
    </section>

    <section className="panel">
      <div className="panel-header">
        <h2>Profile Update Requests</h2>
      </div>
      {data.profile_update_requests?.length ? (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Field</th>
                <th>New Value</th>
                <th>Reason</th>
                <th>Status</th>
                <th>Date</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {data.profile_update_requests.map((item) => (
                <tr key={item.id}>
                  <td style={{ fontWeight: 600, textTransform: 'capitalize' }}>
                    {item.field.replaceAll('_', ' ')}
                  </td>
                  <td>{item.new_value}</td>
                  <td>{item.reason || '—'}</td>
                  <td>
                    <StatusBadge value={item.status} />
                  </td>
                  <td>{formatDate(item.created_at)}</td>
                  <td>
                    {item.status === 'pending' ? (
                      <div style={{ display: 'flex', gap: '8px' }}>
                        <button
                          className="button button-ghost"
                          style={{ borderColor: 'var(--color-success)', color: 'var(--color-success)', cursor: 'pointer' }}
                          onClick={() => {
                            setReviewingItem({ type: 'profile_update', id: item.id })
                            setReviewForm({ status: 'approved', review_notes: '' })
                          }}
                        >
                          Approve
                        </button>
                        <button
                          className="button button-ghost"
                          style={{ borderColor: 'var(--color-danger)', color: 'var(--color-danger)', cursor: 'pointer' }}
                          onClick={() => {
                            setReviewingItem({ type: 'profile_update', id: item.id })
                            setReviewForm({ status: 'rejected', review_notes: '' })
                          }}
                        >
                          Reject
                        </button>
                      </div>
                    ) : item.review_notes ? (
                      <small className="muted-text">Notes: {item.review_notes}</small>
                    ) : (
                      '—'
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <EmptyState title="No profile update requests" message="This donor hasn't requested any profile changes." />
      )}
    </section>
    <Modal open={modal === 'verify'} title="Documentation verification" onClose={() => setModal(null)}>
      <FormMessage message={message.text} errors={message.errors} />
      <form className="form-section" onSubmit={(e) => { e.preventDefault(); execute(`/donors/${id}/verify`, verification, 'PATCH') }}>
        <label>Documentation verification status
          <select value={verification.verification_status} onChange={(e) => setVerification({ ...verification, verification_status: e.target.value })}>
            <option value="verified">Verified</option>
            <option value="rejected">Rejected</option>
          </select>
        </label>
        <label>Confirmed blood type
          <select value={verification.blood_type} onChange={(e) => setVerification({ ...verification, blood_type: e.target.value })}>
            {['Unknown','A+','A-','B+','B-','AB+','AB-','O+','O-'].map((value) => <option key={value}>{value}</option>)}
          </select>
        </label>
        <label>Donor eligibility cycle (days)
          <input
            type="number"
            min="14"
            max="730"
            value={verification.eligibility_days || ''}
            onChange={(e) => setVerification({ ...verification, eligibility_days: Number(e.target.value) })}
            placeholder="e.g. 60 or 90"
            required
          />
          <span className="text-xs text-muted-foreground mt-1 block">Usually 60 days for males and 90 days for females.</span>
        </label>
        <label>Notes
          <textarea rows="3" value={verification.notes} onChange={(e) => setVerification({ ...verification, notes: e.target.value })} />
        </label>
        <button className="button button-primary">Save verification</button>
      </form>
    </Modal>

    <Modal open={modal === 'eligibility'} title="Record donation eligibility assessment" onClose={() => setModal(null)}>
      <form className="form-section" onSubmit={(e) => { e.preventDefault(); execute(`/donors/${id}/eligibility`, eligibility) }}>
        <label>Outcome
          <select value={eligibility.outcome} onChange={(e) => setEligibility({ ...eligibility, outcome: e.target.value })}>
            <option value="eligible">Eligible</option>
            <option value="temporarily_deferred">Temporarily deferred</option>
            <option value="permanently_deferred">Permanently deferred</option>
          </select>
        </label>
        {eligibility.outcome === 'temporarily_deferred' ? (
          <>
            <label>Deferral duration (days)
              <input
                type="number"
                min="1"
                max="3650"
                value={eligibility.deferral_days}
                onChange={(e) => setEligibility({ ...eligibility, deferral_days: e.target.value })}
                placeholder="e.g. 14, 30, 90"
                required
              />
            </label>
            <label>Preview next eligible date
              <input type="date" value={eligibility.next_eligible_date} disabled className="opacity-70 bg-slate-800" />
            </label>
          </>
        ) : eligibility.outcome === 'permanently_deferred' ? null : (
          <label>Next eligible date
            <input type="date" value={eligibility.next_eligible_date} onChange={(e) => setEligibility({ ...eligibility, next_eligible_date: e.target.value })} />
          </label>
        )}
        <label>Reason
          <textarea rows="2" value={eligibility.reason} onChange={(e) => setEligibility({ ...eligibility, reason: e.target.value })} required={eligibility.outcome !== 'eligible'} />
        </label>
        <label>Notes
          <textarea rows="3" value={eligibility.notes} onChange={(e) => setEligibility({ ...eligibility, notes: e.target.value })} />
        </label>
        <button className="button button-primary">Save assessment</button>
      </form>
    </Modal>

    <Modal open={modal === 'donation'} title="Record completed donation" onClose={() => setModal(null)} wide>
      <form className="form-section" onSubmit={(e) => { e.preventDefault(); execute(`/donors/${id}/donations`, donation) }}>
        <div className="form-grid two">
          <label>Donation date
            <input type="date" value={donation.donation_date} onChange={(e) => setDonation({ ...donation, donation_date: e.target.value })} />
          </label>
          <label>Donation type
            <select value={donation.donation_type} onChange={(e) => setDonation({ ...donation, donation_type: e.target.value })}>
              <option value="whole_blood">Whole blood</option>
              <option value="plasma">Plasma</option>
              <option value="platelets">Platelets</option>
              <option value="other">Other</option>
            </select>
          </label>
          <label>Units
            <input type="number" min="1" max="10" value={donation.units} onChange={(e) => setDonation({ ...donation, units: Number(e.target.value) })} />
          </label>
          <label>Screening status
            <select value={donation.screening_status} onChange={(e) => setDonation({ ...donation, screening_status: e.target.value })}>
              <option value="passed">Passed</option>
              <option value="pending">Pending</option>
              <option value="failed">Failed</option>
            </select>
          </label>
          <label>Region
            <select value={donation.region} onChange={(e) => setDonation({ ...donation, region: e.target.value })}>{['Hhohho','Manzini','Lubombo','Shiselweni'].map((value) => <option key={value}>{value}</option>)}</select>
          </label>
          <label>Town
            <input value={donation.town} onChange={(e) => setDonation({ ...donation, town: e.target.value })} />
          </label>
          <label>Interval to next eligibility (days)
            <input
              type="number"
              min="1"
              max="730"
              value={donation.eligibility_days}
              onChange={(e) => setDonation({ ...donation, eligibility_days: e.target.value })}
              placeholder="e.g. 60 or 90"
              required
            />
          </label>
          <label>Preview next eligible date
            <input type="date" value={donation.next_eligible_date} disabled className="opacity-70 bg-slate-800" />
          </label>
          <label>Campaign ID <span>(optional)</span>
            <input type="number" value={donation.campaign_id} onChange={(e) => setDonation({ ...donation, campaign_id: e.target.value })} />
          </label>
          <label className="full-span">Notes
            <textarea rows="3" value={donation.notes} onChange={(e) => setDonation({ ...donation, notes: e.target.value })} />
          </label>
        </div>
        <button className="button button-primary">Record donation</button>
      </form>
    </Modal>

    <Modal open={modal === 'deferral'} title="Add donor deferral" onClose={() => setModal(null)}>
      <form className="form-section" onSubmit={(e) => { e.preventDefault(); execute(`/donors/${id}/deferrals`, deferral) }}>
        <label>Deferral type
          <select value={deferral.deferral_type} onChange={(e) => setDeferral({ ...deferral, deferral_type: e.target.value })}>
            <option value="temporary">Temporary</option>
            <option value="permanent">Permanent</option>
          </select>
        </label>
        <label>Reason
          <textarea rows="2" value={deferral.reason} onChange={(e) => setDeferral({ ...deferral, reason: e.target.value })} required />
        </label>
        <label>Starts on
          <input type="date" value={deferral.starts_on} onChange={(e) => setDeferral({ ...deferral, starts_on: e.target.value })} />
        </label>
        {deferral.deferral_type === 'temporary' && (
          <label>Deferral duration (days)
            <input
              type="number"
              min="1"
              max="3650"
              value={deferral.deferral_days}
              onChange={(e) => setDeferral({ ...deferral, deferral_days: e.target.value })}
              placeholder="e.g. 30, 90, 180"
              required
            />
          </label>
        )}
        {deferral.deferral_type === 'temporary' ? (
          <label>Preview next eligible date
            <input type="date" value={deferral.ends_on} disabled className="opacity-70 bg-slate-800" />
          </label>
        ) : (
          <label>Ends on
            <input type="date" value={deferral.ends_on} onChange={(e) => setDeferral({ ...deferral, ends_on: e.target.value })} />
          </label>
        )}
        <label>Notes
          <textarea rows="3" value={deferral.notes} onChange={(e) => setDeferral({ ...deferral, notes: e.target.value })} />
        </label>
        <button className="button button-primary">Record deferral</button>
      </form>
    </Modal>

    <Modal open={Boolean(reviewingItem)} title={reviewingItem?.type === 'appointment' ? 'Review Appointment Request' : 'Review Profile Update Request'} onClose={() => setReviewingItem(null)}>
      <form className="form-section" onSubmit={handleReviewSubmit}>
        <p>Review the pending request and add decision notes for the donor.</p>
        <label>
          Decision
          <select
            value={reviewForm.status}
            onChange={(e) => setReviewForm({ ...reviewForm, status: e.target.value })}
          >
            <option value="approved">Approve</option>
            <option value="rejected">Reject</option>
          </select>
        </label>
        <label>
          Notes / Reason
          <textarea
            rows="3"
            value={reviewForm.review_notes}
            onChange={(e) => setReviewForm({ ...reviewForm, review_notes: e.target.value })}
            placeholder="Provide notes or feedback to the donor..."
            required={reviewForm.status === 'rejected'}
          />
        </label>
        <button className="button button-primary">Submit Review</button>
      </form>
    </Modal>
  </div>
}
