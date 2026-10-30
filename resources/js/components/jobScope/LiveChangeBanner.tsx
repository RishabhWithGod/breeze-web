import type { ScopeBroadcast } from '@/hooks'
import { usePage } from '@inertiajs/react'
import { Radio } from 'lucide-react'
import type { SharedPageProps } from '@/types'

/**
 * "Updated by Ravi — 2 lines changed": shown after another person's change
 * arrives, so a figure that just moved is never a mystery. Your own changes
 * need no notice.
 */
export function LiveChangeBanner({ latest }: { latest: ScopeBroadcast | null }) {
  const me = usePage<SharedPageProps>().props.auth.user?.id ?? null

  if (latest === null || latest.byUserId === me) return null

  return (
    <p role="status" className="mb-4 flex items-center gap-2 rounded-panel border border-brand/30 bg-brand/10 px-4 py-2 text-sm text-white">
      <Radio size={14} className="shrink-0 text-brand" aria-hidden />
      Updated just now{latest.by ? ` by ${latest.by}` : ''} — {latest.summary.toLowerCase()}.
    </p>
  )
}
