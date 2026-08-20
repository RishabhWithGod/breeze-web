import { useState } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import { ArrowLeft, Gift, Pencil, Plus, Trash2 } from 'lucide-react'
import { Alert, Button, ButtonLink, Card, ConfirmDialog, EmptyState, IconBubble, StatusChip } from '@/components/common'
import { ManageRewardModal } from '@/components/breezeBucks'
import { rewardIconFor } from '@/components/breezeBucks/rewardIcons'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES, routeTo } from '@/constants'
import type { RewardCatalogItemData, SharedPageProps } from '@/types'

export interface BreezeBucksRewardsProps {
  balance: number
  rewards: readonly RewardCatalogItemData[]
  can: { manageCatalog: boolean }
}

/**
 * The real Rewards Catalog screen — its own page, reached from "View
 * Rewards Catalog" on the Breeze Bucks landing page. Every reward, cost and
 * availability shown here comes straight from `reward_catalog_items`.
 */
export default function BreezeBucksRewards({ balance, rewards, can }: BreezeBucksRewardsProps) {
  const { flash } = usePage<SharedPageProps>().props
  const [dismissed, setDismissed] = useState<string | null>(null)
  const [pendingRedeem, setPendingRedeem] = useState<RewardCatalogItemData | null>(null)
  const [editing, setEditing] = useState<RewardCatalogItemData | 'new' | null>(null)
  const [pendingDeactivate, setPendingDeactivate] = useState<RewardCatalogItemData | null>(null)

  const notice = flash.success ?? flash.warning ?? null
  const displayNotice = notice === dismissed ? null : notice

  const confirmRedeem = () => {
    if (!pendingRedeem) return
    router.post(routeTo.breezeBucksRedeem(pendingRedeem.id), {}, { preserveScroll: true })
    setPendingRedeem(null)
  }

  const confirmDeactivate = () => {
    if (!pendingDeactivate) return
    router.delete(routeTo.breezeBucksRewardDestroy(pendingDeactivate.id), { preserveScroll: true })
    setPendingDeactivate(null)
  }

  return (
    <PageTransition>
      <Head title="Rewards Catalog" />

      <PageHeader
        title="Rewards Catalog"
        subtitle={`You have ${balance} BB available to redeem.`}
        actions={
          <div className="flex flex-wrap gap-3">
            {can.manageCatalog && (
              <Button leftIcon={Plus} variant="secondary" onClick={() => setEditing('new')}>
                Add Reward
              </Button>
            )}
            <ButtonLink href={ROUTES.breezeBucks} variant="secondary" leftIcon={ArrowLeft}>
              Back to Breeze Bucks
            </ButtonLink>
          </div>
        }
      />

      <AnimatePresence initial={false}>
        {displayNotice && (
          <Alert key={displayNotice} tone={flash.warning ? 'warning' : 'success'} className="mb-6" onDismiss={() => setDismissed(displayNotice)}>
            {displayNotice}
          </Alert>
        )}
      </AnimatePresence>

      <Card>
        {rewards.length === 0 ? (
          <EmptyState icon={Gift} title="No rewards are currently available." />
        ) : (
          <ul className="space-y-3">
            {rewards.map((reward) => {
              const Icon = rewardIconFor(reward.icon)
              const affordable = balance >= reward.pointsRequired

              return (
                <li key={reward.id} className="flex items-center justify-between gap-3 rounded-panel border border-hairline bg-white/4 p-4">
                  <div className="flex items-center gap-3">
                    <IconBubble icon={Icon} tone={reward.isActive ? 'brand' : 'neutral'} size="sm" />
                    <div>
                      <p className="font-semibold text-white">{reward.name}</p>
                      {reward.description && <p className="text-sm text-white/70">{reward.description}</p>}
                      <p className="mt-0.5 text-sm text-white/60">
                        {reward.pointsRequired} BB
                        {reward.stock !== null && ` · ${reward.stock} left`}
                        {!reward.isActive && ' · Inactive'}
                      </p>
                    </div>
                  </div>

                  <div className="flex items-center gap-2">
                    {!reward.isRedeemable && !can.manageCatalog && (
                      <StatusChip hideDot tone="neutral" label={!reward.isActive ? 'Unavailable' : 'Out of Stock'} />
                    )}
                    {can.manageCatalog && (
                      <>
                        <button
                          type="button"
                          aria-label={`Edit ${reward.name}`}
                          onClick={() => setEditing(reward)}
                          className="rounded-full p-1.5 text-white/60 transition-colors hover:bg-white/10 hover:text-white"
                        >
                          <Pencil size={16} aria-hidden />
                        </button>
                        {reward.isActive && (
                          <button
                            type="button"
                            aria-label={`Deactivate ${reward.name}`}
                            onClick={() => setPendingDeactivate(reward)}
                            className="rounded-full p-1.5 text-white/60 transition-colors hover:bg-status-danger/15 hover:text-status-danger"
                          >
                            <Trash2 size={16} aria-hidden />
                          </button>
                        )}
                      </>
                    )}
                    <Button size="sm" disabled={!reward.isRedeemable || !affordable} onClick={() => setPendingRedeem(reward)}>
                      Redeem
                    </Button>
                  </div>
                </li>
              )
            })}
          </ul>
        )}
      </Card>

      <ConfirmDialog
        isOpen={pendingRedeem !== null}
        title={`Redeem "${pendingRedeem?.name ?? ''}"?`}
        description={
          pendingRedeem
            ? balance >= pendingRedeem.pointsRequired
              ? `This will use ${pendingRedeem.pointsRequired} BB from your balance of ${balance} BB.`
              : `Insufficient Breeze Bucks. You need ${pendingRedeem.pointsRequired} BB but only have ${balance} BB.`
            : ''
        }
        confirmLabel="Redeem"
        onConfirm={confirmRedeem}
        onCancel={() => setPendingRedeem(null)}
      />

      <ConfirmDialog
        isOpen={pendingDeactivate !== null}
        tone="danger"
        title={`Deactivate "${pendingDeactivate?.name ?? ''}"?`}
        description="It will no longer be redeemable, but past redemptions stay in everyone's history."
        confirmLabel="Deactivate"
        confirmVariant="danger"
        onConfirm={confirmDeactivate}
        onCancel={() => setPendingDeactivate(null)}
      />

      <ManageRewardModal isOpen={editing !== null} onClose={() => setEditing(null)} reward={editing === 'new' ? null : editing} />
    </PageTransition>
  )
}

BreezeBucksRewards.layout = appLayout
