import { BellRing, CheckCircle2, MapPin, Plus, RefreshCw, Truck, Users } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useParams } from 'react-router-dom'
import { api } from '../api/client'
import FormMessage from '../components/FormMessage'
import Modal from '../components/Modal'
import StatCard from '../components/StatCard'
import StatusBadge from '../components/StatusBadge'
import { useAuth } from '../context/AuthContext'
import { formatDate } from '../utils/format'

export default function RequestDetailPage() {
  const { id } = useParams()
  const { user } = useAuth()
  const [data, setData] = useState(null)
  const [inventory, setInventory] = useState([])
  const [message, setMessage] = useState({ text: '', type: 'error' })
  const [busy, setBusy] = useState(false)
  const [statusOpen, setStatusOpen] = useState(false)
  const [statusForm, setStatusForm] = useState({ status: 'active', units_fulfilled: 0 })
  const [dispatchForm, setDispatchForm] = useState({ assigned_bank_id: '', units_assigned: 1, dispatch_notes: '' })
  const [notifyOpen, setNotifyOpen] = useState(false)
  const [selectedMatches, setSelectedMatches] = useState([])
  const [sendSms, setSendSms] = useState(true)

  const load = async () => {
    try {
      const [result, stock] = await Promise.all([
        api(`/requests/${id}`),
        user.role === 'admin' ? api('/enbts/inventory') : Promise.resolve([]),
      ])
      setData(result)
      setInventory(stock)
      setStatusForm({ status: result.request.status, units_fulfilled: Number(result.request.units_fulfilled) })
      setDispatchForm((current) => ({ ...current, units_assigned: Math.max(1, Number(result.request.units_required) - Number(result.request.units_fulfilled)) }))
    } catch (error) {
      setMessage({ text: error.message, type: 'error' })
    }
  }

  useEffect(() => { void load() }, [id])

  const banks = useMemo(() => {
    const map = new Map()
    inventory.forEach((row) => map.set(String(row.institution_id), row.institution_name))
    return [...map.entries()]
  }, [inventory])

  useEffect(() => {
    if (!dispatchForm.assigned_bank_id && banks[0]) {
      setDispatchForm((current) => ({ ...current, assigned_bank_id: banks[0][0] }))
    }
  }, [banks, dispatchForm.assigned_bank_id])

  const eligibleMatches = useMemo(() => {
    if (!data?.matches) return []
    return data.matches.filter(m => 
      ['not_sent', 'failed'].includes(m.notification_status) &&
      m.eligibility_status === 'eligible' &&
      m.verification_status === 'verified' &&
      m.availability_status === 'available' &&
      m.account_status === 'active'
    )
  }, [data])

  useEffect(() => {
    if (notifyOpen) {
      setSelectedMatches(eligibleMatches.map(m => m.id))
    }
  }, [notifyOpen, eligibleMatches])

  if (!data) return <div className="panel-loading"><div className="blood-loader" />Loading request…</div>

  const request = data.request
  const canMatch = user.role === 'admin'
  const canUpdateStatus = ['admin', 'hospital'].includes(user.role)
  const isCriticalInventoryRequest = request.urgency_level === 'critical' && request.request_code.startsWith('AUTO-')

  const run = async (action, body = {}) => {
    setBusy(true); setMessage({ text: '', type: 'error' })
    try {
      const result = await api(`/requests/${id}/${action}`, { method: 'POST', body })
      setMessage({ text: action === 'match' ? `${result.count} suitable donors ranked.` : `${result.notified} donors notified.`, type: 'success' })
      await load()
    } catch (error) { setMessage({ text: error.message, type: 'error' }) }
    finally { setBusy(false) }
  }

  const createDispatch = async (event) => {
    event.preventDefault()
    setBusy(true); setMessage({ text: '', type: 'error' })
    try {
      await api('/enbts/dispatches', {
        method: 'POST',
        body: {
          request_id: Number(id),
          assigned_bank_id: Number(dispatchForm.assigned_bank_id),
          units_assigned: Number(dispatchForm.units_assigned),
          dispatch_notes: dispatchForm.dispatch_notes,
        },
      })
      setMessage({ text: 'Dispatch assigned. Mbabane can assign Mbabane Blood Bank too, including Mbabane hospital requests.', type: 'success' })
      await load()
    } catch (error) { setMessage({ text: error.message, type: 'error' }) }
    finally { setBusy(false) }
  }

  const updateStatus = async (event) => {
    event.preventDefault(); setBusy(true)
    try {
      await api(`/requests/${id}/status`, { method: 'PATCH', body: statusForm })
      setStatusOpen(false); setMessage({ text: 'Request status updated.', type: 'success' }); await load()
    } catch (error) { setMessage({ text: error.message, type: 'error' }) }
    finally { setBusy(false) }
  }

  const toggleSelectAll = () => {
    if (selectedMatches.length === eligibleMatches.length) {
      setSelectedMatches([])
    } else {
      setSelectedMatches(eligibleMatches.map(m => m.id))
    }
  }

  const toggleSelectMatch = (matchId) => {
    setSelectedMatches(prev => 
      prev.includes(matchId) ? prev.filter(id => id !== matchId) : [...prev, matchId]
    )
  }

  const submitNotification = async (event) => {
    event.preventDefault()
    setBusy(true); setMessage({ text: '', type: 'error' })
    try {
      const result = await api(`/requests/${id}/notify`, {
        method: 'POST',
        body: {
          match_ids: selectedMatches,
          send_sms: sendSms
        }
      })
      setMessage({ text: `${result.notified} donors notified.`, type: 'success' })
      setNotifyOpen(false)
      await load()
    } catch (error) { setMessage({ text: error.message, type: 'error' }) }
    finally { setBusy(false) }
  }

  const accepted = data.matches.filter((item) => item.donor_response === 'accepted').length
  const notified = data.matches.filter((item) => ['sent', 'seen'].includes(item.notification_status)).length

  return <div className="dashboard-stack">
    <section className={`request-detail-hero urgency-${request.urgency_level}`}><div><div className="request-card-top"><span>{request.request_code}</span><StatusBadge value={request.urgency_level} /></div><h2>{request.blood_type_needed} blood required</h2><p>{request.hospital_name} • <MapPin size={16} /> {request.town}, {request.region}</p><small>Created {formatDate(request.created_at, true)} {request.needed_by ? `• Needed by ${formatDate(request.needed_by, true)}` : ''}</small></div><div className="detail-blood-orb"><span>{request.blood_type_needed}</span></div></section>
    <FormMessage type={message.type} message={message.text} />
    <div className="stats-grid four"><StatCard label="Units" value={`${request.units_fulfilled}/${request.units_required}`} detail="Fulfilled / required" /><StatCard icon={Users} label="Matched donors" value={data.matches.length} tone="blue" /><StatCard icon={BellRing} label="Notified" value={notified} tone="gold" /><StatCard icon={CheckCircle2} label="Accepted" value={accepted} tone="green" /></div>

    <section className="panel">
      <div className="panel-header"><div><span className="eyebrow">Request control</span><h2>Coordination actions</h2></div><StatusBadge value={request.status} /></div>
      <div className="button-row wrap">{canMatch ? <><button className="button button-secondary" disabled={busy} onClick={() => run('match', { limit: 100 })}><RefreshCw size={17} /> Identify suitable donors</button>{isCriticalInventoryRequest && <button className="button button-primary" disabled={busy} onClick={() => setNotifyOpen(true)}><BellRing size={17} /> Notify top matches</button>}</> : null}{canUpdateStatus ? <button className="button button-secondary" onClick={() => setStatusOpen(true)}>Update fulfilment and status</button> : null}</div>
    </section>

    {canMatch ? <section className="panel">
      <div className="panel-header"><div><span className="eyebrow">Mbabane Central dispatch</span><h2>Assign blood bank to fulfil this request</h2><p>Mbabane Central may assign Mbabane Blood Bank, Manzini, or Hlathikhulu depending on stock and location.</p></div><Truck /></div>
      <form className="form-grid" onSubmit={createDispatch}>
        <label>Dispatching blood bank<select value={dispatchForm.assigned_bank_id} onChange={(e) => setDispatchForm({ ...dispatchForm, assigned_bank_id: e.target.value })}>{banks.map(([bankId, name]) => <option key={bankId} value={bankId}>{name}</option>)}</select></label>
        <label>Units assigned<input type="number" min="1" max={Math.max(1, Number(request.units_required) - Number(request.units_fulfilled))} value={dispatchForm.units_assigned} onChange={(e) => setDispatchForm({ ...dispatchForm, units_assigned: e.target.value })} /></label>
        <label className="full-span">Dispatch instruction<input value={dispatchForm.dispatch_notes} onChange={(e) => setDispatchForm({ ...dispatchForm, dispatch_notes: e.target.value })} placeholder="Example: Mbabane Blood Bank to dispatch directly to Mbabane hospital." /></label>
        <button className="button button-primary" disabled={busy || !dispatchForm.assigned_bank_id}><Plus size={17} /> Assign dispatch</button>
      </form>
    </section> : null}

    <section className="panel"><div className="panel-header"><div><span className="eyebrow">Ranked matching</span><h2>Suitable donors</h2></div><span className="panel-count">Compatibility + eligibility + location + responsiveness</span></div>{data.matches.length ? <div className="table-wrap"><table><thead><tr><th>Rank</th><th>Donor</th><th>Blood</th><th>Location</th><th>Score</th><th>Notification</th><th>Response</th></tr></thead><tbody>{data.matches.map((match, index) => <tr key={match.id}><td><strong>#{index + 1}</strong></td><td><strong>{match.full_name}</strong><small>{match.donor_code} • {match.phone}</small></td><td><strong className="blood-text">{match.blood_type}</strong></td><td>{match.town}<small>{match.region}</small></td><td><div className="score-cell"><strong>{match.total_match_score}</strong><span style={{ width: `${Math.min(100, Number(match.total_match_score))}%` }} /></div></td><td><StatusBadge value={match.notification_status} /></td><td><StatusBadge value={match.donor_response} /></td></tr>)}</tbody></table></div> : <div className="empty-state"><Users size={34} /><h3>No matched donors yet</h3><p>Run donor matching to rank verified, eligible and available donors.</p></div>}</section>

    <Modal open={statusOpen} onClose={() => setStatusOpen(false)} title="Update request status"><form className="form-section" onSubmit={updateStatus}><label>Status<select value={statusForm.status} onChange={(e) => setStatusForm({ ...statusForm, status: e.target.value })}>{['draft','active','partially_fulfilled','fulfilled','cancelled','expired'].map((value) => <option key={value} value={value}>{value.replaceAll('_', ' ')}</option>)}</select></label><label>Units fulfilled<input type="number" min="0" max={request.units_required} value={statusForm.units_fulfilled} onChange={(e) => setStatusForm({ ...statusForm, units_fulfilled: Number(e.target.value) })} /></label><button className="button button-primary" disabled={busy}>Save changes</button></form></Modal>

    <Modal open={notifyOpen} onClose={() => setNotifyOpen(false)} title="Notify Top Matches" wide={true}>
      {eligibleMatches.length ? (
        <form onSubmit={submitNotification} className="form-section">
          <p style={{ marginBottom: '10px', color: 'var(--muted)' }}>
            Select the matches you would like to notify. Only verified, eligible, and available donors are listed.
          </p>
          
          <div className="table-wrap" style={{ maxHeight: '350px', overflowY: 'auto', border: '1px solid var(--line)', borderRadius: '10px', marginBottom: '15px' }}>
            <table style={{ minWidth: '100%' }}>
              <thead>
                <tr>
                  <th style={{ width: '40px', padding: '10px' }}>
                    <input 
                      type="checkbox" 
                      checked={selectedMatches.length === eligibleMatches.length} 
                      onChange={toggleSelectAll} 
                      style={{ width: '16px', height: '16px', cursor: 'pointer' }}
                    />
                  </th>
                  <th style={{ padding: '10px' }}>Rank</th>
                  <th style={{ padding: '10px' }}>Donor</th>
                  <th style={{ padding: '10px' }}>Blood</th>
                  <th style={{ padding: '10px' }}>Location</th>
                  <th style={{ padding: '10px' }}>Score</th>
                </tr>
              </thead>
              <tbody>
                {eligibleMatches.map((match) => {
                  const originalIndex = data.matches.findIndex(m => m.id === match.id);
                  const isChecked = selectedMatches.includes(match.id);
                  return (
                    <tr key={match.id}>
                      <td style={{ padding: '10px' }}>
                        <input 
                          type="checkbox" 
                          checked={isChecked} 
                          onChange={() => toggleSelectMatch(match.id)}
                          style={{ width: '16px', height: '16px', cursor: 'pointer' }}
                        />
                      </td>
                      <td style={{ padding: '10px' }}><strong>#{originalIndex + 1}</strong></td>
                      <td style={{ padding: '10px' }}>
                        <strong>{match.full_name}</strong>
                        <small>{match.donor_code} • {match.phone}</small>
                      </td>
                      <td style={{ padding: '10px' }}><strong className="blood-text">{match.blood_type}</strong></td>
                      <td style={{ padding: '10px' }}>{match.town}<small>{match.region}</small></td>
                      <td style={{ padding: '10px' }}><strong>{match.total_match_score}</strong></td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>

          <div style={{ display: 'flex', flexDirection: 'column', gap: '12px' }}>
            <label className="checkbox-label">
              <input 
                type="checkbox" 
                checked={sendSms} 
                onChange={(e) => setSendSms(e.target.checked)} 
              />
              <span>Send SMS alert (only sent if donor's preferred contact method is SMS)</span>
            </label>

            <div className="button-row" style={{ justifyContent: 'flex-end', marginTop: '10px' }}>
              <button type="button" className="button button-secondary" onClick={() => setNotifyOpen(false)}>
                Cancel
              </button>
              <button 
                type="submit" 
                className="button button-primary" 
                disabled={busy || selectedMatches.length === 0}
              >
                <BellRing size={17} /> Notify Selected Matches ({selectedMatches.length})
              </button>
            </div>
          </div>
        </form>
      ) : (
        <div className="empty-state" style={{ padding: '20px 0' }}>
          <Users size={34} />
          <h3>No unnotified matches</h3>
          <p>All suitable donors have already been notified, or there are no eligible/available donors.</p>
          <div className="button-row" style={{ marginTop: '20px' }}>
            <button type="button" className="button button-secondary" onClick={() => setNotifyOpen(false)}>
              Close
            </button>
          </div>
        </div>
      )}
    </Modal>
  </div>
}
