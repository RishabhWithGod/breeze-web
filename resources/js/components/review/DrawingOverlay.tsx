import { useEffect, useLayoutEffect, useMemo, useRef, useState, type PointerEvent as ReactPointerEvent } from 'react'
import { router } from '@inertiajs/react'
import { Box, Maximize, Minus, Plus, RotateCcw, StretchHorizontal } from 'lucide-react'
import { Button, ButtonLink, Card, FilterTabs, IconButton, SectionHeading, SelectField } from '@/components/common'
import { routeTo } from '@/constants'
import type { OccurrenceOrigin, OverlaySymbol, PageDimensions, ReviewStatus } from '@/types'
import { categoryKey, symbolColor, UNMAPPED_COLOR } from '@/utils'
import type { SymbolColor } from '@/utils'

import { ManualAddPopover } from './ManualAddPopover'
import { SymbolBox, type OccurrenceRef } from './SymbolBox'
import { SymbolLegend } from './SymbolLegend'

export interface DrawingOverlayProps {
  resultId: number
  pageCount: number
  overlaySymbols: readonly OverlaySymbol[]
  pageDimensions: Readonly<Record<number, PageDimensions>>
  distinctNames: readonly string[]
  /**
   * Every category's colour, resolved once for the whole drawing by the screen
   * that owns it. Taken as a prop rather than worked out here so the drawing,
   * the legend and the card grid cannot drift apart — which is exactly what
   * happened when each resolved its own.
   */
  colors: ReadonlyMap<string, SymbolColor>
  /** Seeds the active page from the grid's own page filter, when set. */
  initialPage: number | null
  selected: OccurrenceRef | null
  onSelect: (ref: OccurrenceRef | null) => void
  /** Set by a card's "Show on drawing" — switches page and centers that occurrence, then must be acknowledged. */
  focusRequest: OccurrenceRef | null
  onFocusHandled: () => void
}

interface DrawableBox {
  key: string
  reviewId: number
  name: string
  finalCount: number
  bbox: readonly number[]
  status: ReviewStatus
  occurrenceKey?: string
  occurrenceOrigin?: OccurrenceOrigin
}

interface Draft {
  page: number
  /** Click position as a 0–100 percentage of the rendered image. */
  xPct: number
  yPct: number
}

const DEFAULT_BOX_FRACTION = 0.035
/**
 * Whether the Drawing card offers a way into the spatial viewer.
 *
 * Off while that feature is parked. Everything behind it is intact — the route,
 * the page, the components, the tests — so turning this to `true` is the whole
 * of putting it back.
 */
const SHOW_THREE_D_ENTRY = false

const ZOOM_STEPS = [0.5, 0.75, 1, 1.25, 1.5, 2, 3, 4]
const MIN_SCALE: number = ZOOM_STEPS[0] ?? 0.5
const MAX_SCALE: number = ZOOM_STEPS[ZOOM_STEPS.length - 1] ?? 4
const DRAG_CLICK_THRESHOLD = 4

function clampScale(scale: number): number {
  return Math.min(MAX_SCALE, Math.max(MIN_SCALE, scale))
}

/**
 * The drawing itself, with every detected symbol boxed at its real position,
 * zoomable and pannable. The image and every box live inside one transformed
 * layer, so zoom/pan never touches the percentage-based coordinate math from
 * Phase 1 — a box's `left/top/width/height` stay exactly what they always
 * were; only the layer they sit in gets visually scaled and translated.
 *
 * Sits above the card grid on the AI Review screen — the two read the same
 * server props, so a decision made here or on a card shows up in both places
 * on the very next render.
 */
