import { useState } from 'react'
import { useForm } from '@inertiajs/react'
import { Mail } from 'lucide-react'
import { Button, Modal, TextInput } from '@/components/common'
import { routeTo } from '@/constants'

export interface ChangeEmailModalProps {
  isOpen: boolean
  onClose: () => void
}

/** A new email only takes effect after a code sent to it is verified. */
export function ChangeEmailModal({ isOpen, onClose }: ChangeEmailModalProps) {
  const [step, setStep] = useState<'request' | 'verify'>('request')
  const emailForm = useForm({ email: '' })
  const codeForm = useForm({ code: '' })

  const close = () => {
    setStep('request')
    emailForm.reset()
    codeForm.reset()
    onClose()
  }

  const requestCode = () => {
    emailForm.post(routeTo.securityEmailChallenge, { preserveScroll: true, onSuccess: () => setStep('verify') })
  }

  const confirm = () => {
    codeForm.post(routeTo.securityEmailConfirm, { preserveScroll: true, onSuccess: close })
  }

  return (
    <Modal
      isOpen={isOpen}
      onClose={close}
      title="Change Email Address"
      description={step === 'request' ? 'Enter your new email address.' : `Enter the code sent to ${emailForm.data.email}.`}
      footer={
        <>
          <Button variant="white" onClick={close}>
            Cancel
          </Button>
          {step === 'request' ? (
            <Button leftIcon={Mail} isLoading={emailForm.processing} disabled={!emailForm.data.email} onClick={requestCode}>
              Send Code
            </Button>
          ) : (
            <Button isLoading={codeForm.processing} disabled={!codeForm.data.code} onClick={confirm}>
              Confirm Change
            </Button>
          )}
        </>
      }
    >
      {step === 'request' ? (
        <TextInput
          id="new-email"
          type="email"
          label="New Email Address"
          value={emailForm.data.email}
          onChange={(event) => emailForm.setData('email', event.target.value)}
          error={emailForm.errors.email}
        />
      ) : (
        <TextInput
          id="email-change-code"
          label="Verification Code"
          inputMode="numeric"
          maxLength={6}
          value={codeForm.data.code}
          onChange={(event) => codeForm.setData('code', event.target.value.replace(/\D/g, '').slice(0, 6))}
          error={codeForm.errors.code}
        />
      )}
    </Modal>
  )
}
