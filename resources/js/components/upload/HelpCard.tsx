import { ArrowRight } from 'lucide-react'
import { Card, IconBubble } from '@/components/common'
import type { HelpResource } from '@/constants'

export interface HelpCardProps {
  resource: HelpResource
  index?: number
}

/** Support/documentation tile used in the "Need help?" section. */
export function HelpCard({ resource, index }: HelpCardProps) {
  return (
    <Card
      hoverable
      className="group h-full"
      {...(index !== undefined ? { index } : {})}
    >
      <div className="flex gap-4">
        <IconBubble icon={resource.icon} size="lg" />
        <div className="min-w-0">
          <p className="font-bold text-white">{resource.title}</p>
          <p className="mt-1 text-md text-white/90">{resource.description}</p>
          <span className="mt-3 inline-flex items-center gap-1.5 text-sm font-medium text-brand transition-colors group-hover:text-white">
            {resource.action}
            <ArrowRight
              size={14}
              aria-hidden
              className="transition-transform group-hover:translate-x-1"
            />
          </span>
        </div>
      </div>
    </Card>
  )
}
