import { Link, router, usePage } from '@inertiajs/react'
import { AnimatePresence, motion } from 'framer-motion'
import { ArrowRight, CirclePlay, X } from 'lucide-react'
import { IconBubble } from '@/components/common'
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
export function ResumeTakeoffButton() {
  const { takeoffFlow } = usePage<SharedPageProps>().props

  return (
    <AnimatePresence>
      {takeoffFlow && (
        <motion.div
          key="resume-takeoff"
          initial={{ opacity: 0, y: 20, scale: 0.96 }}
          animate={{ opacity: 1, y: 0, scale: 1 }}
          exit={{ opacity: 0, y: 20, scale: 0.96 }}
          transition={{ duration: 0.25, ease: [0.22, 1, 0.36, 1] }}
          className="fixed right-4 bottom-4 z-90 max-w-[calc(100vw-2rem)] sm:right-6 sm:bottom-6"
        >
          <div
            className={[
              'group flex items-stretch overflow-hidden rounded-card',
              // The same border the estimate sections wear: two pixels round,
              // six down the lit edge. Orange, because it is the one colour no
              // action in this app uses — and it means what this is, something
              // left unfinished.
              'border-2 border-l-[6px] border-status-warning/55 border-l-status-warning',
              'bg-navy-900/95 shadow-panel backdrop-blur-xl',
              'transition-colors duration-200 hover:border-status-warning/80',
            ].join(' ')}
          >
            <Link
              href={takeoffFlow.resumeUrl}
              className="flex min-w-0 items-stretch"
            >
              <span className="flex min-w-0 items-center gap-3 py-2.5 pr-3 pl-3.5">
                {/* The same bubble the estimate sections head themselves with,
                    so this reads as one of them rather than as a stray widget. */}
                <IconBubble icon={CirclePlay} tone="warning" size="sm" />

                <span className="min-w-0">
                  <span className="block text-2xs font-medium tracking-wider text-white/55 uppercase">
                    Resume · {takeoffFlow.stage}
                  </span>
                  <span className="mt-0.5 block truncate text-md font-semibold text-white">
                    {takeoffFlow.projectName}
                  </span>
                </span>

                {/* Quiet until asked: the lit edge is already saying "go here". */}
                <ArrowRight
                  size={17}
                  aria-hidden
                  className="shrink-0 text-white/55 transition-all duration-200 group-hover:translate-x-0.5 group-hover:text-status-warning"
                />
              </span>
            </Link>

            {/*
              Dismissing hides the reminder, not the takeoff — opening any of
              the flow's screens brings it straight back.

              Behind its own hairline, so it reads as a separate action rather
              than part of the link it sits against.
            */}
            <button
              type="button"
              aria-label="Hide this until I open the takeoff again"
              onClick={() =>
                router.delete(ROUTES.takeoffFlowForget, { preserveScroll: true })
              }
              className="border-l border-hairline px-2.5 text-white/40 transition-colors hover:bg-white/8 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-status-warning/60 focus-visible:ring-inset"
            >
              <X size={14} aria-hidden />
            </button>
          </div>
        </motion.div>
      )}
    </AnimatePresence>
  )
}
