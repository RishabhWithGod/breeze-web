import { router } from '@inertiajs/react'
import { Check, Minus, Move, Plus, RotateCcw, Trash2, X } from 'lucide-react'
import { Button, StatusChip } from '@/components/common'
import { routeTo } from '@/constants'
import { symbolLabel } from '@/utils'
import type { SymbolColor } from '@/utils'
import type { ThreeDMarker } from './markers'

export interface ThreeDSymbolDetailsProps {
  resultId: number
  marker: ThreeDMarker | null
  color: SymbolColor | undefined
  canEdit: boolean
  onClose: () => void
}

/**
 * Everything on record about the selected symbol, and every action the review
 * workflow already offers on it.
 *
 * Every button posts to a review endpoint. There is no approval state, no
 * quantity rule and no history writing in this feature — the review owns all
 * three, and a second implementation of any of them would be a second answer.
 */
export function ThreeDSymbolDetails({
  resultId,
  marker,
  color,
  canEdit,
  onClose,
}: ThreeDSymbolDetailsProps) {
  if (marker === null) {
    return (
      <p className="text-2xs text-white/65">
        Select a symbol on the drawing to see what is on record for it.
      </p>
    )
  }

  /*
   * `except: ['flash']` for the same reason the review overlay's own toggle
   * uses it: the review screen renders a flash banner, and a banner appearing
   * mid-edit shoves the drawing under the pointer.
   */
  const post = (url: string, data: Record<string, number> = {}) =>
    router.post(url, data, { preserveScroll: true, preserveState: true, except: ['flash'] })

  const isRejected = marker.status === 'rejected'

  return (
    <div className="flex flex-col gap-3">
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="flex items-center gap-2 text-sm font-semibold text-white">
            <span
              aria-hidden
              className="size-2.5 shrink-0 rounded-full"
              style={{ backgroundColor: color?.solid }}
            />
            <span className="truncate" title={marker.name}>
              {symbolLabel(marker.name)}
            </span>
          </p>
          <p className="mt-1 text-2xs text-white/65">
            {marker.origin === 'manual' ? 'Added by a reviewer' : 'Detected by the engine'}
            {marker.moved && ' · moved'}
          </p>
        </div>
        <Button size="sm" variant="ghost" leftIcon={X} onClick={onClose}>
          Close
        </Button>
      </div>

      <dl className="grid grid-cols-2 gap-2 text-2xs">
        <Fact label="Status">
          <StatusChip
            hideDot
            tone={isRejected ? 'danger' : marker.status === 'approved' ? 'success' : 'neutral'}
            label={marker.status}
          />
        </Fact>
        <Fact label="Quantity">
          <span className="tabular-nums text-white">{marker.finalCount}</span>
        </Fact>
        <Fact label="Page">
          <span className="tabular-nums text-white">{marker.page}</span>
        </Fact>
        <Fact label="Position">
          {/* The engine's own page pixels, rounded for reading — never the
              browser's, which mean nothing once the window is resized. */}
          <span className="tabular-nums text-white">
            {Math.round(marker.bbox[0] ?? 0)}, {Math.round(marker.bbox[1] ?? 0)}
          </span>
        </Fact>
        <Fact label="Confidence">
          <span className="tabular-nums text-white">
            {marker.confidence === null ? 'Data not available' : `${Math.round(marker.confidence * 100)}%`}
          </span>
        </Fact>
        <Fact label="Source">
          <span className="text-white">
            {marker.origin === 'manual' ? 'Added by hand' : 'AI detected'}
          </span>
        </Fact>
      </dl>

      {canEdit ? (
        <div className="flex flex-col gap-2 border-t border-hairline pt-3">
          {/* Quantity — the review's own count endpoint, by step. */}
          <div className="flex items-center gap-2">
            <span className="text-2xs text-white/70">Quantity</span>
            <Button
              size="sm"
              variant="white"
              leftIcon={Minus}
              aria-label="Decrease quantity"
              onClick={() => post(routeTo.symbolCount(resultId, marker.reviewId), { step: -1 })}
            >
              {''}
            </Button>
            <span className="min-w-8 text-center text-sm tabular-nums text-white">
              {marker.finalCount}
            </span>
            <Button
              size="sm"
              variant="white"
              leftIcon={Plus}
              aria-label="Increase quantity"
              onClick={() => post(routeTo.symbolCount(resultId, marker.reviewId), { step: 1 })}
            >
              {''}
            </Button>
          </div>

          {/* Drag is the move; this says so rather than offering a second way. */}
          {marker.occurrenceKey !== null && (
            <p className="flex items-center gap-1.5 text-2xs text-white/60">
              <Move size={12} aria-hidden />
              Drag it on the drawing to reposition — it saves as you drop it.
            </p>
          )}

          {/*
            One occurrence flips through the occurrence endpoint; a row with no
            occurrences of its own flips through approve/reject. The same two
            paths the review overlay picks between, for the same reason.
          */}
          <div className="flex flex-wrap gap-2">
            {marker.occurrenceKey ? (
              <Button
                size="sm"
                variant={isRejected ? 'primary' : 'danger'}
                leftIcon={isRejected ? RotateCcw : X}
                onClick={() =>
                  post(routeTo.symbolOccurrence(resultId, marker.reviewId, marker.occurrenceKey!))
                }
              >
                {isRejected ? 'Reinstate' : 'Reject'}
              </Button>
            ) : (
              <>
                <Button
                  size="sm"
                  leftIcon={Check}
                  onClick={() => post(routeTo.symbolApprove(resultId, marker.reviewId))}
                >
                  Approve
                </Button>
                <Button
                  size="sm"
                  variant="danger"
                  leftIcon={X}
                  onClick={() => post(routeTo.symbolReject(resultId, marker.reviewId))}
                >
                  Reject
                </Button>
              </>
            )}

            {/*
              Only what the review allows. A manually added symbol can be
              deleted; an AI detection is rejected instead, so that the record of
              what the engine found survives the review of it. The rule is the
              server's — this only stops offering a button that would be refused.
            */}
            {marker.origin === 'manual' && marker.occurrenceKey && (
              <Button
                size="sm"
                variant="ghost"
                leftIcon={Trash2}
                className="text-status-danger"
                onClick={() =>
                  router.delete(
                    routeTo.symbolOccurrenceDelete(resultId, marker.reviewId, marker.occurrenceKey!),
                    { preserveScroll: true, preserveState: true, onSuccess: onClose },
                  )
                }
              >
                Remove
              </Button>
            )}
          </div>

          {marker.origin !== 'manual' && (
            <p className="text-2xs text-white/55">
              An AI detection is rejected rather than deleted, so the record of what the engine
              found survives the review of it.
            </p>
          )}
        </div>
      ) : (
        <p className="border-t border-hairline pt-3 text-2xs text-white/65">
          This takeoff is read-only. Reopen the review to make changes.
        </p>
      )}
    </div>
  )
}

function Fact({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="min-w-0">
      <dt className="text-white/60">{label}</dt>
      <dd className="mt-0.5 min-w-0 truncate">{children}</dd>
    </div>
  )
}
