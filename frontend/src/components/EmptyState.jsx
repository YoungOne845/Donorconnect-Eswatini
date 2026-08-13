import { Inbox } from 'lucide-react'

export default function EmptyState({ title = 'Nothing here yet', message = 'New information will appear here when available.' }) {
  return (
    <div className="empty-state">
      <Inbox size={34} />
      <h3>{title}</h3>
      <p>{message}</p>
    </div>
  )
}
