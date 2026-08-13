import { AlertCircle, AlertTriangle, Award, Check, Clock, Heart, Shield, TrendingDown, Users } from 'lucide-react'

const TIER_DETAILS = {
  Gold: {
    bg: 'linear-gradient(135deg, #fef3c7 0%, #fffbeb 100%)',
    borderColor: '#f59e0b',
    textColor: '#78350f',
    iconColor: '#d97706',
    badgeBg: '#f59e0b',
    badgeText: '#ffffff',
  },
  Silver: {
    bg: 'linear-gradient(135deg, #f1f5f9 0%, #f8fafc 100%)',
    borderColor: '#94a3b8',
    textColor: '#1e293b',
    iconColor: '#475569',
    badgeBg: '#64748b',
    badgeText: '#ffffff',
  },
  Bronze: {
    bg: 'linear-gradient(135deg, #fef9ee 0%, #fffbeb 100%)',
    borderColor: '#d97706',
    textColor: '#451a03',
    iconColor: '#b45309',
    badgeBg: '#d97706',
    badgeText: '#ffffff',
  },
  'New donor': {
    bg: 'linear-gradient(135deg, #f0fdf4 0%, #f8fafc 100%)',
    borderColor: '#86efac',
    textColor: '#14532d',
    iconColor: '#16a34a',
    badgeBg: '#22c55e',
    badgeText: '#ffffff',
  },
}

function DaysCounter({ days }) {
  if (days === null || days === undefined) return null
  const months = Math.floor(days / 30)
  const label = months >= 1 ? `${months} month${months !== 1 ? 's' : ''}` : `${days} day${days !== 1 ? 's' : ''}`
  return (
    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: '12px', fontWeight: 600, color: '#64748b' }}>
      <Clock size={12} /> Last donated: {label} ago
    </span>
  )
}

