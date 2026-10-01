import { Head, router, usePage } from '@inertiajs/react'
import {
  ArrowRight,
  Briefcase,
  Building2,
  Check,
  Clock,
  FileDown,
  FileText,
  FolderOpen,
  History,
  MessageSquare,
  MinusCircle,
  RotateCcw,
  ShieldCheck,
  Table2,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useState } from 'react'
import { Alert, Button, ButtonLink, Card, ConfirmDialog, StatusChip, TextArea } from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES, routeTo } from '@/constants'
import type { SharedPageProps } from '@/types'
import { cn, formatCurrency, formatDate } from '@/utils'

export interface EstimateReviewProps {
  estimate: {
    readonly id: number
    readonly number: string
    readonly status: 'sent' | 'approved'
    readonly builderManaged: boolean
  }
  client: { readonly name: string; readonly address: string | null }
  project: { readonly name: string; readonly detail: string }
  scopeOfWork: string | null
  exclusions: readonly string[]
  summary: {
    readonly rows: readonly { readonly category: string; readonly amount: number; readonly pct: number }[]
    /** Material, labor and equipment before markup and tax. */
    readonly cost: number
    readonly markup: number
    readonly tax: number
    /** What the customer is charged. */
    readonly total: number
  }
  revisions: readonly {
    readonly version: number
    readonly date: string | null
    readonly changes: string
    readonly by: string | null
    readonly total: number
    readonly approved: boolean
  }[]
  approval: {
    readonly by: string | null
    readonly at: string | null
    readonly revision: number | null
    readonly notes: string | null
  } | null
  canDecide: boolean
  jobUrl: string | null
  hasJob: boolean
  exports: { readonly pdf: string; readonly csv: string } | null
}

const MAX_NOTES = 1000

/**
 * Estimate Review and Approval — verify the scope and authorize the estimate for use.
 *
 * The reviewer reads who it is for, what it covers and leaves out, and where the money
 * goes, then approves it (which locks the revision and records who and when) or returns
 * it for edits with notes. Once approved it can go out as a file or become a job.
 */
