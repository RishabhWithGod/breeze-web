import { Link, router, usePage } from '@inertiajs/react'
import { useCallback, useEffect, useRef, useState } from 'react'
import { AnimatePresence, motion, useDragControls } from 'framer-motion'
import { ArrowRight, CirclePlay, GripVertical, X } from 'lucide-react'
import { ROUTES } from '@/constants'
import type { SharedPageProps } from '@/types'

/**
 * The way back into a takeoff you stepped out of.
 *
 * The flow runs over several screens and often several days, and in the middle
 * of it people go and look at an invoice. Without this, finding the way back
 * means remembering which takeoff it was and which screen it had reached.
 *
 * It only appears once you have left the flow — on the flow's own screens the
 * roadmap at the top already says where you are — and it goes for good once
 * the job's tasks are saved, because there is nothing left to resume.
 *
 * Where it points is worked out from the takeoff every time, not stored, so
 * signing the review off from somewhere else moves the button on with it.
 *
 * ---
 *
 * It is the estimate sections' own card, shrunk: the same two-pixel border with
 * a thicker lit edge, the same icon bubble heading it. Looking like the rest of
 * the product is what makes it look designed.
 *
 * Several louder versions came first and were worse. A solid fill stops reading
 * as a card at this size and starts reading as a banner. Tinted outlines all the
 * way down — outlined card, outlined icon, outlined arrow — are three ghosts and
 * no anchor. A coloured glow and a breathing halo read as a light source behind
 * the page rather than a card sitting on it.
 *
 * Orange because it is the one colour no action in this app uses — the brand
 * cyan is on every button already — and it means what this is: something left
 * unfinished. Red would read as an error, green as work already done.
 */
const STORAGE_KEY = 'breeze.resume.offset'

/** How far the card has been dragged from its default spot (px, right/down positive). */
type Offset = { x: number; y: number }

const readOffset = (): Offset => {
  try {
    const saved = JSON.parse(window.localStorage.getItem(STORAGE_KEY) ?? 'null') as Offset | null

    if (saved && Number.isFinite(saved.x) && Number.isFinite(saved.y)) return saved
  } catch {
    /* storage blocked — the default spot is fine */
  }

  return { x: 0, y: 0 }
}

export function ResumeTakeoffButton() {
  const { takeoffFlow } = usePage<SharedPageProps>().props
  const card = useRef<HTMLDivElement>(null)
  const controls = useDragControls()
  const [offset, setOffset] = useState<Offset>({ x: 0, y: 0 })

  // Where the user last left it. Until they move it, it stays at its default spot.
  useEffect(() => setOffset(readOffset()), [])

  /** Pulls the card back inside the window — a resize or a smaller screen must never strand it off-screen. */
  const keepOnScreen = useCallback(() => {
    const el = card.current
    if (!el) return

    const box = el.getBoundingClientRect()
    const margin = 8
    const dx = Math.min(0, window.innerWidth - margin - box.right) + Math.max(0, margin - box.left)
    const dy = Math.min(0, window.innerHeight - margin - box.bottom) + Math.max(0, margin - box.top)

    if (dx !== 0 || dy !== 0) setOffset((current) => ({ x: current.x + dx, y: current.y + dy }))
  }, [])

  useEffect(() => {
    keepOnScreen()
    window.addEventListener('resize', keepOnScreen)

    return () => window.removeEventListener('resize', keepOnScreen)
  }, [keepOnScreen, takeoffFlow])

  const save = (next: Offset) => {
    setOffset(next)

    try {
      window.localStorage.setItem(STORAGE_KEY, JSON.stringify(next))
    } catch {
      /* not persisted this time */
    }
  }

  const reset = () => {
    setOffset({ x: 0, y: 0 })

    try {
      window.localStorage.removeItem(STORAGE_KEY)
    } catch {
      /* nothing to clear */
    }
  }

  return (
    <AnimatePresence>
      {takeoffFlow && (
        // Default spot: bottom-right, sitting just above Breezy (the robot is 150px tall) so the two
        // never cover each other. Dragging moves it from there, and the new spot is remembered.
        <motion.div
          key="resume-takeoff"
          ref={card}
          initial={{ opacity: 0, y: 20, scale: 0.96 }}
          animate={{ opacity: 1, x: offset.x, y: offset.y, scale: 1 }}
          exit={{ opacity: 0, scale: 0.96 }}
          transition={{ duration: 0.25, ease: [0.22, 1, 0.36, 1] }}
          drag
          dragControls={controls}
          dragListener={false}
          dragMomentum={false}
          dragElastic={0}
          onDragEnd={(_, info) => {
            save({ x: offset.x + info.offset.x, y: offset.y + info.offset.y })
            requestAnimationFrame(keepOnScreen)
          }}
          className="fixed right-4 bottom-[178px] z-[55] max-w-[calc(100vw-2rem)] sm:right-6 max-sm:bottom-[122px]"
        >
          <div className="group glass relative flex items-stretch overflow-hidden rounded-card border border-status-warning/45 shadow-[0_18px_40px_-22px_rgb(0_0_0/0.5)] transition-colors duration-200 hover:border-status-warning/75">
            <span
              aria-hidden
              className="pointer-events-none absolute -top-10 -left-10 size-32 rounded-full bg-[radial-gradient(circle,rgb(245_158_11/0.22),transparent_70%)]"
            />

            {/* The only part that starts a drag, so the link and the close button keep behaving as they do. */}
            <button
              type="button"
              aria-label="Drag to move — double-click to put it back"
              title="Drag to move · double-click to reset"
              onPointerDown={(event) => controls.start(event)}
              onDoubleClick={reset}
              className="relative flex w-6 shrink-0 cursor-grab touch-none items-center justify-center text-[var(--text-3)] transition-colors hover:text-white active:cursor-grabbing"
            >
              <GripVertical size={15} aria-hidden />
            </button>

            <Link href={takeoffFlow.resumeUrl} className="relative flex min-w-0 items-stretch">
              <span className="flex min-w-0 items-center gap-3 py-2.5 pr-3 pl-1">
                <span className="grid size-10 shrink-0 place-items-center rounded-[12px] bg-[var(--warn-bg)] text-[var(--warn-text)]">
                  <CirclePlay size={20} aria-hidden />
                </span>

                <span className="min-w-0">
                  <span className="block text-[11px] font-semibold tracking-[0.06em] text-[var(--text-3)] uppercase">
                    Resume · {takeoffFlow.stage}
                  </span>
                  <span className="mt-0.5 block max-w-52 truncate text-[14px] font-semibold text-white">
                    {takeoffFlow.projectName}
                  </span>
                </span>

                <span className="grid size-7 shrink-0 place-items-center rounded-full bg-[var(--surface-3)] text-[var(--text-2)] transition-colors group-hover:bg-status-warning group-hover:text-navy-950">
                  <ArrowRight size={15} aria-hidden />
                </span>
              </span>
            </Link>

            {/*
              Dismissing hides the reminder, not the takeoff — opening any of
              the flow's screens brings it straight back.
            */}
            <button
              type="button"
              aria-label="Hide this until I open the takeoff again"
              onClick={() => router.delete(ROUTES.takeoffFlowForget, { preserveScroll: true })}
              className="relative border-l border-hairline px-2.5 text-[var(--text-3)] transition-colors hover:bg-[var(--surface-3)] hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-status-warning/60 focus-visible:ring-inset"
            >
              <X size={14} aria-hidden />
            </button>
          </div>
        </motion.div>
      )}
    </AnimatePresence>
  )
}
