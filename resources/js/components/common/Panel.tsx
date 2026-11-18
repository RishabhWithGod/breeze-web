import type { ReactNode } from 'react'
import { cn } from '@/utils'

export interface PanelProps {
  title?: ReactNode
  /** The small muted line under the title. */
  sub?: ReactNode
  /** Right-aligned controls in the head. */
  actions?: ReactNode
  /** `false` lets a table run edge to edge inside the card. */
  pad?: boolean
  id?: string
  className?: string
  children?: ReactNode
}

/**
 * The Breeze app's card: a head (title, optional sub-line, actions) over a hairline,
 * then the body. 18/22px in the head, 22px in the body, 16px corners.
 */
export function Panel({ title, sub, actions, pad = true, id, className, children }: PanelProps) {
  return (
    <section id={id} className={cn('glass flex h-full min-w-0 flex-col rounded-card border border-hairline', className)}>
      {(title || actions) && (
        <div className="flex items-center justify-between gap-3 border-b border-hairline px-[22px] py-[18px]">
          <div className="min-w-0">
            <h2 className="font-display text-xl leading-[1.25] font-[620] tracking-[-0.01em] text-white">{title}</h2>
            {sub && <div className="mt-0.5 text-[13px] font-normal text-[var(--text-3)]">{sub}</div>}
          </div>
          {actions && <div className="flex shrink-0 items-center gap-2.5">{actions}</div>}
        </div>
      )}
      {pad ? <div className="flex-1 p-[22px]">{children}</div> : <div className="flex-1">{children}</div>}
    </section>
  )
}
