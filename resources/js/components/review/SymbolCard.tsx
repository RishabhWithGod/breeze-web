import { useCallback, useState } from 'react'
import { router } from '@inertiajs/react'
import {
  Check,
  Minus,
  Pencil,
  Plus,
  RotateCcw,
  X,
} from 'lucide-react'
import {
  Badge,
  Button,
  Card,
  Checkbox,
  DetailRow,
  IconButton,
  StatusChip,
  TextArea,
  TextInput,
} from '@/components/common'
import { REVIEW_STATUS_LABEL, REVIEW_STATUS_TONE, routeTo } from '@/constants'
import type { SymbolReviewRow } from '@/types'
import { cn, formatRelative, symbolLabel } from '@/utils'
import type { SymbolColor } from '@/utils'

/** Which inline editor the card currently shows. */
type CardMode = 'rename' | 'split' | 'notes' | 'history' | null

export interface SymbolCardProps {
  resultId: number
  row: SymbolReviewRow
  /** Selected for a merge. */
  selected: boolean
  onSelect: (id: number, selected: boolean) => void
  /** A finalised takeoff is read-only until it is reopened. */
  index?: number
  /** Highlighted because its marker is selected on the drawing — distinct from `selected`, which is merge-checkbox state. */
  focused?: boolean
  /**
   * This category's colour, from the drawing's one canonical map. Required,
   * not optional: an optional colour meant a `?? symbolColor(name)` fallback,
   * and that fallback was a second mapping that could disagree with the legend
   * — the exact bug the map exists to prevent.
   */
  color: SymbolColor
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
  index = 0,
  focused = false,
  color,
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
      hoverable
      className={cn(
        'flex h-full flex-col overflow-hidden',
        /*
         * The section border every card in the app wears, in this category's
         * own identity colour — `solid`, the same value the dot beside the name
         * uses and the same one the colour bar along the top used before this,
         * so nothing about which card is which category has changed.
         *
         * Thick along the top rather than down the left. These are a grid, not
         * a stacked list: a lit left edge on a card in the middle of a row
         * points at the card beside it, and the eye reads the row as pairs.
         *
         * Set here rather than through `accent`, which takes one of the six app
         * tones and cannot say "whatever this category happens to be".
         */
        'border-2 border-t-[6px] border-(--sym-accent)',
        selected && 'ring-1 ring-brand/70',
        focused && 'ring-2 ring-white',
        isRejected && 'opacity-75',
      )}
      style={{ '--sym-accent': color.solid } as React.CSSProperties}
    >

      {/* Symbol image — a fixed height regardless of the crop's own aspect
          ratio, so every card presents the exact same size image area; the
          crop itself still shows uncropped (`object-contain`) so a
          technical symbol never loses an edge to fit the box. A fixed
          height rather than `aspect-square` — a flex column with a taller
          sibling (a long name, notes) can otherwise stretch an
          aspect-ratioed box past its ratio in some browsers. */}
      {/*
        A solid white plate, not a 5% tint.
        
        These crops are line art cut from the drawing: black strokes, often on
        a transparent background. On the dark card that meant black lines on
        near-black, and the symbol the reviewer is being asked to judge was the
        one thing they could not see. White is the paper the symbol was drawn
        on, so it is the background it reads on.
      */}
      <div className="relative flex h-32 w-full shrink-0 items-center justify-center border-b border-hairline bg-white">
        {row.cropUrl && !imageFailed ? (
          <img
            src={row.cropUrl}
            alt={row.name}
            className="size-full object-contain p-3"
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
          <span className="px-4 text-center text-2xs text-navy-900/60">
            {row.cropUrl ? 'Image failed to load' : 'No image for this symbol'}
          </span>
        )}

        {/* On its own dark plate: the checkbox is drawn for this app's dark
            chrome, and its unchecked state is a hairline on white/10 — which
            on the white crop below is nothing at all. */}
        <div className="absolute top-2 left-2 rounded-md bg-navy-900/85 p-1">
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
        {/* Badges are drawn for dark chrome too — tinted fills with light text,
            which the white plate below would swallow. They keep their own. */}
        {(row.origin === 'needs_review' || isRejected || row.isModified) && (
          <div className="absolute top-2 right-2 flex flex-col items-end gap-1 rounded-panel bg-navy-900/85 p-1">
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
        )}
      </div>

      <div className="flex min-w-0 flex-1 flex-col gap-2.5 p-3.5">
        {/* Symbol name */}
        <div className="min-w-0">
          <h3 className="flex items-center gap-2 truncate text-sm font-semibold text-white" title={row.name}>
            <span
              aria-hidden
              className="size-2 shrink-0 rounded-full"
              style={{ backgroundColor: color.solid }}
            />
            <span className="truncate">{symbolLabel(row.name)}</span>
          </h3>
          <div className="mt-1 flex items-center gap-2">
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
          <div className="mt-1 flex items-stretch overflow-hidden rounded-panel border border-hairline-strong bg-white/5">
            <IconButton
              variant="ghost"
              size="sm"
              icon={Minus}
              label={`Decrease the quantity of ${row.name}`}
              disabled={row.finalCount <= 0}
              onClick={() => step(-1)}
              className="size-8 shrink-0 rounded-none border-0 hover:bg-white/10"
            />
            <TextInput
              id={`symbol-count-${row.id}`}
              type="number"
              min={0}
              inputMode="numeric"
              value={countDraft ?? String(row.finalCount)}
              onChange={(event) => setCountDraft(event.target.value)}
              onBlur={commitCount}
              onKeyDown={(event) => {
                if (event.key === 'Enter') {
                  event.preventDefault()
                  commitCount()
                }
              }}
              className="min-w-0 flex-1"
              /*
                The box is the middle of a stepper, not a field of its own: no
                corners, no height of its own, and no browser spinner — there
                are already a minus and a plus either side of it, and the tiny
                native arrows on top of them are two controls for one job.
              */
              controlClassName="h-8 rounded-none border-x border-y-0 border-hairline bg-transparent px-2 py-0 text-center text-sm font-semibold hover:border-hairline focus:bg-white/10 [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
            />
            <IconButton
              variant="ghost"
              size="sm"
              icon={Plus}
              label={`Increase the quantity of ${row.name}`}
              onClick={() => step(1)}
              className="size-8 shrink-0 rounded-none border-0 hover:bg-white/10"
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
            <IconButton
              variant="secondary"
              size="sm"
              icon={RotateCcw}
              label="Undo"
              onClick={() => post(routeTo.symbolReset(resultId, row.id))}
              className="mx-auto rounded-panel"
            />
          ) : (
            <IconButton
              variant="primary"
              size="sm"
              icon={Check}
              label="Approve"
              onClick={() => post(routeTo.symbolApprove(resultId, row.id))}
              className="mx-auto rounded-panel"
            />
          )}

          {isRejected ? (
            <Button
              variant="secondary"
              size="sm"
              leftIcon={RotateCcw}
              onClick={() => post(routeTo.symbolReset(resultId, row.id))}
            >
              Reinstate
            </Button>
          ) : (
            <Button
              variant="danger"
              size="sm"
              leftIcon={X}
              onClick={() => post(routeTo.symbolReject(resultId, row.id))}
            >
              Reject
            </Button>
          )}

          <IconButton
            variant="secondary"
            size="sm"
            icon={Pencil}
            label="Rename"
            onClick={() => {
              setRenameDraft(row.name)
              setMode('rename')
            }}
            className="mx-auto rounded-panel"
          />
        </div>
      </div>
    </Card>
  )
}
