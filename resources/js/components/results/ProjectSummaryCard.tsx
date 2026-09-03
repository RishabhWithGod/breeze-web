import { CalendarClock, FileStack, Layers3 } from 'lucide-react'
import { Badge, Card, CircularProgress, StatusChip } from '@/components/common'
import type { TakeoffProject } from '@/types'
import {
  TAKEOFF_STATUS_LABEL,
  TAKEOFF_STATUS_TONE,
  formatDate,
  formatPercent,
} from '@/utils'
import { DrawingPreview } from './DrawingPreview'

export interface ProjectSummaryCardProps {
  project: TakeoffProject
  index?: number
}

/** Hero card: project identity, drawing preview and headline confidence. */
export function ProjectSummaryCard({ project, index }: ProjectSummaryCardProps) {
  const primarySheet = project.sheets[1] ?? project.sheets[0]

  // The card's own heading is the client's name, so it is not repeated here.
  const meta = [
    { icon: Layers3, label: 'Discipline', value: project.discipline },
    { icon: FileStack, label: 'Sheets', value: `${project.pageCount} pages` },
    {
      icon: CalendarClock,
      label: 'Completed',
      value: project.completedAt
        ? formatDate(project.completedAt, "MMM d, yyyy 'at' h:mm a")
        : 'In progress',
    },
  ]

  // A run can be reviewed before every symbol class has been scored.
  const confidence = project.overallConfidence ?? 0

  return (
    <Card
      variant="spotlight"
      padding="lg"
      {...(index !== undefined ? { index } : {})}
    >
      <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.15fr)] xl:gap-10">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-3">
            <StatusChip
              tone={TAKEOFF_STATUS_TONE[project.status]}
              label={TAKEOFF_STATUS_LABEL[project.status]}
            />
            <Badge tone="neutral" size="sm">
              ID {project.id}
            </Badge>
          </div>

          <h2 className="mt-3 text-2xl font-bold text-white sm:text-3xl">
            {project.name}
          </h2>
          <p className="mt-2 text-md text-white/90">{project.drawingName}</p>

          <dl className="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
            {meta.map(({ icon: Icon, label, value }) => (
              <div key={label} className="flex items-start gap-3">
                <Icon size={17} aria-hidden className="mt-1 shrink-0 text-brand" />
                <div className="min-w-0">
                  <dt className="text-xs tracking-wide text-white/70 uppercase">
                    {label}
                  </dt>
                  <dd className="truncate text-md text-white">{value}</dd>
                </div>
              </div>
            ))}
          </dl>

          <div className="mt-7 flex items-center gap-5 rounded-card border border-hairline bg-navy-950/30 p-4">
            <CircularProgress value={confidence * 100} size="sm" tone="success">
              <span className="text-md font-bold text-white">
                {formatPercent(confidence)}
              </span>
            </CircularProgress>
            <div>
              <p className="text-md font-semibold text-white">Overall confidence</p>
              <p className="mt-1 text-sm text-white/85">
                {project.symbols.length} symbol classes matched against the drawing
                legend.
              </p>
            </div>
          </div>
        </div>

        {primarySheet && (
          <DrawingPreview
            sheetCode={primarySheet.code}
            title={primarySheet.title}
            scale={primarySheet.scale}
          />
        )}
      </div>
    </Card>
  )
}
