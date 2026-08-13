import { Droplets, Filter, Plus, Search } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api/client'
import EmptyState from '../components/EmptyState'
import FormMessage from '../components/FormMessage'
import Modal from '../components/Modal'
import StatusBadge from '../components/StatusBadge'
import { formatDate } from '../utils/format'
import { ESWATINI_TOWNS } from '../data/eswatini'

const initialForm = {
  blood_type_needed: 'O+',
  units_required: 1,
  urgency_level: 'medium',
  hospital_name: '',
  region: 'Hhohho',
  town: '',
  needed_by: '',
  clinical_reference: '',
  description: '',
  send_real_sms: false,
  demo_sms_phone: '',
}

export default function RequestsPage() {
  const [items, setItems] = useState(null)
  const [filters, setFilters] = useState({ search: '', status: '', urgency_level: '' })
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState(initialForm)
  const [state, setState] = useState({ message: '', errors: null, type: 'error' })
  const [institutions, setInstitutions] = useState([])
  const [selectedInstId, setSelectedInstId] = useState('')
  const [customTown, setCustomTown] = useState('')

  const load = async () => {
    const query = new URLSearchParams(Object.entries(filters).filter(([, value]) => value)).toString()
    try {
      setItems(await api(`/requests${query ? `?${query}` : ''}`))
    } catch (error) {
      setState({ message: error.message, type: 'error' })
    }
  }

  useEffect(() => {
    load()
    const fetchInsts = async () => {
      try {
        const res = await api('/institutions')
        setInstitutions(res || [])
      } catch (err) {
        console.error('Failed to load institutions', err)
      }
    }
    void fetchInsts()
  }, [])

  const handleInstChange = (id) => {
    setSelectedInstId(id)
    const hospitals = institutions.filter(inst => inst.institution_type === 'hospital' && inst.is_active)
    if (id === 'other') {
      setForm((current) => ({
        ...current,
        hospital_name: '',
        region: 'Hhohho',
        town: '',
      }))
      setCustomTown('')
    } else if (id === '') {
      setForm((current) => ({
        ...current,
        hospital_name: '',
        region: 'Hhohho',
        town: '',
      }))
      setCustomTown('')
    } else {
      const inst = hospitals.find((item) => String(item.id) === String(id))
      if (inst) {
        setForm((current) => ({
          ...current,
          hospital_name: inst.name,
          region: inst.region,
          town: inst.town,
        }))
      }
    }
  }

  const handleCloseModal = () => {
    setOpen(false)
    setForm(initialForm)
    setSelectedInstId('')
    setCustomTown('')
  }

  const updateForm = (field, value) => {
    setForm((current) => ({ ...current, [field]: value }))
  }

  const create = async (event) => {
    event.preventDefault()
    setState({ message: '', errors: null, type: 'error' })

    const payload = { ...form }
    if (selectedInstId === 'other' && form.town === 'Other / rural locality') {
      payload.town = customTown.trim()
    }

    try {
      const result = await api('/requests', { method: 'POST', body: payload })
      setOpen(false)
      setForm(initialForm)
      setSelectedInstId('')
      setCustomTown('')
      await load()

      const smsStatus = result.real_sms
        ? result.real_sms.status === 'sent'
          ? ' Real SMS sent to the donor phone.'
          : ` SMS request returned: ${result.real_sms.status}. Check api/storage/logs/sms.log.`
        : ''

      setState({
        message: `Blood request ${result.request_code || ''} created.${smsStatus}`,
        type: result.real_sms && result.real_sms.status !== 'sent' ? 'error' : 'success',
      })
    } catch (error) {
      setState({ message: error.message, errors: error.errors, type: 'error' })
    }
  }

  return (
    <div className="dashboard-stack">
      <section className="welcome-banner compact-banner">
        <div>
          <span className="eyebrow">Hospital coordination</span>
          <h2>Blood requests built on a managed donor pool.</h2>
          <p>Requests identify demand. Donor lifecycle management creates the trusted pool that can respond.</p>
        </div>
        <button className="button button-primary" onClick={() => setOpen(true)}>
          <Plus size={18} /> New request
        </button>
      </section>

      <FormMessage type={state.type} message={state.message} errors={state.errors} />

      <section className="panel">
        <form className="filter-bar" onSubmit={(e) => { e.preventDefault(); load() }}>
          <label className="search-field">
            <Search size={18} />
            <input
              placeholder="Request code, hospital or town"
              value={filters.search}
              onChange={(e) => setFilters({ ...filters, search: e.target.value })}
            />
          </label>
          <select value={filters.status} onChange={(e) => setFilters({ ...filters, status: e.target.value })}>
            <option value="">All statuses</option>
            {['draft','active','partially_fulfilled','fulfilled','cancelled','expired'].map((value) => (
              <option key={value} value={value}>{value.replaceAll('_', ' ')}</option>
            ))}
          </select>
          <select value={filters.urgency_level} onChange={(e) => setFilters({ ...filters, urgency_level: e.target.value })}>
            <option value="">All urgency levels</option>
            {['low','medium','high','critical'].map((value) => <option key={value}>{value}</option>)}
          </select>
          <button className="button button-secondary"><Filter size={17} /> Apply</button>
        </form>

        {!items ? (
          <div className="panel-loading"><div className="blood-loader" />Loading requests…</div>
        ) : items.length === 0 ? (
          <EmptyState title="No blood requests found" />
        ) : (
          <div className="request-card-grid">
            {items.map((item) => (
              <Link to={`/app/requests/${item.id}`} className={`request-card urgency-${item.urgency_level}`} key={item.id}>
                <div className="request-card-top"><span>{item.request_code}</span><StatusBadge value={item.urgency_level} /></div>
                <div className="request-blood"><Droplets />{item.blood_type_needed}</div>
                <h3>{item.hospital_name}</h3>
                <p>{item.town}, {item.region}</p>
                <div className="request-metrics">
                  <span><strong>{item.units_fulfilled}/{item.units_required}</strong> units</span>
                  <span><strong>{item.donors_matched || 0}</strong> matched</span>
                  <span><strong>{item.accepted || 0}</strong> accepted</span>
                </div>
                <div className="request-card-footer"><StatusBadge value={item.status} /><small>{formatDate(item.created_at, true)}</small></div>
              </Link>
            ))}
          </div>
        )}
      </section>

      <Modal open={open} onClose={handleCloseModal} title="Create blood request" wide>
        <FormMessage message={state.message} errors={state.errors} />
        <form className="form-section" onSubmit={create}>
          <div className="form-grid two">
            <label>
              Blood type needed
              <select value={form.blood_type_needed} onChange={(e) => updateForm('blood_type_needed', e.target.value)}>
                {['A+','A-','B+','B-','AB+','AB-','O+','O-'].map((value) => <option key={value}>{value}</option>)}
              </select>
            </label>

            <label>
              Units required
              <input type="number" min="1" max="100" value={form.units_required} onChange={(e) => updateForm('units_required', Number(e.target.value))} />
            </label>

            <label>
              Urgency
              <select value={form.urgency_level} onChange={(e) => updateForm('urgency_level', e.target.value)}>
                {['low','medium','high','critical'].map((value) => <option key={value}>{value}</option>)}
              </select>
            </label>

            <label>
              Needed by
              <input
                type="datetime-local"
                value={form.needed_by}
                onChange={(e) => updateForm('needed_by', e.target.value)}
                min={new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16)}
              />
            </label>

            <label>
              Hospital
              <select value={selectedInstId} onChange={(e) => handleInstChange(e.target.value)} required>
                <option value="">Select hospital...</option>
                {institutions
                  .filter((inst) => inst.institution_type === 'hospital' && Number(inst.is_active))
                  .map((inst) => (
                    <option key={inst.id} value={inst.id}>
                      {inst.name} ({inst.town})
                    </option>
                  ))}
                <option value="other">Other (Specify)</option>
              </select>
            </label>

            <label>
              Clinical reference <span>(optional)</span>
              <input value={form.clinical_reference} onChange={(e) => updateForm('clinical_reference', e.target.value)} />
            </label>

            {selectedInstId === 'other' ? (
              <>
                <label>
                  Hospital name
                  <input
                    value={form.hospital_name}
                    onChange={(e) => updateForm('hospital_name', e.target.value)}
                    placeholder="Enter hospital name"
                    required
                  />
                </label>

                <label>
                  Region
                  <select
                    value={form.region}
                    onChange={(e) => {
                      const region = e.target.value
                      setForm((current) => ({ ...current, region, town: '' }))
                      setCustomTown('')
                    }}
                  >
                    {['Hhohho','Manzini','Lubombo','Shiselweni'].map((value) => (
                      <option key={value}>{value}</option>
                    ))}
                  </select>
                </label>

                <label>
                  Town / locality
                  <select
                    value={form.town}
                    onChange={(e) => {
                      setCustomTown('')
                      updateForm('town', e.target.value)
                    }}
                    required
                  >
                    <option value="">Select town or locality</option>
                    {(ESWATINI_TOWNS[form.region] || []).map((value) => (
                      <option key={value}>{value}</option>
                    ))}
                  </select>
                </label>

                {form.town === 'Other / rural locality' && (
                  <label>
                    Specify town / locality
                    <input
                      value={customTown}
                      onChange={(e) => setCustomTown(e.target.value)}
                      required
                    />
                  </label>
                )}
              </>
            ) : (
              <>
                <label>
                  Region
                  <input value={form.region} disabled />
                </label>

                <label>
                  Town
                  <input value={form.town} disabled />
                </label>
              </>
            )}

            <label className="full-span">
              Description
              <textarea rows="3" value={form.description} onChange={(e) => updateForm('description', e.target.value)} />
            </label>

            <label className="checkbox-label full-span">
              <input
                type="checkbox"
                checked={form.send_real_sms}
                onChange={(e) => updateForm('send_real_sms', e.target.checked)}
              />
              <span>Send real SMS alert to matching donors now</span>
            </label>

            {form.send_real_sms && (
              <label className="full-span">
                Recipient phone number (for testing)
                <input
                  value={form.demo_sms_phone}
                  onChange={(e) => updateForm('demo_sms_phone', e.target.value)}
                  inputMode="tel"
                  placeholder="76123456 or +26876123456"
                  required={form.send_real_sms}
                />
                <small>Sends an immediate SMS notification via Twilio. Ensure the recipient number is verified on your Twilio Trial account.</small>
              </label>
            )}
          </div>

          <button className="button button-primary">Create request</button>
        </form>
      </Modal>
    </div>
  )
}
