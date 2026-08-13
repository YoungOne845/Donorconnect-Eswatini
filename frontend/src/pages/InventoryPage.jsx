import { useEffect, useMemo, useState } from 'react'
import { Droplets, Save, Search } from 'lucide-react'
import { api } from '../api/client'
import EmptyState from '../components/EmptyState'
import FormMessage from '../components/FormMessage'
import StatusBadge from '../components/StatusBadge'
import { useAuth } from '../context/AuthContext'

const bloodTypes = ['A+','A-','B+','B-','AB+','AB-','O+','O-']

export default function InventoryPage() {
  const { user } = useAuth()
  const [items, setItems] = useState(null)
  const [message, setMessage] = useState(null)
  const [query, setQuery] = useState('')
  const [form, setForm] = useState({ institution_id: '', blood_type: 'O+', available_units: 0, reserved_units: 0, expired_units: 0 })

  const load = () => api('/enbts/inventory').then(setItems).catch((error) => setMessage({ type: 'error', message: error.message }))
  useEffect(() => { load() }, [])

  const banks = useMemo(() => {
    const map = new Map()
    ;(items || []).forEach((row) => map.set(String(row.institution_id), row.institution_name))
    return [...map.entries()]
  }, [items])

  useEffect(() => {
    if (!form.institution_id && banks.length) setForm((value) => ({ ...value, institution_id: banks[0][0] }))
  }, [banks, form.institution_id])

  // Automatically pre-fill form fields when the selected blood bank or blood type changes
  useEffect(() => {
    if (!items || !form.institution_id) return
    const row = items.find(
      (item) => String(item.institution_id) === String(form.institution_id) && item.blood_type === form.blood_type
    )
    if (row) {
      setForm((prev) => ({
        ...prev,
        available_units: row.available_units,
        reserved_units: row.reserved_units,
        expired_units: row.expired_units,
      }))
    } else {
      setForm((prev) => ({
        ...prev,
        available_units: 0,
        reserved_units: 0,
        expired_units: 0,
      }))
    }
  }, [items, form.institution_id, form.blood_type])

  const filtered = (items || []).filter((row) => `${row.institution_name} ${row.blood_type}`.toLowerCase().includes(query.toLowerCase()))

  const save = async (event) => {
    event.preventDefault()
    try {
      await api('/enbts/inventory', { method: 'PUT', body: {
        ...form,
        institution_id: Number(form.institution_id),
        available_units: Number(form.available_units),
        reserved_units: Number(form.reserved_units),
        expired_units: Number(form.expired_units),
      } })
      setMessage({ type: 'success', message: 'Inventory updated.' })
      await load()
    } catch (error) {
      setMessage({ type: 'error', message: error.message, errors: error.errors })
    }
  }

  const handleRowClick = (row) => {
    if (user.role !== 'admin' && String(row.institution_id) !== String(user.institution_id)) {
      return
    }
    setForm({
      institution_id: String(row.institution_id),
      blood_type: row.blood_type,
      available_units: row.available_units,
      reserved_units: row.reserved_units,
      expired_units: row.expired_units,
    })
  }

  return <div className="dashboard-stack">
    <section className="welcome-banner compact-banner">
      <div><span className="eyebrow">ENBTS stock visibility</span><h2>{user.role === 'admin' ? 'National blood inventory.' : 'Your branch inventory.'}</h2><p>Mbabane Central sees all blood banks. Branch operators update only their own bank stock.</p></div>
      <Droplets size={46} />
    </section>
    <FormMessage type={message?.type} message={message?.message} errors={message?.errors} />
    <section className="content-grid two-columns">
      <article className="panel">
        <div className="panel-header"><div><span className="eyebrow">Update stock</span><h2>Blood units</h2></div></div>
        <form className="form-grid" onSubmit={save}>
          <label>Blood bank<select value={form.institution_id} onChange={(e) => setForm({ ...form, institution_id: e.target.value })} disabled={user.role !== 'admin'}>{banks.map(([id, name]) => <option key={id} value={id}>{name}</option>)}</select></label>
          <label>Blood type<select value={form.blood_type} onChange={(e) => setForm({ ...form, blood_type: e.target.value })}>{bloodTypes.map((type) => <option key={type}>{type}</option>)}</select></label>
          <label>Available units<input type="number" min="0" value={form.available_units} onChange={(e) => setForm({ ...form, available_units: e.target.value })} /></label>
          <label>Reserved units<input type="number" min="0" value={form.reserved_units} onChange={(e) => setForm({ ...form, reserved_units: e.target.value })} /></label>
          <label>Expired units<input type="number" min="0" value={form.expired_units} onChange={(e) => setForm({ ...form, expired_units: e.target.value })} /></label>
          <button className="button button-primary"><Save size={17} /> Save inventory</button>
        </form>
      </article>
      <article className="panel">
        <div className="panel-header"><div><span className="eyebrow">Live stock board</span><h2>Availability by bank</h2></div></div>
        <label className="search-field"><Search size={18} /><input placeholder="Search bank or blood type" value={query} onChange={(e) => setQuery(e.target.value)} /></label>
        {!items ? <div className="panel-loading"><div className="blood-loader" />Loading inventory…</div> : filtered.length === 0 ? <EmptyState title="No inventory found" /> : <div className="table-wrap"><table><thead><tr><th>Bank</th><th>Type</th><th>Available</th><th>Reserved</th><th>Stock</th></tr></thead><tbody>{filtered.map((row) => <tr key={`${row.institution_id}-${row.blood_type}`} onClick={() => handleRowClick(row)} style={{ cursor: 'pointer' }}><td><strong>{row.institution_name}</strong><small>{row.town}</small></td><td><strong>{row.blood_type}</strong></td><td>{row.available_units}</td><td>{row.reserved_units}</td><td><StatusBadge value={Number(row.is_critical) ? 'critical' : 'safe'} /></td></tr>)}</tbody></table></div>}
      </article>
    </section>
  </div>
}
