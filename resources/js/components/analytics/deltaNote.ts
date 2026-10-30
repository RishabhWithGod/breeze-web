/**
 * "+12.0% vs previous period" — `lowerIsBetter` flips which direction is
 * good, since for cost and hours a rise is the bad one.
 */
export function deltaNote(
  current: number,
  previous: number | null | undefined,
  lowerIsBetter = true,
): { note?: string; noteTone?: 'success' | 'danger' | 'neutral' } {
  if (previous === null || previous === undefined) return {}
  if (previous === 0) return current === 0 ? { note: 'No change vs previous period', noteTone: 'neutral' } : { note: 'New vs previous period', noteTone: 'neutral' }

  const pct = ((current - previous) / previous) * 100
  if (Math.abs(pct) < 0.05) return { note: 'No change vs previous period', noteTone: 'neutral' }

  const worse = lowerIsBetter ? pct > 0 : pct < 0
  return { note: `${pct > 0 ? '+' : ''}${pct.toFixed(1)}% vs previous period`, noteTone: worse ? 'danger' : 'success' }
}
