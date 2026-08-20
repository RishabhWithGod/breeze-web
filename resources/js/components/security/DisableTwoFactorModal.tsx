import { useForm } from '@inertiajs/react'
import { ShieldOff } from 'lucide-react'
import { Button, Modal, TextInput } from '@/components/common'
import { routeTo } from '@/constants'

export interface DisableTwoFactorModalProps {
  isOpen: boolean
  onClose: () => void
}

/** Disabling 2FA requires the current password — never a bare toggle click. */
export function DisableTwoFactorModal({ isOpen, onClose }: DisableTwoFactorModalProps) {
  const { data, setData, post, processing, errors, reset } = useForm({ current_password: '' })

  const close = () => {
    reset()
    onClose()
  }

  const submit = () => {
    post(routeTo.securityTwoFactorDisable, { preserveScroll: true, onSuccess: close })
  }

  return (
    <Modal
      isOpen={isOpen}
      onClose={close}
      title="Disable Two-Factor Authentication"
      description="Confirm your password to turn off two-factor authentication."
      footer={
        <>
          <Button variant="white" onClick={close}>
            Cancel
          </Button>
          <Button variant="danger" leftIcon={ShieldOff} isLoading={processing} disabled={!data.current_password} onClick={submit}>
            Disable 2FA
          </Button>
        </>
      }
    >
      <TextInput
        id="disable-2fa-password"
        type="password"
        label="Current Password"
        value={data.current_password}
        onChange={(event) => setData('current_password', event.target.value)}
        error={errors.current_password}
        autoComplete="current-password"
      />
    </Modal>
  )
}
