import { motion } from 'framer-motion'
import { Check, Clock } from 'lucide-react'
import { Badge, Panel } from '@/components/common'
import type { EstimatingComponent } from '@/types'
import { cn } from '@/utils'

export interface EstimatingComponentsProps {
  components: readonly EstimatingComponent[]
  className?: string
}

/**
 * What the estimate already has from this takeoff.
 *
 * Only components the run actually returned data for are shown — a component
 * with nothing behind it yet (still on the roadmap, or simply not produced by
 * this run) is left off entirely rather than shown as a "coming soon"
 * placeholder. Nothing here is invented: a figure shown is always one the
 * engine actually returned.
 */
export function EstimatingComponents({ components, className }: EstimatingComponentsProps) {
  const available = components.filter((component) => component.status === 'available')

  if (available.length === 0) return null

  return (
    <Panel
      title="Estimating components"
      sub="What this takeoff already has for the estimate."
      className={cn('mt-4', className)}
    >
      <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        {available.map((component, index) => (
          <motion.li
            key={component.key}
            initial={{ opacity: 0, y: 8 }}
            whileInView={{ opacity: 1, y: 0 }}
            viewport={{ once: true }}
            transition={{ duration: 0.3, delay: Math.min(index, 6) * 0.05 }}
            className="relative overflow-hidden rounded-card border border-hairline bg-[var(--surface-2)] p-4"
          >
            <span
              aria-hidden
              className="pointer-events-none absolute -top-10 -right-10 size-[120px] rounded-full bg-[radial-gradient(circle,var(--glow-a),transparent_70%)]"
            />
            <div className="relative flex items-start justify-between gap-2">
              <p className="min-w-0 text-xs font-semibold tracking-[0.04em] text-[var(--text-2)] uppercase">
                {component.label}
              </p>
              {component.status === 'available' ? (
                <Badge tone="success" size="sm" icon={Check}>
                  From takeoff
                </Badge>
              ) : (
                <Badge tone="neutral" size="sm" icon={Clock}>
                  Coming soon
                </Badge>
              )}
            </div>

            <p className="relative mt-2 font-display text-[1.125rem] leading-snug font-[620] tracking-tight text-white">
              {component.summary}
            </p>

            {component.items.length > 0 && (
              <ul className="relative mt-3 space-y-1.5 border-t border-hairline pt-3">
                {component.items.map((item, position) => (
                  <li
                    key={`${component.key}-${position}`}
                    className="flex items-baseline justify-between gap-3 text-sm"
                  >
                    <span className="min-w-0 truncate text-white">
                      {item.label ?? '—'}
                      {item.detail && (
                        <span className="text-white/65"> · {item.detail}</span>
                      )}
                    </span>
                    <span className="shrink-0 tabular-nums text-white/85">
                      {item.value ?? (item.page === null ? '' : `p.${item.page}`)}
                    </span>
                  </li>
                ))}

                {component.moreCount > 0 && (
                  <li className="pt-1 text-sm text-white/60">
                    +{component.moreCount} more
                  </li>
                )}
              </ul>
            )}
          </motion.li>
        ))}
      </ul>
    </Panel>
  )
}
