import { AlertTriangle, ArrowRight, Check, History, Info, PhoneCall, RefreshCw, Smartphone, User } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { api } from '../api/client'
import FormMessage from '../components/FormMessage'
import StatusBadge from '../components/StatusBadge'
import { formatDate } from '../utils/format'

export default function UssdSimulatorPage() {
  const [activeTab, setActiveTab] = useState('simulator') // 'simulator' or 'activity'
  
  // Simulator State
  const [simPhone, setSimPhone] = useState('+26879586436')
  const [simPhoneInput, setSimPhoneInput] = useState('*256#')
  const [simSessionId, setSimSessionId] = useState(null)
  const [simText, setSimText] = useState('')
  const [simInput, setSimInput] = useState('')
  const [simOutputText, setSimOutputText] = useState('')
  const [simIsActive, setSimIsActive] = useState(false)
  const [simIsLoading, setSimIsLoading] = useState(false)
  const [simContinues, setSimContinues] = useState(true)
  const [simDialError, setSimDialError] = useState('')
  const [currentTime, setCurrentTime] = useState('')
  
  // Dashboard State
  const [requests, setRequests] = useState(null)
  const [availabilityUpdates, setAvailabilityUpdates] = useState([])
  const [ussdLogs, setUssdLogs] = useState([])
  const [dashboardLoading, setDashboardLoading] = useState(false)
  const [actionState, setActionState] = useState({ message: '', type: 'error', errors: null })
  const [actionSubmitting, setActionSubmitting] = useState(false)

  const replyInputRef = useRef(null)

  // Real-time clock for simulator idle screen
  useEffect(() => {
    const updateTime = () => {
      const d = new Date()
      setCurrentTime(d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }))
    }
    updateTime()
    const interval = setInterval(updateTime, 60000)
    return () => clearInterval(interval)
  }, [])

  // Auto-focus reply input when session text changes
  useEffect(() => {
    if (simIsActive && simContinues && replyInputRef.current) {
      replyInputRef.current.focus()
    }
  }, [simIsActive, simContinues, simOutputText])

  // Load Dashboard Data
  const loadDashboardData = async (silent = false) => {
    if (!silent) setDashboardLoading(true)
    try {
      const data = await api('/admin/ussd/requests')
      setRequests(data.requests || [])
      setAvailabilityUpdates(data.availability_updates || [])
      setUssdLogs(data.logs || [])
    } catch (error) {
      setActionState({
        message: error.message || 'Failed to load USSD dashboard data.',
        type: 'error',
        errors: error.errors
      })
    } finally {
      if (!silent) setDashboardLoading(false)
    }
  }

  useEffect(() => {
    void loadDashboardData()
  }, [])

  // Handle Simulator Dial
  const handleDial = async () => {
    setSimDialError('')
    if (simPhoneInput.trim() !== '*256#') {
      setSimDialError('Please dial the correct service code: *256#')
      return
    }
    if (!simPhone.trim()) {
      setSimDialError('Please enter a phone number to simulate.')
      return
    }
    if (/[^0-9\s\+\-\(\)]/.test(simPhone)) {
      setSimDialError('Invalid caller number. Phone numbers must only contain digits and standard symbols (no letters).')
      return
    }
    const cleaned = simPhone.replace(/\D/g, '')
    const isValid = (cleaned.length === 8 && /^(76|78|79|24)/.test(cleaned)) || 
                    (cleaned.length === 11 && /^268(76|78|79|24)/.test(cleaned))
    if (!isValid) {
      setSimDialError('Invalid Eswatini phone number. Phone number must be exactly 8 digits (excluding country code) and start with 76, 78, 79, or 24.')
      return
    }

    setSimIsLoading(true)
    const newSessionId = 'sim-' + Math.random().toString(36).substr(2, 9)
    setSimSessionId(newSessionId)
    setSimText('')
    setSimInput('')
    
    try {
      const result = await api('/ussd', {
        method: 'POST',
        headers: { Accept: 'application/json' },
        body: {
          sessionId: newSessionId,
          phoneNumber: simPhone,
          text: ''
        }
      })
      
      setSimOutputText(result.response)
      setSimContinues(result.continues)
      setSimIsActive(true)
    } catch (error) {
      setSimDialError(error.message || 'Failed to connect. Network error.')
      setSimIsActive(false)
    } finally {
      setSimIsLoading(false)
    }
  }

  // Handle Simulator Reply Submission
  const handleSendReply = async () => {
    if (simIsLoading) return
    const reply = simInput.trim()
    if (!reply) return

    setSimIsLoading(true)
    const nextText = simText === '' ? reply : `${simText}*${reply}`
    setSimText(nextText)
    setSimInput('')

    try {
      const result = await api('/ussd', {
        method: 'POST',
        headers: { Accept: 'application/json' },
        body: {
          sessionId: simSessionId,
          phoneNumber: simPhone,
          text: nextText
        }
      })

      setSimOutputText(result.response)
      setSimContinues(result.continues)
      
      // If the session terminated (END), we keep the output on screen but mark it inactive so they can exit.
      if (!result.continues) {
        setSimContinues(false)
      }
      
      // Refresh dashboard data silently in background to capture updates instantly
      void loadDashboardData(true)
    } catch (error) {
      setSimOutputText(`END\nError: ${error.message || 'Connection lost.'}`)
      setSimContinues(false)
    } finally {
      setSimIsLoading(false)
    }
  }

  // Terminate USSD session
  const handleEndSession = () => {
    setSimIsActive(false)
    setSimSessionId(null)
    setSimText('')
    setSimInput('')
    setSimOutputText('')
    setSimContinues(true)
    setSimDialError('')
  }

  // Resolve request status
  const handleResolveRequest = async (id, currentStatus) => {
    setActionState({ message: '', type: 'error', errors: null })
    setActionSubmitting(true)
    const newStatus = currentStatus === 'pending' ? 'resolved' : 'pending'
    try {
      await api(`/admin/ussd/requests/${id}`, {
        method: 'PATCH',
        body: { status: newStatus }
      })
      setActionState({
        message: `USSD request successfully marked as ${newStatus}.`,
        type: 'success',
        errors: null
      })
      await loadDashboardData(true)
    } catch (error) {
      setActionState({
        message: error.message || 'Failed to update request status.',
        type: 'error',
        errors: error.errors
      })
    } finally {
      setActionSubmitting(false)
    }
  }

  // Quick select preset test numbers
  const fillPreset = (phone) => {
    setSimPhone(phone)
    handleEndSession()
  }

  // Split USSD screen text by line and assign appropriate CSS classes
  const renderScreenContent = () => {
    if (!simOutputText) return null
    const lines = simOutputText.split('\n')
    const isEnd = simOutputText.startsWith('END')
    
    return (
      <div className="ussd-screen-text">
        {lines.map((line, idx) => {
          // Clean prefixes if desired, but keep them for classic terminal feel
          if (idx === 0) {
            return (
              <div key={idx} className={isEnd ? 'ussd-end-line' : 'ussd-title-line'}>
                {line}
              </div>
            )
          }
          return (
            <div key={idx} className="ussd-body-line">
              {line}
            </div>
          )
        })}
      </div>
    )
  }

  return (
    <div className="dashboard-stack">
      <section className="welcome-banner compact-banner">
        <div>
          <span className="eyebrow">Offline Engagement Portal</span>
          <h2>USSD Service Simulator & Dashboard</h2>
          <p>
            Supports citizens without smartphones or internet access. Donors can dial the access code to check eligibility, change availability, and request administrative assistance.
          </p>
        </div>
      </section>

      {/* Tabs */}
      <div className="tabs-row">
        <button
          className={`tab-button ${activeTab === 'simulator' ? 'active' : ''}`}
          onClick={() => setActiveTab('simulator')}
        >
          <Smartphone size={16} /> Simulator Interface
        </button>
        <button
          className={`tab-button ${activeTab === 'activity' ? 'active' : ''}`}
          onClick={() => setActiveTab('activity')}
        >
          <History size={16} /> Requests & Activity Log
        </button>
      </div>

      <FormMessage type={actionState.type} message={actionState.message} errors={actionState.errors} />

      {activeTab === 'simulator' ? (
        <div className="ussd-sim-layout">
          {/* Phone Mockup Column */}
          <div className="ussd-phone-wrap">
            <div className="ussd-phone">
              <div className="ussd-speaker" />
              
              {/* Screen */}
              <div className="ussd-screen">
                {!simIsActive ? (
                  <div className="ussd-idle">
                    <div className="ussd-signal">📶 3G</div>
                    <div className="ussd-carrier">DonorConnect SZ</div>
                    <div className="ussd-clock">{currentTime}</div>
                    <div className="ussd-idle-hint">Dial *256# to start</div>
                  </div>
                ) : simIsLoading && simText === '' ? (
                  <div className="ussd-idle">
                    <div className="ussd-loading">Dialling...</div>
                  </div>
                ) : (
                  renderScreenContent()
                )}
              </div>
              
              {/* Keypad controls */}
              {!simIsActive ? (
                <div className="ussd-keypad">
                  <div className="ussd-dial-row">
                    <input
                      type="text"
                      className="ussd-dial-field"
                      placeholder="Dial *256#..."
                      value={simPhoneInput}
                      onChange={(e) => setSimPhoneInput(e.target.value)}
                      onKeyDown={(e) => e.key === 'Enter' && handleDial()}
                    />
                    <button
                      className="ussd-call-btn"
                      onClick={handleDial}
                      disabled={simIsLoading}
                      title="Dial USSD Code"
                    >
                      📞
                    </button>
                  </div>
                  {simDialError && <small style={{ color: '#ff6b6b', fontSize: '0.72rem', display: 'block', marginTop: '2px' }}>{simDialError}</small>}
                  
                  <div className="form-group" style={{ marginTop: '8px' }}>
                    <label style={{ color: '#8b949e', fontSize: '0.75rem', marginBottom: '4px', display: 'block' }}>Caller Number:</label>
                    <input
                      type="text"
                      className="form-control"
                      style={{ background: '#1c1c2e', border: '1px solid #333350', color: '#e6edf3', padding: '6px 10px', borderRadius: '8px', fontSize: '0.8rem', width: '100%' }}
                      placeholder="e.g. +26876123456"
                      value={simPhone}
                      onChange={(e) => setSimPhone(e.target.value)}
                    />
                  </div>
                </div>
              ) : (
                <div className="ussd-session-controls">
                  {simContinues ? (
                    <div className="ussd-reply-row">
                      <input
                        type="text"
                        className="ussd-reply-field"
                        placeholder="Choose option..."
                        value={simInput}
                        onChange={(e) => setSimInput(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && handleSendReply()}
                        disabled={simIsLoading}
                        ref={replyInputRef}
                      />
                      <button
                        className="ussd-send-btn"
                        onClick={handleSendReply}
                        disabled={simIsLoading || !simInput.trim()}
                      >
                        {simIsLoading ? '...' : 'Send'}
                      </button>
                    </div>
                  ) : null}
                  <button className="ussd-end-btn" onClick={handleEndSession}>
                    {simContinues ? 'Cancel Session' : 'Exit / Close'}
                  </button>
                </div>
              )}

              {/* Decorative phone features */}
              <div className="ussd-buttons-row">
                <div className="ussd-btn-pill" />
                <div className="ussd-btn-circle" onClick={handleEndSession} style={{ cursor: 'pointer' }} title="Phone Home Button" />
                <div className="ussd-btn-pill" />
              </div>
            </div>
          </div>

          {/* Guide / Test cases Column */}
          <div className="ussd-info-panel">
            <div className="ussd-info-card">
              <h3 className="ussd-info-title"><Info size={16} style={{ display: 'inline', marginRight: '6px', verticalAlign: 'middle' }} /> USSD Simulation Profiles</h3>
              <p className="ussd-info-text">
                Select a profile to load its phone number, dial the code <span className="ussd-code-chip">*256#</span>, and observe the database interactions:
              </p>
              
              <div className="presets-group" style={{ display: 'flex', flexDirection: 'column', gap: '8px', marginTop: '12px' }}>
                <button className="button button-secondary" onClick={() => fillPreset('+26879586436')} style={{ justifyContent: 'space-between', width: '100%' }}>
                  <span style={{ display: 'flex', alignItems: 'center', gap: '8px', textAlign: 'left' }}>
                    <User size={14} style={{ flexShrink: 0 }} /> 
                    <div>
                      <strong>Sihle Mhlanga</strong>
                      <small style={{ display: 'block', color: '#6e7681', fontSize: '0.72rem' }}>Registered • NID: 0505057100123</small>
                    </div>
                  </span>
                  <code style={{ fontSize: '0.75rem', background: '#eee', padding: '2px 6px', borderRadius: '4px' }}>79586436</code>
                </button>

                <button className="button button-secondary" onClick={() => fillPreset('+26878294833')} style={{ justifyContent: 'space-between', width: '100%' }}>
                  <span style={{ display: 'flex', alignItems: 'center', gap: '8px', textAlign: 'left' }}>
                    <User size={14} style={{ flexShrink: 0 }} /> 
                    <div>
                      <strong>Thandolwethu Magaya</strong>
                      <small style={{ display: 'block', color: '#6e7681', fontSize: '0.72rem' }}>Registered • NID: 0303037100123</small>
                    </div>
                  </span>
                  <code style={{ fontSize: '0.75rem', background: '#eee', padding: '2px 6px', borderRadius: '4px' }}>78294833</code>
                </button>

                <button className="button button-secondary" onClick={() => fillPreset('+26877999888')} style={{ justifyContent: 'space-between', width: '100%' }}>
                  <span style={{ display: 'flex', alignItems: 'center', gap: '8px', textAlign: 'left' }}>
                    <User size={14} style={{ flexShrink: 0 }} /> 
                    <div>
                      <strong>Unregistered Prospect</strong>
                      <small style={{ display: 'block', color: '#6e7681', fontSize: '0.72rem' }}>Anonymous Prospect</small>
                    </div>
                  </span>
                  <code style={{ fontSize: '0.75rem', background: '#eee', padding: '2px 6px', borderRadius: '4px' }}>77999888</code>
                </button>
              </div>
            </div>

            <div className="ussd-info-card ussd-try-steps">
              <h3 className="ussd-try-title">Recommended Test Scenarios</h3>
              <ul className="ussd-try-list">
                <li>
                  <strong>Toggling Donor Availability</strong>: Select <em>Sihle</em>, dial `*256#`, choose option `2`. When prompted, enter their National ID `0505057100123` to log in, then toggle availability. Check the Requests & Activity Log tab to verify.
                </li>
                <li>
                  <strong>Unregistered Caller Signup</strong>: Select <em>Unregistered Prospect</em>, dial `*256#`, select Option `2` or `3`. The system will offer to contact you without requiring a National ID login. Dial `1` (Yes) to log a callback request.
                </li>
                <li>
                  <strong>Registered Callback Request</strong>: Select <em>Thandolwethu</em>, select Option `5`. Authenticate using their National ID `0303037100123`, then choose `2` (Profile update assistance) to queue a callback request.
                </li>
              </ul>
            </div>

            <div className="ussd-info-card ussd-tech-card">
              <h3 className="ussd-info-title">System Integration Flow</h3>
              <div className="ussd-tech-flow">
                <div className="ussd-tech-step">
                  USSD Dial
                  <small>Basic Phone</small>
                </div>
                <div className="ussd-tech-arrow">→</div>
                <div className="ussd-tech-step">
                  USSD Gateway
                  <small>Africa's Talking</small>
                </div>
                <div className="ussd-tech-arrow">→</div>
                <div className="ussd-tech-step">
                  UssdController
                  <small>PHP REST Endpoint</small>
                </div>
                <div className="ussd-tech-arrow">→</div>
                <div className="ussd-tech-step">
                  Database
                  <small>availability/requests</small>
                </div>
              </div>
              <p className="ussd-tech-note">
                In production, when a user dials *256#, the telecom carrier forwards the request via a web callback to the API. The script replies in real-time, executing database updates without requiring internet on the phone.
              </p>
            </div>
          </div>
        </div>
      ) : (
        <div className="dashboard-stack">
          {/* Requests Management section */}
          <section className="panel">
            <div className="panel-header" style={{ justifyContent: 'space-between' }}>
              <div>
                <h3>Pending USSD Callback & Registration Queue</h3>
                <p>Actionable requests submitted by citizens via basic phone USSD dialing.</p>
              </div>
              <button className="icon-button" onClick={() => loadDashboardData(false)} disabled={dashboardLoading}>
                <RefreshCw size={16} className={dashboardLoading ? 'spin' : ''} />
              </button>
            </div>

            {requests === null ? (
              <div className="panel-loading"><div className="blood-loader" />Loading request queue...</div>
            ) : requests.length === 0 ? (
              <div className="panel-empty">No USSD callback requests found. Try submitting one from the simulator.</div>
            ) : (
              <div className="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>Phone Number</th>
                      <th>Caller / Profile</th>
                      <th>Request Type</th>
                      <th>Details / Notes</th>
                      <th>Submitted Date</th>
                      <th>Status</th>
                      <th>Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    {requests.map((req) => (
                      <tr key={req.id}>
                        <td><strong>{req.phone}</strong></td>
                        <td>
                          {req.donor_name ? (
                            <span style={{ display: 'flex', alignItems: 'center', gap: '4px' }}>
                              <User size={14} style={{ color: '#1f6feb' }} /> {req.donor_name}
                            </span>
                          ) : (
                            <span style={{ color: '#8b949e', fontStyle: 'italic' }}>Unregistered prospect</span>
                          )}
                        </td>
                        <td>
                          <span className={`status-pill ${req.request_type === 'registration_request' ? 'status-active' : 'status-pending'}`}>
                            {req.request_type === 'registration_request' ? 'Registration' : 'Help Callback'}
                          </span>
                        </td>
                        <td>{req.notes}</td>
                        <td>{formatDate(req.created_at, true)}</td>
                        <td>
                          <span className={`status-pill ${req.status === 'resolved' ? 'status-active' : 'status-expired'}`}>
                            {req.status === 'resolved' ? 'Resolved' : 'Pending'}
                          </span>
                        </td>
                        <td>
                          <button
                            className={`button button-small ${req.status === 'resolved' ? 'button-secondary' : 'button-primary'}`}
                            onClick={() => handleResolveRequest(req.id, req.status)}
                            disabled={actionSubmitting}
                            style={{ display: 'flex', alignItems: 'center', gap: '4px' }}
                          >
                            {req.status === 'resolved' ? (
                              'Reopen'
                            ) : (
                              <>
                                <Check size={14} /> Resolve
                              </>
                            )}
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>

          {/* Activity Logs & Audit Trails Grid */}
          <div className="grid two">
            <section className="panel">
              <div className="panel-header">
                <h3>USSD Availability Updates</h3>
                <p>Toggles performed by registered donors using the USSD menu.</p>
              </div>
              
              {dashboardLoading && availabilityUpdates.length === 0 ? (
                <div className="panel-loading">Loading updates...</div>
              ) : availabilityUpdates.length === 0 ? (
                <div className="panel-empty" style={{ padding: '2rem 1rem' }}>No availability changes recorded via USSD yet.</div>
              ) : (
                <div className="timeline" style={{ padding: '0.5rem' }}>
                  {availabilityUpdates.map((update) => (
                    <article key={update.id} className="timeline-item-premium" style={{ borderLeft: '3px solid #58cc7a', background: 'rgba(88,204,122,0.04)', marginBottom: '10px' }}>
                      <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '4px' }}>
                        <strong>{update.donor_name}</strong>
                        <small style={{ color: '#8b949e' }}>{formatDate(update.created_at, true)}</small>
                      </div>
                      <p style={{ fontSize: '0.85rem', color: '#4b5567', margin: 0 }}>
                        {update.description} ({update.phone})
                      </p>
                    </article>
                  ))}
                </div>
              )}
            </section>

            <section className="panel">
              <div className="panel-header">
                <h3>USSD Session Audit Trail</h3>
                <p>Raw inputs and generated replies for the last 50 USSD dials.</p>
              </div>

              {dashboardLoading && ussdLogs.length === 0 ? (
                <div className="panel-loading">Loading audit logs...</div>
              ) : ussdLogs.length === 0 ? (
                <div className="panel-empty" style={{ padding: '2rem 1rem' }}>No USSD audit logs recorded yet.</div>
              ) : (
                <div style={{ maxHeight: '400px', overflowY: 'auto', fontSize: '0.8rem' }}>
                  {ussdLogs.map((log) => {
                    const isEnd = log.response_text.startsWith('END')
                    return (
                      <div key={log.id} style={{ borderBottom: '1px solid #e6e9ef', padding: '10px 4px' }}>
                        <div style={{ display: 'flex', justifyContent: 'space-between', color: '#8b949e', marginBottom: '4px' }}>
                          <span>Session: <code>{log.session_id.substring(0, 12)}...</code></span>
                          <span>{formatDate(log.created_at, true)}</span>
                        </div>
                        <div style={{ marginBottom: '4px' }}>
                          <strong>Phone:</strong> {log.phone} {log.donor_name && <small style={{ color: '#1f6feb' }}>({log.donor_name})</small>}
                        </div>
                        <div style={{ marginBottom: '4px' }}>
                          <strong>Dial/Input:</strong> <code style={{ background: '#f0f2f5', padding: '1px 4px', borderRadius: '4px' }}>{log.input_text || '[Initial Dial]'}</code>
                        </div>
                        <div style={{ background: '#0d1117', color: '#58cc7a', padding: '8px', borderRadius: '6px', fontFamily: 'monospace', whiteSpace: 'pre-wrap', border: '1px solid #1c1f24', fontSize: '0.75rem' }}>
                          {log.response_text}
                        </div>
                      </div>
                    )
                  })}
                </div>
              )}
            </section>
          </div>
        </div>
      )}
    </div>
  )
}
