import { motion } from 'framer-motion'
import { Maximize2, ScanLine, ZoomIn, ZoomOut } from 'lucide-react'
import { Badge } from '@/components/common'
import { cn } from '@/utils'

/** Fixed hotspot positions expressed as utility classes (no inline styles). */
const HOTSPOTS = [
  { id: 'h1', label: 'L1', position: 'top-[22%] left-[18%]' },
  { id: 'h2', label: 'R1', position: 'top-[38%] left-[46%]' },
  { id: 'h3', label: 'SW', position: 'top-[63%] left-[28%]' },
  { id: 'h4', label: 'PB', position: 'top-[30%] left-[72%]' },
  { id: 'h5', label: 'SD', position: 'top-[71%] left-[64%]' },
  { id: 'h6', label: 'DT', position: 'top-[52%] left-[84%]' },
] as const

export interface DrawingPreviewProps {
  sheetCode: string
  title: string
  scale: string
  className?: string
}

/**
 * Placeholder for the rendered drawing. Uses a blueprint grid plus mock symbol
 * hotspots — no real drawing asset is loaded in this phase.
 */
export function DrawingPreview({
  sheetCode,
  title,
  scale,
  className,
}: DrawingPreviewProps) {
  const toolButton =
    'grid size-8 place-items-center rounded-panel border border-hairline bg-navy-950/50 text-white ' +
    'transition-colors hover:border-brand/60 hover:text-brand'

  return (
    <div
      className={cn(
        'relative aspect-16/10 w-full overflow-hidden rounded-card border border-hairline-strong bg-navy-950/55',
        className,
      )}
    >
      <div className="blueprint-grid absolute inset-0 opacity-70" aria-hidden />

      {/* Mock floor-plan geometry. */}
      <svg
        viewBox="0 0 400 250"
        className="absolute inset-0 size-full"
        role="img"
        aria-label={`Preview placeholder for sheet ${sheetCode}`}
      >
        <g className="fill-none stroke-brand/45" strokeWidth={1.5}>
          <rect x="28" y="24" width="344" height="200" rx="3" />
          <path d="M28 120h150M178 24v96M178 168h194M258 168v56M300 24v70" />
          <rect x="196" y="40" width="60" height="46" rx="2" />
          <rect x="52" y="150" width="88" height="56" rx="2" />
        </g>
        <g className="fill-brand/12">
          <rect x="196" y="40" width="60" height="46" rx="2" />
          <rect x="52" y="150" width="88" height="56" rx="2" />
        </g>
      </svg>

      {/* Detected-symbol markers. */}
      {HOTSPOTS.map((hotspot, index) => (
        <motion.span
          key={hotspot.id}
          initial={{ opacity: 0, scale: 0.5 }}
          animate={{ opacity: 1, scale: 1 }}
          transition={{ delay: 0.3 + index * 0.09, type: 'spring', stiffness: 300 }}
          className={cn('absolute', hotspot.position)}
        >
          <span className="relative grid size-7 place-items-center rounded-full border border-brand bg-navy-950/80 text-2xs font-bold text-brand">
            <span
              className="absolute inset-0 rounded-full bg-brand/40 animate-pulse-ring"
              aria-hidden
            />
            {hotspot.label}
          </span>
        </motion.span>
      ))}

      {/* Sheet metadata overlay. */}
      <div className="absolute inset-x-0 top-0 flex items-start justify-between gap-3 bg-linear-to-b from-navy-950/85 to-transparent p-4">
        <div className="min-w-0">
          <p className="flex items-center gap-2 text-md font-semibold text-white">
            <ScanLine size={16} aria-hidden className="text-brand" />
            <span className="truncate">{sheetCode}</span>
          </p>
          <p className="mt-0.5 truncate text-sm text-white/85">{title}</p>
        </div>
        <Badge tone="brand" size="sm">
          Scale {scale}
        </Badge>
      </div>

      {/* Non-functional viewer controls. */}
      <div className="absolute right-3 bottom-3 flex items-center gap-1.5">
        <button type="button" className={toolButton} aria-label="Zoom out">
          <ZoomOut size={15} aria-hidden />
        </button>
        <button type="button" className={toolButton} aria-label="Zoom in">
          <ZoomIn size={15} aria-hidden />
        </button>
        <button type="button" className={toolButton} aria-label="Full screen">
          <Maximize2 size={15} aria-hidden />
        </button>
      </div>
    </div>
  )
}
