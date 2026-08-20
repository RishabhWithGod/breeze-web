import { useState } from 'react'
import { useForm } from '@inertiajs/react'
import { Mail, ShieldCheck } from 'lucide-react'
import { Alert, Button, Modal, TextInput } from '@/components/common'
import { routeTo } from '@/constants'

export interface EnableTwoFactorModalProps {
  isOpen: boolean
  onClose: () => void
  maskedEmail: string
}

/**
 * Real enrollment: a code is emailed, then verified server-side. The toggle
 * never flips `two_factor_enabled` on its own — only a verified code does.
 */
export function EnableTwoFactorModal({ isOpen, onClose, maskedEmail }: EnableTwoFactorModalProps) {
  const [step, setStep] = useState<'send' | 'verify'>('send')
  const { post, processing, errors } = useForm<Record<string, string>>({})
  const codeForm = useForm({ code: '' })

  const close = () => {
    setStep('send')
    codeForm.reset()
    onClose()
  }

  const sendCode = () => {
    post(routeTo.securityTwoFactorChallenge, {
      preserveScroll: true,
      onSuccess: () => setStep('verify'),
    })
  }

  const confirm = () => {
    codeForm.post(routeTo.securityTwoFactorConfirm, {
      preserveScroll: true,
      onSuccess: close,
    })
  }

  return (
    <Modal
      isOpen={isOpen}
      onClose={close}
      title="Enable Two-Factor Authentication"
      description={step === 'send' ? 'A verification code will be emailed to you.' : 'Enter the code we just sent.'}
      footer={
        <>
          <Button variant="white" onClick={close}>
            Cancel
          </Button>
          {step === 'send' ? (
            <Button leftIcon={Mail} isLoading={processing} onClick={sendCode}>
              Send Code
            </Button>
          ) : (
            <Button leftIcon={ShieldCheck} isLoading={codeForm.processing} disabled={codeForm.data.code.length === 0} onClick={confirm}>
              Verify &amp; Enable
            </Button>
          )}
        </>
      }
    >
      {step === 'send' ? (
        <div className="space-y-3">
          <p className="text-md text-white/85">
            We'll send a 6-digit verification code to <span className="font-medium text-white">{maskedEmail}</span>.
          </p>
          {errors.method && <Alert tone="danger">{errors.method}</Alert>}
        </div>
      ) : (
        <div className="space-y-4">
          <TextInput
            id="two-factor-code"
            label="Verification Code"
            inputMode="numeric"
            maxLength={6}
            placeholder="123456"
            value={codeForm.data.code}
            onChange={(event) => codeForm.setData('code', event.target.value.replace(/\D/g, '').slice(0, 6))}
            error={codeForm.errors.code}
          />
          <button type="button" onClick={sendCode} className="text-sm text-brand hover:underline">
            Resend code
          </button>
        </div>
      )}
    </Modal>
  )
}
