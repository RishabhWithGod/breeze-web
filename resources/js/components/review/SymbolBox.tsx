import { useEffect, useRef, useState, type CSSProperties } from 'react'
import { Check, X } from 'lucide-react'
import { motion, type PanInfo } from 'framer-motion'
import { router } from '@inertiajs/react'
import type { OccurrenceOrigin, PageDimensions, ReviewStatus } from '@/types'
import { cn, symbolColor } from '@/utils'
import { routeTo } from '@/constants'
import { OccurrencePopover } from './OccurrencePopover'

/** Identifies one occurrence (or a whole row, for a single-box card) on the drawing. */
export interface OccurrenceRef {
  reviewId: number
  occurrenceKey?: string
  page: number
}

export interface SymbolBoxProps {
  resultId: number
  reviewId: number
  name: string
  finalCount: number
  /** `[x, y, width, height]` in the AI's page pixel space. */
  bbox: readonly number[]
  page: number
  pageDimensions: PageDimensions | undefined
  status: ReviewStatus
  /** Present for one physical occurrence; absent for a single-box row (e.g. needs-review). */
  occurrenceKey?: string
  occurrenceOrigin?: OccurrenceOrigin
  locked: boolean
  isSelected: boolean
  onSelect: (ref: OccurrenceRef | null) => void
  /** Current on-screen CSS pixel size of the layer this box's percentages are relative to. Move is disabled without it. */
  containerSize: { width: number; height: number } | null
}

/**
 * One detected symbol, marked at its real position on the drawing page.
 *
 * Outline only, no fill and no icon over the box's own area — the actual
 * electrical symbol is already drawn on the page underneath, and covering it
 * would defeat the point of a spatial review. Approved boxes carry the
 * symbol's own identity color; a rejected box turns solid red regardless of
 * identity, since "rejected" is a state everyone needs to spot at a glance —
 * but it stays on the drawing rather than disappearing, so a reviewer can
 * see what was turned down and bring it back. A click selects the box
 * (opening its actions); a drag moves it. Every action posts straight to the
 * server — the card grid re-renders from the same response, so both views
 * stay in sync.
 */
