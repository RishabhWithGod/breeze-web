import { useEffect } from 'react'
import { useForm } from '@inertiajs/react'
import { Button, Checkbox, Modal, SelectField, TextInput } from '@/components/common'
import { routeTo } from '@/constants'
import type { RewardCatalogItemData } from '@/types'
import { REWARD_ICON_OPTIONS } from './rewardIcons'

export interface ManageRewardModalProps {
  isOpen: boolean
  onClose: () => void
  reward: RewardCatalogItemData | null
}

/** Admin-only create/edit for a reward catalog row — never available to a normal user. */
export function ManageRewardModal({ isOpen, onClose, reward }: ManageRewardModalProps) {
  const { data, setData, post, put, processing, errors, reset, transform } = useForm({
    name: reward?.name ?? '',
    description: reward?.description ?? '',
    points_required: String(reward?.pointsRequired ?? 100),
    icon: reward?.icon ?? REWARD_ICON_OPTIONS[0],
    stock: reward?.stock !== null && reward?.stock !== undefined ? String(reward.stock) : '',
    is_active: reward?.isActive ?? true,
  })

  useEffect(() => {
    setData({
      name: reward?.name ?? '',
      description: reward?.description ?? '',
      points_required: String(reward?.pointsRequired ?? 100),
      icon: reward?.icon ?? REWARD_ICON_OPTIONS[0],
      stock: reward?.stock !== null && reward?.stock !== undefined ? String(reward.stock) : '',
      is_active: reward?.isActive ?? true,
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [reward?.id])

  const close = () => {
    reset()
    onClose()
  }

  const submit = () => {
    transform((current) => ({ ...current, stock: current.stock === '' ? null : current.stock }))

    if (reward) {
      put(routeTo.breezeBucksRewardUpdate(reward.id), { preserveScroll: true, onSuccess: close })
    } else {
      post(routeTo.breezeBucksRewardsStore, { preserveScroll: true, onSuccess: close })
    }
  }

  return (
    <Modal
      isOpen={isOpen}
      onClose={close}
      title={reward ? `Edit "${reward.name}"` : 'Add Reward'}
      footer={
        <>
          <Button variant="white" onClick={close}>
            Cancel
          </Button>
          <Button isLoading={processing} disabled={!data.name || !data.points_required} onClick={submit}>
            {reward ? 'Save Changes' : 'Add Reward'}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <TextInput
          id="reward-name"
          label="Reward Name"
          value={data.name}
          onChange={(event) => setData('name', event.target.value)}
          error={errors.name}
        />
        <TextInput
          id="reward-description"
          label="Description"
          value={data.description}
          onChange={(event) => setData('description', event.target.value)}
          error={errors.description}
        />
        <div className="grid grid-cols-2 gap-4">
          <TextInput
            id="reward-points"
            type="number"
            min={1}
            label="Points Required"
            value={data.points_required}
            onChange={(event) => setData('points_required', event.target.value)}
            error={errors.points_required}
          />
          <TextInput
            id="reward-stock"
            type="number"
            min={0}
            label="Stock (blank = unlimited)"
            value={data.stock}
            onChange={(event) => setData('stock', event.target.value)}
            error={errors.stock}
          />
        </div>
        <SelectField
          id="reward-icon"
          label="Icon"
          options={REWARD_ICON_OPTIONS.map((icon) => ({ label: icon, value: icon }))}
          value={data.icon}
          onChange={(event) => setData('icon', event.target.value)}
        />
        <div>
          <Checkbox
            id="reward-active"
            label="Active (redeemable)"
            checked={data.is_active}
            onChange={(event) => setData('is_active', event.target.checked)}
          />
        </div>
      </div>
    </Modal>
  )
}