export default function DonorBenefitsCard({ recognition }) {
  if (!recognition) return null

  const {
    level = 'New donor',
    earned_level = level,
    total_donations = 0,
    next_level_donations_needed = null,
    insurance_cover = 0,
    priority_note = '',
    benefit_summary = '',
    family_support_note = '',
    days_since_last_donation = null,
    tier_at_risk = false,
    is_demoted = false,
  } = recognition

  const details      = TIER_DETAILS[level] || TIER_DETAILS['New donor']
  const earnedDetails = TIER_DETAILS[earned_level] || details

  // How many days until demotion
  const daysUntilDemotion = tier_at_risk && days_since_last_donation !== null
    ? Math.max(0, 365 - days_since_last_donation)
    : null

  return (
    <article
      className="panel"
      style={{
        background: details.bg,
        border: `2px solid ${is_demoted ? '#ef4444' : tier_at_risk ? '#f59e0b' : details.borderColor}`,
        borderRadius: '16px',
        padding: '24px',
        boxShadow: '0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03)',
        transition: 'transform 0.2s ease, box-shadow 0.2s ease',
      }}
    >
      {/* ── Header ── */}
      <div className="panel-header" style={{ marginBottom: '16px', borderBottom: 'none', paddingBottom: 0 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
          <div
            style={{
              background: details.badgeBg,
              color: '#fff',
              width: '44px',
              height: '44px',
              borderRadius: '12px',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              boxShadow: '0 4px 10px rgba(0,0,0,0.1)',
              position: 'relative',
            }}
          >
            <Award size={24} />
            {is_demoted && (
              <TrendingDown
                size={14}
                style={{
                  position: 'absolute', bottom: -4, right: -4,
                  background: '#ef4444', borderRadius: '50%',
                  padding: 2, color: '#fff',
                  boxShadow: '0 1px 4px rgba(0,0,0,0.25)',
                }}
              />
            )}
          </div>
          <div>
            <span className="eyebrow" style={{ color: details.textColor, opacity: 0.8 }}>Donor Recognition &amp; Benefits</span>
            <div style={{ display: 'flex', alignItems: 'center', gap: '8px', marginTop: '2px', flexWrap: 'wrap' }}>
              <h2 style={{ margin: 0, fontSize: '22px', color: details.textColor, fontWeight: 800 }}>
                {level} Status
                {is_demoted && (
                  <span style={{ fontSize: '13px', fontWeight: 600, color: '#ef4444', marginLeft: 8 }}>
                    (Demoted)
                  </span>
                )}
              </h2>
              <span
                style={{
                  background: details.badgeBg,
                  color: details.badgeText,
                  padding: '2px 10px',
                  borderRadius: '99px',
                  fontSize: '11px',
                  fontWeight: 700,
                  textTransform: 'uppercase',
                  letterSpacing: '0.05em',
                }}
              >
                {total_donations} {total_donations === 1 ? 'Donation' : 'Donations'}
              </span>
            </div>
            <div style={{ marginTop: '4px' }}>
              <DaysCounter days={days_since_last_donation} />
            </div>
          </div>
        </div>
      </div>

      {/* ── DEMOTED banner ── */}
      {is_demoted && (
        <div
          style={{
            display: 'flex',
            gap: '10px',
            alignItems: 'flex-start',
            background: 'rgba(239,68,68,0.08)',
            border: '1px solid rgba(239,68,68,0.28)',
            borderRadius: '12px',
            padding: '12px 14px',
            marginBottom: '16px',
          }}
        >
          <TrendingDown size={18} style={{ color: '#dc2626', flexShrink: 0, marginTop: 2 }} />
          <div>
            <strong style={{ display: 'block', fontSize: '13px', color: '#b91c1c', fontWeight: 700 }}>
              Tier Downgraded — You Were {earned_level}
            </strong>
            <span style={{ fontSize: '13px', color: '#7f1d1d', lineHeight: 1.5 }}>
              You haven&apos;t donated in over 12 months. Your active tier has been reduced from{' '}
              <strong>{earned_level}</strong> to <strong>{level}</strong>. Donate once to restore your
              earned {earned_level} status immediately.
            </span>
          </div>
        </div>
      )}

      {/* ── AT-RISK warning ── */}
      {!is_demoted && tier_at_risk && (
        <div
          style={{
            display: 'flex',
            gap: '10px',
            alignItems: 'flex-start',
            background: 'rgba(245,158,11,0.10)',
            border: '1px solid rgba(245,158,11,0.35)',
            borderRadius: '12px',
            padding: '12px 14px',
            marginBottom: '16px',
          }}
        >
          <AlertTriangle size={18} style={{ color: '#d97706', flexShrink: 0, marginTop: 2 }} />
          <div>
            <strong style={{ display: 'block', fontSize: '13px', color: '#92400e', fontWeight: 700 }}>
              ⚠️ Tier at Risk — Donate Within {daysUntilDemotion} Day{daysUntilDemotion !== 1 ? 's' : ''}
            </strong>
            <span style={{ fontSize: '13px', color: '#78350f', lineHeight: 1.5 }}>
              You haven&apos;t donated in {Math.floor(days_since_last_donation / 30)} months. After 12 months of
              inactivity your <strong>{level}</strong> tier will be downgraded. Book an appointment now to keep your benefits.
            </span>
          </div>
        </div>
      )}

      {/* ── Benefit summary ── */}
      <div style={{ color: details.textColor, fontSize: '14px', lineHeight: '1.6', marginBottom: '20px' }}>
        <p style={{ margin: '0 0 12px 0', fontWeight: 500 }}>{benefit_summary}</p>

        <div
          style={{
            background: 'rgba(255, 255, 255, 0.6)',
            backdropFilter: 'blur(4px)',
            border: '1px solid rgba(0, 0, 0, 0.05)',
            borderRadius: '12px',
            padding: '14px 16px',
            display: 'flex',
            gap: '12px',
            alignItems: 'flex-start',
            marginBottom: '16px',
          }}
        >
          <Shield size={20} style={{ color: details.iconColor, flexShrink: 0, marginTop: '2px' }} />
          <div>
            <strong style={{ display: 'block', fontSize: '13px', color: details.textColor, fontWeight: 700 }}>Priority Blood Access</strong>
            <span style={{ fontSize: '13px', opacity: 0.9 }}>{priority_note}</span>
          </div>
        </div>

        {insurance_cover > 0 ? (
          <div
            style={{
              background: 'rgba(255, 255, 255, 0.6)',
              backdropFilter: 'blur(4px)',
              border: '1px solid rgba(0, 0, 0, 0.05)',
              borderRadius: '12px',
              padding: '14px 16px',
              display: 'flex',
              gap: '12px',
              alignItems: 'flex-start',
            }}
          >
            <Users size={20} style={{ color: details.iconColor, flexShrink: 0, marginTop: '2px' }} />
            <div>
              <strong style={{ display: 'block', fontSize: '13px', color: details.textColor, fontWeight: 700 }}>Family Insurance Plan</strong>
              <span style={{ fontSize: '13px', opacity: 0.9 }}>
                Covers <strong>{insurance_cover} {insurance_cover === 1 ? 'person (yourself)' : 'family members'}</strong> under the ENBTS blood security program.
                {is_demoted && earned_level !== level && (
                  <span style={{ display: 'block', marginTop: 4, color: '#dc2626', fontWeight: 600 }}>
                    Reduced from {TIER_CONFIG[earned_level]?.cover ?? '?'} members (restore by donating).
                  </span>
                )}
              </span>
            </div>
          </div>
        ) : (
          <div
            style={{
              background: 'rgba(255, 255, 255, 0.4)',
              borderRadius: '12px',
              padding: '14px 16px',
              display: 'flex',
              gap: '12px',
              alignItems: 'flex-start',
            }}
          >
            <AlertCircle size={20} style={{ color: is_demoted ? '#dc2626' : '#64748b', flexShrink: 0, marginTop: '2px' }} />
            <div>
              <strong style={{ display: 'block', fontSize: '13px', color: is_demoted ? '#b91c1c' : '#475569', fontWeight: 700 }}>
                {is_demoted ? 'Insurance suspended — donate to reactivate' : 'No active insurance coverage'}
              </strong>
              <span style={{ fontSize: '13px', color: is_demoted ? '#7f1d1d' : '#64748b' }}>
                {is_demoted
                  ? 'One donation reactivates your Bronze emergency blood priority immediately.'
                  : 'Make your first donation to unlock the Bronze tier benefits.'}
              </span>
            </div>
          </div>
        )}
      </div>

      {/* ── Progress towards next level ── */}
      {!is_demoted && next_level_donations_needed !== null && (
        <div style={{ marginTop: '20px', padding: '16px', background: 'rgba(255, 255, 255, 0.4)', borderRadius: '12px', border: '1px dashed rgba(0, 0, 0, 0.1)' }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '8px', fontSize: '13px' }}>
            <span style={{ fontWeight: 600, color: details.textColor }}>Next Recognition Level</span>
            <span style={{ fontWeight: 700, color: details.textColor }}>
              {next_level_donations_needed} more {next_level_donations_needed === 1 ? 'donation' : 'donations'} needed
            </span>
          </div>
          <div style={{ width: '100%', height: '8px', background: 'rgba(0, 0, 0, 0.05)', borderRadius: '99px', overflow: 'hidden' }}>
            <div
              style={{
                height: '100%',
                background: details.badgeBg,
                borderRadius: '99px',
                width: `${Math.min(100, Math.max(10, (total_donations / (total_donations + next_level_donations_needed)) * 100))}%`,
                transition: 'width 0.6s ease',
              }}
            />
          </div>
          <p style={{ margin: '8px 0 0 0', fontSize: '12px', opacity: 0.8, color: details.textColor }}>{family_support_note}</p>
        </div>
      )}

      {/* ── Restore prompt (when demoted) ── */}
      {is_demoted && (
        <div
          style={{
            marginTop: '16px',
            padding: '14px 16px',
            background: 'rgba(255,255,255,0.55)',
            borderRadius: '12px',
            border: `1px dashed ${earnedDetails.borderColor}`,
            display: 'flex',
            gap: '10px',
            alignItems: 'center',
          }}
        >
          <Heart size={18} style={{ color: earnedDetails.iconColor, flexShrink: 0 }} />
          <p style={{ margin: 0, fontSize: '13px', color: details.textColor, lineHeight: 1.5 }}>
            <strong>One donation restores your {earned_level} status</strong> — and all the benefits that come with it.{' '}
            Book an appointment at your nearest ENBTS clinic today.
          </p>
        </div>
      )}

      {/* ── Tier Breakdown ── */}
      <div style={{ marginTop: '24px', borderTop: '1px solid rgba(0, 0, 0, 0.08)', paddingTop: '16px' }}>
        <h4 style={{ margin: '0 0 6px 0', fontSize: '13px', textTransform: 'uppercase', letterSpacing: '0.05em', color: details.textColor, opacity: 0.8, fontWeight: 700 }}>
          ENBTS Tier Structure
        </h4>
        <p style={{ margin: '0 0 10px 0', fontSize: '11px', color: details.textColor, opacity: 0.65, lineHeight: 1.5 }}>
          Tiers are maintained by staying active. Inactivity beyond 12 months triggers a one-tier demotion.
        </p>
        <div style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
          {[
            { name: 'Bronze', range: '1 – 3 donations', benefits: 'Priority list & self insurance (1 person)' },
            { name: 'Silver', range: '4 – 6 donations', benefits: 'Priority list & family cover (5 people)' },
            { name: 'Gold',   range: '7+ donations',    benefits: 'Priority list & family cover (10 people)' },
          ].map((t) => {
            const isCurrent = level === t.name
            const isEarned  = earned_level === t.name && is_demoted
            return (
              <div
                key={t.name}
                style={{
                  display: 'flex',
                  justifyContent: 'space-between',
                  alignItems: 'center',
                  padding: '8px 12px',
                  background: isCurrent ? 'rgba(255, 255, 255, 0.8)' : isEarned ? 'rgba(239,68,68,0.06)' : 'rgba(255, 255, 255, 0.2)',
                  border: isCurrent
                    ? `1px solid ${details.borderColor}`
                    : isEarned ? '1px dashed rgba(239,68,68,0.4)' : '1px solid transparent',
                  borderRadius: '8px',
                  fontSize: '12px',
                }}
              >
                <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                  {isCurrent ? <Check size={14} style={{ color: details.iconColor }} /> : <span style={{ width: 14 }} />}
                  <strong style={{ color: details.textColor }}>{t.name}</strong>
                  <span style={{ opacity: 0.6, color: details.textColor }}>({t.range})</span>
                  {isEarned && (
                    <span style={{ fontSize: '10px', color: '#dc2626', fontWeight: 700, background: 'rgba(239,68,68,0.1)', borderRadius: 4, padding: '1px 5px' }}>
                      YOUR EARNED TIER
                    </span>
                  )}
                </div>
                <span style={{ fontWeight: 500, color: details.textColor, opacity: isCurrent ? 1 : 0.7 }}>
                  {t.benefits}
                </span>
              </div>
            )
          })}
        </div>
      </div>
    </article>
  )
}