export function SymbolBox({
  resultId,
  reviewId,
  name,
  finalCount,
  bbox,
  page,
  pageDimensions,
  status,
  occurrenceKey,
  occurrenceOrigin,
  locked,
  isSelected,
  onSelect,
  containerSize,
}: SymbolBoxProps) {
  const [isDragging, setIsDragging] = useState(false)
  const draggedRef = useRef(false)
  const boxRef = useRef<HTMLDivElement>(null)
  const [popoverAnchor, setPopoverAnchor] = useState<{ left: number; top: number; placeAbove: boolean } | null>(null)

  // Computed from the marker's real screen position, not a CSS percentage —
  // the popover is portalled straight to `document.body` (see
  // `OccurrencePopover`) so it's never clipped by the drawing viewport's own
  // `overflow-hidden`, no matter how close to its edge the marker sits.
  useEffect(() => {
    if (!isSelected || !boxRef.current) {
      setPopoverAnchor(null)
      return
    }

    const rect = boxRef.current.getBoundingClientRect()
    const estimatedHeight = 260
    const placeAbove = rect.bottom + estimatedHeight + 10 > window.innerHeight

    setPopoverAnchor({
      left: rect.left + rect.width / 2,
      top: placeAbove ? rect.top - 10 : rect.bottom + 10,
      placeAbove,
    })
  }, [isSelected])

  if (!pageDimensions || pageDimensions.width <= 0 || pageDimensions.height <= 0) return null

  const [x = 0, y = 0, w = 0, h = 0] = bbox
  const color = symbolColor(name)
  const isRejected = status === 'rejected'
  const canMove = !locked && containerSize !== null

  const ref: OccurrenceRef = { reviewId, occurrenceKey, page }

  const handleClick = (event: React.MouseEvent) => {
    event.stopPropagation()

    if (draggedRef.current) {
      draggedRef.current = false
      return
    }

    onSelect(isSelected ? null : ref)
  }

  /** The quick hover badge: reject an approved box, or reinstate a rejected one — instantly, no popover. */
  const toggle = () => {
    if (locked) return

    const url = occurrenceKey
      ? routeTo.symbolOccurrence(resultId, reviewId, occurrenceKey)
      : isRejected
        ? routeTo.symbolApprove(resultId, reviewId)
        : routeTo.symbolReject(resultId, reviewId)

    router.post(url, {}, { preserveScroll: true, preserveState: true })
  }

  const handleDragEnd = (_event: MouseEvent | TouchEvent | PointerEvent, info: PanInfo) => {
    setIsDragging(false)

    const moved = Math.abs(info.offset.x) > 2 || Math.abs(info.offset.y) > 2
    draggedRef.current = moved

    if (!moved || !containerSize) return

    // `info.offset` is in real screen pixels. The layer this box sits in is
    // now sized in real CSS pixels too (no `transform: scale()` involved),
    // so a screen-pixel delta maps 1:1 onto that layer's own pixels before
    // converting to the AI's page pixels.
    const deltaX = (info.offset.x / containerSize.width) * pageDimensions.width
    const deltaY = (info.offset.y / containerSize.height) * pageDimensions.height

    const newBbox = [
      Math.max(0, x + deltaX),
      Math.max(0, y + deltaY),
      w,
      h,
    ]

    const url = occurrenceKey
      ? routeTo.symbolOccurrenceMove(resultId, reviewId, occurrenceKey)
      : null

    if (url) {
      router.post(url, { bbox: newBbox }, { preserveScroll: true, preserveState: true })
    }
  }

  return (
    <motion.div
      ref={boxRef}
      className={cn('group absolute', canMove ? 'cursor-grab active:cursor-grabbing' : 'cursor-pointer')}
      style={{
        left: `${(x / pageDimensions.width) * 100}%`,
        top: `${(y / pageDimensions.height) * 100}%`,
        width: `${(w / pageDimensions.width) * 100}%`,
        height: `${(h / pageDimensions.height) * 100}%`,
        minWidth: 16,
        minHeight: 16,
      }}
      title={`${name} · page ${page}`}
      drag={canMove}
      dragMomentum={false}
      dragElastic={0}
      onDragStart={() => setIsDragging(true)}
      onDrag={(_event, info) => {
        if (Math.abs(info.offset.x) > 2 || Math.abs(info.offset.y) > 2) draggedRef.current = true
      }}
      onDragEnd={handleDragEnd}
      onClick={handleClick}
      whileHover={{ scale: 1.08 }}
      animate={isSelected ? { scale: 1.15 } : { scale: 1 }}
    >
      {/* Outline only — deliberately no fill and no icon glyph over the box's
          own area, so the real symbol already drawn on the page underneath
          stays fully visible. The border color alone carries both the
          category identity (approved) and the rejected state. */}
      <div
        className={cn(
          'absolute inset-0 rounded-sm border-2 bg-transparent transition-colors duration-150',
          isRejected ? 'border-red-500' : 'border-(--sb-border)',
          isSelected && 'ring-2 ring-white ring-offset-1 ring-offset-navy-900',
          isDragging && 'shadow-lg',
        )}
        style={{ '--sb-border': color.border } as CSSProperties}
      />

      {/* Rejected is the only status color that persists at rest — an
          approved/pending box's badge stays neutral so nothing on the
          drawing reads as "green = good" at a glance. Hovering previews the
          one action available (reject, or reinstate) with a neutral swap,
          never a green one, and clicking it fires instantly, no popover
          needed. */}
      <button
        type="button"
        onClick={(event) => {
          event.stopPropagation()
          toggle()
        }}
        onPointerDown={(event) => event.stopPropagation()}
        disabled={locked}
        aria-label={isRejected ? `Reinstate ${name}` : `Reject this ${name}`}
        className={cn(
          'absolute -top-1.5 -right-1.5 grid size-4 place-items-center rounded-full text-white shadow-sm transition-colors duration-150',
          isRejected ? 'bg-red-500 group-hover:bg-white/25' : 'bg-white/25 group-hover:bg-red-500',
        )}
      >
        <Check
          size={9}
          strokeWidth={3.5}
          className={cn(
            'absolute transition-opacity duration-150',
            isRejected ? 'opacity-0 group-hover:opacity-100' : 'opacity-100 group-hover:opacity-0',
          )}
        />
        <X
          size={9}
          strokeWidth={3.5}
          className={cn(
            'absolute transition-opacity duration-150',
            isRejected ? 'opacity-100 group-hover:opacity-0' : 'opacity-0 group-hover:opacity-100',
          )}
        />
      </button>

      <span className="pointer-events-none absolute -top-6 left-1/2 hidden -translate-x-1/2 rounded-full bg-navy-900/95 px-2 py-0.5 text-2xs whitespace-nowrap text-white group-hover:block">
        {name} · page {page}
      </span>

      {isSelected && popoverAnchor && (
        <OccurrencePopover
          resultId={resultId}
          reviewId={reviewId}
          occurrenceKey={occurrenceKey}
          occurrenceOrigin={occurrenceOrigin}
          name={name}
          status={status}
          finalCount={finalCount}
          locked={locked}
          onClose={() => onSelect(null)}
          anchorStyle={{
            left: popoverAnchor.left,
            top: popoverAnchor.top,
            transform: popoverAnchor.placeAbove ? 'translate(-50%, -100%)' : 'translate(-50%, 0)',
          }}
        />
      )}
    </motion.div>
  )
}
