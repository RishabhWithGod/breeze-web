import { useCallback, useEffect, useRef, useState } from 'react'
import { router, usePage } from '@inertiajs/react'
import type { SharedPageProps } from '@/types'
import { useEchoConnectionState, usePrivateChannel } from './useEcho'

/** What a `job.scope-changed` broadcast carries — a nudge, never a dollar figure. */
export interface ScopeBroadcast {
  readonly jobId: number
  readonly estimateId: number | null
  readonly version: number
  readonly kind: string
  readonly source: string
  readonly summary: string
  readonly byUserId: number | null
  readonly by: string | null
  readonly at: string | null
}

export interface ScopeSyncOptions {
  /** The props to re-fetch; leave out for the whole page. */
  readonly only?: readonly string[]
}

/** Bursts (a builder save is several changes) become one refresh. */
const DEBOUNCE_MS = 350

function useScopeReload(options: ScopeSyncOptions) {
  const timer = useRef<number | null>(null)
  const only = options.only?.join(',') ?? ''
  const [latest, setLatest] = useState<ScopeBroadcast | null>(null)

  const reload = useCallback(() => {
    if (timer.current !== null) window.clearTimeout(timer.current)
    timer.current = window.setTimeout(() => {
      router.reload({ ...(only ? { only: only.split(',') } : {}) })
    }, DEBOUNCE_MS)
  }, [only])

  useEffect(
    () => () => {
      if (timer.current !== null) window.clearTimeout(timer.current)
    },
    [],
  )

  const onChange = useCallback(
    (payload: Record<string, unknown>) => {
      setLatest(payload as unknown as ScopeBroadcast)
      reload()
    },
    [reload],
  )

  // A dropped socket may have missed changes — realtime is a nudge, the server is the truth.
  useEchoConnectionState(reload)

  return { latest, onChange }
}

/**
 * Re-fetches this page the moment the given job's scope changes — an estimate
 * edited anywhere (web, Estimate Builder, phone), a change order approved, a
 * cost logged, an invoice adjusted. `latest` is the last change heard, for a
 * "Updated by …" notice.
 */
export function useJobScopeSync(jobId: number | null | undefined, options: ScopeSyncOptions = {}) {
  const { latest, onChange } = useScopeReload(options)

  usePrivateChannel(jobId ? `job.${jobId}` : null, { 'job.scope-changed': onChange })

  return { latest }
}

/** The same, for screens that span the company's jobs — Analytics, lists. */
export function useCompanyScopeSync(options: ScopeSyncOptions = {}) {
  const companyId = usePage<SharedPageProps>().props.company?.id ?? null
  const { latest, onChange } = useScopeReload(options)

  usePrivateChannel(companyId ? `company.${companyId}` : null, { 'job.scope-changed': onChange })

  return { latest }
}
