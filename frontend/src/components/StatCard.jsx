export default function StatCard({ icon: Icon, label, value, detail, tone = 'red' }) {
  return (
    <article className={`stat-card tone-${tone}`}>
      <div className="stat-icon">{Icon ? <Icon size={22} /> : null}</div>
      <div>
        <p className="stat-label">{label}</p>
        <h3>{value ?? 0}</h3>
        {detail ? <span className="stat-detail">{detail}</span> : null}
      </div>
    </article>
  )
}
