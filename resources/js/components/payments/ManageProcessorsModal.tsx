import { useState } from 'react'
import { useForm } from '@inertiajs/react'
import { CheckCircle2, Loader2, Plug, Unplug, XCircle } from 'lucide-react'
import { Alert, Button, Modal, StatusChip, TextInput } from '@/components/common'
import { PAYMENT_PROCESSOR_STATUS_LABEL, PAYMENT_PROCESSOR_STATUS_TONE, routeTo } from '@/constants'
import type { PaymentProcessor } from '@/types'
import { formatModified } from '@/utils'
import { ProcessorMark } from './ProcessorMark'

export interface ManageProcessorsModalProps {
  isOpen: boolean
  onClose: () => void
  processors: readonly PaymentProcessor[]
}

/**
 * Both "Add Payment Processor" and "Manage Integrations" open this same
 * modal — connecting, testing and disconnecting a processor is one workflow
 * with two entry points, not two separate systems.
 */
export function ManageProcessorsModal({ isOpen, onClose, processors }: ManageProcessorsModalProps) {
  const [expanded, setExpanded] = useState<number | null>(null)

  const close = () => {
    setExpanded(null)
    onClose()
  }

  return (
    <Modal isOpen={isOpen} onClose={close} title="Manage Payment Integrations" description="Connect, test or disconnect a payment processor." size="lg">
      <div className="space-y-4">
        {processors.map((processor) => (
          <ProcessorRow
            key={processor.id}
            processor={processor}
            isExpanded={expanded === processor.id}
            onToggle={() => setExpanded(expanded === processor.id ? null : processor.id)}
            onConnected={close}
          />
        ))}
      </div>
    </Modal>
  )
}

function ProcessorRow({
  processor,
  isExpanded,
  onToggle,
  onConnected,
}: {
  processor: PaymentProcessor
  isExpanded: boolean
  onToggle: () => void
  onConnected: () => void
}) {
  const { data, setData, post, delete: destroy, processing, errors, reset } = useForm<Record<string, string>>(
    Object.fromEntries(Object.keys(processor.credentialFields).map((field) => [field, ''])),
  )

  const connect = () => {
    post(routeTo.paymentProcessorConnect(processor.id), {
      preserveScroll: true,
      onSuccess: () => {
        reset()
        onConnected()
      },
    })
  }

  const test = () => {
    post(routeTo.paymentProcessorTest(processor.id), { preserveScroll: true })
  }

  const disconnect = () => {
    destroy(routeTo.paymentProcessorDisconnect(processor.id), { preserveScroll: true })
  }

  return (
    <div className="rounded-panel border border-hairline bg-white/4 p-4 transition-colors duration-200 hover:border-hairline-strong hover:bg-white/6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <ProcessorMark processorKey={processor.key} size="sm" />
          <div>
            <p className="font-semibold text-white">{processor.displayName}</p>
            <p className="mt-0.5 text-sm text-white/70">
              {processor.connectedAt ? `Connected on ${formatModified(processor.connectedAt)}` : 'Not yet connected'}
            </p>
          </div>
        </div>

        <div className="flex items-center gap-3">
          <StatusChip hideDot tone={PAYMENT_PROCESSOR_STATUS_TONE[processor.status]} label={PAYMENT_PROCESSOR_STATUS_LABEL[processor.status]} />

          {processor.isConnected ? (
            <>
              <Button variant="white" size="sm" leftIcon={processing ? Loader2 : CheckCircle2} isLoading={processing} onClick={test}>
                Test
              </Button>
              <Button
                variant="white"
                size="sm"
                leftIcon={Unplug}
                onClick={disconnect}
                className="text-status-danger hover:border-status-danger hover:bg-status-danger hover:text-white"
              >
                Disconnect
              </Button>
            </>
          ) : (
            <Button variant="secondary" size="sm" leftIcon={Plug} onClick={onToggle}>
              {isExpanded ? 'Cancel' : 'Connect'}
            </Button>
          )}
        </div>
      </div>

      {processor.lastError && !isExpanded && (
        <Alert tone="danger" className="mt-3">
          <span className="flex items-center gap-2 text-sm">
            <XCircle size={14} aria-hidden />
            {processor.lastError}
          </span>
        </Alert>
      )}

      {isExpanded && !processor.isConnected && (
        <div className="mt-4 space-y-3 border-t border-hairline pt-4">
          {Object.entries(processor.credentialFields).map(([field, label]) => (
            <TextInput
              key={field}
              id={`${processor.key}-${field}`}
              type="password"
              label={label}
              value={data[field] ?? ''}
              onChange={(event) => setData(field, event.target.value)}
              error={errors[field]}
              autoComplete="off"
            />
          ))}
          {errors.credentials && <p className="text-sm text-red-300">{errors.credentials}</p>}
          <p className="text-xs text-white/60">
            Stored encrypted on the server. Connecting makes one real, authenticated call to {processor.displayName} to confirm it works.
          </p>
          <Button size="sm" leftIcon={Plug} isLoading={processing} onClick={connect}>
            Connect {processor.displayName}
          </Button>
        </div>
      )}
    </div>
  )
}
