import { useState } from 'react'
import { useForm } from '@inertiajs/react'
import { Phone } from 'lucide-react'
import { Button, Modal, TextInput } from '@/components/common'
import { routeTo } from '@/constants'
import { formatUsPhone } from '@/utils'

export interface ChangePhoneModalProps {
  isOpen: boolean
  onClose: () => void
}

/**
 * No SMS provider is configured, so the confirmation code for a phone change
 * goes to email instead — a real verification step either way, never a
 * silent, unverified update.
 */
export function ChangePhoneModal({ isOpen, onClose }: ChangePhoneModalProps) {
  const [step, setStep] = useState<'request' | 'verify'>('request')
  const phoneForm = useForm({ phone: '' })
  const codeForm = useForm({ code: '' })

  const close = () => {
    setStep('request')
    phoneForm.reset()
    codeForm.reset()
    onClose()
  }

  const requestCode = () => {
    phoneForm.post(routeTo.securityPhoneChallenge, { preserveScroll: true, onSuccess: () => setStep('verify') })
  }

  const confirm = () => {
    codeForm.post(routeTo.securityPhoneConfirm, { preserveScroll: true, onSuccess: close })
  }

  return (
    <Modal
      isOpen={isOpen}
      onClose={close}
      title="Change Phone Number"
      description={
        step === 'request'
          ? "Enter your new phone number — we'll email a confirmation code, since no SMS provider is configured yet."
          : 'Enter the code we emailed you.'
      }
      footer={
        <>
          <Button variant="white" onClick={close}>
            Cancel
          </Button>
          {step === 'request' ? (
            <Button leftIcon={Phone} isLoading={phoneForm.processing} disabled={!phoneForm.data.phone} onClick={requestCode}>
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
          id="new-phone"
          type="tel"
          inputMode="tel"
          label="New Phone Number"
          placeholder="(415) 555-0134"
          value={phoneForm.data.phone}
          onChange={(event) => phoneForm.setData('phone', formatUsPhone(event.target.value))}
          error={phoneForm.errors.phone}
        />
      ) : (
        <TextInput
          id="phone-change-code"
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
