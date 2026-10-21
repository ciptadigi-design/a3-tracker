import { useId } from 'react'
import { Info } from 'lucide-react'

/** Small accessible hint: focusable, shown on hover/focus, announced via aria-describedby. */
export function InfoTip({ text }) {
  const id = useId()
  return <span className="info-tip"><button type="button" className="info-tip-button" aria-label="Keterangan" aria-describedby={id}><Info size={13} /></button><span id={id} role="tooltip" className="info-tip-text">{text}</span></span>
}
