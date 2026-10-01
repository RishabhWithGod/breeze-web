import { Alert } from './Alert'

export interface RequiredFieldsNoticeProps {
  /** Plain labels, in the order shown on the form — e.g. ["Client", "Project Name"]. */
  fields: readonly string[]
  className?: string
}

/**
 * What a form needs before it can be saved — said up front, not discovered by
 * submitting and getting told. Always visible, unlike the error alert a failed
 * submit shows, which says what is wrong right now rather than what is required
 * at all.
 */
export function RequiredFieldsNotice({ fields, className }: RequiredFieldsNoticeProps) {
  if (fields.length === 0) return null

  return (
    <Alert tone="info" title="Required to save" {...(className ? { className } : {})}>
      {fields.join(', ')} {fields.length === 1 ? 'is' : 'are'} required. Everything else is optional.
    </Alert>
  )
}
