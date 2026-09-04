import type { OverlayOccurrence, OverlaySymbol, ReviewStatus } from '@/types'

/**
 * The occurrence as it actually arrives.
 *
 * `SymbolOverlayResource` hands the stored JSON straight through, and the
 * controller writes the pre-move position under `original_bbox` — snake case,
 * because that is what is in the column. The shared `OverlayOccurrence` type
 * declares a camel-cased `originalBbox` that nothing has ever read, so the
 * mismatch has been harmless; reading the real field here rather than
 * correcting the shared type keeps this feature to itself.
 */
type WireOccurrence = OverlayOccurrence & {
  readonly original_bbox?: readonly number[]
}

/**
 * One thing the spatial viewer draws.
 *
 * Flattened out of the review's own rows so the viewer never has to reason
 * about the two shapes a symbol arrives in — a row with an `occurrences` array,
 * or an older row with a single `bbox` of its own. Both become markers; only
 * the first kind can be moved or flipped one at a time, which is why
 * `occurrenceKey` is nullable and every action checks it.
 */
export interface ThreeDMarker {
  readonly key: string
  readonly reviewId: number
  readonly name: string
  readonly page: number
  /** `[x, y, width, height]` in the engine's page pixel space. */
  readonly bbox: readonly number[]
  readonly status: ReviewStatus
  readonly finalCount: number
  readonly origin: 'ai' | 'manual'
  readonly confidence: number | null
  /** Null for a row-level box, which the review workflow moves as a whole. */
  readonly occurrenceKey: string | null
  /** True once the reviewer has dragged it — the review stores the original. */
  readonly moved: boolean
}

/**
 * Every marker on one page.
 *
 * Only that page: a drawing can carry thousands of detections, and the viewer
 * has no use for the ones on sheets nobody is looking at. A symbol that appears
 * on three pages yields a marker on each, from its own occurrence — never
 * copied forward onto page one.
 */
export function markersForPage(
  symbols: readonly OverlaySymbol[],
  page: number,
): ThreeDMarker[] {
  const markers: ThreeDMarker[] = []

  for (const symbol of symbols) {
    if (symbol.occurrences && symbol.occurrences.length > 0) {
      for (const occurrence of symbol.occurrences as readonly WireOccurrence[]) {
        if (occurrence.page !== page) continue

        markers.push({
          key: `${symbol.id}-${occurrence.key}`,
          reviewId: symbol.id,
          name: symbol.name,
          page,
          bbox: occurrence.bbox,
          status: occurrence.status,
          finalCount: symbol.finalCount,
          origin: occurrence.origin === 'manual' ? 'manual' : 'ai',
          confidence: occurrence.confidence ?? null,
          occurrenceKey: occurrence.key,
          moved: occurrence.original_bbox != null || occurrence.originalBbox != null,
        })
      }

      continue
    }

    // An older row keeps one box of its own. It is still a real detection and
    // still belongs on the page it names.
    if (symbol.page === page && symbol.bbox) {
      markers.push({
        key: `${symbol.id}`,
        reviewId: symbol.id,
        name: symbol.name,
        page,
        bbox: symbol.bbox,
        status: symbol.status,
        finalCount: symbol.finalCount,
        origin: symbol.origin === 'manual' ? 'manual' : 'ai',
        confidence: null,
        occurrenceKey: null,
        moved: false,
      })
    }
  }

  return markers
}
