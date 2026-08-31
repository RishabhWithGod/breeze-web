import { useState } from 'react'
import { Head, usePage } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import { Coins, Gift, History as HistoryIcon } from 'lucide-react'
import { Alert, ButtonLink, Card, ProgressBar } from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES } from '@/constants'
import type { NextRewardInfo, SharedPageProps } from '@/types'

export interface BreezeBucksProps {
  balance: number
  lifetimeEarned: number
  lifetimeRedeemed: number
  nextReward: NextRewardInfo | null
  rewardsAvailable: boolean
  can: { award: boolean; manageCatalog: boolean }
}

/**
 * Breeze Bucks — every number here is derived from `breeze_bucks_transactions`
 * on the backend (see `BreezeBucksController`/`BreezeBucksLedger`). There is
 * no balance column anywhere that could drift from the ledger, and no static
 * demo data — a brand-new user genuinely sees 0 BB and an empty history.
 *
 * The Rewards Catalog, History and Award Bonus screens are each their own
 * real page (`/breeze-bucks/rewards`, `/history`, `/award`) — not popups.
 */
export default function BreezeBucks({ balance, lifetimeEarned, lifetimeRedeemed, nextReward, rewardsAvailable, can }: BreezeBucksProps) {
  const { flash } = usePage<SharedPageProps>().props
  const [dismissed, setDismissed] = useState<string | null>(null)

  const notice = flash.success ?? flash.warning ?? null
  const displayNotice = notice === dismissed ? null : notice

  return (
    <PageTransition>
      <Head title="Breeze Bucks" />

      <PageHeader
        title="Breeze Bucks"
        subtitle="Rewards earned on completed work — real points, real history."
        actions={
          can.award ? (
            <ButtonLink href={ROUTES.breezeBucksAwardForm} leftIcon={Gift}>
              Award Bonus
            </ButtonLink>
          ) : undefined
        }
      />

      <AnimatePresence initial={false}>
        {displayNotice && (
          <Alert key={displayNotice} tone={flash.warning ? 'warning' : 'success'} className="mb-6" onDismiss={() => setDismissed(displayNotice)}>
            {displayNotice}
          </Alert>
        )}
      </AnimatePresence>

      <Card className="text-center">
        <p className="text-2xl text-white">
          Your Balance: <span className="text-3xl font-extrabold text-brand">{balance}</span>
        </p>

        <div className="mx-auto mt-6 max-w-2xl">
          {nextReward ? (
            <>
              <ProgressBar value={nextReward.percent} tone="brand" size="lg" />
              <p className="mt-3 text-lg font-semibold text-white">
                {nextReward.remaining} BB until your next reward!
              </p>
            </>
          ) : rewardsAvailable ? (
            <p className="text-lg font-semibold text-white">You can redeem any available reward right now!</p>
          ) : (
            <p className="text-lg text-white/70">No rewards are currently available.</p>
          )}
        </div>

        <div className="mx-auto mt-8 grid max-w-2xl gap-4 sm:grid-cols-2">
          <div className="rounded-panel border border-hairline bg-white/4 p-6">
            <p className="text-md text-white/70">Total Earned</p>
            <p className="mt-1 text-2xl font-bold text-white">{lifetimeEarned} BB</p>
          </div>
          <div className="rounded-panel border border-hairline bg-white/4 p-6">
            <p className="text-md text-white/70">Total Redeemed</p>
            <p className="mt-1 text-2xl font-bold text-white">{lifetimeRedeemed} BB</p>
          </div>
        </div>

        <div className="mt-8 flex flex-wrap items-center justify-center gap-3">
          <ButtonLink href={ROUTES.breezeBucksRewards} leftIcon={Gift}>
            View Rewards Catalog
          </ButtonLink>
          <ButtonLink href={ROUTES.breezeBucksHistory} variant="white" leftIcon={HistoryIcon}>
            View History
          </ButtonLink>
        </div>

        <p className="mx-auto mt-8 max-w-xl text-md text-white/70">
          Breeze Bucks are earned through consistent work and recognition.
        </p>

        {balance === 0 && lifetimeEarned === 0 && (
          <p className="mt-4 flex items-center justify-center gap-2 text-sm text-white/50">
            <Coins size={14} aria-hidden /> No Breeze Bucks earned yet.
          </p>
        )}
      </Card>
    </PageTransition>
  )
}

BreezeBucks.layout = appLayout
