import { useState } from 'react'
import { router } from '@inertiajs/react'
import { Users } from 'lucide-react'
import { Alert, Button, Modal, TextInput } from '@/components/common'
import { routeTo } from '@/constants'
import type { SeatOffer } from '@/types'
import { formatCalendarDate, formatCents } from '@/utils'

export interface BuySeatsModalProps {
  isOpen: boolean
  onClose: () => void
  offer: SeatOffer
  /** The seats in use today, so the dialog can say how many are free. */
  used: number
}

/** A few common sizes, so the usual answer is one tap rather than typing. */
const QUICK_ADD = [1, 5, 10, 25] as const

/**
 * Adding seats to the plan the company is already on.
 *
 * The price shown is worked out here for reading only; the server prices the purchase again from
 * the plan and charges that, so what is on screen can never be what is billed by mistake.
 */
export function BuySeatsModal({ isOpen, onClose, offer, used }: BuySeatsModalProps) {
  return isOpen ? (
    <SeatsForm key="open" offer={offer} used={used} onClose={onClose} />
  ) : (
    <Modal isOpen={false} onClose={onClose} />
  )
}

function SeatsForm({ offer, used, onClose }: { offer: SeatOffer; used: number; onClose: () => void }) {
  const [text, setText] = useState('5')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const add = Number.parseInt(text, 10)
  const valid = Number.isInteger(add) && add >= 1 && add <= offer.maxAdd && String(add) === text.trim()
  const total = valid ? offer.seats + add : offer.seats
  const perCycle = valid ? offer.perSeatCents * add : 0
  // What is charged today: the share of the current period still to run.
  const today = Math.round(perCycle * offer.periodShare)
  const cycleWord = offer.cycle === 'yearly' ? 'year' : 'month'

  const buy = () =>
    router.post(
      routeTo.seatsBuy,
      { seats: add },
      {
        preserveScroll: true,
        // Stay on the page as it is: a form being filled in behind this dialog keeps what was typed.
        preserveState: true,
        onStart: () => {
          setBusy(true)
          setError(null)
        },
        onFinish: () => setBusy(false),
        onSuccess: onClose,
        onError: (errors) => setError(errors['seats'] ?? 'The seats could not be added.'),
      },
    )

  return (
    <Modal
      isOpen
      onClose={onClose}
      title="Buy more seats"
      description={`Each seat is one person who can sign in. Your ${offer.planName} plan has ${offer.seats} ${offer.seats === 1 ? 'seat' : 'seats'}, and ${used} ${used === 1 ? 'is' : 'are'} in use.`}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button leftIcon={Users} isLoading={busy} disabled={!valid || !offer.canBuy} onClick={buy}>
            {valid ? `Buy ${add} ${add === 1 ? 'seat' : 'seats'}` : 'Buy seats'}
          </Button>
        </>
      }
    >
      {!offer.canBuy && (
        <Alert tone="warning" className="mb-4">
          Seats cannot be added to this account from here. Please contact support.
        </Alert>
      )}
      {error && (
        <Alert tone="danger" className="mb-4">
          {error}
        </Alert>
      )}

      <div className="mb-3 flex flex-wrap gap-2">
        {QUICK_ADD.filter((n) => n <= offer.maxAdd).map((n) => (
          <Button
            key={n}
            size="sm"
            variant={text === String(n) ? 'primary' : 'secondary'}
            onClick={() => setText(String(n))}
          >
            +{n}
          </Button>
        ))}
      </div>

      <TextInput
        id="seats-to-add"
        label="Seats to add"
        type="number"
        min={1}
        max={offer.maxAdd}
        inputMode="numeric"
        value={text}
        onChange={(event) => setText(event.target.value)}
        {...(text.trim() !== '' && !valid ? { error: `Enter a whole number from 1 to ${offer.maxAdd}.` } : {})}
      />

      {offer.priceKnown ? (
        <dl className="mt-4 space-y-2 rounded-panel border border-hairline bg-white/4 px-4 py-3 text-sm">
          <div className="flex justify-between gap-4 text-white/80">
            <dt>One seat</dt>
            <dd className="tabular-nums">
              {formatCents(offer.perSeatCents)} / {cycleWord}
            </dd>
          </div>
          <div className="flex justify-between gap-4 text-white/80">
            <dt>Seats after this</dt>
            <dd className="tabular-nums">{total}</dd>
          </div>
          <div className="flex justify-between gap-4 text-white/80">
            <dt>
              Charged today
              {offer.renewsOn && (
                <span className="text-white/55">
                  {' '}
                  (rest of this period, to {formatCalendarDate(offer.renewsOn, 'MMM d')})
                </span>
              )}
            </dt>
            <dd className="tabular-nums">{valid ? formatCents(today) : '—'}</dd>
          </div>
          <div className="flex justify-between gap-4 border-t border-hairline pt-2 font-semibold text-white">
            <dt>Each {cycleWord} from then</dt>
            <dd className="tabular-nums">{valid ? `+${formatCents(perCycle)}` : '—'}</dd>
          </div>
        </dl>
      ) : (
        <Alert tone="info" className="mt-4">
          Your plan is billed at its own rate. The exact amount is charged to your card on file and shown on the
          receipt; seats are added once it goes through.
        </Alert>
      )}

      <p className="mt-3 text-xs text-white/60">
        {offer.via === 'stripe'
          ? 'Charged to the card on your subscription. If the payment fails, no seats are added.'
          : 'Test mode: seats are added without a charge.'}
      </p>
    </Modal>
  )
}
