import { useEffect, useMemo, useState } from 'react'
import { PackageCheck, Plus, Truck } from 'lucide-react'
import { api } from '../api/client'
import EmptyState from '../components/EmptyState'
import FormMessage from '../components/FormMessage'
import StatusBadge from '../components/StatusBadge'
import { useAuth } from '../context/AuthContext'
import { formatDate } from '../utils/format'

const statuses = ['assigned','accepted','packed','in_transit','delivered','rejected','cancelled']

export default function DispatchesPage() {
  const { user } = useAuth()
  const [dispatches, setDispatches] = useState(null)
  const [requests, setRequests] = useState([])
  const [inventory, setInventory] = useState([])
  const [message, setMessage] = useState(null)
  const [form, setForm] = useState({ request_id: '', assigned_bank_id: '', units_assigned: 1, dispatch_notes: '' })

  const load = async () => {
    try {
      const [dispatchRows, requestRows, inventoryRows] = await Promise.all([
        api('/enbts/dispatches'),
        user.role === 'admin' ? api('/requests?status=active') : Promise.resolve([]),
        user.role === 'admin' ? api('/enbts/inventory') : Promise.resolve([]),
      ])
      setDispatches(dispatchRows)
      setRequests(requestRows)
      setInventory(inventoryRows)
    } catch (error) { setMessage({ type: 'error', message: error.message }) }
  }
  useEffect(() => { load() }, [])

  const banks = useMemo(() => {
    const map = new Map()
    inventory.forEach((row) => map.set(String(row.institution_id), row.institution_name))
    return [...map.entries()]
  }, [inventory])
  useEffect(() => {
    if (!form.request_id && requests[0]) setForm((value) => ({ ...value, request_id: String(requests[0].id) }))
    if (!form.assigned_bank_id && banks[0]) setForm((value) => ({ ...value, assigned_bank_id: banks[0][0] }))
  }, [requests, banks, form.request_id, form.assigned_bank_id])

  const createDispatch = async (event) => {
    event.preventDefault()
    try {
      await api('/enbts/dispatches', { method: 'POST', body: { ...form, request_id: Number(form.request_id), assigned_bank_id: Number(form.assigned_bank_id), units_assigned: Number(form.units_assigned) } })
      setMessage({ type: 'success', message: 'Dispatch assigned to branch.' })
      await load()
    } catch (error) { setMessage({ type: 'error', message: error.message, errors: error.errors }) }
  }

  const updateStatus = async (id, status) => {
    try {
      await api(`/enbts/dispatches/${id}/status`, { method: 'PATCH', body: { status } })
      setMessage({ type: 'success', message: 'Dispatch status updated.' })
      await load()
    } catch (error) { setMessage({ type: 'error', message: error.message, errors: error.errors }) }
  }

  return <div className="dashboard-stack">
    <section className="welcome-banner compact-banner"><div><span className="eyebrow">Central-to-branch fulfilment</span><h2>Dispatch assignments.</h2><p>Hospitals request blood. Mbabane Central assigns the dispatch. Branches accept, pack, move and deliver.</p></div><Truck size={48} /></section>
    <FormMessage type={message?.type} message={message?.message} errors={message?.errors} />
    {user.role === 'admin' ? <section className="panel"><div className="panel-header"><div><span className="eyebrow">Mbabane Central only</span><h2>Assign blood bank dispatch</h2></div></div><form className="form-grid" onSubmit={createDispatch}>
      <label>Hospital request<select value={form.request_id} onChange={(e) => setForm({ ...form, request_id: e.target.value })}>{requests.map((request) => <option key={request.id} value={request.id}>{request.request_code} • {request.hospital_name} • {request.blood_type_needed}</option>)}</select></label>
      <label>Blood bank<select value={form.assigned_bank_id} onChange={(e) => setForm({ ...form, assigned_bank_id: e.target.value })}>{banks.map(([id, name]) => <option key={id} value={id}>{name}</option>)}</select></label>
      <label>Units assigned<input type="number" min="1" value={form.units_assigned} onChange={(e) => setForm({ ...form, units_assigned: e.target.value })} /></label>
      <label>Notes<input value={form.dispatch_notes} onChange={(e) => setForm({ ...form, dispatch_notes: e.target.value })} placeholder="Optional dispatch instruction" /></label>
      <button className="button button-primary"><Plus size={17} /> Assign dispatch</button>
    </form></section> : null}
    <section className="panel"><div className="panel-header"><div><span className="eyebrow">Dispatch board</span><h2>{user.role === 'staff' ? 'Your assigned dispatches' : 'All dispatches'}</h2></div></div>{!dispatches ? <div className="panel-loading"><div className="blood-loader" />Loading dispatches…</div> : dispatches.length === 0 ? <EmptyState title="No dispatches yet" /> : <div className="table-wrap"><table><thead><tr><th>Request</th><th>Assigned bank</th><th>Blood</th><th>Units</th><th>Status</th><th>Updated</th></tr></thead><tbody>{dispatches.map((item) => <tr key={item.id}><td><strong>{item.request_code}</strong><small>{item.hospital_name}, {item.hospital_town}</small></td><td>{item.assigned_bank_name}</td><td>{item.blood_type}</td><td>{item.units_assigned}</td><td>{['staff','admin'].includes(user.role) ? <select value={item.status} onChange={(e) => updateStatus(item.id, e.target.value)}>{statuses.map((status) => <option key={status}>{status}</option>)}</select> : <StatusBadge value={item.status} />}</td><td><small>{formatDate(item.updated_at, true)}</small></td></tr>)}</tbody></table></div>}</section>
  </div>
}
