import { ArrowUpRight, FileText } from 'lucide-react'
import { ButtonLink, ProgressBar, StatusChip, Table } from '@/components/common'
import { ROUTES } from '@/constants'
import type { TableColumn, TakeoffHistoryRow } from '@/types'
import {
  TAKEOFF_STATUS_LABEL,
  TAKEOFF_STATUS_TONE,
  formatDate,
  formatNumber,
} from '@/utils'
import { DashboardPanel } from './DashboardPanel'
import { ProjectListItem } from './ProjectListItem'

/** History row plus the two columns this panel adds. */
export interface DashboardProject extends TakeoffHistoryRow {
  readonly drawings: number
  /** Completion percentage, 0–100. */
  readonly progress: number
}

export interface RecentProjectsPanelProps {
  projects: readonly DashboardProject[]
  index?: number
}

/**
 * Recent projects as a table on wide screens and as stacked cards below `lg`,
 * so the six columns never force a horizontal scroll on a phone.
 */
export function RecentProjectsPanel({ projects, index }: RecentProjectsPanelProps) {
  const columns: TableColumn<DashboardProject>[] = [
    {
      key: 'name',
      header: 'Project Name',
      render: (project) => (
        <div className="flex items-center gap-3">
          <span className="grid size-9 shrink-0 place-items-center rounded-panel bg-brand/15 text-brand">
            <FileText size={16} aria-hidden />
          </span>
          <div className="min-w-0">
            <p className="font-semibold text-white">{project.name}</p>
            <p className="text-sm text-white/75">{project.client}</p>
          </div>
        </div>
      ),
    },
    {
      key: 'date',
      header: 'Date',
      render: (project) => (
        <span className="whitespace-nowrap text-white/90">
          {formatDate(project.date)}
        </span>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      render: (project) => (
        <StatusChip
          tone={TAKEOFF_STATUS_TONE[project.status]}
          label={TAKEOFF_STATUS_LABEL[project.status]}
          pulse={project.status === 'processing'}
        />
      ),
    },
    {
      key: 'drawings',
      header: 'Drawings',
      align: 'right',
      width: 'w-28',
      render: (project) => (
        <span className="tabular-nums text-white">
          {formatNumber(project.drawings)}
        </span>
      ),
    },
    {
      key: 'progress',
      header: 'Progress',
      width: 'w-48',
      render: (project) => (
        <div className="flex items-center gap-3">
          <ProgressBar
            value={project.progress}
            size="sm"
            tone={project.status === 'failed' ? 'danger' : 'brand'}
            className="min-w-0 flex-1"
          />
          <span className="shrink-0 text-sm font-semibold tabular-nums text-white">
            {project.progress}%
          </span>
        </div>
      ),
    },
    {
      key: 'actions',
      header: 'Action',
      align: 'center',
      width: 'w-28',
      render: () => (
        <ButtonLink href={ROUTES.results} size="sm" rightIcon={ArrowUpRight}>
          Open
        </ButtonLink>
      ),
    },
  ]

  return (
    <DashboardPanel
      title="Recent Projects"
      subtitle={`${projects.length} most recent takeoffs`}
      link={{ label: 'View all projects', href: ROUTES.history }}
      {...(index !== undefined ? { index } : {})}
    >
      {/* Table view — xl and up, where all six columns fit without scrolling */}
      <div className="hidden xl:block">
        <Table
          columns={columns}
          rows={projects}
          getRowId={(project) => project.id}
          caption="Recent AI takeoff projects"
        />
      </div>

      {/* Card view — below xl */}
      <ul className="space-y-3 xl:hidden">
        {projects.map((project, rowIndex) => (
          <ProjectListItem
            key={project.id}
            project={project}
            href={ROUTES.results}
            index={rowIndex}
            progress={project.progress}
            drawings={project.drawings}
          />
        ))}
      </ul>
    </DashboardPanel>
  )
}
