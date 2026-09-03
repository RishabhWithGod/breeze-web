import { Alert, ButtonLink } from '@/components/common'
import type { ResumableTakeoff } from '@/types'

export interface UnfinishedTakeoffNoticeProps {
  /** Null when nothing is on the go — the notice then draws nothing. */
  takeoff: ResumableTakeoff | null
  /** What this screen is about to start, named in the sentence. */
  starting: string
  className?: string
}

/**
 * Said on every screen that can start a takeoff off.
 *
 * Starting a second one is a normal thing to do. Doing it by accident, and
 * losing track of the first, is not — so the screens that fork the flow say
 * what is already running and offer the way back to it before anything is
 * created.
 */
export function UnfinishedTakeoffNotice({
  takeoff,
  starting,
  className,
}: UnfinishedTakeoffNoticeProps) {
  if (!takeoff) return null

  return (
    <Alert
      tone="warning"
      title="You have a takeoff on the go"
      {...(className ? { className } : {})}
    >
      <span className="flex flex-wrap items-center gap-3">
        “{takeoff.projectName}” is waiting at {takeoff.stage}. Starting {starting} leaves
        it where it is.
        <ButtonLink href={takeoff.resumeUrl} variant="secondary" size="sm">
          Resume it
        </ButtonLink>
      </span>
    </Alert>
  )
}
