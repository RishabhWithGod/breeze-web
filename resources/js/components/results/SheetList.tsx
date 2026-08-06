import { motion } from 'framer-motion'
import { FileText } from 'lucide-react'
import { Badge } from '@/components/common'
import type { DrawingSheet } from '@/types'
import { cn, formatNumber } from '@/utils'

export interface SheetListProps {
  sheets: readonly DrawingSheet[]
  /** Currently previewed sheet id. */
  activeId?: number
  onSelect?: (sheet: DrawingSheet) => void
  className?: string
}

/** Compact list of drawing sheets included in the takeoff. */
export function SheetList({ sheets, activeId, onSelect, className }: SheetListProps) {
  return (
    <ul className={cn('space-y-2', className)}>
      {sheets.map((sheet, index) => {
        const isActive = sheet.id === activeId

        return (
          <motion.li
            key={sheet.id}
            initial={{ opacity: 0, y: 8 }}
            whileInView={{ opacity: 1, y: 0 }}
            viewport={{ once: true }}
            transition={{ duration: 0.3, delay: index * 0.05 }}
          >
            <button
              type="button"
              onClick={() => onSelect?.(sheet)}
              className={cn(
                'flex w-full items-center gap-3 rounded-panel border p-3 text-left transition-colors',
                isActive
                  ? 'border-brand/60 bg-brand/12'
                  : 'border-hairline bg-navy-950/30 hover:border-brand/40 hover:bg-white/8',
              )}
            >
              <span className="grid size-9 shrink-0 place-items-center rounded-panel bg-white/10 text-brand">
                <FileText size={17} aria-hidden />
              </span>

              <span className="min-w-0 flex-1">
                <span className="flex items-center gap-2">
                  <span className="text-md font-semibold text-white">{sheet.code}</span>
                  <span className="text-xs text-white/40">{sheet.pageCount} pages</span>
                </span>
                <span className="mt-0.5 block truncate text-sm text-white/60">
                  {sheet.title}
                </span>
              </span>

              <Badge tone={isActive ? 'brand' : 'neutral'} size="sm">
                {formatNumber(sheet.symbolCount)}
              </Badge>
            </button>
          </motion.li>
        )
      })}
    </ul>
  )
}
