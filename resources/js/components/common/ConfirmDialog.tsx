import type { ReactNode } from 'react'
import { AlertTriangle, type LucideIcon } from 'lucide-react'
import type { Variant } from '@/types'
import { cn } from '@/utils'
import { Button } from './Button'
import { Modal } from './Modal'

export interface ConfirmDialogProps {
  isOpen: boolean
  title: string
  description?: ReactNode
  confirmLabel?: string
  cancelLabel?: string
  confirmVariant?: Variant
  tone?: 'danger' | 'brand'
  icon?: LucideIcon
  isBusy?: boolean
  onConfirm: () => void
  onCancel: () => void
}

/** Destructive/confirmation prompt built on top of `Modal`. */
export function ConfirmDialog({
  isOpen,
  title,
  description,
  confirmLabel = 'Confirm',
  cancelLabel = 'Cancel',
  confirmVariant = 'primary',
  tone = 'brand',
  icon: Icon = AlertTriangle,
  isBusy = false,
  onConfirm,
  onCancel,
}: ConfirmDialogProps) {
  return (
    <Modal
      isOpen={isOpen}
      onClose={onCancel}
      size="sm"
      hideCloseButton
      footer={
        <>
          <Button variant="secondary" size="sm" onClick={onCancel}>
            {cancelLabel}
          </Button>
          <Button
            variant={confirmVariant}
            size="sm"
            isLoading={isBusy}
            onClick={onConfirm}
          >
            {confirmLabel}
          </Button>
        </>
      }
    >
      <div className="flex gap-4">
        <span
          className={cn(
            'grid size-12 shrink-0 place-items-center rounded-full',
            tone === 'danger'
              ? 'bg-status-danger/20 text-red-300'
              : 'bg-brand/15 text-brand',
          )}
        >
          <Icon size={22} aria-hidden />
        </span>
        <div>
          <h2 className="text-lg font-semibold text-white">{title}</h2>
          {description && <p className="mt-2 text-md text-white/70">{description}</p>}
        </div>
      </div>
    </Modal>
  )
}
