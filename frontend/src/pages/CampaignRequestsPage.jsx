import { useEffect, useState } from 'react'
import { ClipboardCheck, Send } from 'lucide-react'
import { api } from '../api/client'
import EmptyState from '../components/EmptyState'
import FormMessage from '../components/FormMessage'
import StatusBadge from '../components/StatusBadge'
import { useAuth } from '../context/AuthContext'
import { formatDate } from '../utils/format'

const initial = { title: '', description: '', campaign_type: 'recruitment', target_region: '', target_town: '', target_blood_type: 'All', venue: '', starts_at: '', capacity: '' }

export default function CampaignRequestsPage() {
  const { user } = useAuth()
  const [items, setItems] = useState(null)
  const [form, setForm] = useState(initial)
  const [message, setMessage] = useState(null)
  const load = () => api('/enbts/campaign-requests').then(setItems).catch((error) => setMessage({ type: 'error', message: error.message }))
  useEffect(() => { load() }, [])

  const submit = async (event) => {
    event.preventDefault()
    try {
      await api('/enbts/campaign-requests', { method: 'POST', body: { ...form, target_region: form.target_region || null, target_town: form.target_town || null, capacity: form.capacity ? Number(form.capacity) : null } })
      setForm(initial); setMessage({ type: 'success', message: 'Request sent to Mbabane Central.' }); await load()
    } catch (error) { setMessage({ type: 'error', message: error.message, errors: error.errors }) }
  }

  const review = async (id, status) => {
    try {
      await api(`/enbts/campaign-requests/${id}/review`, { method: 'PATCH', body: { status } })
      setMessage({ type: 'success', message: status === 'approved' ? 'Approved and converted to scheduled campaign.' : 'Campaign request rejected.' })
      await load()
    } catch (error) { setMessage({ type: 'error', message: error.message, errors: error.errors }) }
  }

  return <div className="dashboard-stack">
    <section className="welcome-banner compact-banner"><div><span className="eyebrow">Controlled donor communication</span><h2>Campaign requests.</h2><p>Branches request campaigns. Mbabane Central approves and converts them before donor messaging.</p></div><ClipboardCheck size={48} /></section>
    <FormMessage type={message?.type} message={message?.message} errors={message?.errors} />
    {user.role === 'staff' ? <section className="panel"><div className="panel-header"><div><span className="eyebrow">Branch operator</span><h2>Request a campaign</h2></div></div><form className="form-grid" onSubmit={submit}>
      <label>Title<input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} /></label>
      <label>Campaign type<select value={form.campaign_type} onChange={(e) => setForm({ ...form, campaign_type: e.target.value })}>{['recruitment','donation_drive','awareness','retention','emergency'].map((value) => <option key={value}>{value}</option>)}</select></label>
      <label>Venue<input value={form.venue} onChange={(e) => setForm({ ...form, venue: e.target.value })} /></label>
      <label>Starts at<input type="datetime-local" value={form.starts_at} onChange={(e) => setForm({ ...form, starts_at: e.target.value })} /></label>
      <label>Target region<select value={form.target_region} onChange={(e) => setForm({ ...form, target_region: e.target.value })}><option value="">Any region</option>{['Hhohho','Manzini','Lubombo','Shiselweni'].map((value) => <option key={value}>{value}</option>)}</select></label>
      <label>Target blood type<select value={form.target_blood_type} onChange={(e) => setForm({ ...form, target_blood_type: e.target.value })}>{['All','A+','A-','B+','B-','AB+','AB-','O+','O-'].map((value) => <option key={value}>{value}</option>)}</select></label>
      <label className="full-span">Description<textarea value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} /></label>
      <button className="button button-primary"><Send size={17} /> Send request</button>
    </form></section> : null}
    <section className="panel"><div className="panel-header"><div><span className="eyebrow">Request queue</span><h2>{user.role === 'admin' ? 'Mbabane review queue' : 'Your branch requests'}</h2></div></div>{!items ? <div className="panel-loading"><div className="blood-loader" />Loading campaign requests…</div> : items.length === 0 ? <EmptyState title="No campaign requests" /> : <div className="table-wrap"><table><thead><tr><th>Campaign</th><th>Branch</th><th>When</th><th>Status</th><th>Action</th></tr></thead><tbody>{items.map((item) => <tr key={item.id}><td><strong>{item.title}</strong><small>{item.campaign_type}</small></td><td>{item.institution_name}</td><td>{formatDate(item.starts_at, true)}</td><td><StatusBadge value={item.status} /></td><td>{user.role === 'admin' && item.status === 'pending' ? <div className="button-row"><button className="button button-secondary" onClick={() => review(item.id, 'approved')}>Approve</button><button className="button button-danger" onClick={() => review(item.id, 'rejected')}>Reject</button></div> : item.campaign_id ? `Campaign #${item.campaign_id}` : '—'}</td></tr>)}</tbody></table></div>}</section>
  </div>
}
