export default function StatusBadge({ value }) {
  const normalized = String(value || 'unknown').toLowerCase().replaceAll(' ', '_')
  return <span className={`status-badge status-${normalized}`}>{String(value || 'Unknown').replaceAll('_', ' ')}</span>
}
