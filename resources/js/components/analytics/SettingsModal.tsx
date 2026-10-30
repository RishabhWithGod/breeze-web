import { useState } from 'react'
import { router } from '@inertiajs/react'
import { Button, Modal, TextInput } from '@/components/common'
import { ROUTES } from '@/constants'
import type { AnalyticsSettings } from '@/types'

const FIELDS: readonly { key: keyof AnalyticsSettings; label: string; hint: string; step: string }[] = [
  { key: 'low_margin_pct', label: 'Low margin below (%)', hint: 'A job with a margin under this is flagged.', step: '0.5' },
  { key: 'hours_warning_pct', label: 'Hours warning at (%)', hint: 'Flag a job once this share of its estimated hours is used.', step: '1' },
  { key: 'stale_timer_hours', label: 'Missing check-out after (hours)', hint: 'A timer still running past this is treated as a missed check-out.', step: '1' },
  { key: 'overdue_grace_days', label: 'Overdue grace (days)', hint: 'Days after the due date before an invoice counts as overdue.', step: '1' },
]

export function SettingsModal({ isOpen, onClose, settings }: { isOpen: boolean; onClose: () => void; settings: AnalyticsSettings }) {
  const [draft, setDraft] = useState<Record<keyof AnalyticsSettings, string>>(() => toDraft(settings))
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [saving, setSaving] = useState(false)

  const save = () => {
    setSaving(true)
    router.put(`${ROUTES.jobCosting}/settings`, draft, {
      preserveScroll: true,
      onError: (e) => setErrors(e),
      onSuccess: () => {
        setErrors({})
        onClose()
      },
      onFinish: () => setSaving(false),
    })
  }

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title="Alert thresholds"
      description="What counts as needing attention on the Analytics alerts."
      size="md"
      footer={
        <div className="flex justify-end gap-3">
          <Button variant="white" onClick={() => { setDraft(toDraft(settings)); onClose() }}>Cancel</Button>
          <Button onClick={save} isLoading={saving}>Save</Button>
        </div>
      }
    >
      <div className="grid gap-4 sm:grid-cols-2">
        {FIELDS.map((field) => (
          <TextInput
            key={field.key}
            id={`analytics-${field.key}`}
            type="number"
            step={field.step}
            min={0}
            label={field.label}
            hint={field.hint}
            error={errors[field.key]}
            value={draft[field.key]}
            onChange={(event) => setDraft({ ...draft, [field.key]: event.target.value })}
          />
        ))}
      </div>
    </Modal>
  )
}

function toDraft(settings: AnalyticsSettings): Record<keyof AnalyticsSettings, string> {
  return {
    low_margin_pct: String(settings.low_margin_pct),
    hours_warning_pct: String(settings.hours_warning_pct),
    stale_timer_hours: String(settings.stale_timer_hours),
    overdue_grace_days: String(settings.overdue_grace_days),
  }
}
