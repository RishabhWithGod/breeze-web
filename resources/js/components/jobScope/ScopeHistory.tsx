import { useState } from 'react'
import { ChevronDown, FileText, Hammer, Receipt, RefreshCw, Smartphone, Wrench } from 'lucide-react'
import { Card, EmptyState, SectionHeading } from '@/components/common'
import type { ScopeChange, ScopeChangeLine } from '@/types'
import { cn, formatCurrency, formatRelative } from '@/utils'

const SOURCE_LABEL: Record<ScopeChange['source'], string> = {
  web: 'Web',
  builder: 'Estimate Builder',
  mobile: 'Phone',
  field: 'Field crew',
  invoice: 'Invoice',
  system: 'System',
}

const KIND_ICON = {
  estimate: FileText,
  change_order: Wrench,
  cost_entry: Hammer,
  invoice: Receipt,
  hours: RefreshCw,
} as const

const ACTION_LABEL: Record<ScopeChangeLine['action'], string> = {
  added: 'Added',
  removed: 'Removed',
  changed: 'Changed',
  settings: 'Rate changed',
}

const money = (value: number | null | undefined) => (value === null || value === undefined ? null : formatCurrency(value, 2))

/**
 * A job's (or an estimate's) scope timeline — every change, who made it and
 * from where, newest first. Dollar figures appear only when the server sent
 * them; a role without cost access sees what changed, never the amounts.
 */
export function ScopeHistory({ changes, title = 'Change History', emptyText = 'Nothing has changed since this was created.' }: { changes: readonly ScopeChange[]; title?: string; emptyText?: string }) {
  return (
    <Card padding="lg">
      <SectionHeading as="h3" title={title} />
      {changes.length === 0 ? (
        <EmptyState title="No changes yet" description={emptyText} />
      ) : (
        <ol className="flex flex-col gap-2">
          {changes.map((change) => (
            <ChangeRow key={change.id} change={change} />
          ))}
        </ol>
      )}
    </Card>
  )
}

function ChangeRow({ change }: { change: ScopeChange }) {
  const [open, setOpen] = useState(false)
  const Icon = change.source === 'mobile' || change.source === 'field' ? Smartphone : KIND_ICON[change.kind]
  const hasLines = change.lines.length > 0

  return (
    <li className="rounded-panel border border-hairline bg-white/4">
      <button
        type="button"
        disabled={!hasLines}
        aria-expanded={open}
        onClick={() => setOpen((v) => !v)}
        className="flex w-full items-start gap-3 p-3 text-left disabled:cursor-default"
      >
        <Icon size={16} className="mt-0.5 shrink-0 text-brand" aria-hidden />
        <div className="min-w-0 flex-1">
          <p className="text-md font-medium text-white">{change.summary}</p>
          <p className="text-sm text-white/70">
            v{change.version} · {change.by ?? 'Someone'} · {SOURCE_LABEL[change.source]}
            {change.at && ` · ${formatRelative(change.at)}`}
          </p>
        </div>
        {change.amount !== null && change.amount !== 0 && (
          <span className={cn('shrink-0 text-md font-semibold tabular-nums', change.amount > 0 ? 'text-status-danger' : 'text-status-success')}>
            {change.amount > 0 ? '+' : ''}
            {formatCurrency(change.amount, 2)}
          </span>
        )}
        {hasLines && <ChevronDown size={16} className={cn('mt-0.5 shrink-0 text-white/60 transition-transform', open && 'rotate-180')} aria-hidden />}
      </button>

      {open && hasLines && (
        <ul className="flex flex-col gap-1 border-t border-hairline px-3 py-2 text-sm text-white/85">
          {change.lines.map((line, index) => (
            <LineRow key={index} line={line} />
          ))}
        </ul>
      )}
    </li>
  )
}

function LineRow({ line }: { line: ScopeChangeLine }) {
  if (line.action === 'settings') {
    return (
      <li>
        {Object.entries(line.settings ?? {}).map(([field, v]) => (
          <span key={field} className="mr-3">
            {field.replace('_pct', ' rate')}: {String(v.from)}% → {String(v.to)}%
          </span>
        ))}
      </li>
    )
  }

  const before = money(line.total_before)
  const after = money(line.total_after)
  const qty =
    line.qty_before !== null && line.qty_before !== undefined && line.qty_after !== null && line.qty_after !== undefined && line.qty_before !== line.qty_after
      ? ` · qty ${line.qty_before} → ${line.qty_after}`
      : ''

  return (
    <li className="flex items-start justify-between gap-3">
      <span className="min-w-0">
        <span className="font-medium text-white">{ACTION_LABEL[line.action]}</span> {line.description}
        <span className="text-white/60">
          {' '}
          ({line.category}){qty}
        </span>
      </span>
      {(before || after) && (
        <span className="shrink-0 tabular-nums text-white/75">
          {before ?? '—'} → {after ?? '—'}
        </span>
      )}
    </li>
  )
}
