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
 * What the estimate needs from this takeoff, and how much of it the run
 * actually returned.
 *
 * Components the engine has not produced yet are listed too, marked as still to
 * come — a reviewer signing off should know what the estimate will and will not
 * carry, rather than finding out at the estimate. Nothing here is invented: a
 * figure shown is one the engine returned, and everything else says it is
 * pending instead of showing a zero.
 */
export function EstimatingComponents({ components, className }: EstimatingComponentsProps) {
  if (components.length === 0) return null

  const pending = components.filter((component) => component.status === 'pending')

  return (
    <Card accent="success" padding="lg" className={cn('mt-6', className)}>
      <CardHeader
        title="Estimating components"
        subtitle={
          pending.length > 0
            ? 'Labor, wire length, wire size, conduit and conduit sizing, 90° and 45° conduit bends/fittings, along with the other required estimating components, are being added to the estimates.'
            : 'Everything the estimate needs came back with this run.'
        }
      />

      <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        {components.map((component, index) => (
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