export function DrawingOverlay({
  resultId,
  pageCount,
  overlaySymbols,
  pageDimensions,
  distinctNames,
  colors,
  initialPage,
  selected,
  onSelect,
  focusRequest,
  onFocusHandled,
}: DrawingOverlayProps) {
  // A symbol card's own `page` is null once it has an occurrences array — the
  // real page numbers live on each occurrence instead — so both are searched
  // to find the first page actually worth landing on.
  const firstDataPage = useMemo(() => {
    const pages = overlaySymbols.flatMap((symbol) =>
      symbol.occurrences && symbol.occurrences.length > 0
        ? symbol.occurrences.map((occurrence) => occurrence.page)
        : symbol.page !== null
          ? [symbol.page]
          : [],
    )

    return [...pages].sort((a, b) => a - b)[0] ?? 1
  }, [overlaySymbols])

  const [activePage, setActivePage] = useState(initialPage ?? firstDataPage ?? 1)
  const [draft, setDraft] = useState<Draft | null>(null)
  const [scale, setScale] = useState(1)
  // The viewport's own width — the "fit to width" baseline that the content
  // layer's real pixel size is computed from at any zoom level. Tracked via
  // ResizeObserver so it stays correct across window/panel resizes.
  const [viewportWidth, setViewportWidth] = useState(0)
  const [activeCategory, setActiveCategory] = useState<string | null>(null)
  const [activeStatus, setActiveStatus] = useState<'all' | 'approved' | 'rejected'>('all')
  const [hoveredCategory, setHoveredCategory] = useState<string | null>(null)
  // Whether the page image itself loaded — independent of whether the
  // engine's page dimensions have arrived. A missing bbox source shouldn't
  // hide a perfectly good picture, and a real load failure shouldn't be
  // reported as "no dimensions".
  const [imageStatus, setImageStatus] = useState<'loading' | 'loaded' | 'error'>('loading')
  // Fallback shape for the frame while the engine's own page dimensions
  // haven't arrived yet — the rendered preview's own aspect ratio (never
  // used for box math, which always needs the real AI-space dimensions).
  const [imageAspect, setImageAspect] = useState<number | null>(null)
  const [retryToken, setRetryToken] = useState(0)

  const imageRef = useRef<HTMLImageElement>(null)
  const viewportRef = useRef<HTMLDivElement>(null)
  const contentRef = useRef<HTMLDivElement>(null)
  const panState = useRef<{ startX: number; startY: number; startScrollLeft: number; startScrollTop: number; dragging: boolean } | null>(null)
  // A scroll-position fixup queued by a zoom change, applied in a
  // `useLayoutEffect` right after the content layer's new real pixel size
  // has been painted — keeping the anchor point (cursor, viewport center, a
  // focused occurrence) visually still across the resize.
  const pendingScrollRef = useRef<(() => void) | null>(null)

  /*
   * A cached image can finish loading before React attaches `onLoad` — which is
   * exactly what happens on the way *back* to this screen, where the page image
   * is already in the browser's cache. The event never fires, and the overlay
   * sits on "Loading drawing…" over a picture that is right there. So the
   * element is asked directly, on mount and on every change of page or retry.
   */
  useEffect(() => {
    const image = imageRef.current

    // Not cached: the element's own onLoad/onError will answer. Reset first, so
    // a failure on the page just left does not sit over the one just opened.
    if (!image?.complete) {
      setImageStatus('loading')

      return
    }

    // Synchronising to a load that already happened, not deriving render state.
    setImageStatus(image.naturalWidth > 0 ? 'loaded' : 'error')

    if (image.naturalWidth > 0 && image.naturalHeight > 0) {
      setImageAspect(image.naturalWidth / image.naturalHeight)
    }
  }, [resultId, activePage, retryToken])

  const dims = pageDimensions[activePage]
  const aspect = dims ? dims.width / dims.height : (imageAspect ?? 1)
  const baseHeight = aspect > 0 ? viewportWidth / aspect : 0
  const contentWidth = viewportWidth * scale
  const contentHeight = baseHeight * scale

  // Resets the image's own load state whenever the page or result changes —
  // synchronizing local UI state to an external prop change, not deriving
  // state from render.
  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setImageStatus('loading')
    setImageAspect(null)
  }, [activePage, resultId])

  useEffect(() => {
    const node = viewportRef.current
    if (!node) return undefined

    const measure = () => setViewportWidth(node.clientWidth)
    measure()

    const observer = new ResizeObserver(measure)
    observer.observe(node)

    return () => observer.disconnect()
  }, [])

  // The content layer's real on-screen pixel size — every box's percentage
  // position resolves against it, and a drag's screen-pixel delta maps onto
  // it 1:1 (there's no separate `transform: scale()` to divide out). A pure
  // function of state already tracked above, not state of its own.
  const containerSize = contentWidth > 0 && contentHeight > 0 ? { width: contentWidth, height: contentHeight } : null

  // Runs a queued scroll-position fixup right after the content layer has
  // been laid out at its new size — e.g. after a zoom step, so the point
  // under the cursor (or the viewport center, or a focused occurrence)
  // stays visually put instead of jumping.
  useLayoutEffect(() => {
    pendingScrollRef.current?.()
    pendingScrollRef.current = null
  }, [contentWidth, contentHeight])

  const boxes = useMemo<DrawableBox[]>(() => {
    const list: DrawableBox[] = []

    for (const symbol of overlaySymbols) {
      if (symbol.occurrences && symbol.occurrences.length > 0) {
        for (const occurrence of symbol.occurrences) {
          if (occurrence.page !== activePage) continue

          list.push({
            key: `${symbol.id}-${occurrence.key}-${occurrence.bbox.join(',')}`,
            reviewId: symbol.id,
            name: symbol.name,
            finalCount: symbol.finalCount,
            bbox: occurrence.bbox,
            status: occurrence.status,
            occurrenceKey: occurrence.key,
            occurrenceOrigin: occurrence.origin ?? 'ai',
          })
        }

        continue
      }

      if (symbol.page === activePage && symbol.bbox) {
        list.push({
          key: `${symbol.id}`,
          reviewId: symbol.id,
          name: symbol.name,
          finalCount: symbol.finalCount,
          bbox: symbol.bbox,
          status: symbol.status,
        })
      }
    }

    return list
  }, [overlaySymbols, activePage])

  const filteredBoxes = useMemo(
    () =>
      boxes.filter((box) => {
        // Normalised, like every other name comparison here: the engine sends
        // the same category in different cases.
        if (activeCategory && categoryKey(box.name) !== categoryKey(activeCategory)) {
          return false
        }
        if (activeStatus !== 'all' && box.status !== activeStatus) return false

        return true
      }),
    [boxes, activeCategory, activeStatus],
  )

  const filterActive = activeCategory !== null || activeStatus !== 'all'

  const symbolsOnPageFiltered = useMemo(
    () => overlaySymbols.filter((symbol) => boxes.some((box) => box.reviewId === symbol.id)),
    [overlaySymbols, boxes],
  )

  /*
   * A run with no real per-occurrence boxes (every symbol's `page` and
   * `occurrences` are both null — the shape a DB-backed/synced takeoff
   * dataset produces, since it only has aggregate counts, not detected
   * positions) never has anything to place on any page, so the per-page
   * filter above always comes back empty — not because a page genuinely
   * has nothing on it, but because there is no page data anywhere in this
   * result to filter by. The legend falls back to the drawing's full
   * symbol list in that case, same names the review grid below already
   * lists; a run with real page data is never affected, since some symbol
   * somewhere will have a real page/occurrence and this stays false.
   */
  const hasAnyPageData = useMemo(
    () => overlaySymbols.some((symbol) => symbol.page !== null || (symbol.occurrences?.length ?? 0) > 0),
    [overlaySymbols],
  )

  const symbolsOnPage = hasAnyPageData ? symbolsOnPageFiltered : overlaySymbols

  // Rejected occurrences are excluded — the legend's count is what's actually
  // kept, so rejecting or reinstating a marker updates its category's number
  // immediately, the same instant the marker's own outline changes color.
  const countsByName = useMemo(() => {
    const counts = new Map<string, number>()

    if (hasAnyPageData) {
      for (const box of boxes) {
        if (box.status === 'rejected') continue

        const key = box.name.trim().toLowerCase()
        counts.set(key, (counts.get(key) ?? 0) + 1)
      }

      return counts
    }

    // No per-occurrence boxes to count — the reviewed count per symbol
    // stands in, the same figure the card grid shows for each row.
    for (const symbol of overlaySymbols) {
      if (symbol.status === 'rejected') continue

      const key = symbol.name.trim().toLowerCase()
      counts.set(key, (counts.get(key) ?? 0) + symbol.finalCount)
    }

    return counts
  }, [boxes, hasAnyPageData, overlaySymbols])

  /* --------------------------------------------------------------- zoom/pan */

  /**
   * Changes the zoom level while keeping one screen point — the cursor, the
   * viewport's own center, a focused occurrence — visually anchored. The
   * viewport is a real scrollable element now (`overflow: auto`), so
   * "anchoring" just means: work out what fraction of the content that
   * point currently sits at, change the content's real pixel size, then set
   * `scrollLeft`/`scrollTop` so that same fraction lines back up under the
   * same screen point. The actual scroll assignment is deferred to the
   * `useLayoutEffect` above, since it has to happen after the content layer
   * repaints at its new size.
   */
  const applyZoom = (nextScale: number, anchorClientX: number, anchorClientY: number) => {
    const viewport = viewportRef.current
    const clamped = clampScale(nextScale)

    if (!viewport || viewportWidth <= 0 || contentWidth <= 0 || contentHeight <= 0) {
      setScale(clamped)
      return
    }

    const rect = viewport.getBoundingClientRect()
    const anchorX = anchorClientX - rect.left
    const anchorY = anchorClientY - rect.top
    const ratioX = (viewport.scrollLeft + anchorX) / contentWidth
    const ratioY = (viewport.scrollTop + anchorY) / contentHeight
    const nextContentWidth = viewportWidth * clamped
    const nextContentHeight = baseHeight * clamped

    pendingScrollRef.current = () => {
      viewport.scrollLeft = ratioX * nextContentWidth - anchorX
      viewport.scrollTop = ratioY * nextContentHeight - anchorY
    }
    setScale(clamped)
  }

  const viewportCenter = (): [number, number] => {
    const rect = viewportRef.current?.getBoundingClientRect()

    return rect ? [rect.left + rect.width / 2, rect.top + rect.height / 2] : [0, 0]
  }

  const stepZoom = (direction: 1 | -1) => {
    const next = direction === 1
      ? ZOOM_STEPS.find((step) => step > scale + 0.001) ?? MAX_SCALE
      : [...ZOOM_STEPS].reverse().find((step) => step < scale - 0.001) ?? MIN_SCALE
    const [cx, cy] = viewportCenter()
    applyZoom(next, cx, cy)
  }

  const scrollToOrigin = () => {
    const viewport = viewportRef.current
    if (!viewport) return
    pendingScrollRef.current = () => {
      viewport.scrollLeft = 0
      viewport.scrollTop = 0
    }
  }

  const resetZoom = () => {
    scrollToOrigin()
    setScale(1)
  }

  const fitToWidth = () => {
    scrollToOrigin()
    setScale(1)
  }

  const fitToPage = () => {
    const viewport = viewportRef.current
    if (!viewport || !dims || viewportWidth <= 0) return resetZoom()

    const nextScale = clampScale(Math.min(1, viewport.clientHeight / baseHeight))
    scrollToOrigin()
    setScale(nextScale)
  }

  const handleWheel = (event: React.WheelEvent<HTMLDivElement>) => {
    // Trackpad pinch (and an explicit ctrl/cmd+wheel) zooms; a plain wheel
    // is left alone entirely — the viewport is a real scrollable element,
    // so the browser's own native scrolling handles it, in both directions,
    // with no custom pan math to get subtly wrong on some device.
    if (!event.ctrlKey) return

    event.preventDefault()
    const step = event.deltaY * -0.01
    applyZoom(scale * (1 + step), event.clientX, event.clientY)
  }

  const handleDoubleClick = (event: React.MouseEvent<HTMLDivElement>) => {
    const target = event.target as HTMLElement
    if (target.closest('[data-symbol-box]')) return

    applyZoom(scale >= 1.9 ? 1 : 2, event.clientX, event.clientY)
  }

  /* ----------------------------------------------------- pan / manual-add */

  const isEmptySpace = (target: HTMLElement) =>
    target === contentRef.current || target.tagName === 'IMG'

  const handlePointerDown = (event: ReactPointerEvent<HTMLDivElement>) => {
    if (!isEmptySpace(event.target as HTMLElement)) return

    const viewport = viewportRef.current
    panState.current = {
      startX: event.clientX,
      startY: event.clientY,
      startScrollLeft: viewport?.scrollLeft ?? 0,
      startScrollTop: viewport?.scrollTop ?? 0,
      dragging: false,
    }
    viewport?.setPointerCapture(event.pointerId)
  }

  const handlePointerMove = (event: ReactPointerEvent<HTMLDivElement>) => {
    const state = panState.current
    const viewport = viewportRef.current
    if (!state || !viewport) return

    const dx = event.clientX - state.startX
    const dy = event.clientY - state.startY

    if (!state.dragging && Math.hypot(dx, dy) < DRAG_CLICK_THRESHOLD) return

    state.dragging = true
    viewport.scrollLeft = state.startScrollLeft - dx
    viewport.scrollTop = state.startScrollTop - dy
  }

  const handlePointerUp = (event: ReactPointerEvent<HTMLDivElement>) => {
    const state = panState.current
    panState.current = null
    viewportRef.current?.releasePointerCapture(event.pointerId)

    if (!state || state.dragging || !dims || !contentRef.current) return

    // A real click (no drag) on empty space starts a manual-add draft.
    const rect = contentRef.current.getBoundingClientRect()
    const xPct = ((event.clientX - rect.left) / rect.width) * 100
    const yPct = ((event.clientY - rect.top) / rect.height) * 100

    if (xPct < 0 || xPct > 100 || yPct < 0 || yPct > 100) return

    onSelect(null)
    setDraft({ page: activePage, xPct, yPct })
  }

  const saveDraft = (name: string) => {
    if (!draft || !dims) return

    const boxWidth = dims.width * DEFAULT_BOX_FRACTION
    const boxHeight = dims.height * DEFAULT_BOX_FRACTION
    const bbox = [
      (draft.xPct / 100) * dims.width - boxWidth / 2,
      (draft.yPct / 100) * dims.height - boxHeight / 2,
      boxWidth,
      boxHeight,
    ]

    router.post(
      routeTo.symbolManualAdd(resultId),
      { page: draft.page, name, bbox },
      { preserveScroll: true, preserveState: true, onSuccess: () => setDraft(null) },
    )
  }

  /* ------------------------------------------------------ focus (card sync) */

  // Deliberately imperative: this effect exists to synchronize the drawing
  // to an external "command" prop (a card's "Show on drawing" click) —
  // exactly the "subscribe to an external system" case the underlying lint
  // rule carves out, not derived render state.
  useEffect(() => {
    if (!focusRequest) return

    // eslint-disable-next-line react-hooks/set-state-in-effect
    if (focusRequest.page !== activePage) setActivePage(focusRequest.page)

    const symbol = overlaySymbols.find((row) => row.id === focusRequest.reviewId)
    const occurrence = focusRequest.occurrenceKey
      ? symbol?.occurrences?.find((item) => item.key === focusRequest.occurrenceKey)
      : null
    const bbox = occurrence?.bbox ?? (symbol?.page === focusRequest.page ? symbol?.bbox : null)
    const size = pageDimensions[focusRequest.page]

    if (bbox && size) {
      const [x = 0, y = 0, w = 0, h = 0] = bbox
      const centerXPct = (x + w / 2) / size.width
      const centerYPct = (y + h / 2) / size.height
      const viewport = viewportRef.current

      if (viewport && viewportWidth > 0) {
        const targetScale = 1.75
        const nextContentWidth = viewportWidth * targetScale
        const nextContentHeight = (viewportWidth / (size.width / size.height)) * targetScale

        pendingScrollRef.current = () => {
          viewport.scrollLeft = centerXPct * nextContentWidth - viewport.clientWidth / 2
          viewport.scrollTop = centerYPct * nextContentHeight - viewport.clientHeight / 2
        }
        setScale(targetScale)
      }
    }

    onSelect(focusRequest)
    onFocusHandled()
    // Only reacts to a new focus request landing — activePage/overlaySymbols
    // are read fresh each time but must not themselves retrigger this.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [focusRequest])

  const draftColor = symbolColor('manual')
  const zoomPercent = Math.round(scale * 100)

  return (
    // `animated={false}`: Card's default whileInView fade only fires once the
    // element has scrolled into view, but this card sits right at the fold on
    // load — a reviewer would see nothing until they scrolled and back up.
    <Card accent="brand" padding="md" className="mb-6" animated={false}>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <SectionHeading
          as="h3"
          title="Drawing"
          subtitle="Every detected symbol, boxed where it actually sits on the page. Click a symbol to act on it, drag it to reposition, or click empty space to mark one the AI missed."
        />
        {/*
          The way in to the spatial viewer, switched off for now.

          Hidden, not removed: the route, the page and every `ThreeD*` component
          are still there and still tested, and `/reviews/{id}/3d` still opens if
          you go to it. Flip the flag above to put the button back.
        */}
        {SHOW_THREE_D_ENTRY && (
          <ButtonLink
            href={routeTo.reviewThreeD(resultId)}
            variant="secondary"
            size="sm"
            leftIcon={Box}
          >
            3D View
          </ButtonLink>
        )}
        <SelectField
          id="drawing-overlay-page"
          aria-label="Drawing page"
          className="sm:w-48"
          value={String(activePage)}
          onChange={(event) => {
            setDraft(null)
            onSelect(null)
            setActivePage(Number(event.target.value))
          }}
          options={Array.from({ length: pageCount }, (_, index) => {
            const page = index + 1
            return { value: String(page), label: `Page ${page}` }
          })}
        />
      </div>

      {/* Zoom + filter toolbar — every control has a visible, non-technical label. */}
      <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap items-center gap-1.5">
          <IconButton
            variant="secondary"
            size="sm"
            icon={Minus}
            label="Zoom out"
            onClick={() => stepZoom(-1)}
            disabled={scale <= MIN_SCALE}
          />
          <span className="w-12 text-center text-2xs text-white/80">{zoomPercent}%</span>
          <IconButton
            variant="secondary"
            size="sm"
            icon={Plus}
            label="Zoom in"
            onClick={() => stepZoom(1)}
            disabled={scale >= MAX_SCALE}
          />
          <IconButton variant="secondary" size="sm" icon={RotateCcw} label="Reset zoom" onClick={resetZoom} />
          <IconButton
            variant="secondary"
            size="sm"
            icon={StretchHorizontal}
            label="Fit to width"
            onClick={fitToWidth}
          />
          <IconButton variant="secondary" size="sm" icon={Maximize} label="Fit to page" onClick={fitToPage} />
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <FilterTabs
            options={[
              { label: 'All', value: 'all' as const },
              { label: 'Approved', value: 'approved' as const },
              { label: 'Rejected', value: 'rejected' as const },
            ]}
            value={activeStatus}
            onChange={setActiveStatus}
          />
          {filterActive && (
            <Button
              variant="ghost"
              size="sm"
              onClick={() => {
                setActiveCategory(null)
                setActiveStatus('all')
              }}
            >
              Clear filters
            </Button>
          )}
        </div>
      </div>

      <div className="grid gap-4 lg:grid-cols-[1fr_14rem]">
        <div
          ref={viewportRef}
          className="relative grid h-105 w-full place-items-center overflow-auto rounded-panel border border-hairline bg-white/5 select-none sm:h-140"
          onWheel={handleWheel}
          onDoubleClick={handleDoubleClick}
          onPointerDown={handlePointerDown}
          onPointerMove={handlePointerMove}
          onPointerUp={handlePointerUp}
        >
          <div
            ref={contentRef}
            className="relative min-h-0 min-w-0"
            style={{
              // Real CSS pixel size, not a `transform: scale()` — this is
              // what makes the viewport's native scrollbars (and native
              // wheel scrolling) work at all: a transform never changes an
              // element's layout size, so `overflow: auto` would never see
              // anything to scroll. `viewportWidth` is the "fit to width"
              // baseline; `contentHeight` falls back to the loaded image's
              // own aspect ratio when the engine's real page dimensions
              // haven't arrived yet — box math still only ever uses `dims`.
              width: viewportWidth > 0 ? contentWidth : '100%',
              height: contentHeight > 0 ? contentHeight : undefined,
              aspectRatio: contentHeight > 0 ? undefined : (dims ? `${dims.width} / ${dims.height}` : (imageAspect ?? undefined)),
            }}
          >
            <img
              ref={imageRef}
              key={`${resultId}-${activePage}-${retryToken}`}
              /*
               * The retry token is in the URL, not just the key. Re-requesting
               * the same address after a failure is answered from the browser's
               * cache with the same failure, so a Retry that only remounts the
               * element does nothing at all.
               */
              src={
                retryToken === 0
                  ? routeTo.reviewPage(resultId, activePage)
                  : `${routeTo.reviewPage(resultId, activePage)}?retry=${retryToken}`
              }
              alt={`Drawing page ${activePage}`}
              className="absolute inset-0 block size-full object-contain"
              draggable={false}
              onLoad={(event) => {
                const { naturalWidth, naturalHeight } = event.currentTarget
                if (naturalWidth > 0 && naturalHeight > 0) setImageAspect(naturalWidth / naturalHeight)
                setImageStatus('loaded')
              }}
              onError={() => setImageStatus('error')}
            />

            {dims &&
              filteredBoxes.map((box) => (
                <div key={box.key} data-symbol-box>
                  <SymbolBox
                    resultId={resultId}
                    reviewId={box.reviewId}
                    name={box.name}
                    finalCount={box.finalCount}
                    bbox={box.bbox}
                    page={activePage}
                    pageDimensions={dims}
                    status={box.status}
                    occurrenceKey={box.occurrenceKey}
                    occurrenceOrigin={box.occurrenceOrigin}
                    isSelected={
                      selected?.reviewId === box.reviewId
                      && selected?.occurrenceKey === box.occurrenceKey
                    }
                    onSelect={onSelect}
                    onHoverChange={setHoveredCategory}
                    containerSize={containerSize}
                    /*
                     * One map, no fallback. A `?? symbolColor(name)` here was a
                     * second mapping that could disagree with the legend's —
                     * the exact bug this map exists to prevent. `colors` is
                     * built from the drawing's own names, so a miss is not
                     * possible; if one ever were, an uncoloured box is a
                     * visible bug rather than a silently wrong colour.
                     */
                    color={colors.get(categoryKey(box.name)) ?? UNMAPPED_COLOR}
                  />
                </div>
              ))}

            {draft && draft.page === activePage && (
              <>
                <div
                  className="pointer-events-none absolute rounded-sm border-2 border-dashed"
                  style={{
                    left: `${draft.xPct}%`,
                    top: `${draft.yPct}%`,
                    width: `${DEFAULT_BOX_FRACTION * 100}%`,
                    height: `${DEFAULT_BOX_FRACTION * 100}%`,
                    transform: 'translate(-50%, -50%)',
                    // On the page, so the paper colour — see `symbolColor`.
                    borderColor: draftColor.onPaper,
                    backgroundColor: draftColor.fill,
                  }}
                />
                <ManualAddPopover
                  anchorStyle={{
                    left: `${draft.xPct}%`,
                    top: `${draft.yPct}%`,
                    transform: 'translate(-50%, calc(-100% - 12px))',
                  }}
                  distinctNames={distinctNames}
                  onCancel={() => setDraft(null)}
                  onSave={saveDraft}
                />
              </>
            )}

            {imageStatus === 'loading' && (
              <div className="absolute inset-0 grid place-items-center bg-navy-900/60 text-center text-2xs text-white/75">
                Loading drawing…
              </div>
            )}

            {imageStatus === 'error' && (
              <div className="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-navy-900/80 text-center text-2xs text-white/85">
                <p>Unable to load this drawing page.</p>
                <Button
                  variant="secondary"
                  size="sm"
                  onClick={() => {
                    setImageStatus('loading')
                    setRetryToken((token) => token + 1)
                  }}
                >
                  Retry
                </Button>
              </div>
            )}

            {imageStatus === 'loaded' && !dims && (
              <div className="pointer-events-none absolute inset-x-0 bottom-0 bg-navy-900/85 px-3 py-1.5 text-center text-2xs text-white/80">
                Page dimensions aren't available yet for this page, so symbols can't be positioned here. They'll appear once the takeoff finishes catching up.
              </div>
            )}
          </div>
        </div>

        <div className="min-h-0">
          <p className="mb-2 text-2xs tracking-wide text-white/75 uppercase">
            Legend ({symbolsOnPage.length})
          </p>
          <SymbolLegend
            symbols={symbolsOnPage}
            countsByName={countsByName}
            activeCategory={activeCategory}
            onSelectCategory={setActiveCategory}
            hoveredCategory={hoveredCategory}
            colors={colors}
          />
        </div>
      </div>
    </Card>
  )
}
