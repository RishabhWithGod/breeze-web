import { useState } from 'react'
import { router } from '@inertiajs/react'
import { PencilLine, Trash2 } from 'lucide-react'
import { motion } from 'framer-motion'
import { Button, ConfirmDialog, IconButton, StatusChip } from '@/components/common'
import { TASK_STATUS_LABEL, TASK_STATUS_TONE, routeTo } from '@/constants'
import type { JobOrigin } from '@/constants'
import type { JobTaskSummary } from '@/types'
import { formatHours } from '@/utils'

export interface JobTasksPanelProps {
  tasks: readonly JobTaskSummary[]
  /** False for anyone who cannot plan work — the list is still readable. */
  canPlan: boolean
  /**
   * The trail the job itself was opened on, passed on to Edit so that coming
   * back out of a task lands on a job that still knows the way out.
   */
  jobOrigin?: JobOrigin | null
}

/**
 * The work this job is broken into.
 *
 * What each task is, who has it, where it has got to and how much of the
 * estimate it covers. Dates and dependencies are not here: those are the
 * schedule's business, worked over days, and repeating them would give two
 * places to read the same plan from.
 */
export function JobTasksPanel({ tasks, canPlan, jobOrigin = null }: JobTasksPanelProps) {
  const [removing, setRemoving] = useState<JobTaskSummary | null>(null)

  if (tasks.length === 0) {
    return (
      <p className="text-md text-white/75">
        This job has not been broken into tasks yet.
      </p>
    )
  }

  return (
    <ul className="space-y-3">
      {tasks.map((task, index) => (
        <motion.li
          key={task.id}
          initial={{ opacity: 0, y: 8 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.25, delay: Math.min(index, 6) * 0.04 }}
          className="rounded-panel border border-hairline bg-white/4 p-4"
        >
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div className="min-w-0">
              <p className="truncate font-semibold text-white">{task.title}</p>
              <p className="mt-0.5 text-sm text-white/75">
                {task.foreman ?? 'Unassigned'}
                {/* Named only when there is one: "no foreman" is a normal
                    state and does not need saying on every row. */}
                {task.supervisor && ` · under ${task.supervisor}`}
                {task.lineCount > 0 &&
                  ` · ${task.lineCount} estimate ${task.lineCount === 1 ? 'line' : 'lines'}`}
              </p>
            </div>

            <div className="flex items-center gap-3">
              <span className="whitespace-nowrap text-md font-semibold tabular-nums text-white">
                {task.actualHours ? `${formatHours(task.actualHours)} / ` : ''}
                {task.estimatedHours === null ? '—' : formatHours(task.estimatedHours)}
              </span>
              <StatusChip
                hideDot
                tone={TASK_STATUS_TONE[task.status]}
                label={TASK_STATUS_LABEL[task.status]}
              />
            </div>
          </div>

          {canPlan && (
            <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-hairline pt-3">
              <Button
                variant="ghost"
                size="sm"
                leftIcon={PencilLine}
                disabled={task.status === 'completed'}
                title={task.status === 'completed' ? 'Completed tasks cannot be edited.' : undefined}
                onClick={() => router.visit(routeTo.taskEditFromJob(task.id, jobOrigin))}
              >
                Edit
              </Button>
              <IconButton
                icon={Trash2}
                label={`Remove ${task.title}`}
                variant="white"
                size="sm"
                disabled={task.status === 'completed'}
                title={task.status === 'completed' ? 'Completed tasks cannot be removed.' : undefined}
                onClick={() => setRemoving(task)}
                className="text-status-danger hover:border-status-danger hover:bg-status-danger hover:text-white disabled:pointer-events-none disabled:opacity-40"
              />
            </div>
          )}
        </motion.li>
      ))}

      <ConfirmDialog
        isOpen={removing !== null}
        tone="danger"
        title={`Remove “${removing?.title ?? ''}”?`}
        description="The estimate lines it covers go back to be planned into another task, and this job's hours are recalculated."
        confirmLabel="Remove task"
        confirmVariant="danger"
        onConfirm={() => {
          if (removing) {
            router.delete(routeTo.taskRemoveFromJob(removing.id), { preserveScroll: true })
          }
          setRemoving(null)
        }}
        onCancel={() => setRemoving(null)}
      />
    </ul>
  )
}
