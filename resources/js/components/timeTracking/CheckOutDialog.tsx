import { useState } from 'react'
import { router } from '@inertiajs/react'
import { LogOut } from 'lucide-react'
import { Button, Modal, TextInput } from '@/components/common'
import { routeTo } from '@/constants'

/** The open check-in being closed, and what the form needs to offer a sensible time. */
export interface CheckOutTarget {
  readonly attendanceId: number
  readonly employee: string
  readonly job: string | null
  /** e.g. "09/16/2026 6:58 AM" — said in the dialog so it is clear which one. */
  readonly checkedInAt: string | null
  /** `YYYY-MM-DDTHH:mm` — the earliest a checkout can be. */
  readonly min: string | null
  /** `YYYY-MM-DDTHH:mm` — where the field starts. */
  readonly suggested: string | null
}

export interface CheckOutDialogProps {
  target: CheckOutTarget | null
  onClose: () => void
}

/**
 * A manager closing a check-in the technician never closed: they say when the
 * person actually left. The server refuses a time before the check-in or in the
 * future, and that refusal is shown on the field.
 */
export function CheckOutDialog({ target, onClose }: CheckOutDialogProps) {
  return target === null ? (
    <Modal isOpen={false} onClose={onClose} />
  ) : (
    // Keyed on the row, so the time starts from that row's suggestion each time.
    <CheckOutForm key={target.attendanceId} target={target} onClose={onClose} />
  )
}

function CheckOutForm({ target, onClose }: { target: CheckOutTarget; onClose: () => void }) {
  const [value, setValue] = useState(target.suggested ?? '')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const submit = () =>
    router.post(
      routeTo.attendanceCheckOut(target.attendanceId),
      { check_out_at: value },
      {
        preserveScroll: true,
        onStart: () => setBusy(true),
        onFinish: () => setBusy(false),
        onSuccess: onClose,
        onError: (errors) => setError(errors['check_out_at'] ?? 'That checkout time was not accepted.'),
      },
    )

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={`Check out ${target.employee}`}
      description={`${target.job ?? 'No job'} — checked in ${target.checkedInAt ?? ''} and never checked out. Say when they actually left.`}
      size="sm"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button leftIcon={LogOut} isLoading={busy} disabled={value === ''} onClick={submit}>
            Check out
          </Button>
        </>
      }
    >
      <TextInput
        id="check-out-at"
        type="datetime-local"
        label="Checked out at"
        value={value}
        min={target.min ?? ''}
        onChange={(event) => {
          setValue(event.target.value)
          setError(null)
        }}
        {...(error ? { error } : {})}
      />
    </Modal>
  )
}
