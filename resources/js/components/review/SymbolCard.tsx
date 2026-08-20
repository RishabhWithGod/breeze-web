import { useCallback, useState } from 'react'
import { router } from '@inertiajs/react'
import {
  Check,
  Minus,
  History,
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
  DetailRow,
  IconButton,
  MoreMenu,
  StatusChip,
  TextArea,
  TextInput,
} from '@/components/common'
import { REVIEW_STATUS_LABEL, REVIEW_STATUS_TONE, routeTo } from '@/constants'
import type { SymbolReviewRow } from '@/types'
import { cn, formatRelative } from '@/utils'

/** Which inline editor the card currently shows. */
type CardMode = 'rename' | 'split' | 'notes' | 'history' | null

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
 * One counted symbol, with every decision the reviewer can make on it.
 *
 * The card answers an estimator's question and nothing else: what is it, how many,
 * and is that right. Everything describing *how the engine decided* — the crop id,
 * the bounding box, which detectors fired, confidence, the pipeline trail — is real
 * and occasionally settles an argument, so none of it is discarded; it moves behind
 * "Advanced details" where it stops competing with the count.
 *
 * Each action posts to its own endpoint and the page re-renders from the server, so
 * the card never shows a decision the database has not accepted.
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
  // A URL that exists but 404s/CORS-fails is a different state than having no
  // URL at all — the reviewer should be told the image broke, not that the
  // symbol has none. Reset during render (not an effect) when the server hands
  // us a different URL to try, e.g. once a backfilled crop lands.
  const [imageFailed, setImageFailed] = useState(false)
  const [lastCropUrl, setLastCropUrl] = useState(row.cropUrl)

  if (row.cropUrl !== lastCropUrl) {
    setLastCropUrl(row.cropUrl)
    setImageFailed(false)
  }

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

  const isRejected = row.status === 'rejected'

  return (
    <Card
      padding="none"
      index={index}
      className={cn(
        'flex h-full flex-col overflow-hidden',
        selected && 'ring-1 ring-brand/70',
        isRejected && 'opacity-75',
      )}
    >
      {/* Symbol image */}
      <div className="relative flex h-36 items-center justify-center border-b border-hairline bg-white/5">
        {row.cropUrl && !imageFailed ? (
          <img
            src={row.cropUrl}
            alt={row.name}
            className="max-h-32 max-w-full object-contain"
            onError={() => {
              if (import.meta.env.DEV) {
                console.error(
                  `Symbol crop failed to load for "${row.name}" (review #${row.id}): ${row.cropUrl}`,
                )
              }
              setImageFailed(true)
            }}
          />
        ) : (
          <span className="px-4 text-center text-2xs text-white/65">
            {row.cropUrl ? 'Image failed to load' : 'No image for this symbol'}
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

        {/*
          Only states a reviewer acts on. The engine's own verdict, its category and
          its provenance all moved into Advanced details.
        */}
        <div className="absolute top-2 right-2 flex flex-col items-end gap-1">
          {row.origin === 'needs_review' && (
            <Badge tone="warning" size="sm">
              Needs review
            </Badge>
          )}
          {isRejected && (
            <Badge tone="danger" size="sm">
              Rejected
            </Badge>
          )}
          {row.isModified && (
            <Badge tone="info" size="sm">
              Edited
            </Badge>
          )}
        </div>
      </div>

      <div className="flex min-w-0 flex-1 flex-col gap-4 p-5">
        {/* Symbol name */}
        <div className="min-w-0">
          <h3 className="truncate text-lg font-semibold text-white" title={row.name}>
            {row.name}
          </h3>
          <div className="mt-1.5 flex items-center gap-2">
            <StatusChip
              tone={REVIEW_STATUS_TONE[row.status]}
              label={REVIEW_STATUS_LABEL[row.status]}
              className="text-2xs"
            />
            {row.isRenamed && (
              <span className="truncate text-2xs text-white/70">
                was “{row.aiName}”
              </span>
            )}
          </div>
        </div>

        {/* Quantity — the number the estimate is built from, so it leads. */}
        <div>
          <label
            className="text-2xs tracking-wide text-white/75 uppercase"
            htmlFor={`symbol-count-${row.id}`}
          >
            Quantity
          </label>
          <div className="mt-1.5 flex items-center gap-2">
            <IconButton
              variant="secondary"
              size="sm"
              icon={Minus}
              label={`Decrease the quantity of ${row.name}`}
              disabled={locked || row.finalCount <= 0}
              onClick={() => step(-1)}
            />
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
              className="h-10 py-0 text-center text-base font-semibold"
            />
            <IconButton
              variant="secondary"
              size="sm"
              icon={Plus}
              label={`Increase the quantity of ${row.name}`}
              disabled={locked}
              onClick={() => step(1)}
            />
          </div>
        </div>

        {row.notes && mode !== 'notes' && (
          <p className="rounded-panel bg-white/5 px-3 py-2 text-2xs text-white/90">
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
              hint={`This symbol is counted as ${row.finalCount}.`}
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

        {mode === 'history' && (
          <div className="rounded-panel border border-hairline bg-white/5 p-3">
            <div className="mb-2 flex items-center justify-between gap-2">
              <p className="text-2xs tracking-wide text-white/75 uppercase">History</p>
              <Button variant="ghost" size="sm" onClick={() => setMode(null)}>
                Close
              </Button>
            </div>
            <dl className="divide-y divide-hairline">
              <DetailRow label="AI reported">
                {row.aiName} × {row.aiCount}
              </DetailRow>
              {row.isRenamed && <DetailRow label="Renamed to">{row.name}</DetailRow>}
              {row.finalCount !== row.aiCount && (
                <DetailRow label="Quantity changed to">{row.finalCount}</DetailRow>
              )}
              <DetailRow label="Decision">
                {REVIEW_STATUS_LABEL[row.status]}
              </DetailRow>
              <DetailRow label="Reviewed">
                {row.reviewedAt ? formatRelative(row.reviewedAt) : 'Not yet'}
              </DetailRow>
              {row.splitFromId && <DetailRow label="Split from">#{row.splitFromId}</DetailRow>}
              {row.mergedIntoId && (
                <DetailRow label="Merged into">#{row.mergedIntoId}</DetailRow>
              )}
            </dl>
          </div>
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

        {/* Two decisions on the surface; everything else behind More. */}
        <div className="mt-auto grid grid-cols-3 gap-2 pt-1">
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

          {isRejected ? (
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

          <MoreMenu
            ariaLabel={`More actions for ${row.name}`}
            items={[
              {
                label: 'Rename',
                icon: Pencil,
                disabled: locked,
                onSelect: () => {
                  setRenameDraft(row.name)
                  setMode('rename')
                },
              },
              {
                label: 'Split',
                icon: Scissors,
                disabled: locked || row.finalCount <= 1,
                onSelect: () => setMode('split'),
              },
              {
                label: 'Notes',
                icon: StickyNote,
                disabled: locked,
                onSelect: () => {
                  setNoteDraft(row.notes ?? '')
                  setMode('notes')
                },
              },
              {
                label: 'History',
                icon: History,
                onSelect: () => setMode('history'),
              },
            ]}
          />
        </div>

      </div>
    </Card>
  )
}
