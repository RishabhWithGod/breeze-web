import { useId, useState } from 'react'
import { AnimatePresence, motion } from 'framer-motion'
import { ChevronDown, ClipboardList, Package } from 'lucide-react'
import { cardAccent, IconBubble } from '@/components/common'
import type { JobTaskMaterialLine, JobTaskSummary } from '@/types'
import { cn, formatFileSize, formatRelative } from '@/utils'

export interface JobTaskFieldNotesPanelProps {
  tasks: readonly JobTaskSummary[]
}

/** Whether a material line has anything worth its own row. */
function hasFieldDetails(line: JobTaskMaterialLine): boolean {
  return line.comments.length > 0 || line.attachments.length > 0
}

/**
 * What the crew left behind on site — notes and photos added from the
 * mobile app's own Materials screen, grouped by the task they belong to
 * and then by the material line within it.
 *
 * Read-only. Per-material notes/photos (`estimate_item_comments`/
 * `estimate_item_attachments`) are what the mobile app actually writes to
 * now; each task's own `comments`/`attachments` (`job_task_comments`/
 * `job_task_attachments`) are legacy — still shown if a task has some from
 * before that change, so nothing recorded on site goes missing here. Only
 * tasks with something at either level show up — a job with none renders
 * nothing (`JobShow.tsx` hides the whole card in that case), rather than a
 * wall of empty rows.
 */
export function JobTaskFieldNotesPanel({ tasks }: JobTaskFieldNotesPanelProps) {
  const withDetails = tasks.filter(
    (task) =>
      task.comments.length > 0 ||
      task.attachments.length > 0 ||
      task.materialLines.some(hasFieldDetails),
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
  const materialsWithDetails = task.materialLines.filter(hasFieldDetails)
  const summary = [
    task.comments.length > 0 &&
      `${task.comments.length} ${task.comments.length === 1 ? 'note' : 'notes'}`,
    task.attachments.length > 0 &&
      `${task.attachments.length} ${task.attachments.length === 1 ? 'photo' : 'photos'}`,
    materialsWithDetails.length > 0 &&
      `${materialsWithDetails.length} ${materialsWithDetails.length === 1 ? 'material' : 'materials'}`,
  ]
    .filter(Boolean)
    .join(' · ')

  // Multiple materials under one task can each be open at once — unlike
  // the outer task list (one at a time is enough there), a reviewer
  // comparing two materials' photos side by side shouldn't have to
  // re-expand the first one after opening the second.
  const [openMaterialIds, setOpenMaterialIds] = useState<ReadonlySet<number>>(
    () => new Set(materialsWithDetails[0] ? [materialsWithDetails[0].id] : []),
  )
  const toggleMaterial = (id: number) =>
    setOpenMaterialIds((current) => {
      const next = new Set(current)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })

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
              {(task.comments.length > 0 || task.attachments.length > 0) && (
                <FieldNotesAndPhotos
                  comments={task.comments}
                  attachments={task.attachments}
                />
              )}

              {materialsWithDetails.length > 0 && (
                <div>
                  <p className="mb-2.5 text-2xs font-semibold tracking-wide text-white/70 uppercase">
                    Materials
                  </p>
                  <div className="space-y-2.5">
                    {materialsWithDetails.map((line) => (
                      <MaterialRow
                        key={line.id}
                        line={line}
                        isOpen={openMaterialIds.has(line.id)}
                        onToggle={() => toggleMaterial(line.id)}
                      />
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

interface MaterialRowProps {
  line: JobTaskMaterialLine
  isOpen: boolean
  onToggle: () => void
}

/**
 * One material's own row, nested inside its task — a smaller echo of
 * {@link TaskRow} one indent level in, so the task → material hierarchy
 * reads at a glance without a reviewer having to track it themselves.
 */
function MaterialRow({ line, isOpen, onToggle }: MaterialRowProps) {
  const panelId = useId()
  const summary = [
    line.comments.length > 0 &&
      `${line.comments.length} ${line.comments.length === 1 ? 'note' : 'notes'}`,
    line.attachments.length > 0 &&
      `${line.attachments.length} ${line.attachments.length === 1 ? 'photo' : 'photos'}`,
  ]
    .filter(Boolean)
    .join(' · ')

  return (
    <div className="ml-3 overflow-hidden rounded-panel border-l-2 border-brand/30 bg-white/3">
      <button
        type="button"
        onClick={onToggle}
        aria-expanded={isOpen}
        aria-controls={panelId}
        className="flex w-full items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-white/6"
      >
        <IconBubble icon={Package} tone="neutral" size="sm" />

        <span className="min-w-0 flex-1">
          <span className="block truncate text-sm font-semibold text-white">
            {line.description}
          </span>
          {summary && (
            <span className="mt-0.5 block truncate text-xs text-white/65">{summary}</span>
          )}
        </span>

        <ChevronDown
          size={18}
          aria-hidden
          className={cn(
            'shrink-0 text-white/60 transition-transform duration-200',
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
            <div className="space-y-5 px-4 pb-4">
              <FieldNotesAndPhotos
                comments={line.comments}
                attachments={line.attachments}
              />
            </div>
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  )
}

interface FieldNotesAndPhotosProps {
  comments: JobTaskSummary['comments']
  attachments: JobTaskSummary['attachments']
}

/** The Notes list + Photos grid shared by a task's own row and each of its
 *  material rows — identical shape, just a different source array. */
function FieldNotesAndPhotos({ comments, attachments }: FieldNotesAndPhotosProps) {
  return (
    <>
      {comments.length > 0 && (
        <div>
          <p className="mb-2.5 text-2xs font-semibold tracking-wide text-white/70 uppercase">
            Notes
          </p>
          <ul className="space-y-3">
            {comments.map((note) => (
              <li
                key={note.id}
                className={cn('rounded-panel bg-white/6 p-4', cardAccent('info'))}
              >
                <p className="text-md whitespace-pre-line text-white/90">{note.body}</p>
                <p className="mt-2 text-sm text-white/70">
                  {note.author} · {formatRelative(note.createdAt)}
                </p>
              </li>
            ))}
          </ul>
        </div>
      )}

      {attachments.length > 0 && (
        <div>
          <p className="mb-2.5 text-2xs font-semibold tracking-wide text-white/70 uppercase">
            Photos
          </p>
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
            {attachments.map((photo) => (
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
                  <p className="truncate text-2xs text-white/80">{photo.uploadedBy}</p>
                  <p className="truncate text-2xs text-white/55">
                    {formatFileSize(photo.sizeBytes)} · {formatRelative(photo.createdAt)}
                  </p>
                </div>
              </a>
            ))}
          </div>
        </div>
      )}
    </>
  )
}
