import { motion } from 'framer-motion'
import {
  Archive,
  ArchiveRestore,
  ClipboardCheck,
  Copy,
  FilePlus2,
  FileText,
  PencilLine,
  Plus,
  Repeat,
  StickyNote,
  Trash2,
  UserMinus,
  UserPlus,
  type LucideIcon,
} from 'lucide-react'
import type { JobActivity, Tone } from '@/types'
import { TONE_DOT_CLASS, cn, formatRelative } from '@/utils'

/** Icon + tone per activity type, falling back to a neutral marker. */
const APPEARANCE: Record<string, { icon: LucideIcon; tone: Tone }> = {
  created: { icon: Plus, tone: 'success' },
  updated: { icon: PencilLine, tone: 'info' },
  status_changed: { icon: Repeat, tone: 'brand' },
  archived: { icon: Archive, tone: 'warning' },
  unarchived: { icon: ArchiveRestore, tone: 'info' },
  duplicated: { icon: Copy, tone: 'info' },
  deleted: { icon: Trash2, tone: 'danger' },
  restored: { icon: ArchiveRestore, tone: 'success' },
  team_assigned: { icon: UserPlus, tone: 'success' },
  team_removed: { icon: UserMinus, tone: 'warning' },
  note_added: { icon: StickyNote, tone: 'info' },
  note_deleted: { icon: StickyNote, tone: 'warning' },
  attachment_added: { icon: FilePlus2, tone: 'info' },
  attachment_deleted: { icon: FileText, tone: 'warning' },
  estimate_created: { icon: FileText, tone: 'brand' },
  estimate_converted: { icon: ClipboardCheck, tone: 'success' },
}

const FALLBACK = { icon: ClipboardCheck, tone: 'neutral' as Tone }

export interface JobActivityTimelineProps {
  activities: readonly JobActivity[]
  className?: string
}

/** Vertical audit trail of everything that has happened to a job. */
export function JobActivityTimeline({ activities, className }: JobActivityTimelineProps) {
  if (activities.length === 0) {
    return <p className="text-md text-white/50">No activity recorded yet.</p>
  }

  return (
    <ol className={cn('relative', className)}>
      {activities.map((activity, index) => {
        const { icon: Icon, tone } = APPEARANCE[activity.type] ?? FALLBACK
        const isLast = index === activities.length - 1

        return (
          <motion.li
            key={activity.id}
            initial={{ opacity: 0, x: -8 }}
            animate={{ opacity: 1, x: 0 }}
            transition={{ duration: 0.25, delay: Math.min(index, 8) * 0.04 }}
            className="relative flex gap-4 pb-5 last:pb-0"
          >
            {/* Connector */}
            {!isLast && (
              <span
                aria-hidden
                className="absolute top-9 left-3.75 h-[calc(100%-1.5rem)] w-px bg-hairline"
              />
            )}

            <span
              className={cn(
                'relative grid size-8 shrink-0 place-items-center rounded-full bg-navy-950/60 ring-1 ring-hairline-strong',
              )}
            >
              <Icon size={15} aria-hidden className="text-white/85" />
              <span
                aria-hidden
                className={cn(
                  'absolute -right-0.5 -bottom-0.5 size-2.5 rounded-full ring-2 ring-navy-950',
                  TONE_DOT_CLASS[tone],
                )}
              />
            </span>

            <div className="min-w-0 flex-1 pt-1">
              <p className="text-md text-white">{activity.description}</p>
              <p className="mt-0.5 text-sm text-white/45">
                {activity.actor} · {formatRelative(activity.createdAt)}
              </p>
            </div>
          </motion.li>
        )
      })}
    </ol>
  )
}
