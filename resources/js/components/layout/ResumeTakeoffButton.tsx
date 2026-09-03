import { Link, router, usePage } from '@inertiajs/react'
import { AnimatePresence, motion } from 'framer-motion'
import { ArrowRight, X } from 'lucide-react'
import { IconButton } from '@/components/common'
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
 */
export function ResumeTakeoffButton() {
  const { takeoffFlow } = usePage<SharedPageProps>().props

  return (
    <AnimatePresence>
      {takeoffFlow && (
        <motion.div
          key="resume-takeoff"
          initial={{ opacity: 0, y: 16 }}
          animate={{ opacity: 1, y: 0 }}
          exit={{ opacity: 0, y: 16 }}
          transition={{ duration: 0.2 }}
          className="fixed right-4 bottom-4 z-90 flex max-w-[calc(100vw-2rem)] items-center gap-2 rounded-card border border-brand/50 bg-navy-900/95 p-2 pl-4 shadow-panel backdrop-blur-xl sm:right-6 sm:bottom-6"
        >
          <Link
            href={takeoffFlow.resumeUrl}
            className="group flex min-w-0 items-center gap-3"
          >
            <span className="min-w-0">
              <span className="block text-2xs tracking-wide text-white/70 uppercase">
                Resume takeoff · {takeoffFlow.stage}
              </span>
              <span className="block truncate text-md font-semibold text-white transition-colors group-hover:text-brand">
                {takeoffFlow.projectName}
              </span>
            </span>
            <span className="grid size-9 shrink-0 place-items-center rounded-full bg-brand text-brand-ink transition-transform group-hover:translate-x-0.5">
              <ArrowRight size={18} aria-hidden />
            </span>
          </Link>

          {/*
            Dismissing hides the reminder, not the takeoff — opening any of the
            flow's screens brings it straight back.
          */}
          <IconButton
            icon={X}
            label="Hide this until I open the takeoff again"
            variant="white"
            size="sm"
            className="shrink-0"
            onClick={() =>
              router.delete(ROUTES.takeoffFlowForget, { preserveScroll: true })
            }
          />
        </motion.div>
      )}
    </AnimatePresence>
  )
}
