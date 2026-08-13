import { Activity, Calendar, Lock, UserPlus, UserCog, Clock, ShieldCheck, HeartPulse, Droplets, CalendarX, Sparkles, Check, X, CalendarHeart, Award, CheckCircle2 } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api/client'
import EmptyState from '../components/EmptyState'
import FormMessage from '../components/FormMessage'
import Modal from '../components/Modal'
import { useAuth } from '../context/AuthContext'
import { formatDate, titleCase } from '../utils/format'

const SENSITIVE_ACTIVITY_TYPES = ['deferred', 'eligibility_assessed', 'eligibility_restored']

export default function ActivityPage() {
  const { statusRevealed, setStatusRevealed, user } = useAuth()
  const [items, setItems] = useState(null)
  const [revealModalOpen, setRevealModalOpen] = useState(false)
  const [revealPassword, setRevealPassword] = useState('')
  const [revealState, setRevealState] = useState({ loading: false, message: '', errors: null, type: 'error' })

  const load = () => api('/donor/activity').then(setItems).catch(() => setItems([]))

  useEffect(() => {
    void load()
  }, [])

  const handleRevealStatus = async (event) => {
    event.preventDefault()
    setRevealState({ loading: true, message: '', errors: null, type: 'error' })
    try {
      await api('/donor/verify-password', { method: 'POST', body: { password: revealPassword } })
      setStatusRevealed(true)
      setRevealModalOpen(false)
      setRevealPassword('')
      setRevealState({ loading: false, message: '', errors: null, type: 'error' })
    } catch (err) {
      setRevealState({ loading: false, message: err.message, errors: err.errors, type: 'error' })
    }
  }

  const getActivityIcon = (type, isLocked) => {
    if (isLocked) return Lock
    switch (type) {
      case 'registered': return UserPlus
      case 'profile_updated': return UserCog
      case 'profile_update_requested': return Clock
      case 'verified': return ShieldCheck
      case 'eligibility_assessed': return HeartPulse
      case 'donation_recorded': return Droplets
      case 'deferred': return CalendarX
      case 'eligibility_restored': return Sparkles
      case 'request_accepted': return Check
      case 'request_declined': return X
      case 'campaign_joined': return CalendarHeart
      case 'milestone_reached': return Award
      default: return CheckCircle2
    }
  }

  if (!items) return <div className="panel-loading"><div className="blood-loader" />Loading activity…</div>

  const passwordUnset = user.password_status === 'unset'

  return (
    <div className="dashboard-stack">
      <section className="panel">
        <div className="panel-header">
          <div>
            <span className="eyebrow">Lifecycle history</span>
            <h2>Your DonorConnect activity</h2>
          </div>
          <Activity size={24} style={{ color: 'var(--red-700)' }} />
        </div>

        {items.length ? (
          <div className="timeline">
            {items.map((item) => {
              const isSensitive = SENSITIVE_ACTIVITY_TYPES.includes(item.activity_type)
              const isLocked = isSensitive && !statusRevealed
              const IconComponent = getActivityIcon(item.activity_type, isLocked)

              return (
                <article
                  key={item.id}
                  className={`timeline-item-premium ${isLocked ? 'locked-item' : ''}`}
                  onClick={isLocked ? () => {
                    setRevealState({ loading: false, message: '', errors: null, type: 'error' })
                    setRevealModalOpen(true)
                  } : undefined}
                >
                  <span className={`timeline-icon ${isLocked ? 'type-locked' : `type-${item.activity_type}`}`}>
                    <IconComponent size={18} />
                  </span>
                  <div>
                    <h3 style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                      {isLocked ? 'Eligibility Update' : titleCase(item.activity_type.replaceAll('_', ' '))}
                      {isLocked && <span style={{ fontSize: '10px', color: 'var(--muted)', display: 'inline-flex', alignItems: 'center', gap: '3px', fontWeight: 500, background: '#f1f5f9', padding: '2px 6px', borderRadius: '4px' }}><Lock size={9} /> Hidden</span>}
                    </h3>
                    <p style={{ color: isLocked ? 'var(--muted)' : 'var(--text-body)', fontWeight: isLocked ? 500 : 400 }}>
                      {isLocked
                        ? '🔒 Sensitive eligibility details are hidden. Click to verify password and reveal.'
                        : item.description}
                    </p>
                    <small>
                      <Calendar size={13} /> {formatDate(item.created_at, true)}
                    </small>
                  </div>
                </article>
              )
            })}
          </div>
        ) : (
          <EmptyState title="No activity recorded yet" />
        )}
      </section>

      <Modal open={revealModalOpen} onClose={() => setRevealModalOpen(false)} title="Verify Password">
        <FormMessage type={revealState.type} message={revealState.message} errors={revealState.errors} />
        {passwordUnset ? (
          <div className="otp-helper-card">
            <strong>Password Required</strong>
            <span>You have not set a password yet because you logged in using OTP. Please create a password under the "Account security" panel in your <Link to="/app/profile" style={{ textDecoration: 'underline', color: 'var(--color-primary)' }}>Profile</Link> first before you can reveal sensitive activity logs.</span>
          </div>
        ) : (
          <form className="form-section" onSubmit={handleRevealStatus}>
            <p>Please confirm your account password to reveal sensitive eligibility and deferral activity logs.</p>
            <label>
              Password
              <input
                type="password"
                value={revealPassword}
                onChange={(e) => setRevealPassword(e.target.value)}
                autoComplete="current-password"
                required
              />
            </label>
            <button className="button button-primary" disabled={revealState.loading}>
              {revealState.loading ? 'Verifying…' : 'Verify password'}
            </button>
          </form>
        )}
      </Modal>
    </div>
  )
}
