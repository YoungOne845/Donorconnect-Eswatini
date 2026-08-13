import { AlertTriangle, Plus, Trash2, UserCog } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { api } from '../api/client'
import FormMessage from '../components/FormMessage'
import Modal from '../components/Modal'
import StatusBadge from '../components/StatusBadge'
import { formatDate } from '../utils/format'
import { NATIONAL_ID_LENGTH, normalizeNationalId } from '../utils/identity'

const PROTECTED_EMAILS = [
  'mbabane.admin@enbts.org.sz',
  'manzini.operator@enbts.org.sz',
  'hlathikhulu.operator@enbts.org.sz',
]

const initialForm = {
  full_name: '',
  national_id: '',
  phone: '',
  email: '',
  password: '',
  role: 'staff',
  institution_id: '',
}

export default function UsersPage() {
  const [items, setItems] = useState(null)
  const [institutions, setInstitutions] = useState([])
  const [open, setOpen] = useState(false)
  const [deleteTarget, setDeleteTarget] = useState(null)
  const [form, setForm] = useState(initialForm)
  const [state, setState] = useState({ message: '', type: 'error', errors: null })

  const load = async () => {
    try {
      const [users, institutionRows] = await Promise.all([
        api('/admin/users'),
        api('/institutions'),
      ])
      setItems(users)
      setInstitutions(institutionRows)
    } catch (error) {
      setState({ message: error.message, type: 'error', errors: error.errors })
    }
  }

  useEffect(() => {
    void load()
  }, [])

  const availableInstitutions = useMemo(() => {
    const active = institutions.filter((item) => item.is_active == 1)
    if (form.role === 'hospital') return active.filter((item) => item.institution_type === 'hospital')
    if (form.role === 'staff') return active.filter((item) => item.institution_type === 'blood_service')
    return active
  }, [form.role, institutions])

  const updateRole = (role) => {
    setForm((current) => ({ ...current, role, institution_id: '' }))
  }

  const create = async (event) => {
    event.preventDefault()
    setState({ message: '', type: 'error', errors: null })

    try {
      await api('/admin/users', {
        method: 'POST',
        body: {
          ...form,
          institution_id: form.institution_id || null,
        },
      })
      setOpen(false)
      setForm(initialForm)
      setState({ message: 'Operational account created. The user can now sign in with their national ID.', type: 'success', errors: null })
      await load()
    } catch (error) {
      setState({ message: error.message, errors: error.errors, type: 'error' })
    }
  }

  const changeStatus = async (id, account_status) => {
    try {
      await api(`/admin/users/${id}/status`, { method: 'PATCH', body: { account_status } })
      setState({ message: 'Account status updated.', type: 'success', errors: null })
      await load()
    } catch (error) {
      setState({ message: error.message, type: 'error', errors: error.errors })
    }
  }

  const remove = async () => {
    if (!deleteTarget) return

    try {
      await api(`/admin/users/${deleteTarget.id}`, { method: 'DELETE' })
      setDeleteTarget(null)
      setState({ message: 'User account deleted.', type: 'success', errors: null })
      await load()
    } catch (error) {
      setDeleteTarget(null)
      setState({ message: error.message, type: 'error', errors: error.errors })
    }
  }

  return (
    <div className="dashboard-stack">
      <section className="welcome-banner compact-banner">
        <div>
          <span className="eyebrow">Access governance</span>
          <h2>Manage operational roles and institutions.</h2>
          <p>Hospital, blood-service staff and administrator accounts are created by authorised administrators. Every account uses a unique national ID as its login identifier.</p>
        </div>
        <button className="button button-primary" onClick={() => setOpen(true)}><Plus size={18} /> New account</button>
      </section>

      <FormMessage type={state.type} message={state.message} errors={state.errors} />

      <section className="panel">
        {!items ? (
          <div className="panel-loading"><div className="blood-loader" />Loading user accounts…</div>
        ) : (
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>User</th>
                  <th>National ID</th>
                  <th>Role</th>
                  <th>Institution</th>
                  <th>Last login</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {items.map((item) => (
                  <tr key={item.id}>
                    <td>
                      <strong>{item.full_name}</strong>
                      <small>{item.phone} • {item.email || 'No email'}</small>
                    </td>
                    <td>{item.national_id_last_four ? `•••••••••${item.national_id_last_four}` : 'Not assigned'}</td>
                    <td><StatusBadge value={item.role} /></td>
                    <td>{item.institution_name || 'Independent / system-wide'}</td>
                    <td>{formatDate(item.last_login_at, true)}</td>
                    <td>
                      <select value={item.account_status} onChange={(event) => changeStatus(item.id, event.target.value)}>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="suspended">Suspended</option>
                        <option value="pending">Pending</option>
                      </select>
                    </td>
                    <td>
                      {PROTECTED_EMAILS.includes(item.email) ? (
                        <button className="icon-button" disabled title="Protected system account" style={{ opacity: 0.4, cursor: 'not-allowed' }}>
                          <Trash2 size={18} />
                        </button>
                      ) : (
                        <button className="icon-button danger-icon" onClick={() => setDeleteTarget(item)} title="Delete account">
                          <Trash2 size={18} />
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      <Modal open={open} onClose={() => setOpen(false)} title="Create operational account" wide>
        <form className="form-section" onSubmit={create}>
          <div className="form-grid two">
            <label>
              Full name
              <input value={form.full_name} onChange={(event) => setForm({ ...form, full_name: event.target.value })} required />
            </label>

            <label>
              National ID
              <input
                value={form.national_id}
                onChange={(event) => setForm({ ...form, national_id: normalizeNationalId(event.target.value) })}
                inputMode="numeric"
                maxLength={NATIONAL_ID_LENGTH}
                placeholder="0412227100041"
                required
              />
            </label>

            <label>
              Role
              <select value={form.role} onChange={(event) => updateRole(event.target.value)}>
                <option value="staff">Blood service staff</option>
                <option value="hospital">Hospital user</option>
                <option value="admin">Administrator</option>
              </select>
            </label>

            <label>
              Phone
              <input value={form.phone} onChange={(event) => setForm({ ...form, phone: event.target.value })} inputMode="tel" required />
            </label>

            <label>
              Email
              <input type="email" value={form.email} onChange={(event) => setForm({ ...form, email: event.target.value })} />
            </label>

            <label>
              Institution {form.role !== 'admin' ? <span>(required)</span> : <span>(optional)</span>}
              <select
                value={form.institution_id}
                onChange={(event) => setForm({ ...form, institution_id: event.target.value })}
                required={form.role !== 'admin'}
              >
                <option value="">{form.role === 'admin' ? 'No institution' : 'Select institution'}</option>
                {availableInstitutions.map((item) => (
                  <option key={item.id} value={item.id}>{item.name}</option>
                ))}
              </select>
            </label>

            <label className="full-span">
              Temporary password
              <input
                type="password"
                value={form.password}
                onChange={(event) => setForm({ ...form, password: event.target.value })}
                minLength="10"
                required
              />
              <small>At least 10 characters with uppercase, lowercase and a number.</small>
            </label>
          </div>

          <button className="button button-primary"><UserCog size={17} /> Create account</button>
        </form>
      </Modal>

      <Modal open={Boolean(deleteTarget)} onClose={() => setDeleteTarget(null)} title="Delete user account">
        <div className="danger-dialog">
          <AlertTriangle size={28} />
          <div>
            <strong>Delete {deleteTarget?.full_name}?</strong>
            <p>Unused accounts can be permanently deleted. Accounts with donation, eligibility, campaign, request or staff activity history are protected and must be deactivated instead.</p>
          </div>
        </div>
        <div className="modal-action-row">
          <button className="button button-secondary" onClick={() => setDeleteTarget(null)}>Cancel</button>
          <button className="button button-danger" onClick={remove}><Trash2 size={17} /> Delete account</button>
        </div>
      </Modal>
    </div>
  )
}
