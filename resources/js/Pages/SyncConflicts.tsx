import { Head, router, usePage } from '@inertiajs/react'
import { Check, GitMerge, Smartphone } from 'lucide-react'
import { Alert, Badge, Button, Card, EmptyState } from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES, routeTo } from '@/constants'
import type { SharedPageProps } from '@/types'
import { formatModified } from '@/utils'

interface ConflictField {
  readonly key: string
  readonly label: string
  readonly field: { readonly value: string | null; readonly at: string | null }
  readonly office: { readonly value: string | null; readonly at: string | null }
}

interface Conflict {
  readonly id: number
  readonly title: string
  readonly job: string | null
  readonly jobId: number | null
  readonly reporter: string | null
  readonly fields: readonly ConflictField[]
  readonly status: 'open' | 'resolved'
  readonly resolution: 'keep_office' | 'apply_field' | null
  readonly resolvedBy: string | null
  readonly resolvedAt: string | null
  readonly createdAt: string
}

export interface SyncConflictsProps {
  conflicts: readonly Conflict[]
}

/**
 * Sync conflicts: a technician changed something in the field while the office changed the same thing,
 * and sent the clash up. Keep the office's version, or apply the one from the field.
 */
export default function SyncConflicts({ conflicts }: SyncConflictsProps) {
  const { flash } = usePage<SharedPageProps>().props

  const resolve = (conflict: Conflict, resolution: 'keep_office' | 'apply_field') =>
    router.post(routeTo.syncConflictResolve(conflict.id), { resolution }, { preserveScroll: true })

  return (
    <PageTransition>
      <Head title="Sync Conflicts" />

      <PageHeader
        title="Sync Conflicts"
        subtitle="Changes from the field that clashed with an office edit"
        breadcrumbs={[{ label: 'Jobs', href: ROUTES.jobs }, { label: 'Sync Conflicts' }]}
        className="mb-5"
      />

      {flash.success && (
        <Alert key={flash.success} tone="success" className="mb-4">
          {flash.success}
        </Alert>
      )}

      {conflicts.length === 0 ? (
        <Card padding="lg">
          <EmptyState
            icon={GitMerge}
            title="No conflicts"
            description="When a technician's offline change clashes with something you changed, and they send it up, it lands here."
          />
        </Card>
      ) : (
        <div className="space-y-4">
          {conflicts.map((conflict) => (
            <Card key={conflict.id} padding="md">
              <div className="mb-3 flex flex-wrap items-start justify-between gap-3">
                <div>
                  <h2 className="text-lg font-semibold text-white">{conflict.title}</h2>
                  <p className="text-sm text-white/75">
                    {conflict.job ?? 'Job'} · from {conflict.reporter ?? 'a technician'} ·{' '}
                    {formatModified(conflict.createdAt)}
                  </p>
                </div>
                {conflict.status === 'open' ? (
                  <Badge tone="warning">Needs a decision</Badge>
                ) : (
                  <Badge tone="success">
                    {conflict.resolution === 'apply_field' ? 'Field version applied' : 'Office version kept'}
                  </Badge>
                )}
              </div>

              <div className="space-y-3">
                {conflict.fields.map((field) => (
                  <div key={field.key} className="rounded-panel border border-hairline p-3">
                    <p className="mb-2 text-sm font-semibold text-white">{field.label}</p>
                    <div className="grid gap-3 sm:grid-cols-2">
                      <div>
                        <p className="flex items-center gap-1.5 text-xs text-white/70">
                          <Smartphone size={13} aria-hidden /> Field
                          {field.field.at && <span> · {formatModified(field.field.at)}</span>}
                        </p>
                        <p className="mt-1 rounded-panel bg-white/6 p-2 text-md text-white">
                          {field.field.value ?? '—'}
                        </p>
                      </div>
                      <div>
                        <p className="text-xs text-white/70">
                          Office{field.office.at && <span> · {formatModified(field.office.at)}</span>}
                        </p>
                        <p className="mt-1 rounded-panel bg-white/6 p-2 text-md text-white">
                          {field.office.value ?? '—'}
                        </p>
                      </div>
                    </div>
                  </div>
                ))}
              </div>

              {conflict.status === 'open' && (
                <div className="mt-4 flex flex-wrap justify-end gap-3">
                  <Button variant="secondary" leftIcon={Check} onClick={() => resolve(conflict, 'keep_office')}>
                    Keep office version
                  </Button>
                  <Button leftIcon={Smartphone} onClick={() => resolve(conflict, 'apply_field')}>
                    Apply field version
                  </Button>
                </div>
              )}
            </Card>
          ))}
        </div>
      )}
    </PageTransition>
  )
}

SyncConflicts.layout = appLayout
