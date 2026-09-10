import { useId, useState } from 'react'
import { AnimatePresence, motion } from 'framer-motion'
import { ChevronDown, ClipboardList } from 'lucide-react'
import { cardAccent, IconBubble } from '@/components/common'
import type { JobTaskSummary } from '@/types'
import { cn, formatFileSize, formatRelative } from '@/utils'

export interface JobTaskFieldNotesPanelProps {
  tasks: readonly JobTaskSummary[]
}

/**
 * What the crew left behind on site — notes and photos added from the
 * mobile app's own Materials screen, grouped by the task they belong to.
 *
 * Read-only: these came from the field, through `job_task_comments`/
 * `job_task_attachments`, the same tables the mobile API writes to and the
 * web's own task-comment box (`JobTaskController::comment()`) already
 * shares. Only tasks that actually have something show up here — a job
 * with none renders nothing (`JobShow.tsx` hides the whole card in that
 * case), rather than a wall of empty task rows.
 */
export function JobTaskFieldNotesPanel({ tasks }: JobTaskFieldNotesPanelProps) {
  const withDetails = tasks.filter(
    (task) => task.comments.length > 0 || task.attachments.length > 0,
  )
  // The first one open by default — there is rarely more than a couple of
  // tasks with anything to show, and starting collapsed would make a
  // reviewer open every row just to see whether it's worth reading.
  const [openTaskId, setOpenTaskId] = useState<number | null>(
    withDetails[0]?.id ?? null,
  )

  if (withDetails.length === 0) return null

  return (
    <div className="space-y-3">
      {withDetails.map((task) => (
        <TaskRow
          key={task.id}
          task={task}
          isOpen={openTaskId === task.id}
          onToggle={() => setOpenTaskId(openTaskId === task.id ? null : task.id)}
        />
      ))}
    </div>
  )
}

interface TaskRowProps {
  task: JobTaskSummary
  isOpen: boolean
  onToggle: () => void
}

/**
 * One task's row — no accent border of its own (that belongs to the card
 * around the whole section); the tint that marks something as "from the
 * field" lives on each note below instead, not on this outer shell.
 */
function TaskRow({ task, isOpen, onToggle }: TaskRowProps) {
  const panelId = useId()
  const summary = [
    task.comments.length > 0 &&
      `${task.comments.length} ${task.comments.length === 1 ? 'note' : 'notes'}`,
    task.attachments.length > 0 &&
      `${task.attachments.length} ${task.attachments.length === 1 ? 'photo' : 'photos'}`,
  ]
    .filter(Boolean)
    .join(' · ')

  return (
    <div className="overflow-hidden rounded-panel bg-white/4">
      <button
        type="button"
        onClick={onToggle}
        aria-expanded={isOpen}
        aria-controls={panelId}
        className="flex w-full items-center gap-4 px-5 py-4 text-left transition-colors hover:bg-white/6"
      >
        <IconBubble icon={ClipboardList} tone="brand" size="sm" />

        <span className="min-w-0 flex-1">
          <span className="block truncate font-semibold text-white">{task.title}</span>
          {summary && (
            <span className="mt-0.5 block truncate text-sm text-white/65">{summary}</span>
          )}
        </span>

        <ChevronDown
          size={20}
          aria-hidden
          className={cn(
            'shrink-0 text-white/70 transition-transform duration-200',
            isOpen && 'rotate-180',
          )}
        />
      </button>

      <AnimatePresence initial={false}>
        {isOpen && (
          <motion.div
            id={panelId}
            initial={{ height: 0, opacity: 0 }}
            animate={{ height: 'auto', opacity: 1 }}
            exit={{ height: 0, opacity: 0 }}
            transition={{ duration: 0.2 }}
            className="overflow-hidden"
          >
            <div className="space-y-6 px-5 pb-5">
              {task.comments.length > 0 && (
                <div>
                  <p className="mb-2.5 text-2xs font-semibold tracking-wide text-white/70 uppercase">
                    Notes
                  </p>
                  <ul className="space-y-3">
                    {task.comments.map((note) => (
                      <li
                        key={note.id}
                        className={cn('rounded-panel bg-white/6 p-4', cardAccent('info'))}
                      >
                        <p className="text-md whitespace-pre-line text-white/90">
                          {note.body}
                        </p>
                        <p className="mt-2 text-sm text-white/70">
                          {note.author} · {formatRelative(note.createdAt)}
                        </p>
                      </li>
                    ))}
                  </ul>
                </div>
              )}

              {task.attachments.length > 0 && (
                <div>
                  <p className="mb-2.5 text-2xs font-semibold tracking-wide text-white/70 uppercase">
                    Photos
                  </p>
                  <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
                    {task.attachments.map((photo) => (
                      <a
                        key={photo.id}
                        href={photo.url}
                        target="_blank"
                        rel="noreferrer"
                        className="group overflow-hidden rounded-panel bg-white/6 transition-colors hover:ring-1 hover:ring-brand/60"
                      >
                        <div className="aspect-square overflow-hidden bg-navy-950/40">
                          <img
                            src={photo.url}
                            alt={photo.name}
                            loading="lazy"
                            className="size-full object-cover transition-transform duration-200 group-hover:scale-105"
                          />
                        </div>
                        <div className="px-2.5 py-2">
                          <p className="truncate text-2xs text-white/80">
                            {photo.uploadedBy}
                          </p>
                          <p className="truncate text-2xs text-white/55">
                            {formatFileSize(photo.sizeBytes)} ·{' '}
                            {formatRelative(photo.createdAt)}
                          </p>
                        </div>
                      </a>
                    ))}
                  </div>
                </div>
              )}
            </div>
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  )
}
