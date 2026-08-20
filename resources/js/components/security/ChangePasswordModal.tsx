import { useForm } from '@inertiajs/react'
import { KeyRound } from 'lucide-react'
import { Button, Modal, TextInput } from '@/components/common'
import { routeTo } from '@/constants'

export interface ChangePasswordModalProps {
  isOpen: boolean
  onClose: () => void
}

export function ChangePasswordModal({ isOpen, onClose }: ChangePasswordModalProps) {
  const { data, setData, put, processing, errors, reset } = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
  })

  const close = () => {
    reset()
    onClose()
  }

  const submit = () => {
    put(routeTo.securityPasswordUpdate, { preserveScroll: true, onSuccess: close })
  }

  return (
    <Modal
      isOpen={isOpen}
      onClose={close}
      title="Change Password"
      description="Every other signed-in session for your account will be signed out."
      footer={
        <>
          <Button variant="white" onClick={close}>
            Cancel
          </Button>
          <Button leftIcon={KeyRound} isLoading={processing} onClick={submit}>
            Update Password
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <TextInput
          id="current-password"
          type="password"
          label="Current Password"
          value={data.current_password}
          onChange={(event) => setData('current_password', event.target.value)}
          error={errors.current_password}
          autoComplete="current-password"
        />
        <TextInput
          id="new-password"
          type="password"
          label="New Password"
          value={data.password}
          onChange={(event) => setData('password', event.target.value)}
          error={errors.password}
          autoComplete="new-password"
        />
        <TextInput
          id="new-password-confirmation"
          type="password"
          label="Confirm New Password"
          value={data.password_confirmation}
          onChange={(event) => setData('password_confirmation', event.target.value)}
          autoComplete="new-password"
        />
      </div>
    </Modal>
  )
}
