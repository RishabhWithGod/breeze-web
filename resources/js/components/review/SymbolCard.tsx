import { useCallback, useState } from 'react'
import { router } from '@inertiajs/react'
import {
  Check,
  Minus,
  Pencil,
  Plus,
  RotateCcw,
  Scissors,
  StickyNote,
  X,
} from 'lucide-react'
import {
  Badge,
  Button,
  Card,
  Checkbox,
  IconButton,
  StatusChip,
  TextArea,
  TextInput,
} from '@/components/common'
import { REVIEW_STATUS_LABEL, REVIEW_STATUS_TONE, routeTo } from '@/constants'
import type { SymbolReviewRow } from '@/types'
import { cn } from '@/utils'
import { PipelineTrail } from './PipelineTrail'
import { SourceBadges } from './SourceBadges'

/** Which inline editor the card currently shows. */
type CardMode = 'rename' | 'split' | 'notes' | null

export interface SymbolCardProps {
  resultId: number
  row: SymbolReviewRow
  /** Selected for a merge. */
  selected: boolean
  onSelect: (id: number, selected: boolean) => void
  /** A finalised takeoff is read-only until it is reopened. */
  locked?: boolean
  index?: number
}

/**
 * One detected symbol, with every decision the reviewer can make on it.
 *
 * Each action posts to its own endpoint and the page re-renders from the server,
 * so the card never shows a decision the database has not accepted.
 */
