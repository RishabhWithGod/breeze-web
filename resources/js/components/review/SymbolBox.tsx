import { useEffect, useRef, useState, type CSSProperties } from 'react'
import { Check, X } from 'lucide-react'
import { motion, type PanInfo } from 'framer-motion'
import { router } from '@inertiajs/react'
import type { OccurrenceOrigin, PageDimensions, ReviewStatus } from '@/types'
import type { SymbolColor } from '@/utils'
import { cn } from '@/utils'
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
  isSelected: boolean
  onSelect: (ref: OccurrenceRef | null) => void
  /** Reports this box's own name while the pointer is over it, and `null` on leave — drives the matching row's highlight in the legend. */
  onHoverChange?: (name: string | null) => void
  /** Current on-screen CSS pixel size of the layer this box's percentages are relative to. Move is disabled without it. */
  containerSize: { width: number; height: number } | null
  /**
   * This category's colour, resolved across every name on the drawing.
   *
   * Passed in rather than looked up here: resolving one name at a time cannot
   * see its siblings, so a box could end up a different colour from its own row
   * in the legend — see `resolveSymbolColors`.
   */
  color: SymbolColor
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
  isSelected,
  onSelect,
  onHoverChange,
  containerSize,
  color,
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
  const isRejected = status === 'rejected'
  const canMove = containerSize !== null

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

    const url = occurrenceKey
      ? routeTo.symbolOccurrence(resultId, reviewId, occurrenceKey)
      : isRejected
        ? routeTo.symbolApprove(resultId, reviewId)
        : routeTo.symbolReject(resultId, reviewId)

    /*
     * `except: ['flash']` is what keeps this from shoving the page around.
     *
     * The server flashes "X rejected" on this route, and the review screen
     * renders that banner above the drawing. The first rejection of a session
     * therefore made the banner appear and pushed everything below it down —
     * which reads as the whole screen reloading. Every rejection after that
     * only swapped the banner's text, so it never happened again, which is why
     * it looked like a first-time-only bug.
     *
     * A banner is the right feedback for the card grid's own buttons, where the
     * row can be scrolled out of view. It is redundant here: the marker under
     * the pointer turns red the moment it lands. So this request asks for
     * everything except the flash, and the page does not move.
     */
    router.post(url, {}, {
      preserveScroll: true,
      preserveState: true,
      except: ['flash'],
    })
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
      onHoverStart={() => onHoverChange?.(name)}
      onHoverEnd={() => onHoverChange?.(null)}
      whileHover={{ scale: 1.08 }}
      animate={isSelected ? { scale: 1.15 } : { scale: 1 }}
    >
      {/* No icon glyph over the box's own area — the real electrical symbol is
          drawn on the page underneath, and covering it would defeat the point
          of a spatial review. The wash is 22%, which the linework reads
          straight through, and it is what makes a light hue findable: a
          two-pixel yellow line on white is a line you hunt for, a tinted patch
          is not. */}
      <div
        className={cn(
          'absolute inset-0 rounded-sm transition-colors duration-150',
          /*
           * A white halo around the coloured line.
           *
           * These lines land on dense black linework, not on clean paper, and
           * a two-pixel colour against a drawing is a colour you have to hunt
           * for. The white ring outside it is what makes it read as a marker
           * rather than as part of the drawing.
           */
          'border-2 outline-2 outline-white/90',
          /*
           * Rejected is red, and red is only ever rejected.
           *
           * The category palette has no red in it — see `PALETTE_HUES` — so a
           * red box on this page cannot be mistaken for a category. That is
           * what makes spending the hue on status affordable here: it costs
           * nothing that another colour was using.
           *
           * Dashed and filled as well as red, so it is still the odd one out
           * for anyone who cannot separate red from green. The wash is light
           * enough to read the symbol through: it is dismissed, not deleted,
           * and a reviewer has to see what they turned down to bring it back.
           */
          isRejected
            ? 'border-dashed border-red-700 bg-red-600/25'
            : 'border-(--sb-border) bg-(--sb-fill)',
          // The selected box is picked out in ink, against its own white halo.
          isSelected && 'ring-2 ring-navy-900 ring-offset-2 ring-offset-white',
          isDragging && 'shadow-lg',
        )}
        style={
          {
            '--sb-border': color.onPaper,
            '--sb-fill': color.paperFill,
          } as CSSProperties
        }
      />

      {/*
        The one action available on this marker, shown only while the pointer
        is on it.

        A badge sitting on every box at rest was the problem: these boxes are
        often barely bigger than the badge, so the thing meant to help was
        covering the coloured outline that says what the symbol is. At rest the
        drawing is now just outlines — which is the whole point of a spatial
        review — and the action appears where it is wanted.

        Nothing is lost by hiding it: a rejected box already reads as rejected
        from its red outline, with or without a badge on it.

        Green to reinstate, red to reject, and the glyph is the action rather
        than the current state — there is only ever one thing this button does.
      */}
      <button
        type="button"
        onClick={(event) => {
          event.stopPropagation()
          toggle()
        }}
        onPointerDown={(event) => event.stopPropagation()}
        aria-label={isRejected ? `Reinstate ${name}` : `Reject this ${name}`}
        className={cn(
          'absolute -top-1.5 -right-1.5 grid size-4 place-items-center rounded-full text-white shadow-sm',
          // A white ring, so it separates from whatever linework it lands on.
          'ring-[1.5px] ring-white transition-opacity duration-150',
          /*
           * Hidden means unclickable too. An invisible hit target hanging off
           * every marker would swallow clicks meant for the drawing, or for the
           * box behind it on a crowded page. Keyboard focus still reveals it —
           * `pointer-events` has no say over tabbing.
           */
          'opacity-0 pointer-events-none group-hover:pointer-events-auto',
          'group-hover:opacity-100 focus-visible:pointer-events-auto focus-visible:opacity-100',
          isSelected && 'pointer-events-auto opacity-100',
          isRejected ? 'bg-[#15803d]' : 'bg-red-600',
        )}
      >
        {isRejected ? (
          <Check size={10} strokeWidth={3.5} aria-hidden />
        ) : (
          <X size={10} strokeWidth={3.5} aria-hidden />
        )}
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
