import { useState } from 'react'
import { Head, Link, router, usePage } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import {
  BarChart3,
  Briefcase,
  Building2,
  ChevronRight,
  ClipboardList,
  Clock,
  ExternalLink,
  FileText,
  Files,
  FolderKanban,
  Mail,
  MapPin,
  PencilLine,
  Phone,
  Receipt,
  Sparkles,
  Trash2,
  Upload as UploadIcon,
  Users,
  type LucideIcon,
} from 'lucide-react'
import {
  Alert,
  Badge,
  Button,
  ButtonLink,
  Card,
  CardHeader,
  ConfirmDialog,
  EmptyState,
  MoreMenu,
  StatusChip,
  Table,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ProjectDocumentList } from '@/components/projects'
import { ROUTES, routeTo } from '@/constants'
import { useDisclosure } from '@/hooks'
import type {
  ProjectDocument,
  ProjectDocumentRow,
  ProjectEstimateRow,
  ProjectInvoiceRow,
  ProjectJobRow,
  ProjectRecord,
  SharedPageProps,
  TableColumn,
  Tone,
} from '@/types'
import {
  ESTIMATE_STATUS_LABEL,
  ESTIMATE_STATUS_TONE,
  INVOICE_STATUS_LABEL,
  INVOICE_STATUS_TONE,
  JOB_STATUS_LABEL,
  JOB_STATUS_TONE,
  JOB_TYPE_LABEL,
  TAKEOFF_STATUS_LABEL,
  TAKEOFF_STATUS_TONE,
  cn,
  formatCurrency,
  formatDate,
  formatFileSize,
  formatRelative,
} from '@/utils'

export interface ProjectShowProps {
  project: ProjectRecord
  documents: readonly ProjectDocument[]
}

/**
 * Project detail: the details it was opened with, the drawing PDFs it holds,
 * and what has happened to it.
 *
 * Drawings can be opened and removed here but not added — a PDF only ever
 * arrives through AI Takeoff, which uploads it against a client that already
 * exists.
 */
type TabKey =
  | 'overview'
  | 'drawings'
  | 'takeoffs'
  | 'estimates'
  | 'jobs'
  | 'team'
  | 'documents'
  | 'invoices'

const TAB_KEYS: readonly TabKey[] = [
  'overview',
  'drawings',
  'takeoffs',
  'estimates',
  'jobs',
  'team',
  'documents',
  'invoices',
]

/**
 * Which tab opens first. A redirect that just filed something onto one of
 * them — a document, a commodity list upload — says so with `?tab=`, so the
 * result is where it actually landed rather than back at the Overview.
 */
function initialTab(): TabKey {
  if (typeof window === 'undefined') return 'overview'

  const requested = new URLSearchParams(window.location.search).get('tab')

  return TAB_KEYS.includes(requested as TabKey) ? (requested as TabKey) : 'overview'
}

