import { X } from 'lucide-react'

export default function Modal({ open, title, onClose, children, wide = false }) {
  if (!open) return null
  return (
    <div className="modal-backdrop" role="presentation" onMouseDown={onClose}>
      <section className={`modal ${wide ? 'modal-wide' : ''}`} role="dialog" aria-modal="true" onMouseDown={(event) => event.stopPropagation()}>
        <header className="modal-header">
          <h2>{title}</h2>
          <button type="button" className="icon-button" onClick={onClose} aria-label="Close"><X size={20} /></button>
        </header>
        <div className="modal-body">{children}</div>
      </section>
    </div>
  )
}
