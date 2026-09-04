import { useState } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import { ArrowLeft, Save } from 'lucide-react'
import {
  Alert,
  Button,
  ButtonLink,
  Card,
  CardHeader,
  Checkbox,
  SelectField,
  TextInput,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES } from '@/constants'
import type { SharedPageProps, TimeTrackingSettingsState } from '@/types'

export interface TimeTrackingSettingsProps {
  settings: TimeTrackingSettingsState
  timezones: readonly string[]
}

/**
 * Admin-only: the overtime thresholds and default rates every calculation in
 * this module reads — `OvertimeCalculator` and `LaborCostCalculator` on the
 * server, nowhere else. Nothing here is hard-coded business logic.
 */
export default function TimeTrackingSettings({ settings, timezones }: TimeTrackingSettingsProps) {
  const { flash } = usePage<SharedPageProps>().props
  const [dismissed, setDismissed] = useState<string | null>(null)

  const [regularDailyHours, setRegularDailyHours] = useState(String(settings.regular_daily_hours))
  const [regularWeeklyHours, setRegularWeeklyHours] = useState(String(settings.regular_weekly_hours))
  const [overtimeMultiplier, setOvertimeMultiplier] = useState(String(settings.overtime_multiplier))
  const [weekendOvertime, setWeekendOvertime] = useState(settings.weekend_overtime)
  const [holidayOvertime, setHolidayOvertime] = useState(settings.holiday_overtime)
  const [defaultBillableRate, setDefaultBillableRate] = useState(
    settings.default_billable_rate !== null ? String(settings.default_billable_rate) : '',
  )
  const [defaultCostRate, setDefaultCostRate] = useState(
    settings.default_cost_rate !== null ? String(settings.default_cost_rate) : '',
  )
  const [timezone, setTimezone] = useState(settings.timezone)
  const [isSaving, setIsSaving] = useState(false)

  const flashed = flash.success ?? null
  const notice = flashed === dismissed ? null : flashed

  const save = () => {
    setIsSaving(true)
    router.put(
      ROUTES.timeTrackingSettings,
      {
        regular_daily_hours: Number(regularDailyHours),
        regular_weekly_hours: Number(regularWeeklyHours),
        overtime_multiplier: Number(overtimeMultiplier),
        weekend_overtime: weekendOvertime,
        holiday_overtime: holidayOvertime,
        default_billable_rate: defaultBillableRate ? Number(defaultBillableRate) : null,
        default_cost_rate: defaultCostRate ? Number(defaultCostRate) : null,
        timezone,
      },
      { preserveScroll: true, onFinish: () => setIsSaving(false) },
    )
  }

  return (
    <PageTransition>
      <Head title="Time Tracking Settings" />

      <PageHeader
        title="Time Tracking Settings"
        subtitle="Overtime rules and default labor rates for the whole company."
        breadcrumbs={[{ label: 'Time Tracking', href: ROUTES.timeTracking }, { label: 'Settings' }]}
        actions={
          <ButtonLink href={ROUTES.timeTracking} variant="secondary" leftIcon={ArrowLeft}>
            Back
          </ButtonLink>
        }
      />

      <AnimatePresence initial={false}>
        {notice && (
          <Alert key={notice} tone="success" className="mb-6" onDismiss={() => setDismissed(notice)}>
            {notice}
          </Alert>
        )}
      </AnimatePresence>

      <div className="grid gap-6 xl:grid-cols-2">
        <Card accent="brand" padding="lg">
          <CardHeader title="Overtime rules" subtitle="When hours stop being regular time" />

          <div className="space-y-4">
            <TextInput
              id="regular-daily-hours"
              type="number"
              min={1}
              max={24}
              step="0.5"
              label="Regular daily hours"
              hint="Hours per day before overtime starts."
              value={regularDailyHours}
              onChange={(event) => setRegularDailyHours(event.target.value)}
            />
            <TextInput
              id="regular-weekly-hours"
              type="number"
              min={1}
              max={168}
              step="1"
              label="Regular weekly hours"
              hint="Hours per week before overtime starts."
              value={regularWeeklyHours}
              onChange={(event) => setRegularWeeklyHours(event.target.value)}
            />
            <TextInput
              id="overtime-multiplier"
              type="number"
              min={1}
              max={5}
              step="0.1"
              label="Overtime multiplier"
              hint="Used for payroll-facing exports, e.g. 1.5×."
              value={overtimeMultiplier}
              onChange={(event) => setOvertimeMultiplier(event.target.value)}
            />
            <Checkbox
              id="weekend-overtime"
              label="Weekend hours are always overtime"
              checked={weekendOvertime}
              onChange={(event) => setWeekendOvertime(event.target.checked)}
            />
            <Checkbox
              id="holiday-overtime"
              label="Holiday hours are always overtime"
              checked={holidayOvertime}
              onChange={(event) => setHolidayOvertime(event.target.checked)}
            />
            <SelectField
              id="timezone"
              label="Business timezone"
              options={timezones.map((tz) => ({ label: tz, value: tz }))}
              value={timezone}
              onChange={(event) => setTimezone(event.target.value)}
            />
            <p className="text-sm text-white/70">
              A running timer&rsquo;s date and clock time are recorded in this timezone.
            </p>
          </div>
        </Card>

        <Card accent="success" padding="lg">
          <CardHeader
            title="Default labor rates"
            subtitle="Used when a team member has no rate of their own set"
          />

          <div className="space-y-4">
            <TextInput
              id="default-billable-rate"
              type="number"
              min={0}
              step="0.01"
              label="Default billable rate ($/hr)"
              value={defaultBillableRate}
              onChange={(event) => setDefaultBillableRate(event.target.value)}
              placeholder="Not set"
            />
            <TextInput
              id="default-cost-rate"
              type="number"
              min={0}
              step="0.01"
              label="Default cost rate ($/hr)"
              hint="Internal labor cost — never shown to employees without permission."
              value={defaultCostRate}
              onChange={(event) => setDefaultCostRate(event.target.value)}
              placeholder="Not set"
            />
          </div>
        </Card>
      </div>

      <div className="mt-6 flex justify-end">
        <Button leftIcon={Save} isLoading={isSaving} onClick={save}>
          Save Settings
        </Button>
      </div>
    </PageTransition>
  )
}

TimeTrackingSettings.layout = appLayout
