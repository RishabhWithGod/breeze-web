import breezeIcon from '@/assets/breeze-icon.png'
import { cn } from '@/utils'

export interface BrandWordmarkProps {
  className?: string
}

/** The brand mark and "Breeze AI" wordmark as shown on the auth screens. */
export function BrandWordmark({ className }: BrandWordmarkProps) {
  return (
    <div
      role="img"
      aria-label="Breeze AI"
      className={cn('inline-flex items-center gap-4', className)}
    >
      <span className="relative grid size-16 shrink-0 place-items-center rounded-panel grad-midnight ring-1 ring-brand/40">
        <img src={breezeIcon} alt="" className="size-11 object-contain" />
      </span>
      <span className="text-4xl font-black tracking-wide text-white">Breeze AI</span>
    </div>
  )
}