export default function EstimateReview({
  estimate,
  client,
  project,
  scopeOfWork,
  exclusions,
  summary,
  revisions,
  approval,
  canDecide,
  jobUrl,
  hasJob,
  exports,
}: EstimateReviewProps) {
  const { flash, errors } = usePage<SharedPageProps>().props
  const [notes, setNotes] = useState('')
  const [confirming, setConfirming] = useState(false)
  const [busy, setBusy] = useState(false)
  const [needNotes, setNeedNotes] = useState(false)

  const approved = estimate.status === 'approved'
  const notesError = needNotes
    ? 'Say what needs to change, so it can be fixed.'
    : (errors['notes'] as string | undefined)

  const send = (url: string) =>
    router.post(
      url,
      { notes },
      {
        preserveScroll: true,
        onStart: () => setBusy(true),
        onFinish: () => {
          setBusy(false)
          setConfirming(false)
        },
      },
    )

  const returnToDraft = () => {
    if (notes.trim().length < 3) return setNeedNotes(true)
    send(routeTo.estimateReturn(estimate.id))
  }

  return (
    <PageTransition>
      <Head title="Estimate Review and Approval" />

      <PageHeader
        title="Estimate Review and Approval"
        subtitle={
          approved
            ? 'This estimate is approved and its revision is locked.'
            : 'Review the details below before approving this estimate.'
        }
        breadcrumbs={[{ label: 'Estimates', href: ROUTES.estimates }, { label: estimate.number }]}
        actions={
          <StatusChip
            pill
            tone={approved ? 'success' : 'warning'}
            label={approved ? 'Approved' : 'Pending Approval'}
            className="px-5 py-2.5 text-md"
          />
        }
      />

      {flash.success && (
        <Alert key={flash.success} tone="success" className="mb-4">
          {flash.success}
        </Alert>
      )}
      {flash.warning && (
        <Alert key={flash.warning} tone="warning" className="mb-4">
          {flash.warning}
        </Alert>
      )}

      <div className="grid items-start gap-5 xl:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)]">
        <div className="min-w-0 space-y-5">
          {/* --------------------------------------------------------- client & project -- */}
          <Card padding="md">
            <div className="grid gap-5 sm:grid-cols-2 sm:divide-x sm:divide-hairline">
              <Party
                icon={Building2}
                label="Client"
                name={client.name}
                detail={client.address ?? 'No address on file'}
              />
              <Party
                icon={FolderOpen}
                label="Project"
                name={project.name}
                detail={project.detail}
                className="sm:pl-5"
              />
            </div>
          </Card>

          {/* ------------------------------------------------------------ scope of work -- */}
          <Card padding="md">
            <SectionTitle icon={FileText}>Scope of Work</SectionTitle>
            {scopeOfWork ? (
              <p className="mt-3 text-md leading-relaxed whitespace-pre-line text-white/90">{scopeOfWork}</p>
            ) : (
              <p className="mt-3 text-md text-white/65">No scope of work was written for this estimate.</p>
            )}
          </Card>

          {/* --------------------------------------------------------------- exclusions -- */}
          <Card padding="md">
            <SectionTitle icon={MinusCircle}>Exclusions</SectionTitle>
            {exclusions.length === 0 ? (
              <p className="mt-3 text-md text-white/65">Nothing is listed as excluded.</p>
            ) : (
              <ul className="mt-3 space-y-2">
                {exclusions.map((line) => (
                  <li key={line} className="flex items-start gap-3 text-md text-white/90">
                    <span aria-hidden className="mt-2 size-1.5 shrink-0 rounded-full bg-white/80" />
                    {line}
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>

        <div className="min-w-0 space-y-5">
          {/* -------------------------------------------------------- line item summary -- */}
          <Card padding="none" className="overflow-hidden">
            <div className="px-5 pt-5">
              <SectionTitle icon={Table2}>Line Item Summary</SectionTitle>
            </div>
            <table className="mt-3 w-full text-left text-md">
              <thead>
                <tr className="border-b border-hairline text-sm text-white/75">
                  <th className="px-5 py-2 font-medium">Category</th>
                  <th className="px-3 py-2 text-right font-medium">Amount</th>
                  <th className="px-5 py-2 text-right font-medium">% of Total</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-hairline">
                {summary.rows.map((row) => (
                  <tr key={row.category}>
                    <td className="px-5 py-2.5 text-white">{row.category}</td>
                    <td className="px-3 py-2.5 text-right tabular-nums text-white">{formatCurrency(row.amount, 2)}</td>
                    <td className="px-5 py-2.5 text-right tabular-nums text-white/85">{row.pct.toFixed(1)}%</td>
                  </tr>
                ))}
                {summary.rows.length === 0 && (
                  <tr>
                    <td colSpan={3} className="px-5 py-6 text-center text-white/65">
                      Nothing has been priced.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
            <div className="flex items-center justify-between gap-4 bg-brand/10 px-5 py-4">
              <span className="text-lg font-semibold text-white">Total Estimate</span>
              <span className="flex items-baseline gap-8">
                <span className="text-2xl font-bold text-brand tabular-nums">{formatCurrency(summary.total, 2)}</span>
                <span className="text-md font-semibold text-white tabular-nums">100%</span>
              </span>
            </div>
            <dl className="grid grid-cols-3 divide-x divide-hairline border-t border-hairline text-center text-sm">
              {(
                [
                  ['Cost', summary.cost],
                  ['Markup', summary.markup],
                  ['Sell total', summary.total],
                ] as const
              ).map(([label, value]) => (
                <div key={label} className="px-3 py-3">
                  <dt className="text-xs text-white/65">{label}</dt>
                  <dd className="mt-0.5 font-semibold tabular-nums text-white">{formatCurrency(value, 2)}</dd>
                </div>
              ))}
            </dl>
          </Card>

          {/* ----------------------------------------------------------- revision history -- */}
          <Card padding="md">
            <SectionTitle icon={History}>Revision History</SectionTitle>
            {revisions.length === 0 ? (
              <p className="mt-3 text-md text-white/65">No revisions were recorded for this estimate.</p>
            ) : (
              <div className="mt-3 overflow-x-auto">
                <table className="w-full min-w-md text-left text-md">
                  <thead>
                    <tr className="border-b border-hairline text-sm text-white/75">
                      <th className="py-2 pr-3 font-medium">Version</th>
                      <th className="px-3 py-2 font-medium">Date</th>
                      <th className="px-3 py-2 font-medium">Changes</th>
                      <th className="py-2 pl-3 font-medium">By</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-hairline">
                    {revisions.map((revision) => (
                      <tr key={revision.version}>
                        <td className="py-2.5 pr-3 whitespace-nowrap text-white">
                          v{revision.version}
                          {revision.approved && (
                            <span className="ml-2 inline-flex items-center gap-1 rounded-full bg-status-success/15 px-2 py-0.5 text-2xs font-semibold text-status-success">
                              <ShieldCheck size={11} aria-hidden /> Approved
                            </span>
                          )}
                        </td>
                        <td className="px-3 py-2.5 whitespace-nowrap text-white/85">
                          {revision.date ? formatDate(revision.date) : '—'}
                        </td>
                        <td className="px-3 py-2.5 text-white/90">{revision.changes}</td>
                        <td className="py-2.5 pl-3 whitespace-nowrap text-white/85">{revision.by ?? '—'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Card>
        </div>
      </div>

      {/* --------------------------------------------------------- reviewer notes / result -- */}
      <Card padding="md" className="mt-5">
        <SectionTitle icon={MessageSquare}>Reviewer Notes</SectionTitle>

        {canDecide ? (
          <>
            <TextArea
              id="reviewer-notes"
              className="mt-3"
              rows={3}
              maxLength={MAX_NOTES}
              placeholder="Add your notes or comments for the estimate reviewer..."
              aria-label="Reviewer notes"
              value={notes}
              onChange={(event) => {
                setNotes(event.target.value)
                setNeedNotes(false)
              }}
              addon={`${notes.length} / ${MAX_NOTES}`}
              {...(notesError ? { error: notesError } : {})}
            />
            <div className="mt-4 flex flex-wrap items-center justify-end gap-3">
              <Button variant="secondary" size="lg" leftIcon={RotateCcw} disabled={busy} onClick={returnToDraft}>
                Return to Draft
              </Button>
              <Button variant="primary" size="lg" leftIcon={Check} disabled={busy} onClick={() => setConfirming(true)}>
                Approve Estimate
              </Button>
            </div>
          </>
        ) : approved && approval ? (
          <div className="mt-3 space-y-4">
            <p className="flex flex-wrap items-center gap-2 text-md text-white/90">
              <ShieldCheck size={18} aria-hidden className="text-status-success" />
              Approved by <strong className="text-white">{approval.by ?? 'a reviewer'}</strong>
              {approval.at && <>on {formatDate(approval.at)}</>}
              {approval.revision && <>· revision v{approval.revision} is locked</>}
            </p>
            {approval.notes && (
              <p className="rounded-panel border border-hairline bg-white/4 px-4 py-3 text-md whitespace-pre-line text-white/90">
                {approval.notes}
              </p>
            )}

            <div className="flex flex-wrap items-center justify-end gap-3">
              {exports && (
                <>
                  <a href={exports.pdf} className={cn(buttonLink)}>
                    <FileDown size={18} aria-hidden /> Export PDF
                  </a>
                  <a href={exports.csv} className={cn(buttonLink)}>
                    <FileDown size={18} aria-hidden /> Export CSV
                  </a>
                </>
              )}
              {jobUrl && (
                <ButtonLink href={jobUrl} size="lg" rightIcon={hasJob ? Briefcase : ArrowRight}>
                  {hasJob ? 'View Job' : 'Create Job'}
                </ButtonLink>
              )}
            </div>
          </div>
        ) : (
          <p className="mt-3 flex items-center gap-2 text-md text-white/75">
            <Clock size={16} aria-hidden /> Waiting for a project manager, estimator or supervisor to review it.
          </p>
        )}
      </Card>

      <ConfirmDialog
        isOpen={confirming}
        title={`Approve ${estimate.number}?`}
        description={`This authorizes the estimate for use at ${formatCurrency(summary.total, 2)}. The current revision is locked, and your name and the time are recorded.`}
        confirmLabel="Approve Estimate"
        tone="brand"
        isBusy={busy}
        onConfirm={() => send(routeTo.estimateApprove(estimate.id))}
        onCancel={() => setConfirming(false)}
      />
    </PageTransition>
  )
}

EstimateReview.layout = appLayout

const buttonLink =
  'inline-flex items-center gap-2 rounded-panel border border-hairline-strong bg-white/8 px-5 py-3 text-md font-semibold text-white transition-colors hover:bg-white/14'

function SectionTitle({ icon: Icon, children }: { icon: LucideIcon; children: React.ReactNode }) {
  return (
    <h2 className="flex items-center gap-3 text-lg font-semibold text-white">
      <Icon size={22} aria-hidden className="text-white/90" />
      {children}
    </h2>
  )
}

function Party({
  icon: Icon,
  label,
  name,
  detail,
  className,
}: {
  icon: LucideIcon
  label: string
  name: string
  detail: string
  className?: string
}) {
  return (
    <div className={cn('flex min-w-0 items-center gap-4', className)}>
      <span className="grid size-16 shrink-0 place-items-center rounded-full bg-white/8 text-white/90 ring-1 ring-hairline">
        <Icon size={28} aria-hidden />
      </span>
      <div className="min-w-0">
        <p className="text-sm text-white/70">{label}</p>
        <p className="truncate text-lg font-semibold text-white">{name}</p>
        <p className="truncate text-sm text-white/70">{detail}</p>
      </div>
    </div>
  )
}
