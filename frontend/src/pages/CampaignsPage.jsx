import { CalendarHeart, MapPin, Megaphone, Plus, Send, Users } from 'lucide-react'
import { useEffect, useState } from 'react'
import { api } from '../api/client'
import EmptyState from '../components/EmptyState'
import FormMessage from '../components/FormMessage'
import Modal from '../components/Modal'
import StatusBadge from '../components/StatusBadge'
import { useAuth } from '../context/AuthContext'
import { formatDate, titleCase } from '../utils/format'

const initialForm = { title: '', description: '', campaign_type: 'recruitment', target_region: '', target_town: '', target_blood_type: 'All', venue: '', starts_at: '', ends_at: '', capacity: '', status: 'scheduled' }

export default function CampaignsPage() {
  const { user } = useAuth()
  const [items, setItems] = useState(null)
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState(initialForm)
  const [state, setState] = useState({ message: '', type: 'error', errors: null })
  const isOperator = user.role === 'admin'
  const load = () => api('/campaigns').then(setItems).catch((error) => setState({ message: error.message, type: 'error' }))
  useEffect(() => { void load() }, [])

  const create = async (event) => {
    event.preventDefault()
    try {
      await api('/campaigns', { method: 'POST', body: { ...form, target_region: form.target_region || null, target_town: form.target_town || null, capacity: form.capacity ? Number(form.capacity) : null } })
      setOpen(false); setForm(initialForm); setState({ message: 'Campaign created successfully.', type: 'success' }); await load()
    } catch (error) { setState({ message: error.message, errors: error.errors, type: 'error' }) }
  }
  const join = async (id, participation_status) => {
    try { await api(`/campaigns/${id}/join`, { method: 'POST', body: { participation_status } }); setState({ message: 'Campaign response saved.', type: 'success' }); await load() }
    catch (error) { setState({ message: error.message, type: 'error' }) }
  }
  const invite = async (id) => {
    try { const result = await api(`/campaigns/${id}/invite`, { method: 'POST', body: { limit: 500 } }); setState({ message: `${result.invited} donors invited.`, type: 'success' }); await load() }
    catch (error) { setState({ message: error.message, type: 'error' }) }
  }
  const updateStatus = async (id, status) => {
    try { await api(`/campaigns/${id}/status`, { method: 'PATCH', body: { status } }); await load() }
    catch (error) { setState({ message: error.message, type: 'error' }) }
  }

  return <div className="dashboard-stack"><section className="welcome-banner compact-banner campaign-banner"><div><span className="eyebrow">Mbabane-approved donor campaigns</span><h2>Campaigns turn awareness into active donors.</h2><p>Branches request campaigns separately; Mbabane Central approves and sends donor invitations.</p></div>{isOperator ? <button className="button button-primary" onClick={() => setOpen(true)}><Plus size={18} /> New campaign</button> : <CalendarHeart size={42} />}</section><FormMessage type={state.type} message={state.message} errors={state.errors} />{!items ? <div className="panel-loading"><div className="blood-loader" />Loading campaigns…</div> : items.length === 0 ? <section className="panel"><EmptyState title="No campaigns available" /></section> : <div className="campaign-grid">{items.map((campaign) => <article className="campaign-card" key={campaign.id}><div className="campaign-card-cover"><Megaphone /><StatusBadge value={campaign.status} /></div><div className="campaign-card-body"><span className="eyebrow">{titleCase(campaign.campaign_type)}</span><h3>{campaign.title}</h3><p>{campaign.description}</p><div className="campaign-facts"><span><CalendarHeart /> {formatDate(campaign.starts_at, true)}</span><span><MapPin /> {campaign.venue}{campaign.target_region ? `, ${campaign.target_region}` : ''}</span><span><Users /> {campaign.participant_count || 0} participant(s) • {campaign.donations_generated || 0} donation(s)</span></div>{user.role === 'donor' ? <div className="button-row"><button className="button button-primary" onClick={() => join(campaign.id, 'registered')}>Register</button><button className="button button-secondary" onClick={() => join(campaign.id, 'interested')}>Interested</button>{campaign.my_status ? <StatusBadge value={campaign.my_status} /> : null}</div> : <div className="campaign-operator-actions"><button className="button button-secondary" onClick={() => invite(campaign.id)}><Send size={16} /> Invite matching donors</button><select value={campaign.status} onChange={(e) => updateStatus(campaign.id, e.target.value)}>{['draft','scheduled','active','completed','cancelled'].map((value) => <option key={value}>{value}</option>)}</select></div>}</div></article>)}</div>}
    <Modal open={open} onClose={() => setOpen(false)} title="Create donor campaign" wide><form className="form-section" onSubmit={create}><FormMessage message={state.message} errors={state.errors} /><div className="form-grid two"><label>Campaign title<input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} required /></label><label>Campaign type<select value={form.campaign_type} onChange={(e) => setForm({ ...form, campaign_type: e.target.value })}>{['recruitment','donation_drive','awareness','retention','emergency'].map((value) => <option key={value} value={value}>{titleCase(value)}</option>)}</select></label><label>Target region<select value={form.target_region} onChange={(e) => setForm({ ...form, target_region: e.target.value })}><option value="">All regions</option>{['Hhohho','Manzini','Lubombo','Shiselweni'].map((value) => <option key={value}>{value}</option>)}</select></label><label>Target town <span>(optional)</span><input value={form.target_town} onChange={(e) => setForm({ ...form, target_town: e.target.value })} /></label><label>Target blood type<select value={form.target_blood_type} onChange={(e) => setForm({ ...form, target_blood_type: e.target.value })}>{['All','A+','A-','B+','B-','AB+','AB-','O+','O-'].map((value) => <option key={value}>{value}</option>)}</select></label><label>Capacity <span>(optional)</span><input type="number" min="1" value={form.capacity} onChange={(e) => setForm({ ...form, capacity: e.target.value })} /></label><label>Venue<input value={form.venue} onChange={(e) => setForm({ ...form, venue: e.target.value })} required /></label><label>Status<select value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}>{['draft','scheduled','active'].map((value) => <option key={value}>{value}</option>)}</select></label><label>Starts at<input type="datetime-local" value={form.starts_at} onChange={(e) => setForm({ ...form, starts_at: e.target.value })} required /></label><label>Ends at<input type="datetime-local" value={form.ends_at} onChange={(e) => setForm({ ...form, ends_at: e.target.value })} /></label><label className="full-span">Description<textarea rows="4" value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} required /></label></div><button className="button button-primary">Create campaign</button></form></Modal></div>
}
