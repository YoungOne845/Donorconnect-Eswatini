import { AlertTriangle, Building2, Plus, Trash2 } from 'lucide-react'
import { useEffect, useState } from 'react'
import { api } from '../api/client'
import FormMessage from '../components/FormMessage'
import Modal from '../components/Modal'
import StatusBadge from '../components/StatusBadge'
import { ESWATINI_REGIONS, ESWATINI_TOWNS, INSTITUTION_TYPES } from '../data/eswatini'
import { titleCase } from '../utils/format'

const initial = {
  name: '',
  institution_type: 'hospital',
  phone: '',
  email: '',
  region: 'Hhohho',
  town: '',
  address: '',
  is_active: true,
}

export default function InstitutionsPage() {
  const [items, setItems] = useState(null)
  const [open, setOpen] = useState(false)
  const [deleteTarget, setDeleteTarget] = useState(null)
  const [form, setForm] = useState(initial)
  const [customTown, setCustomTown] = useState('')
  const [state, setState] = useState({ message: '', type: 'error', errors: null })

  const load = () => api('/institutions')
    .then(setItems)
    .catch((error) => setState({ message: error.message, type: 'error', errors: error.errors }))

  useEffect(() => {
    void load()
  }, [])

  const updateRegion = (region) => {
    setCustomTown('')
    setForm((current) => ({ ...current, region, town: '' }))
  }

  const submit = async (event) => {
    event.preventDefault()
    setState({ message: '', type: 'error', errors: null })

    try {
      await api('/institutions', {
        method: 'POST',
        body: {
          ...form,
          town: form.town === 'Other / rural locality' ? customTown.trim() : form.town,
        },
      })
      setOpen(false)
      setCustomTown('')
      setForm(initial)
      setState({ message: 'Institution created.', type: 'success', errors: null })
      await load()
    } catch (error) {
      setState({ message: error.message, errors: error.errors, type: 'error' })
    }
  }

  const remove = async () => {
    if (!deleteTarget) return

    try {
      await api(`/institutions/${deleteTarget.id}`, { method: 'DELETE' })
      setDeleteTarget(null)
      setState({ message: 'Institution deleted.', type: 'success', errors: null })
      await load()
    } catch (error) {
      setDeleteTarget(null)
      setState({ message: error.message, errors: error.errors, type: 'error' })
    }
  }

  const townOptions = ESWATINI_TOWNS[form.region] || []

  return (
    <div className="dashboard-stack">
      <section className="welcome-banner compact-banner">
        <div>
          <span className="eyebrow">Network administration</span>
          <h2>Institutions that recruit, verify and request blood.</h2>
          <p>Schools, universities, churches, workplaces, hospitals and blood-service facilities all contribute to the donor ecosystem.</p>
        </div>
        <button className="button button-primary" onClick={() => setOpen(true)}><Plus size={18} /> Add institution</button>
      </section>

      <FormMessage type={state.type} message={state.message} errors={state.errors} />

      <section className="panel">
        {!items ? (
          <div className="panel-loading"><div className="blood-loader" />Loading institutions…</div>
        ) : (
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Institution</th>
                  <th>Type</th>
                  <th>Location</th>
                  <th>Contact</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {items.map((item) => (
                  <tr key={item.id}>
                    <td>
                      <strong>{item.name}</strong>
                      <small>{item.address || 'No address recorded'}</small>
                    </td>
                    <td>{titleCase(item.institution_type)}</td>
                    <td>{item.town}<small>{item.region}</small></td>
                    <td>{item.phone || '—'}<small>{item.email || ''}</small></td>
                    <td><StatusBadge value={item.is_active == 1 ? 'active' : 'inactive'} /></td>
                    <td>
                      <button className="icon-button danger-icon" onClick={() => setDeleteTarget(item)} title="Delete institution">
                        <Trash2 size={18} />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      <Modal open={open} onClose={() => setOpen(false)} title="Add institution" wide>
        <form className="form-section" onSubmit={submit}>
          <div className="form-grid two">
            <label>
              Institution name
              <input value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} required />
            </label>

            <label>
              Type
              <select value={form.institution_type} onChange={(event) => setForm({ ...form, institution_type: event.target.value })}>
                {INSTITUTION_TYPES.map((option) => (
                  <option key={option.value} value={option.value}>{option.label}</option>
                ))}
              </select>
            </label>

            <label>
              Region
              <select value={form.region} onChange={(event) => updateRegion(event.target.value)}>
                {ESWATINI_REGIONS.map((value) => <option key={value}>{value}</option>)}
              </select>
            </label>

            <label>
              Town / locality
              <select value={form.town} onChange={(event) => setForm({ ...form, town: event.target.value })} required>
                <option value="">Select town or locality</option>
                {townOptions.map((value) => <option key={value}>{value}</option>)}
              </select>
            </label>

            {form.town === 'Other / rural locality' && (
              <label className="full-span">
                Enter the town or locality
                <input value={customTown} onChange={(event) => setCustomTown(event.target.value)} required />
              </label>
            )}

            <label>
              Phone
              <input value={form.phone} onChange={(event) => setForm({ ...form, phone: event.target.value })} inputMode="tel" />
            </label>

            <label>
              Email
              <input type="email" value={form.email} onChange={(event) => setForm({ ...form, email: event.target.value })} />
            </label>

            <label className="full-span">
              Address
              <textarea rows="3" value={form.address} onChange={(event) => setForm({ ...form, address: event.target.value })} />
            </label>
          </div>

          <button className="button button-primary"><Building2 size={17} /> Create institution</button>
        </form>
      </Modal>

      <Modal open={Boolean(deleteTarget)} onClose={() => setDeleteTarget(null)} title="Delete institution">
        <div className="danger-dialog">
          <AlertTriangle size={28} />
          <div>
            <strong>Delete {deleteTarget?.name}?</strong>
            <p>The institution can be deleted only when no user accounts are still linked to it. Historical campaign, donation and request records remain preserved.</p>
          </div>
        </div>
        <div className="modal-action-row">
          <button className="button button-secondary" onClick={() => setDeleteTarget(null)}>Cancel</button>
          <button className="button button-danger" onClick={remove}><Trash2 size={17} /> Delete institution</button>
        </div>
      </Modal>
    </div>
  )
}
