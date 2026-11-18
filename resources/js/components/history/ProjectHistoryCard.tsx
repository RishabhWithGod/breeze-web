import { motion } from 'framer-motion'
import { Link } from '@inertiajs/react'
import { Trash2 } from 'lucide-react'
import { Badge, Button, ButtonLink, MoreMenu } from '@/components/common'
import { routeTo } from '@/constants'
import type { TakeoffHistoryRow } from '@/types'
import { HISTORY_STATUS_LABEL, HISTORY_STATUS_TONE, cn, formatDate } from '@/utils'
import { DrawingThumb } from './DrawingThumb'

export interface ProjectHistoryCardProps {
  project: TakeoffHistoryRow
  index?: number
  onDelete: (project: TakeoffHistoryRow) => void
  onRetry: (project: TakeoffHistoryRow) => void
  className?: string
}

/**
 * One drawing set as a card, laid out as the Breeze app's takeoff card: a blueprint thumbnail with
 * its status and sheet count, then the drawing's name, its project and client, and the actions.
 * Carries every field and action the history table did.
 */
export function ProjectHistoryCard({ project, index = 0, onDelete, onRetry, className }: ProjectHistoryCardProps) {
  const running = project.reviewStatus === 'processing'
  // Still being read: back to the live progress. Waiting on its review: straight to the review.
  // Anything else: the drawing's detail screen.
  const reviewUrl = project.reviewStatus === 'ready-for-review' ? (project.reviewUrl ?? null) : null
  const processingUrl = running ? (project.processingUrl ?? null) : null
  const href = processingUrl ?? reviewUrl ?? `${routeTo.drawingDetails(project.id)}?from=history`
  const action = processingUrl ? 'View progress' : reviewUrl ? 'Review' : 'View'
  const sheets = `${project.pageCount} ${project.pageCount === 1 ? 'sheet' : 'sheets'}`

  return (
    <motion.li
      initial={{ opacity: 0, y: 10 }}
      whileInView={{ opacity: 1, y: 0 }}
      viewport={{ once: true }}
      transition={{ duration: 0.3, delay: Math.min(index, 6) * 0.05 }}
      className={cn(
        'glass flex flex-col overflow-hidden rounded-card border border-hairline transition-[transform,box-shadow,border-color] duration-200',
        'hover:-translate-y-[3px] hover:border-brand/40 hover:shadow-[0_24px_50px_-24px_rgb(47_91_255/0.45)]',
        className,
      )}
    >
      <Link
        href={href}
        aria-label={`${action} ${project.drawingName ?? 'drawing'}`}
        className="on-dark relative block h-[150px] overflow-hidden border-b border-hairline bg-[#071535]"
      >
        <DrawingThumb seed={String(project.id)} marks={running ? 0 : project.items * 2} />
        {running && <span className="takeoff-scan" aria-hidden />}
        <span className="absolute top-3 left-3">
          <Badge tone={HISTORY_STATUS_TONE[project.reviewStatus]}>{HISTORY_STATUS_LABEL[project.reviewStatus]}</Badge>
        </span>
        <span className="absolute right-3 bottom-2.5 rounded-md bg-[rgb(4_10_26/0.82)] px-2 py-[3px] text-xs font-semibold tracking-[0.06em] text-[#e6eeff]">
          {sheets}
          {project.items > 0 ? ` · ${project.items} items` : ''}
        </span>
      </Link>

      <div className="grid flex-1 gap-3 px-[18px] pt-4 pb-[18px]">
        <div className="min-w-0">
          <p className="truncate text-[14.5px] font-[620] text-white" title={project.drawingName ?? 'Untitled drawing'}>
            {project.drawingName ?? 'Untitled drawing'}
          </p>
          <p className="mt-0.5 truncate text-[12.5px] text-[var(--text-3)]">
            {project.name} · {project.client}
          </p>
          {project.format && <p className="truncate text-[12.5px] text-[var(--text-3)]">{project.format}</p>}
        </div>

        <div className="mt-auto flex items-center justify-between gap-2.5 border-t border-dashed border-hairline pt-3">
          <span className="text-xs text-[var(--text-3)]">Uploaded {formatDate(project.uploadedAt)}</span>

          <div className="flex items-center gap-1.5">
            <ButtonLink href={href} variant="primary" size="sm">
              {action}
            </ButtonLink>

            {project.reviewStatus === 'failed' && (
              <Button variant="secondary" size="sm" onClick={() => onRetry(project)}>
                Retry
              </Button>
            )}

            <MoreMenu
              variant="minimal"
              ariaLabel={`More actions for ${project.name}`}
              items={[
                {
                  label: 'Delete',
                  icon: Trash2,
                  destructive: true,
                  onSelect: () => onDelete(project),
                },
              ]}
            />
          </div>
        </div>
      </div>
    </motion.li>
  )
}