export default function ProjectShow({
  project,
  documents,
}: ProjectShowProps) {
  const { flash } = usePage<SharedPageProps>().props
  const [activeTab, setActiveTab] = useState<TabKey>(initialTab)

  const [pendingDelete, setPendingDelete] = useState<ProjectDocument | null>(null)
  const [selecting, setSelecting] = useState(false)
  const [dismissed, setDismissed] = useState<string | null>(null)
  const [startingTakeoff, setStartingTakeoff] = useState(false)
  const deleteDialog = useDisclosure()
  const deleteProjectDialog = useDisclosure()

  const startTakeoff = () => {
    router.post(routeTo.projectTakeoffStart(project.id), {}, {
      onStart: () => setStartingTakeoff(true),
      onFinish: () => setStartingTakeoff(false),
    })
  }

  const flashed = flash.warning ?? flash.success ?? null
  const notice = flashed === dismissed ? null : flashed

  /*
   * Choosing is a real write — every later takeoff, and the drawing name shown
   * on the Projects list, follow it — so it posts rather than being held in the
   * page. Only offered with more than one drawing: with one there is nothing
   * to choose, and the fallback already points at it.
   */
  const selectDrawing = (document: ProjectDocument) => {
    if (document.id === project.selectedUploadId) return

    router.post(
      routeTo.projectDocumentSelect(project.id, document.id),
      {},
      {
        preserveScroll: true,
        onStart: () => setSelecting(true),
        onFinish: () => setSelecting(false),
      },
    )
  }

  const confirmDocumentDelete = () => {
    if (!pendingDelete) return

    router.delete(routeTo.projectDocument(project.id, pendingDelete.id), {
      preserveScroll: true,
    })
    setPendingDelete(null)
    deleteDialog.close()
  }

  /*
   * Three states, in the order the work happens: read the takeoff once there
   * is one; otherwise run it if a drawing is on record; otherwise go and
   * upload one, which is the only way a drawing gets here. Shared by the
   * header's own button, the Takeoffs tab and the matching stat card and
   * quick action, so all four agree on what happens next.
   */
  const takeoffAction = project.takeoffUrl
    ? { kind: 'link' as const, href: project.takeoffUrl, label: 'View Takeoff', icon: ExternalLink }
    : documents.length === 0
      ? { kind: 'link' as const, href: routeTo.uploadForProject(project.id), label: 'Upload a Drawing', icon: UploadIcon }
      : { kind: 'action' as const, onClick: startTakeoff, label: 'Run AI Takeoff', icon: Sparkles }

  /*
   * Every tab switches what this same screen shows — none of them navigate
   * away, so Back always still means "leave the project", never "leave this
   * tab".
   */
  const tabs: { key: TabKey; label: string; icon: LucideIcon }[] = [
    { key: 'overview', label: 'Overview', icon: FolderKanban },
    { key: 'drawings', label: 'Drawings', icon: FileText },
    { key: 'takeoffs', label: 'Takeoffs', icon: Sparkles },
    { key: 'estimates', label: 'Estimates', icon: ClipboardList },
    { key: 'jobs', label: 'Jobs', icon: Briefcase },
    { key: 'team', label: 'Team', icon: Users },
    { key: 'documents', label: 'Documents', icon: Files },
    { key: 'invoices', label: 'Invoices', icon: Receipt },
  ]

  const estimateColumns: TableColumn<ProjectEstimateRow>[] = [
    {
      key: 'number',
      header: 'Estimate #',
      render: (row) => (
        <Link href={routeTo.estimate(row.id)} className="text-brand hover:underline">
          {row.number}
        </Link>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      render: (row) => (
        <StatusChip hideDot tone={ESTIMATE_STATUS_TONE[row.status]} label={ESTIMATE_STATUS_LABEL[row.status]} />
      ),
    },
    {
      key: 'amount',
      header: 'Amount',
      align: 'right',
      render: (row) => formatCurrency(row.amount, 2),
    },
    {
      key: 'issuedOn',
      header: 'Issued',
      align: 'right',
      render: (row) => (row.issuedOn ? formatDate(row.issuedOn) : '—'),
    },
  ]

  const jobColumns: TableColumn<ProjectJobRow>[] = [
    {
      key: 'name',
      header: 'Job',
      render: (row) => (
        <Link href={routeTo.job(row.id)} className="text-brand hover:underline">
          {row.name}
        </Link>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      render: (row) => <StatusChip hideDot tone={JOB_STATUS_TONE[row.status]} label={JOB_STATUS_LABEL[row.status]} />,
    },
    {
      key: 'type',
      header: 'Type',
      render: (row) => (row.jobType ? JOB_TYPE_LABEL[row.jobType] : '—'),
    },
    {
      key: 'dates',
      header: 'Dates',
      align: 'right',
      render: (row) =>
        row.startDate
          ? `${formatDate(row.startDate)}${row.endDate ? ` – ${formatDate(row.endDate)}` : ''}`
          : '—',
    },
  ]

  const invoiceColumns: TableColumn<ProjectInvoiceRow>[] = [
    {
      key: 'number',
      header: 'Invoice #',
      render: (row) => (
        <Link href={routeTo.invoice(row.id)} className="text-brand hover:underline">
          {row.invoiceNumber}
        </Link>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      render: (row) => (
        <StatusChip hideDot tone={INVOICE_STATUS_TONE[row.status]} label={INVOICE_STATUS_LABEL[row.status]} />
      ),
    },
    {
      key: 'total',
      header: 'Total',
      align: 'right',
      render: (row) => formatCurrency(row.total, 2),
    },
    {
      key: 'invoiceDate',
      header: 'Date',
      align: 'right',
      render: (row) => (row.invoiceDate ? formatDate(row.invoiceDate) : '—'),
    },
  ]

  const documentColumns: TableColumn<ProjectDocumentRow>[] = [
    {
      key: 'name',
      header: 'Name',
      render: (row) => (
        <a href={routeTo.documentDownload(row.id)} className="text-brand hover:underline">
          {row.name}
        </a>
      ),
    },
    {
      key: 'type',
      header: 'Type',
      render: (row) => row.documentType ?? '—',
    },
    {
      key: 'size',
      header: 'Size',
      align: 'right',
      render: (row) => formatFileSize(row.fileSizeBytes),
    },
    {
      key: 'uploaded',
      header: 'Uploaded',
      align: 'right',
      render: (row) => formatDate(row.createdAt),
    },
  ]

  return (
    <PageTransition>
      <Head title={project.name} />

      <PageHeader
        title={
          <span className="flex flex-wrap items-center gap-3">
            {project.name}
            <StatusBadge status={project.status} />
          </span>
        }
        subtitle={`${project.description ?? project.client} · ${project.drawingCount} ${project.drawingCount === 1 ? 'drawing' : 'drawings'} · ${project.takeoffCount} ${project.takeoffCount === 1 ? 'takeoff' : 'takeoffs'}`}
        breadcrumbs={[
          { label: 'Projects', href: ROUTES.projects },
          { label: project.name, href: routeTo.project(project.id) },
          { label: 'Project Workspace' },
        ]}
        actions={
          <>
            {takeoffAction.kind === 'link' ? (
              <ButtonLink href={takeoffAction.href} variant="secondary" leftIcon={takeoffAction.icon}>
                {takeoffAction.label}
              </ButtonLink>
            ) : (
              <Button
                variant="secondary"
                leftIcon={takeoffAction.icon}
                onClick={takeoffAction.onClick}
                isLoading={startingTakeoff}
              >
                {takeoffAction.label}
              </Button>
            )}
            <MoreMenu
              ariaLabel="Project actions"
              items={[
                {
                  label: 'Edit Project',
                  icon: PencilLine,
                  onSelect: () => router.visit(routeTo.projectEdit(project.id)),
                },
                {
                  label: 'Delete Project',
                  icon: Trash2,
                  destructive: true,
                  onSelect: deleteProjectDialog.open,
                },
              ]}
            />
          </>
        }
      />

      <AnimatePresence initial={false}>
        {notice && (
          <Alert
            key={notice}
            tone={flash.warning ? 'warning' : 'success'}
            className="mb-6"
            onDismiss={() => setDismissed(notice)}
          >
            {notice}
          </Alert>
        )}
      </AnimatePresence>

      {/* ======================================================= Tabs ======= */}
      <nav
        aria-label="Project sections"
        className="mb-6 flex gap-1 overflow-x-auto border-b border-hairline"
      >
        {tabs.map((tab) => (
          <button
            key={tab.key}
            type="button"
            onClick={() => setActiveTab(tab.key)}
            aria-current={activeTab === tab.key ? 'page' : undefined}
            className={cn(
              'flex shrink-0 items-center gap-1.5 border-b-2 px-3 py-2.5 text-sm font-medium transition-colors',
              activeTab === tab.key
                ? 'border-brand text-brand'
                : 'border-transparent text-white/60 hover:text-white',
            )}
          >
            <tab.icon size={15} aria-hidden />
            {tab.label}
          </button>
        ))}
      </nav>

      {/* =================================================== Stat cards ===== */}
      <div className="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
        <StatCard
          icon={FileText}
          label="Drawings"
          value={project.drawingCount}
          tone="brand"
          onClick={() => setActiveTab('drawings')}
        />
        <StatCard
          icon={Sparkles}
          label="Takeoffs"
          value={project.takeoffCount}
          tone="purple"
          onClick={() => setActiveTab('takeoffs')}
        />
        <StatCard
          icon={ClipboardList}
          label="Estimates"
          value={project.estimateCount}
          tone="success"
          onClick={() => setActiveTab('estimates')}
        />
        <StatCard
          icon={Briefcase}
          label="Jobs"
          value={project.jobCount}
          tone="warning"
          onClick={() => setActiveTab('jobs')}
        />
        <StatCard
          icon={Users}
          label="Team Members"
          value={project.teamCount}
          tone="purple"
          onClick={() => setActiveTab('team')}
        />
      </div>

      {activeTab === 'overview' && (
        <>
      {/* ============================================ Info + Quick Actions === */}
      <div className="grid gap-6 xl:grid-cols-2">
        <Card padding="md" className="min-w-0">
          <CardHeader
            title={
              <span className="flex items-center gap-2">
                <FolderKanban size={18} aria-hidden className="text-white/70" />
                Project Information
              </span>
            }
            actions={
              <ButtonLink href={routeTo.projectEdit(project.id)} variant="secondary" size="sm" leftIcon={PencilLine}>
                Edit
              </ButtonLink>
            }
            className="border-b border-hairline pb-4"
            titleClassName="text-lg font-semibold"
          />

          <dl className="flex flex-col gap-3">
            <InfoRow label="Name" value={project.name} />
            {project.description && <InfoRow label="Description" value={project.description} />}
            {project.code && <InfoRow label="Project #" value={project.code} />}
          </dl>

          <dl className="mt-6 flex flex-col gap-3 border-t border-hairline pt-6">
            <InfoRow
              label="Status"
              value={<StatusBadge status={project.status} />}
            />
            <InfoRow label="Owner" value={project.owner} icon={Users} />
            <InfoRow
              label="Client"
              icon={Building2}
              value={
                <Link
                  href={routeTo.client(project.clientId)}
                  className="text-brand hover:underline"
                >
                  {project.client}
                </Link>
              }
            />
            <InfoRow label="Location" value={project.location ?? 'Not specified'} icon={MapPin} />
          </dl>

          {/* Hidden for now — see `CommodityList` below to bring it back. */}

          <dl className="mt-6 flex flex-col gap-3 border-t border-hairline pt-6">
            {project.startedAt && <InfoRow label="Start Date" value={formatDate(project.startedAt)} />}
            {project.dueDate && <InfoRow label="End Date" value={formatDate(project.dueDate)} />}
            <InfoRow label="Opened" value={formatDate(project.createdAt)} />
          </dl>
        </Card>

        <Card padding="md" className="min-w-0">
          <CardHeader
            title={
              <span className="flex items-center gap-2">
                <Sparkles size={18} aria-hidden className="text-white/70" />
                Quick Actions
              </span>
            }
            className="border-b border-hairline pb-4"
            titleClassName="text-lg font-semibold"
          />

          <div className="grid gap-3 sm:grid-cols-2">
            <QuickAction
              icon={UploadIcon}
              tone="brand"
              title="Upload Drawings"
              subtitle="Add and manage drawings"
              href={routeTo.uploadForProject(project.id)}
            />
            {takeoffAction.kind === 'link' ? (
              <QuickAction
                icon={takeoffAction.icon}
                tone="purple"
                title={takeoffAction.label}
                subtitle="Start or review a takeoff"
                href={takeoffAction.href}
              />
            ) : (
              <QuickAction
                icon={takeoffAction.icon}
                tone="purple"
                title={takeoffAction.label}
                subtitle="Start a new takeoff"
                onClick={takeoffAction.onClick}
              />
            )}
            <QuickAction
              icon={ClipboardList}
              tone="success"
              title="Create Estimate"
              subtitle="Build an estimate"
              href={`${ROUTES.estimateCreate}?project=${project.id}`}
            />
            <QuickAction
              icon={Briefcase}
              tone="warning"
              title="Create Job"
              subtitle="Convert to a job"
              href={`${ROUTES.jobCreate}?project=${project.id}`}
            />
            <QuickAction
              icon={Users}
              tone="purple"
              title="Invite Team"
              subtitle="Add team members"
              href={routeTo.projectEdit(project.id)}
            />
            <QuickAction
              icon={Files}
              tone="neutral"
              title="Add Document"
              subtitle="Upload project documents"
              href={`${routeTo.projectDocumentCreate(project.id)}&return_to_project=1`}
            />
          </div>
        </Card>
      </div>

      {/* ======================================= Activity + Summary ========= */}
      <div className="mt-6 grid gap-6 xl:grid-cols-2">
        <Card padding="md" className="min-w-0">
          <CardHeader
            title={
              <span className="flex items-center gap-2">
                <Clock size={18} aria-hidden className="text-white/70" />
                Recent Activity
              </span>
            }
            className="border-b border-hairline pb-4"
            titleClassName="text-lg font-semibold"
          />

          {project.activity.length === 0 ? (
            <p className="text-md text-white/70">
              Nothing recorded yet — activity shows up here as work happens on this project.
            </p>
          ) : (
            <ul className="space-y-3">
              {project.activity.map((entry) => (
                <li key={entry.id} className="flex items-start gap-3">
                  <span
                    className={cn(
                      'mt-0.5 grid size-9 shrink-0 place-items-center rounded-full',
                      ACTIVITY_TONE_STYLES[entry.tone],
                    )}
                  >
                    <Clock size={14} aria-hidden />
                  </span>
                  <div className="min-w-0 flex-1">
                    <p className="truncate font-medium text-white">{entry.title}</p>
                    <p className="flex flex-wrap items-center gap-x-2 text-sm text-white/60">
                      {entry.description && <span>{entry.description}</span>}
                      {entry.occurredAt && <span>{formatRelative(entry.occurredAt)}</span>}
                      <span>by {project.owner}</span>
                    </p>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Card>

        <Card padding="md" className="min-w-0">
          <CardHeader
            title={
              <span className="flex items-center gap-2">
                <BarChart3 size={18} aria-hidden className="text-white/70" />
                Project Progress
              </span>
            }
            className="border-b border-hairline pb-4"
            titleClassName="text-lg font-semibold"
          />

          <div className="flex flex-col gap-4">
            <ProgressRow label="Drawings" value={project.drawingCount} tone="brand" />
            <ProgressRow label="Takeoffs" value={project.takeoffCount} tone="purple" />
            <ProgressRow label="Estimates" value={project.estimateCount} tone="success" />
            <ProgressRow label="Jobs" value={project.jobCount} tone="warning" />
            <ProgressRow label="Invoices" value={project.invoiceCount} tone="info" />
          </div>
        </Card>
      </div>

      {/* ============================================================ Notes = */}
      <Card padding="md" className="mt-6">
        <CardHeader
          title={
            <span className="flex items-center gap-2">
              <FileText size={18} aria-hidden className="text-white/70" />
              Notes
            </span>
          }
          actions={
            <ButtonLink href={routeTo.projectEdit(project.id)} variant="secondary" size="sm" leftIcon={PencilLine}>
              Edit
            </ButtonLink>
          }
          className="border-b border-hairline pb-4"
          titleClassName="text-lg font-semibold"
        />

        <p
          className={
            project.notes
              ? 'text-md whitespace-pre-line text-white/90'
              : 'text-md text-white/45'
          }
        >
          {project.notes ?? 'No notes added yet. Click edit to add notes about this project.'}
        </p>
      </Card>
        </>
      )}

      {/* --------------------------------------------------- Drawing PDFs --- */}
      {activeTab === 'drawings' && (
        <Card padding="md">
          <CardHeader
            title={
              <span className="flex items-center gap-2">
                <FileText size={18} aria-hidden className="text-white/70" />
                Drawing PDFs
              </span>
            }
            subtitle={
              documents.length > 1
                ? 'Every drawing on record. Pick the one the next takeoff should run against.'
                : 'Every drawing on record for this client.'
            }
            className="border-b border-hairline pb-4"
            titleClassName="text-lg font-semibold"
          />

          <ProjectDocumentList
            documents={documents}
            selectedId={project.selectedUploadId}
            disabled={selecting}
            {...(documents.length > 1 ? { onSelect: selectDrawing } : {})}
            onRemove={(document) => {
              setPendingDelete(document)
              deleteDialog.open()
            }}
          />
        </Card>
      )}

      {/* ----------------------------------------------------- Takeoffs ---- */}
      {activeTab === 'takeoffs' && (
        <Card padding="md">
          <CardHeader
            title={
              <span className="flex items-center gap-2">
                <Sparkles size={18} aria-hidden className="text-white/70" />
                Takeoffs
              </span>
            }
            className="border-b border-hairline pb-4"
            titleClassName="text-lg font-semibold"
          />

          {project.takeoffCount === 0 ? (
            <EmptyState
              icon={Sparkles}
              title="No takeoffs yet"
              description="Run one from a drawing on record."
            />
          ) : (
            <div className="flex flex-wrap items-center justify-between gap-3 rounded-panel border border-hairline bg-white/4 p-4">
              <div>
                <p className="font-medium text-white">
                  {project.takeoffCount} {project.takeoffCount === 1 ? 'run' : 'runs'} on record
                </p>
                {project.latestTakeoffAt && (
                  <p className="text-sm text-white/60">
                    Last run {formatRelative(project.latestTakeoffAt)}
                  </p>
                )}
              </div>
              {project.takeoffUrl && (
                <ButtonLink href={project.takeoffUrl} size="sm" leftIcon={ExternalLink}>
                  View Results
                </ButtonLink>
              )}
            </div>
          )}
        </Card>
      )}

      {/* ---------------------------------------------------- Estimates ---- */}
      {activeTab === 'estimates' && (
        <Card padding="md">
          <CardHeader
            title={
              <span className="flex items-center gap-2">
                <ClipboardList size={18} aria-hidden className="text-white/70" />
                Estimates
              </span>
            }
            actions={
              <ButtonLink
                href={`${ROUTES.estimateCreate}?project=${project.id}`}
                variant="secondary"
                size="sm"
                leftIcon={ClipboardList}
              >
                Create Estimate
              </ButtonLink>
            }
            className="border-b border-hairline pb-4"
            titleClassName="text-lg font-semibold"
          />

          {project.estimatesList.length === 0 ? (
            <EmptyState
              icon={ClipboardList}
              title="No estimates yet"
              description="Nothing has been estimated for this project."
            />
          ) : (
            <Table
              columns={estimateColumns}
              rows={project.estimatesList}
              getRowId={(row) => row.id}
              variant="lined"
              dense
              caption="Estimates on this project"
            />
          )}
        </Card>
      )}

      {/* --------------------------------------------------------- Jobs ---- */}
      {activeTab === 'jobs' && (
        <Card padding="md">
          <CardHeader
            title={
              <span className="flex items-center gap-2">
                <Briefcase size={18} aria-hidden className="text-white/70" />
                Jobs
              </span>
            }
            actions={
              <ButtonLink
                href={`${ROUTES.jobCreate}?project=${project.id}`}
                variant="secondary"
                size="sm"
                leftIcon={Briefcase}
              >
                Create Job
              </ButtonLink>
            }
            className="border-b border-hairline pb-4"
            titleClassName="text-lg font-semibold"
          />

          {project.jobsList.length === 0 ? (
            <EmptyState
              icon={Briefcase}
              title="No jobs yet"
              description="Nothing has been converted to a job for this project."
            />
          ) : (
            <Table
              columns={jobColumns}
              rows={project.jobsList}
              getRowId={(row) => row.id}
              variant="lined"
              dense
              caption="Jobs on this project"
            />
          )}
        </Card>
      )}

      {/* --------------------------------------------------------- Team ---- */}
      {activeTab === 'team' && (
        <Card padding="md">
          <CardHeader
            title={
              <span className="flex items-center gap-2">
                <Users size={18} aria-hidden className="text-white/70" />
                Team
              </span>
            }
            actions={
              <Link
                href={routeTo.projectEdit(project.id)}
                className="text-sm text-brand hover:underline"
              >
                Manage Team →
              </Link>
            }
            className="border-b border-hairline pb-4"
            titleClassName="text-lg font-semibold"
          />

          {project.members.length === 0 ? (
            <EmptyState
              icon={Users}
              title="No one staffed yet"
              description="Staff this project from Edit Project."
            />
          ) : (
            <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
              {project.members.map((member) => (
                <li
                  key={member.id}
                  className="flex items-start gap-3 rounded-panel border border-hairline bg-white/4 p-3"
                >
                  <span className="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full bg-brand/20 text-sm font-semibold text-brand">
                    {member.initials}
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-md font-medium text-white">
                      {member.name}
                    </span>
                    <span className="block text-sm text-white/60">{member.roleLabel}</span>
                    <span className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-white/70">
                      {member.phone && (
                        <span className="flex items-center gap-1.5">
                          <Phone size={12} aria-hidden className="shrink-0" />
                          {member.phone}
                        </span>
                      )}
                      {member.email && (
                        <span className="flex items-center gap-1.5">
                          <Mail size={12} aria-hidden className="shrink-0" />
                          {member.email}
                        </span>
                      )}
                    </span>
                  </span>
                </li>
              ))}
            </ul>
          )}
        </Card>
      )}

      {/* ---------------------------------------------------- Documents ---- */}
      {activeTab === 'documents' && (
        <Card padding="md">
          <CardHeader
            title={
              <span className="flex items-center gap-2">
                <Files size={18} aria-hidden className="text-white/70" />
                Documents
              </span>
            }
            actions={
              <ButtonLink
                href={`${routeTo.projectDocumentCreate(project.id)}&return_to_project=1`}
                variant="secondary"
                size="sm"
                leftIcon={Files}
              >
                Add Document
              </ButtonLink>
            }
            className="border-b border-hairline pb-4"
            titleClassName="text-lg font-semibold"
          />

          {project.documentsList.length === 0 ? (
            <EmptyState
              icon={Files}
              title="No documents yet"
              description="Nothing has been filed under this project."
            />
          ) : (
            <Table
              columns={documentColumns}
              rows={project.documentsList}
              getRowId={(row) => row.id}
              variant="lined"
              dense
              caption="Documents on this project"
            />
          )}
        </Card>
      )}

      {/* --------------------------------------------------------- Invoices */}
      {activeTab === 'invoices' && (
        <Card padding="md">
          <CardHeader
            title={
              <span className="flex items-center gap-2">
                <Receipt size={18} aria-hidden className="text-white/70" />
                Invoices
              </span>
            }
            className="border-b border-hairline pb-4"
            titleClassName="text-lg font-semibold"
          />

          {project.invoicesList.length === 0 ? (
            <EmptyState
              icon={Receipt}
              title="No invoices yet"
              description="Nothing has been billed for this project."
            />
          ) : (
            <Table
              columns={invoiceColumns}
              rows={project.invoicesList}
              getRowId={(row) => row.id}
              variant="lined"
              dense
              caption="Invoices on this project"
            />
          )}
        </Card>
      )}

      <ConfirmDialog
        isOpen={deleteDialog.isOpen}
        tone="danger"
        title={`Remove “${pendingDelete?.label ?? ''}”?`}
        description="The PDF is deleted from the client and from storage. This cannot be undone."
        confirmLabel="Remove drawing"
        confirmVariant="danger"
        onConfirm={confirmDocumentDelete}
        onCancel={() => {
          setPendingDelete(null)
          deleteDialog.close()
        }}
      />

      <ConfirmDialog
        isOpen={deleteProjectDialog.isOpen}
        tone="danger"
        title={`Delete “${project.name}”?`}
        description="The project is removed from your list. Its drawings stay on file."
        confirmLabel="Delete Project"
        confirmVariant="danger"
        onConfirm={() => {
          router.delete(routeTo.project(project.id))
          deleteProjectDialog.close()
        }}
        onCancel={deleteProjectDialog.close}
      />
    </PageTransition>
  )
}

function StatusBadge({ status }: { status: ProjectRecord['status'] }) {
  return (
    <Badge tone={TAKEOFF_STATUS_TONE[status]} size="sm">
      {TAKEOFF_STATUS_LABEL[status]}
    </Badge>
  )
}

/** The stat cards' own colour set — `Tone` plus the purple the reference uses for Takeoffs and Team. */
type StatTone = Tone | 'purple'

const TONE_STYLES: Record<StatTone, { border: string; iconBg: string; iconText: string; barFill: string }> = {
  brand: {
    border: 'border-brand/40 border-l-brand hover:border-brand/70',
    iconBg: 'bg-brand/15',
    iconText: 'text-brand',
    barFill: 'bg-brand',
  },
  blue: {
    border: 'border-status-blue/40 border-l-status-blue hover:border-status-blue/70',
    iconBg: 'bg-status-blue/15',
    iconText: 'text-status-blue',
    barFill: 'bg-status-blue',
  },
  purple: {
    border: 'border-purple-400/40 border-l-purple-400 hover:border-purple-400/70',
    iconBg: 'bg-purple-400/15',
    iconText: 'text-purple-300',
    barFill: 'bg-purple-400',
  },
  info: {
    border: 'border-status-info/40 border-l-status-info hover:border-status-info/70',
    iconBg: 'bg-status-info/15',
    iconText: 'text-status-info',
    barFill: 'bg-status-info',
  },
  success: {
    border: 'border-status-success/40 border-l-status-success hover:border-status-success/70',
    iconBg: 'bg-status-success/15',
    iconText: 'text-status-success',
    barFill: 'bg-status-success',
  },
  warning: {
    border: 'border-status-warning/40 border-l-status-warning hover:border-status-warning/70',
    iconBg: 'bg-status-warning/15',
    iconText: 'text-status-warning',
    barFill: 'bg-status-warning',
  },
  danger: {
    border: 'border-status-danger/40 border-l-status-danger hover:border-status-danger/70',
    iconBg: 'bg-status-danger/15',
    iconText: 'text-red-300',
    barFill: 'bg-status-danger',
  },
  neutral: {
    border: 'border-hairline-strong border-l-white/50 hover:border-white/40',
    iconBg: 'bg-white/10',
    iconText: 'text-white',
    barFill: 'bg-white/50',
  },
}

const ACTIVITY_TONE_STYLES: Record<Tone, string> = {
  brand: 'bg-brand/15 text-brand',
  info: 'bg-status-info/15 text-status-info',
  purple: 'bg-status-purple/15 text-status-purple',
  blue: 'bg-status-blue/15 text-status-blue',
  success: 'bg-status-success/15 text-status-success',
  warning: 'bg-status-warning/15 text-status-warning',
  danger: 'bg-status-danger/15 text-red-300',
  neutral: 'bg-white/10 text-white',
}

interface StatCardProps {
  icon: LucideIcon
  label: string
  value: number
  tone: StatTone
  onClick: () => void
}

function StatCard({ icon: Icon, label, value, tone, onClick }: StatCardProps) {
  const styles = TONE_STYLES[tone]

  return (
    <button
      type="button"
      onClick={onClick}
      className={cn(
        'flex items-center gap-3 rounded-panel border-2 border-l-[6px] glass p-4 text-left transition-colors',
        styles.border,
      )}
    >
      <span className={cn('grid size-11 shrink-0 place-items-center rounded-panel', styles.iconBg, styles.iconText)}>
        <Icon size={20} aria-hidden />
      </span>
      <div className="min-w-0 flex-1">
        <p className="text-xl font-semibold tabular-nums text-white">{value}</p>
        <p className="truncate text-sm text-white/60">{label}</p>
      </div>
      <ChevronRight size={16} aria-hidden className="shrink-0 text-white/40" />
    </button>
  )
}

interface QuickActionProps {
  icon: LucideIcon
  tone: StatTone
  title: string
  subtitle: string
  href?: string
  onClick?: () => void
}

function QuickAction({ icon: Icon, tone, title, subtitle, href, onClick }: QuickActionProps) {
  const styles = TONE_STYLES[tone]

  const content = (
    <div
      className={cn(
        'flex w-full items-center gap-3 rounded-panel border bg-white/4 p-3 text-left transition-colors hover:bg-white/8',
        'border-hairline',
      )}
    >
      <span className={cn('grid size-10 shrink-0 place-items-center rounded-panel', styles.iconBg, styles.iconText)}>
        <Icon size={18} aria-hidden />
      </span>
      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-medium text-white">{title}</p>
        <p className="truncate text-xs text-white/60">{subtitle}</p>
      </div>
      <ChevronRight size={16} aria-hidden className="shrink-0 text-white/40" />
    </div>
  )

  if (href) {
    return (
      <Link href={href} className="block">
        {content}
      </Link>
    )
  }

  return (
    <button type="button" onClick={onClick} className="block w-full">
      {content}
    </button>
  )
}

interface InfoRowProps {
  label: string
  value: React.ReactNode | null
  icon?: React.ComponentType<{ size?: number; className?: string; 'aria-hidden'?: boolean }>
}

function InfoRow({ label, value, icon: Icon }: InfoRowProps) {
  return (
    <div className="flex flex-wrap items-baseline justify-between gap-3">
      <dt className="flex items-center gap-1.5 text-sm text-white/60">
        {Icon && <Icon size={13} aria-hidden className="shrink-0" />}
        {label}
      </dt>
      <dd className="text-md text-white">{value ?? <span className="text-white/45">—</span>}</dd>
    </div>
  )
}

interface ProgressRowProps {
  label: string
  value: number
  tone: StatTone
}

/**
 * How much of this category is on record — not a target the count is
 * measured against (there is no such thing here), just whether it's zero or
 * not, read the same way the reference's own "1/1, 100%" rows do.
 */
function ProgressRow({ label, value, tone }: ProgressRowProps) {
  const percent = value > 0 ? 100 : 0
  const styles = TONE_STYLES[tone]

  return (
    <div>
      <div className="flex items-center justify-between text-sm">
        <span className="text-white/70">{label}</span>
        <span className="text-white/50">
          {value} / {value}
        </span>
      </div>
      <div className="mt-1.5 flex items-center gap-3">
        <div className="h-2 flex-1 rounded-full bg-white/10">
          <div
            className={cn('h-full rounded-full', styles.barFill)}
            style={{ width: `${percent}%` }}
          />
        </div>
        <span className="w-10 shrink-0 text-right text-xs text-white/50">{percent}%</span>
      </div>
    </div>
  )
}

ProjectShow.layout = appLayout
