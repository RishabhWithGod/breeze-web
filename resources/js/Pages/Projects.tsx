import { useState } from 'react'
import { Head, Link, router } from '@inertiajs/react'
import { AnimatePresence, motion } from 'framer-motion'
import {
  Briefcase,
  ClipboardList,
  ExternalLink,
  FileText,
  Files,
  FolderKanban,
  MapPin,
  PencilLine,
  Plus,
  Receipt,
  SlidersHorizontal,
  Sparkles,
  Trash2,
  Users,
  type LucideIcon,
} from 'lucide-react'
import {
  Alert,
  Badge,
  Button,
  ButtonLink,
  Card,
  cardAccentAt,
  ConfirmDialog,
  EmptyState,
  MoreMenu,
  Pagination,
  SearchBox,
  SelectField,
  StatusChip,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { MOTION, ROUTES, routeTo } from '@/constants'
import { useDisclosure } from '@/hooks'
import type { Paginated, TakeoffStatus } from '@/types'
import { TAKEOFF_STATUS_LABEL, TAKEOFF_STATUS_TONE } from '@/utils'

interface ProjectRow {
  readonly id: number
  readonly name: string
  readonly code: string | null
  readonly status: TakeoffStatus
  readonly projectType: string | null
  /** The client's primary site, snapshotted — see `Project::addresses()`. */
  readonly location: string | null
  readonly drawingCount: number
  readonly takeoffCount: number
  readonly estimateCount: number
  readonly jobCount: number
  readonly teamCount: number
  readonly documentCount: number
  readonly invoiceCount: number
}

/** One client and the projects they have on — the unit this screen pages through. */
interface ClientGroup {
  readonly id: number
  readonly name: string
  readonly projects: readonly ProjectRow[]
}

export interface ProjectsProps {
  clients: Paginated<ClientGroup>
  filters: { readonly search: string; readonly status: string }
  statuses: readonly string[]
  /** Across every client this filter matches, not just the page shown. */
  totalProjects: number
}

/** "in-review" reads as a machine value; the label should not. */
const humanise = (value: string) =>
  value.charAt(0).toUpperCase() + value.slice(1).replaceAll('-', ' ')

/**
 * Every project, under the client it is for.
 *
 * Grouped rather than flat because a project's name only means something
 * beside its client — two clients can both have a "Phase 1". The client is
 * stated once per card, and adding a project starts from the card of the
 * client it is for, so that question is never asked twice.
 */
export default function Projects({ clients, filters, statuses, totalProjects }: ProjectsProps) {
  const [search, setSearch] = useState(filters.search)
  const filterBar = useDisclosure()
  const [pendingDelete, setPendingDelete] = useState<{ id: number; name: string } | null>(null)

  const groups = clients.data
  const activeFilters = [filters.search !== '', filters.status !== 'all'].filter(Boolean).length

  const apply = (changes: Record<string, string>) => {
    const query = new URLSearchParams(window.location.search)

    for (const [key, value] of Object.entries(changes)) {
      if (value === '' || value === 'all') query.delete(key)
      else query.set(key, value)
    }

    query.delete('page')
    if (changes['page'] !== undefined) query.set('page', changes['page'])

    router.get(`${ROUTES.projects}?${query.toString()}`, undefined, {
      preserveState: true,
      replace: true,
    })
  }

  return (
    <PageTransition>
      <Head title="Projects" />

      <PageHeader
        title="Projects"
        subtitle="What the work is. Every drawing, takeoff and job belongs to one."
        breadcrumbs={[{ label: 'Projects' }]}
        actions={
          <>
            <Button
              variant="secondary"
              leftIcon={SlidersHorizontal}
              aria-expanded={filterBar.isOpen}
              onClick={filterBar.toggle}
            >
              Filter{activeFilters > 0 ? ` (${activeFilters})` : ''}
            </Button>
            <ButtonLink href={ROUTES.projectCreate} leftIcon={Plus}>
              Add Project
            </ButtonLink>
          </>
        }
      />

      <AnimatePresence initial={false}>
        {filterBar.isOpen && (
          <motion.div
            key="filter-bar"
            initial={{ opacity: 0, height: 0 }}
            animate={{ opacity: 1, height: 'auto' }}
            exit={{ opacity: 0, height: 0 }}
            transition={{ duration: MOTION.base }}
            className="mb-4 overflow-hidden"
          >
            <Card padding="md">
              <div className="grid gap-4 sm:grid-cols-2">
                <SearchBox
                  id="project-search"
                  label="Search"
                  value={search}
                  onValueChange={setSearch}
                  onSearch={(value) => apply({ search: value })}
                  placeholder="Search projects…"
                  aria-label="Search projects"
                />
                <SelectField
                  id="project-status-filter"
                  label="Status"
                  options={[
                    { label: 'All statuses', value: 'all' },
                    ...statuses.map((status) => ({ label: humanise(status), value: status })),
                  ]}
                  value={filters.status}
                  onChange={(event) => apply({ status: event.target.value })}
                />
              </div>

              {activeFilters > 0 && (
                <div className="mt-4 border-t border-hairline pt-4">
                  <Button
                    variant="secondary"
                    size="sm"
                    onClick={() => {
                      setSearch('')
                      apply({ search: '', status: 'all' })
                    }}
                  >
                    Reset filters
                  </Button>
                </div>
              )}
            </Card>
          </motion.div>
        )}
      </AnimatePresence>

      {groups.length === 0 ? (
        <Card padding="lg">
          <EmptyState
            icon={FolderKanban}
            title={activeFilters > 0 ? 'No project matches' : 'No clients yet'}
            description={
              activeFilters > 0
                ? 'Nothing on any client matches these filters.'
                : 'Add the client the work is for, then open a project under them.'
            }
            {...(activeFilters > 0
              ? {}
              : {
                  actions: (
                    <ButtonLink href={ROUTES.clientCreate} leftIcon={Plus}>
                      Add Client
                    </ButtonLink>
                  ),
                })}
          />
        </Card>
      ) : (
        <div className="flex flex-col gap-4">
          {groups.map((group, index) => (
            /*
             * The same accent the task list walks down its job groups with, so
             * a client group here reads as the same kind of thing. Cycled by
             * position rather than tied to anything: a client has no status to
             * colour it by, and the border is here to separate one card from
             * the next.
             */
            <Card key={group.id} padding="lg" className={cardAccentAt(index)}>
              <header className="mb-4 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-b border-hairline pb-3">
                <Link
                  href={routeTo.client(group.id)}
                  className="min-w-0 truncate text-lg font-semibold text-white transition-colors hover:text-brand"
                >
                  {group.name}
                </Link>

                <div className="flex shrink-0 items-center gap-3">
                  <span className="text-sm text-white/70">
                    {group.projects.length}{' '}
                    {group.projects.length === 1 ? 'project' : 'projects'}
                  </span>
                  {/* Adding to *this* client, so it never asks who it is for. */}
                  <ButtonLink
                    href={routeTo.projectCreateForClient(group.id)}
                    variant="secondary"
                    size="sm"
                    leftIcon={Plus}
                  >
                    Add Project
                  </ButtonLink>
                </div>
              </header>

              {group.projects.length === 0 ? (
                <p className="text-md text-white/70">
                  Nothing on for this client yet. Open the first project above.
                </p>
              ) : (
                <ul className="space-y-3">
                  {group.projects.map((project) => (
                    <li
                      key={project.id}
                      className={cardAccentAt(index, 'rounded-panel bg-white/4 p-4')}
                    >
                      <div className="flex flex-wrap items-start justify-between gap-3">
                        <div className="min-w-0">
                          <div className="flex flex-wrap items-center gap-2">
                            <Link
                              href={routeTo.project(project.id)}
                              className="truncate font-semibold text-white transition-colors hover:text-brand"
                            >
                              {project.name}
                            </Link>
                            <StatusChip
                              hideDot
                              tone={TAKEOFF_STATUS_TONE[project.status]}
                              label={TAKEOFF_STATUS_LABEL[project.status]}
                            />
                          </div>
                          {project.location && (
                            <p className="mt-1 flex items-center gap-1.5 text-sm text-white/60">
                              <MapPin size={13} aria-hidden className="shrink-0" />
                              {project.location}
                            </p>
                          )}
                        </div>

                        <div className="flex shrink-0 items-center gap-2">
                          {/*
                            Not a real feature yet — see the same placeholder on
                            the Clients list. Shown anyway, but plainly marked so
                            it reads as "coming later" rather than as data nobody
                            trusts.
                          */}
                          <span title="Commodity lists aren't tracked yet — coming soon">
                            <Badge tone="neutral" size="sm">
                              Not available yet
                            </Badge>
                          </span>
                          <MoreMenu
                            ariaLabel={`Actions for ${project.name}`}
                            variant="minimal"
                            items={[
                              {
                                label: 'Edit',
                                icon: PencilLine,
                                onSelect: () => router.visit(routeTo.projectEdit(project.id)),
                              },
                              {
                                label: 'Delete',
                                icon: Trash2,
                                destructive: true,
                                onSelect: () => setPendingDelete(project),
                              },
                            ]}
                          />
                        </div>
                      </div>

                      <div className="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-hairline pt-3">
                        <div className="flex flex-wrap items-center gap-x-5 gap-y-2">
                          <StatItem icon={FileText} value={project.drawingCount} label="Drawings" />
                          <StatItem icon={Sparkles} value={project.takeoffCount} label="Takeoffs" />
                          <StatItem icon={ClipboardList} value={project.estimateCount} label="Estimates" />
                          <StatItem icon={Briefcase} value={project.jobCount} label="Jobs" />
                          <StatItem icon={Users} value={project.teamCount} label="Team" />
                          <StatItem icon={Files} value={project.documentCount} label="Documents" />
                          <StatItem icon={Receipt} value={project.invoiceCount} label="Invoices" />
                        </div>

                        <ButtonLink
                          href={routeTo.project(project.id)}
                          variant="secondary"
                          size="sm"
                          leftIcon={ExternalLink}
                        >
                          Open
                        </ButtonLink>
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </Card>
          ))}

          <Alert tone="info" title="Invoices live inside Projects">
            Create, manage, and track all invoices for each project. Open a project to view its
            invoices.
          </Alert>
        </div>
      )}

      <Pagination
        withLabels
        className="mt-6"
        page={clients.meta.current_page}
        pageCount={clients.meta.last_page}
        onPageChange={(page) => apply({ page: String(page) })}
        summary={
          clients.meta.total === 0
            ? 'No clients to display'
            : `${clients.meta.total} ${clients.meta.total === 1 ? 'client' : 'clients'} • ${totalProjects} ${totalProjects === 1 ? 'project' : 'projects'}`
        }
      />

      <ConfirmDialog
        isOpen={pendingDelete !== null}
        tone="danger"
        title={`Delete "${pendingDelete?.name ?? ''}"?`}
        description="The project is removed from your list. Its drawings stay on file."
        confirmLabel="Delete Project"
        confirmVariant="danger"
        onConfirm={() => {
          if (pendingDelete) router.delete(routeTo.project(pendingDelete.id))
          setPendingDelete(null)
        }}
        onCancel={() => setPendingDelete(null)}
      />
    </PageTransition>
  )
}

interface StatItemProps {
  icon: LucideIcon
  value: number
  label: string
}

function StatItem({ icon: Icon, value, label }: StatItemProps) {
  return (
    <div className="flex items-center gap-2">
      <Icon size={16} aria-hidden className="shrink-0 text-white/50" />
      <div className="leading-tight">
        <p className="text-sm font-semibold text-white">{value}</p>
        <p className="text-xs text-white/50">{label}</p>
      </div>
    </div>
  )
}

Projects.layout = appLayout
