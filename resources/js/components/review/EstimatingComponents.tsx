import { motion } from 'framer-motion'
import { Check, Clock } from 'lucide-react'
import { Badge, Card, CardHeader } from '@/components/common'
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
    <Card accent="success" padding="lg" className={cn('mt-6', className)}>
      <CardHeader title="Estimating components" subtitle="What this takeoff already has for the estimate." />

      <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        {available.map((component, index) => (
          <motion.li
            key={component.key}
            initial={{ opacity: 0, y: 8 }}
            whileInView={{ opacity: 1, y: 0 }}
            viewport={{ once: true }}
            transition={{ duration: 0.3, delay: Math.min(index, 6) * 0.05 }}
            className={cn(
              'rounded-panel border p-4',
              component.status === 'available'
                ? 'border-brand/40 bg-brand/8'
                : 'border-hairline bg-white/4',
            )}
          >
            <div className="flex items-start justify-between gap-2">
              <p className="min-w-0 font-medium text-white">{component.label}</p>
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

            <p
              className={cn(
                'mt-1 text-sm',
                component.status === 'available' ? 'text-white/90' : 'text-white/70',
              )}
            >
              {component.summary}
            </p>

            {component.items.length > 0 && (
              <ul className="mt-3 space-y-1.5 border-t border-hairline pt-3">
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
    </Card>
  )
}
