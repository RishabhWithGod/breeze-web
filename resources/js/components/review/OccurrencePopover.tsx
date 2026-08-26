import { useState, type CSSProperties } from 'react'
import { createPortal } from 'react-dom'
import { router } from '@inertiajs/react'
import { Copy, Minus, Pencil, Plus, Trash2 } from 'lucide-react'
import { Button, IconButton, TextInput } from '@/components/common'
import { routeTo } from '@/constants'
import type { OccurrenceOrigin, ReviewStatus } from '@/types'
import { useClickOutside } from '@/hooks'

export interface OccurrencePopoverProps {
  resultId: number
  reviewId: number
  occurrenceKey?: string
  occurrenceOrigin?: OccurrenceOrigin
  name: string
  status: ReviewStatus
  /**
   * Fixed-position screen coordinates, computed by the caller from the
   * marker's real on-screen position — portalled to `document.body` so the
   * panel is never clipped by the drawing viewport's own `overflow-hidden`
   * (needed to contain the zoomed/panned image), regardless of how close to
   * the viewport's edge the marker sits.
   */
  anchorStyle: CSSProperties
  /** The whole symbol's reviewed quantity — shared across every occurrence, not just this one. */
  finalCount: number
  locked: boolean
  onClose: () => void
}

/**
 * The action panel a selected drawing marker opens. Mixes two scopes on
 * purpose, clearly separated: actions on *this occurrence* (approve/reject,
 * duplicate, delete), and actions on *the whole symbol* (rename, quantity) —
 * the same thing a card offers, reached from the drawing instead.
 */
export function OccurrencePopover({
  resultId,
  reviewId,
  occurrenceKey,
  occurrenceOrigin,
  name,
  status,
  anchorStyle,
  finalCount,
  locked,
  onClose,
}: OccurrencePopoverProps) {
  const [renaming, setRenaming] = useState(false)
  const [nameDraft, setNameDraft] = useState(name)
  const ref = useClickOutside<HTMLDivElement>(onClose)

  const post = (url: string, data: Record<string, string | number | null> = {}) => {
    router.post(url, data, { preserveScroll: true, preserveState: true })
  }

  const isRejected = status === 'rejected'
  const canDeleteThis = occurrenceOrigin === 'manual' || occurrenceOrigin === 'duplicate'

  const toggleThisOccurrence = () => {
    if (occurrenceKey) {
      post(routeTo.symbolOccurrence(resultId, reviewId, occurrenceKey))
    } else {
      post(isRejected ? routeTo.symbolApprove(resultId, reviewId) : routeTo.symbolReject(resultId, reviewId))
    }
  }

  const duplicateThis = () => {
    if (!occurrenceKey) return
    post(routeTo.symbolOccurrenceDuplicate(resultId, reviewId, occurrenceKey))
    onClose()
  }

  const deleteThis = () => {
    if (!occurrenceKey) return
    router.delete(routeTo.symbolOccurrenceDelete(resultId, reviewId, occurrenceKey), {
      preserveScroll: true,
      preserveState: true,
    })
    onClose()
  }

  const stepCount = (by: number) => post(routeTo.symbolCount(resultId, reviewId), { step: by })

  const saveRename = () => {
    const trimmed = nameDraft.trim()
    if (trimmed.length < 2 || trimmed === name) {
      setRenaming(false)
      return
    }

    post(routeTo.symbolRename(resultId, reviewId), { name: trimmed })
    setRenaming(false)
  }

  return createPortal(
    <div
      ref={ref}
      // Solid, not the app's usual translucent glass panel — this floats
      // over the drawing image itself (often light-colored), where a 9%-opacity
      // panel is nearly invisible. Opaque guarantees it reads at any position.
      className="fixed z-50 w-60 rounded-panel border border-hairline-strong bg-navy-900 p-3 shadow-panel"
      style={anchorStyle}
      onClick={(event) => event.stopPropagation()}
      onPointerDown={(event) => event.stopPropagation()}
    >
      {renaming ? (
        <form
          className="mb-2 flex flex-col gap-2"
          onSubmit={(event) => {
            event.preventDefault()
            saveRename()
          }}
        >
          <TextInput
            id={`occurrence-rename-${reviewId}`}
            label="Symbol name"
            value={nameDraft}
            onChange={(event) => setNameDraft(event.target.value)}
            autoFocus
          />
          <div className="flex gap-2">
            <Button type="submit" size="sm">
              Save name
            </Button>
            <Button type="button" variant="ghost" size="sm" onClick={() => setRenaming(false)}>
              Cancel
            </Button>
          </div>
        </form>
      ) : (
        <div className="mb-2 flex items-start justify-between gap-2">
          <p className="truncate text-md font-semibold text-white" title={name}>
            {name}
          </p>
          {!locked && (
            <IconButton
              variant="ghost"
              size="sm"
              icon={Pencil}
              label="Rename this symbol"
              onClick={() => {
                setNameDraft(name)
                setRenaming(true)
              }}
            />
          )}
        </div>
      )}

      <div className="mb-3">
        <p className="mb-1.5 text-2xs tracking-wide text-white/75 uppercase">Quantity (all of {name})</p>
        <div className="flex items-center gap-2">
          <IconButton
            variant="secondary"
            size="sm"
            icon={Minus}
            label="Decrease quantity"
            disabled={locked || finalCount <= 0}
            onClick={() => stepCount(-1)}
          />
          <span className="min-w-8 text-center text-sm font-semibold text-white">{finalCount}</span>
          <IconButton
            variant="secondary"
            size="sm"
            icon={Plus}
            label="Increase quantity"
            disabled={locked}
            onClick={() => stepCount(1)}
          />
        </div>
      </div>

      <div className="flex flex-col gap-2">
        <p className="text-2xs tracking-wide text-white/75 uppercase">This occurrence</p>
        <div className="flex flex-wrap gap-2">
          <Button
            size="sm"
            variant={isRejected ? 'secondary' : 'danger'}
            disabled={locked}
            onClick={toggleThisOccurrence}
          >
            {isRejected ? 'Approve' : 'Reject'}
          </Button>
          <Button
            size="sm"
            variant="secondary"
            leftIcon={Copy}
            disabled={locked || !occurrenceKey}
            onClick={duplicateThis}
          >
            Duplicate
          </Button>
          {canDeleteThis && (
            <Button size="sm" variant="danger" leftIcon={Trash2} disabled={locked} onClick={deleteThis}>
              Delete
            </Button>
          )}
        </div>
      </div>
    </div>,
    document.body,
  )
}
