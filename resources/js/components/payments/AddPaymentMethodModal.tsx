import { useForm } from '@inertiajs/react'
import { Button, Checkbox, Modal, SelectField, TextInput } from '@/components/common'
import { PAYMENT_METHOD_BRANDS, routeTo } from '@/constants'
import type { PaymentProcessor } from '@/types'

export interface AddPaymentMethodModalProps {
  isOpen: boolean
  onClose: () => void
  connectedProcessors: readonly PaymentProcessor[]
}

/**
 * Only the fields a real tokenized-method response would ever return —
 * brand, last four, expiry. The full card number and CVV are never
 * collected here, by design; there is no field for either.
 */
export function AddPaymentMethodModal({ isOpen, onClose, connectedProcessors }: AddPaymentMethodModalProps) {
  const { data, setData, post, processing, errors, reset } = useForm({
    payment_processor_id: connectedProcessors[0] ? String(connectedProcessors[0].id) : '',
    brand: PAYMENT_METHOD_BRANDS[0] as string,
    last_four: '',
    exp_month: String(new Date().getMonth() + 1),
    exp_year: String(new Date().getFullYear()),
    make_default: false,
  })

  const close = () => {
    reset()
    onClose()
  }

  const submit = () => {
    post(routeTo.paymentMethodsStore, {
      preserveScroll: true,
      onSuccess: close,
    })
  }

  return (
    <Modal
      isOpen={isOpen}
      onClose={close}
      title="Add Payment Method"
      description="Saved against a connected processor — never a raw card number."
      footer={
        <>
          <Button variant="white" onClick={close}>
            Cancel
          </Button>
          <Button onClick={submit} isLoading={processing} disabled={!data.payment_processor_id || data.last_four.length !== 4}>
            Add Payment Method
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <SelectField
          id="method-processor"
          label="Payment Processor"
          options={connectedProcessors.map((processor) => ({ label: processor.displayName, value: String(processor.id) }))}
          value={data.payment_processor_id}
          onChange={(event) => setData('payment_processor_id', event.target.value)}
          error={errors.payment_processor_id}
        />

        <SelectField
          id="method-brand"
          label="Card Brand"
          options={PAYMENT_METHOD_BRANDS.map((brand) => ({ label: brand, value: brand }))}
          value={data.brand}
          onChange={(event) => setData('brand', event.target.value)}
        />

        <TextInput
          id="method-last-four"
          label="Last 4 Digits"
          inputMode="numeric"
          maxLength={4}
          placeholder="4242"
          value={data.last_four}
          onChange={(event) => setData('last_four', event.target.value.replace(/\D/g, '').slice(0, 4))}
          error={errors.last_four}
        />

        <div className="grid grid-cols-2 gap-4">
          <TextInput
            id="method-exp-month"
            type="number"
            min={1}
            max={12}
            label="Expiry Month"
            value={data.exp_month}
            onChange={(event) => setData('exp_month', event.target.value)}
            error={errors.exp_month}
          />
          <TextInput
            id="method-exp-year"
            type="number"
            min={new Date().getFullYear()}
            label="Expiry Year"
            value={data.exp_year}
            onChange={(event) => setData('exp_year', event.target.value)}
            error={errors.exp_year}
          />
        </div>

        <Checkbox
          id="method-make-default"
          label="Make this the default payment method"
          checked={data.make_default}
          onChange={(event) => setData('make_default', event.target.checked)}
        />
      </div>
    </Modal>
  )
}
