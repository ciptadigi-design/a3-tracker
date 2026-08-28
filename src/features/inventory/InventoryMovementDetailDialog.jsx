import { Eye } from 'lucide-react'
import { DialogFrame } from './InventoryDialogs.jsx'
import { buildMovementDetail } from './movementDetailModel.js'

const quantity = (value) => Number(value ?? 0).toLocaleString('en-US', { maximumFractionDigits: 4 })

export function InventoryMovementDetailDialog({ movement, relatedMovement, timezone, onClose }) {
  const formatTime = (value) => new Intl.DateTimeFormat('id-ID', { timeZone: timezone || 'Asia/Jakarta', dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
  const formatCurrency = (value, currency = 'IDR') => new Intl.NumberFormat('id-ID', { style: 'currency', currency: currency || 'IDR', maximumFractionDigits: 2 }).format(Number(value ?? 0))
  const detail = buildMovementDetail(movement, relatedMovement, { formatCurrency, formatQuantity: quantity, formatTime })
  return <DialogFrame icon={Eye} kicker="Inventory Movement" title={movement.item_name} description={detail.movementType} titleId="inventory-movement-detail-title" onClose={onClose}>
    <div className="machine-form movement-detail"><div className="machine-form-body">
      <div className="movement-detail-hero"><span>Movement Type</span><strong>{detail.movementType}</strong></div>
      <div className="movement-detail-sections">{detail.sections.map((section) => <section key={section.title}><h3>{section.title}</h3><dl>{section.fields.map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{value}</dd></div>)}</dl></section>)}</div>
    </div><footer className="dialog-actions"><button className="secondary-button" type="button" onClick={onClose} data-dialog-initial-focus>Close</button></footer></div>
  </DialogFrame>
}
