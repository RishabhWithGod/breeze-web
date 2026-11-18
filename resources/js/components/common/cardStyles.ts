import type { Tone } from '@/types'
import { cn } from '@/utils'

/**
 * The accent, which is what tells one card from the next.
 *
 * All four edges rather than a stripe down the left: a single lit edge reads as
 * a card that has lost three of its borders, and on a page of stacked sections
 * it is the outline that says where one ends and the next begins.
 *
 * The stripe is kept as a thicker left edge, so the accent still leads the eye
 * down the page the way it did.
 */

/**
 * The estimate screen's section border, for any card that is a section of a
 * detail screen.
 *
 * Two pixels, not one: a hairline in a tinted colour on a dark glass panel is a
 * suggestion of an edge rather than one, and the outline is what tells a stack
 * of sections apart. The left stays thicker still, so the accent leads down the
 * page.
 *
 * Returned as one string rather than left to the glass variant, whose own
 * hairline these override.
 */
export function cardAccent(_tone: Tone = 'brand', className?: string): string {
  // The Breeze app's cards carry no lit edge: one hairline, one surface. The tone is still accepted so
  // every caller keeps compiling, and it can be turned back into a colour here without touching them.
  return cn('border border-hairline', className)
}

/**
 * Accents to walk down a list, so one card is never the next one's colour.
 *
 * Four, and these four: `info` teal sits a hair off brand cyan and the two read
 * as the same card twice, while `danger` red and — next to it — `warning` amber
 * are what this app says failure with. Amber alone, among neutral company,
 * reads as one card of a set rather than as an alarm.
 */
export const CARD_ACCENT_CYCLE: readonly Tone[] = ['brand', 'success', 'warning', 'neutral']

/** The accent for position `index` in a list, cycling through the four. */
export function cardAccentAt(index: number, className?: string): string {
  return cardAccent(
    CARD_ACCENT_CYCLE[index % CARD_ACCENT_CYCLE.length] ?? 'brand',
    className,
  )
}
