import type { PaymentProcessorStatus, Tone } from '@/types'

export const PAYMENT_PROCESSOR_STATUS_LABEL: Record<PaymentProcessorStatus, string> = {
  not_connected: 'Not Connected',
  active: 'Active',
  limited: 'Limited',
  error: 'Error',
  pending_setup: 'Pending Setup',
  inactive: 'Inactive',
}

export const PAYMENT_PROCESSOR_STATUS_TONE: Record<PaymentProcessorStatus, Tone> = {
  not_connected: 'neutral',
  active: 'success',
  limited: 'warning',
  error: 'danger',
  pending_setup: 'info',
  inactive: 'neutral',
}

export const PAYMENT_TRANSACTION_STATUS_LABEL: Record<string, string> = {
  completed: 'Completed',
  pending: 'Pending',
  failed: 'Failed',
  refunded: 'Refunded',
  partially_refunded: 'Partially Refunded',
  cancelled: 'Cancelled',
}

export const PAYMENT_TRANSACTION_STATUS_TONE: Record<string, Tone> = {
  completed: 'success',
  pending: 'warning',
  failed: 'danger',
  refunded: 'info',
  partially_refunded: 'info',
  cancelled: 'neutral',
}

export const PAYMENT_METHOD_BRANDS = ['Visa', 'Mastercard', 'American Express', 'Discover'] as const
