import { Badge } from '@/components/common'
import { DETECTION_SOURCES } from '@/constants'
import type { DetectionSources } from '@/types'

export interface SourceBadgesProps {
  sources: DetectionSources
  size?: 'sm' | 'md'
}

/** The detectors that found this symbol — template, vector, vision, OCR. */
export function SourceBadges({ sources, size = 'sm' }: SourceBadgesProps) {
  const active = DETECTION_SOURCES.filter((source) => sources[source.key])

  if (active.length === 0) {
    return <span className="text-2xs text-white/65">No source reported</span>
  }

  return (
    <>
      {active.map((source) => (
        <Badge key={source.key} tone={source.tone} size={size}>
          {source.label}
        </Badge>
      ))}
    </>
  )
}
