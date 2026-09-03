import { useState } from 'react'
import { Head, Link, router } from '@inertiajs/react'
import { AnimatePresence, motion } from 'framer-motion'
import {
  ExternalLink,
  FileText,
  FolderKanban,
  Plus,
  SlidersHorizontal,
  Sparkles,
} from 'lucide-react'
import {
  Button,
  ButtonLink,
  Card,
  EmptyState,
  Pagination,
  SearchBox,
  SelectField,
  StatusChip,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { MOTION, ROUTES, routeTo } from '@/constants'
import { useDisclosure } from '@/hooks'
import type { Paginated, TakeoffStatus } from '@/types'
import { TAKEOFF_STATUS_LABEL, TAKEOFF_STATUS_TONE, formatDate } from '@/utils'

interface ProjectRow {
  readonly id: number
  readonly name: string
  readonly code: string | null
  readonly status: TakeoffStatus
  readonly projectType: string | null
  readonly drawingCount: number
  readonly takeoffCount: number
  readonly createdAt: string | null
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
export default function Projects({ clients, filters, statuses }: ProjectsProps) {
  const [search, setSearch] = useState(filters.search)
  const filterBar = useDisclosure()

  const groups = clients.data
  const projectCount = groups.reduce((sum, group) => sum + group.projects.length, 0)
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
          {groups.map((group) => (
            <Card key={group.id} padding="lg">
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
                    Add project
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
                      className="rounded-panel border border-hairline bg-white/4 p-4"
                    >
                      <div className="flex flex-wrap items-start justify-between gap-3">
                        <div className="min-w-0">
                          <Link
                            href={routeTo.project(project.id)}
                            className="truncate font-semibold text-white transition-colors hover:text-brand"
                          >
                            {project.name}
                          </Link>
                          <p className="mt-0.5 flex flex-wrap items-center gap-x-3 text-sm text-white/70">
                            {project.code && <span>{project.code}</span>}
                            <span className="flex items-center gap-1.5">
                              <FileText size={13} aria-hidden />
                              {project.drawingCount}{' '}
                              {project.drawingCount === 1 ? 'drawing' : 'drawings'}
                            </span>
                            <span className="flex items-center gap-1.5">
                              <Sparkles size={13} aria-hidden />
                              {project.takeoffCount}{' '}
                              {project.takeoffCount === 1 ? 'takeoff' : 'takeoffs'}
                            </span>
                            {project.createdAt && (
                              <span>Opened {formatDate(project.createdAt)}</span>
                            )}
                          </p>
                        </div>

                        <div className="flex items-center gap-3">
                          <StatusChip
                            hideDot
                            tone={TAKEOFF_STATUS_TONE[project.status]}
                            label={TAKEOFF_STATUS_LABEL[project.status]}
                          />
                          <ButtonLink
                            href={routeTo.project(project.id)}
                            variant="ghost"
                            size="sm"
                            leftIcon={ExternalLink}
                          >
                            Open
                          </ButtonLink>
                        </div>
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </Card>
          ))}
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
            : `${projectCount} ${projectCount === 1 ? 'project' : 'projects'} across ${groups.length} of ${clients.meta.total} clients`
        }
      />
    </PageTransition>
  )
}

Projects.layout = appLayout