export function SymbolCard({
  resultId,
  row,
  selected,
  onSelect,
  locked = false,
  index = 0,
}: SymbolCardProps) {
  const [mode, setMode] = useState<CardMode>(null)
  // Null means "show the server's count"; a string means the field is being typed in.
  const [countDraft, setCountDraft] = useState<string | null>(null)
  const [renameDraft, setRenameDraft] = useState(row.name)
  const [noteDraft, setNoteDraft] = useState(row.notes ?? '')
  const [splitName, setSplitName] = useState('')
  const [splitCount, setSplitCount] = useState('1')

  const post = useCallback(
    (url: string, data: Record<string, string | number | null> = {}, onDone?: () => void) => {
      router.post(url, data, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => onDone?.(),
      })
    },
    [],
  )

  const step = useCallback(
    (by: number) => {
      setCountDraft(null)
      post(routeTo.symbolCount(resultId, row.id), { step: by })
    },
    [post, resultId, row.id],
  )

  const commitCount = useCallback(() => {
    if (countDraft === null) return

    const next = Number.parseInt(countDraft, 10)
    setCountDraft(null)

    if (Number.isNaN(next) || next === row.finalCount) return

    post(routeTo.symbolCount(resultId, row.id), { count: Math.max(0, next) })
  }, [countDraft, post, resultId, row.finalCount, row.id])

  const bbox = row.bbox && row.bbox.length >= 4 ? row.bbox : null
  const isRejected = row.status === 'rejected'

  return (
    <Card
      padding="none"
      index={index}
      className={cn(
        'flex h-full flex-col overflow-hidden',
        selected && 'ring-1 ring-brand/70',
        isRejected && 'opacity-80',
      )}
    >
      {/* Crop preview */}
      <div className="relative flex h-32 items-center justify-center border-b border-hairline bg-white/5">
        {row.cropUrl ? (
          <img
            src={row.cropUrl}
            alt={`Detected ${row.name} on page ${row.page}`}
            className="max-h-28 max-w-full object-contain"
          />
        ) : (
          <span className="px-4 text-center text-2xs text-white/45">
            No crop image returned for this detection
          </span>
        )}

        <div className="absolute top-2 left-2">
          <Checkbox
            id={`select-symbol-${row.id}`}
            checked={selected}
            onChange={(event) => onSelect(row.id, event.target.checked)}
            label=""
            aria-label={`Select ${row.name} for merging`}
          />
        </div>

        <div className="absolute top-2 right-2 flex flex-col items-end gap-1">
          {/* The engine's own verdict, in its own words where it gave one. */}
          <Badge tone={row.isKnown ? 'success' : 'warning'} size="sm">
            {row.finalDecision || (row.isKnown ? 'Known Symbol' : 'Unknown Symbol')}
          </Badge>
          {row.origin === 'needs_review' && (
            <Badge tone="info" size="sm">
              Needs review
            </Badge>
          )}
          {row.aiCategory === 'rejected' && (
            <Badge tone="danger" size="sm">
              AI rejected
            </Badge>
          )}
          {isRejected && row.aiCategory !== 'rejected' && (
            <Badge tone="danger" size="sm">
              Rejected
            </Badge>
          )}
          {row.isModified && (
            <Badge tone="info" size="sm">
              Modified
            </Badge>
          )}
        </div>
      </div>

      <div className="flex min-w-0 flex-1 flex-col gap-2.5 p-4">
        <div className="min-w-0">
          <h3 className="truncate text-md font-semibold text-white" title={row.name}>
            {row.name}
          </h3>
          {row.isRenamed && (
            <p className="truncate text-2xs text-white/50">
              AI called this “{row.aiName}”
            </p>
          )}
        </div>

        <div className="flex flex-wrap items-center gap-1.5">
          <Badge tone="neutral" size="sm" className="font-mono">
            {row.cropId ?? row.externalId ?? `#${row.id}`}
          </Badge>
          <SourceBadges sources={row.sources} />
          <Badge tone="neutral" size="sm">
            page {row.page}
          </Badge>
          <Badge tone="brand" size="sm">
            conf {Math.round(row.confidence * 100)}%
          </Badge>
          {row.cropCount > 1 && (
            <Badge tone="neutral" size="sm">
              {row.cropCount} crops
            </Badge>
          )}
        </div>

        <p className="font-mono text-2xs text-white/45">
          {bbox
            ? `bbox [${bbox.map((value) => Math.round(value)).join(', ')}]`
            : 'bbox not reported'}
        </p>

        {/* What corroborated the detection: legend, template, vector, vision. */}
        {row.evidence.length > 0 && (
          <p className="text-2xs text-white/55">
            <span className="text-white/40">evidence </span>
            {row.evidence.join(' · ')}
          </p>
        )}

        {row.reason && (
          <p className="rounded-panel bg-status-warning/10 px-3 py-2 text-2xs text-status-warning">
            {row.reason}
          </p>
        )}

        <PipelineTrail pipeline={row.pipeline} />

        <div className="flex items-center justify-between gap-2">
          <StatusChip
            tone={REVIEW_STATUS_TONE[row.status]}
            label={REVIEW_STATUS_LABEL[row.status]}
            className="text-sm"
          />
          {row.finalCount !== row.aiCount && (
            <span className="text-2xs text-white/50">AI said {row.aiCount}</span>
          )}
        </div>

        {/* Count editing: steppers plus a directly editable field. */}
        <div className="flex items-center gap-2">
          <IconButton
            variant="secondary"
            size="sm"
            icon={Minus}
            label={`Decrease the count for ${row.name}`}
            disabled={locked || row.finalCount <= 0}
            onClick={() => step(-1)}
          />
          <label className="sr-only" htmlFor={`symbol-count-${row.id}`}>
            Final count for {row.name}
          </label>
          <TextInput
            id={`symbol-count-${row.id}`}
            type="number"
            min={0}
            inputMode="numeric"
            value={countDraft ?? String(row.finalCount)}
            disabled={locked}
            onChange={(event) => setCountDraft(event.target.value)}
            onBlur={commitCount}
            onKeyDown={(event) => {
              if (event.key === 'Enter') {
                event.preventDefault()
                commitCount()
              }
            }}
            className="h-9 py-0 text-center"
          />
          <IconButton
            variant="secondary"
            size="sm"
            icon={Plus}
            label={`Increase the count for ${row.name}`}
            disabled={locked}
            onClick={() => step(1)}
          />
        </div>

        {row.notes && mode !== 'notes' && (
          <p className="rounded-panel bg-white/5 px-3 py-2 text-2xs text-white/70">
            {row.notes}
          </p>
        )}

        {/* Inline editors */}
        {mode === 'rename' && (
          <form
            className="flex flex-col gap-2"
            onSubmit={(event) => {
              event.preventDefault()
              post(
                routeTo.symbolRename(resultId, row.id),
                { name: renameDraft },
                () => setMode(null),
              )
            }}
          >
            <TextInput
              label="Rename symbol"
              id={`symbol-rename-${row.id}`}
              value={renameDraft}
              onChange={(event) => setRenameDraft(event.target.value)}
              placeholder="Pendant Light"
              autoFocus
            />
            <div className="flex gap-2">
              <Button type="submit" size="sm">
                Save name
              </Button>
              <Button type="button" variant="ghost" size="sm" onClick={() => setMode(null)}>
                Cancel
              </Button>
            </div>
          </form>
        )}

        {mode === 'split' && (
          <form
            className="flex flex-col gap-2"
            onSubmit={(event) => {
              event.preventDefault()
              post(
                routeTo.symbolSplit(resultId, row.id),
                { name: splitName, count: Number(splitCount) || 1 },
                () => {
                  setMode(null)
                  setSplitName('')
                  setSplitCount('1')
                },
              )
            }}
          >
            <TextInput
              label="Split off as"
              id={`symbol-split-name-${row.id}`}
              value={splitName}
              onChange={(event) => setSplitName(event.target.value)}
              placeholder="Emergency light"
              autoFocus
            />
            <TextInput
              label="Quantity to move"
              id={`symbol-split-count-${row.id}`}
              type="number"
              min={1}
              max={row.finalCount}
              value={splitCount}
              onChange={(event) => setSplitCount(event.target.value)}
              hint={`This detection is counted as ${row.finalCount}.`}
            />
            <div className="flex gap-2">
              <Button type="submit" size="sm">
                Split
              </Button>
              <Button type="button" variant="ghost" size="sm" onClick={() => setMode(null)}>
                Cancel
              </Button>
            </div>
          </form>
        )}

        {mode === 'notes' && (
          <form
            className="flex flex-col gap-2"
            onSubmit={(event) => {
              event.preventDefault()
              post(
                routeTo.symbolNote(resultId, row.id),
                { notes: noteDraft },
                () => setMode(null),
              )
            }}
          >
            <TextArea
              label="Note"
              id={`symbol-note-${row.id}`}
              rows={3}
              value={noteDraft}
              onChange={(event) => setNoteDraft(event.target.value)}
              placeholder="Why this count or name was changed…"
              autoFocus
            />
            <div className="flex gap-2">
              <Button type="submit" size="sm">
                Save note
              </Button>
              <Button type="button" variant="ghost" size="sm" onClick={() => setMode(null)}>
                Cancel
              </Button>
            </div>
          </form>
        )}

        {/* Decisions */}
        <div className="mt-auto flex flex-wrap gap-2 pt-1">
          {row.status === 'approved' ? (
            <Button
              variant="secondary"
              size="sm"
              leftIcon={RotateCcw}
              disabled={locked}
              onClick={() => post(routeTo.symbolReset(resultId, row.id))}
            >
              Undo
            </Button>
          ) : (
            <Button
              size="sm"
              leftIcon={Check}
              disabled={locked}
              onClick={() => post(routeTo.symbolApprove(resultId, row.id))}
            >
              Approve
            </Button>
          )}

          {row.status === 'rejected' ? (
            <Button
              variant="secondary"
              size="sm"
              leftIcon={RotateCcw}
              disabled={locked}
              onClick={() => post(routeTo.symbolReset(resultId, row.id))}
            >
              Reinstate
            </Button>
          ) : (
            <Button
              variant="danger"
              size="sm"
              leftIcon={X}
              disabled={locked}
              onClick={() => post(routeTo.symbolReject(resultId, row.id))}
            >
              Reject
            </Button>
          )}

          <Button
            variant="ghost"
            size="sm"
            leftIcon={Pencil}
            disabled={locked}
            onClick={() => {
              setRenameDraft(row.name)
              setMode(mode === 'rename' ? null : 'rename')
            }}
          >
            Rename
          </Button>
          <Button
            variant="ghost"
            size="sm"
            leftIcon={Scissors}
            disabled={locked || row.finalCount <= 1}
            onClick={() => setMode(mode === 'split' ? null : 'split')}
          >
            Split
          </Button>
          <Button
            variant="ghost"
            size="sm"
            leftIcon={StickyNote}
            disabled={locked}
            onClick={() => {
              setNoteDraft(row.notes ?? '')
              setMode(mode === 'notes' ? null : 'notes')
            }}
          >
            Notes
          </Button>
        </div>
      </div>
    </Card>
  )
}
