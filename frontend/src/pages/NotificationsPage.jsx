import { Bell, CheckCheck, Trash2 } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api/client'
import EmptyState from '../components/EmptyState'
import StatusBadge from '../components/StatusBadge'
import { formatDate, titleCase } from '../utils/format'

export default function NotificationsPage() {
  const [items, setItems] = useState(null)
  const load = () => api('/notifications').then(setItems).catch(() => setItems([]))
  useEffect(() => { void load() }, [])
  const mark = async (id) => { await api(`/notifications/${id}/read`, { method: 'PATCH' }); load() }
  const markAll = async () => { await api('/notifications/read-all', { method: 'PATCH' }); load() }
  const remove = async (id) => { await api(`/notifications/${id}`, { method: 'DELETE' }); load() }
  const removeAll = async () => {
    if (window.confirm('Are you sure you want to delete all notifications?')) {
      await api('/notifications', { method: 'DELETE' })
      load()
    }
  }

  if (!items) return <div className="panel-loading"><div className="blood-loader" />Loading notifications…</div>

  return <section className="panel">
    <div className="panel-header">
      <div>
        <span className="eyebrow">Stay connected</span>
        <h2>Notifications</h2>
      </div>
      <div style={{ display: 'flex', gap: '8px' }}>
        <button className="button button-secondary" onClick={markAll}>
          <CheckCheck size={17} /> Mark all read
        </button>
        {items.length > 0 && (
          <button className="button button-danger" onClick={removeAll}>
            <Trash2 size={17} /> Delete all
          </button>
        )}
      </div>
    </div>
    {items.length ? (
      <div className="notification-list">
        {items.map((item) => (
          <article className={item.is_read == 1 ? 'read' : 'unread'} key={item.id}>
            <span className="notification-icon">
              <Bell />
            </span>
            <div className="notification-copy">
              <div>
                <StatusBadge value={titleCase(item.notification_type)} />
                <small>{formatDate(item.created_at, true)}</small>
              </div>
              <h3>{item.title}</h3>
              <p>{item.message}</p>
              {item.action_url ? (
                <Link to={item.action_url} className="text-link">
                  Open related item
                </Link>
              ) : null}
            </div>
            <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
              {item.is_read == 0 ? (
                <button className="button button-ghost" onClick={() => mark(item.id)}>
                  Mark read
                </button>
              ) : null}
              <button
                className="button button-ghost"
                onClick={() => remove(item.id)}
                style={{ color: '#dc2626', padding: '0 8px', minHeight: '36px', width: '36px' }}
                title="Delete notification"
              >
                <Trash2 size={16} />
              </button>
            </div>
          </article>
        ))}
      </div>
    ) : (
      <EmptyState
        title="You're all caught up"
        message="Campaign, eligibility and donor coordination messages will appear here."
      />
    )}
  </section>
}

