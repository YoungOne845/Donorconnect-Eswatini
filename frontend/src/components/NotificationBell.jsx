import { Bell, CheckCheck, X } from 'lucide-react'
import { useEffect, useRef } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useNotifications } from '../context/NotificationContext'
import { formatDate } from '../utils/format'

const TYPE_COLORS = {
  blood_request: '#dc2626',
  general: '#7c3aed',
  campaign: '#0284c7',
  appointment: '#059669',
}

export default function NotificationBell() {
  const { unreadCount, recentItems, dropdownOpen, openDropdown, closeDropdown, markRead, markAllRead } = useNotifications()
  const panelRef = useRef(null)
  const navigate = useNavigate()

  // Close when clicking outside
  useEffect(() => {
    if (!dropdownOpen) return
    const handler = (e) => {
      if (panelRef.current && !panelRef.current.contains(e.target)) {
        closeDropdown()
      }
    }
    document.addEventListener('mousedown', handler)
    return () => document.removeEventListener('mousedown', handler)
  }, [dropdownOpen, closeDropdown])

  // Close on Escape
  useEffect(() => {
    const handler = (e) => { if (e.key === 'Escape') closeDropdown() }
    document.addEventListener('keydown', handler)
    return () => document.removeEventListener('keydown', handler)
  }, [closeDropdown])

  const handleToggle = () => {
    if (dropdownOpen) closeDropdown()
    else openDropdown()
  }

  const handleViewAll = () => {
    closeDropdown()
    navigate('/app/notifications')
  }

  const typeColor = (type) => TYPE_COLORS[type] || '#64748b'

  return (
    <div className="notif-bell-wrap" ref={panelRef}>
      <button
        id="notification-bell-btn"
        className={`notif-bell-btn${dropdownOpen ? ' open' : ''}`}
        onClick={handleToggle}
        aria-label={`Notifications${unreadCount > 0 ? ` — ${unreadCount} unread` : ''}`}
        aria-haspopup="true"
        aria-expanded={dropdownOpen}
      >
        <Bell size={20} className={unreadCount > 0 ? 'bell-shake' : ''} />
        {unreadCount > 0 && (
          <span className="notif-badge" aria-hidden="true">
            {unreadCount > 99 ? '99+' : unreadCount}
          </span>
        )}
      </button>

      {dropdownOpen && (
        <div className="notif-dropdown" role="dialog" aria-label="Notifications">
          {/* Header */}
          <div className="notif-dropdown-header">
            <div>
              <strong>Notifications</strong>
              {unreadCount > 0 && <span className="notif-dropdown-count">{unreadCount} unread</span>}
            </div>
            <div style={{ display: 'flex', gap: 4 }}>
              {unreadCount > 0 && (
                <button className="notif-header-action" onClick={markAllRead} title="Mark all as read">
                  <CheckCheck size={15} />
                </button>
              )}
              <button className="notif-header-action" onClick={closeDropdown} title="Close">
                <X size={15} />
              </button>
            </div>
          </div>

          {/* List */}
          <div className="notif-dropdown-list">
            {recentItems.length === 0 ? (
              <div className="notif-empty">
                <Bell size={28} strokeWidth={1.5} />
                <span>You're all caught up</span>
              </div>
            ) : (
              recentItems.map((item) => (
                <button
                  key={item.id}
                  className={`notif-item${item.is_read == 0 ? ' unread' : ''}`}
                  onClick={() => {
                    markRead(item.id)
                    if (item.action_url) {
                      closeDropdown()
                      navigate(item.action_url)
                    }
                  }}
                >
                  <span
                    className="notif-item-dot"
                    style={{ background: typeColor(item.notification_type) }}
                  />
                  <div className="notif-item-body">
                    <div className="notif-item-title">
                      {item.title}
                      {item.is_read == 0 && <span className="notif-unread-pip" />}
                    </div>
                    <p className="notif-item-msg">{item.message}</p>
                    <time className="notif-item-time">{formatDate(item.created_at, true)}</time>
                  </div>
                </button>
              ))
            )}
          </div>

          {/* Footer */}
          <div className="notif-dropdown-footer">
            <button className="notif-view-all" onClick={handleViewAll}>
              View all notifications
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
