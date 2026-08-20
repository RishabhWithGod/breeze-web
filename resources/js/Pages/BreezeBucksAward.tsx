import { useState } from 'react'
import { Head, useForm, usePage } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import { ArrowLeft, Gift } from 'lucide-react'
import { Alert, Button, ButtonLink, Card, SelectField, TextInput } from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES, routeTo } from '@/constants'
import type { BreezeBucksTeamMember, SharedPageProps } from '@/types'

export interface BreezeBucksAwardProps {
  teamMembers: readonly BreezeBucksTeamMember[]
}

/**
 * The manager-only Award Bonus screen — its own page, reached from "Award
 * Bonus" on the Breeze Bucks landing page. The server re-checks
 * authorization independently; a role or user id never comes from the
 * client alone.
 */
export default function BreezeBucksAward({ teamMembers }: BreezeBucksAwardProps) {
  const { flash } = usePage<SharedPageProps>().props
  const [dismissed, setDismissed] = useState<string | null>(null)
  const { data, setData, post, processing, errors, reset } = useForm({
    user_id: teamMembers[0] ? String(teamMembers[0].id) : '',
    amount: '',
    reason: '',
  })

  const notice = flash.success ?? flash.warning ?? null
  const displayNotice = notice === dismissed ? null : notice

  const submit = () => {
    post(routeTo.breezeBucksAward, {
      preserveScroll: true,
      onSuccess: () => reset('amount', 'reason'),
    })
  }

  return (
    <PageTransition>
      <Head title="Award Breeze Bucks" />

      <PageHeader
        title="Award Breeze Bucks"
        subtitle="Give a team member a bonus for great work."
        actions={
          <ButtonLink href={ROUTES.breezeBucks} variant="secondary" leftIcon={ArrowLeft}>
            Back to Breeze Bucks
          </ButtonLink>
        }
      />

      <AnimatePresence initial={false}>
        {displayNotice && (
          <Alert key={displayNotice} tone={flash.warning ? 'warning' : 'success'} className="mb-6" onDismiss={() => setDismissed(displayNotice)}>
            {displayNotice}
          </Alert>
        )}
      </AnimatePresence>

      <Card className="mx-auto max-w-xl">
        <div className="space-y-4">
          <SelectField
            id="award-user"
            label="Team Member"
            options={teamMembers.map((member) => ({ label: `${member.name} (${member.role})`, value: String(member.id) }))}
            value={data.user_id}
            onChange={(event) => setData('user_id', event.target.value)}
            error={errors.user_id}
          />
          <TextInput
            id="award-amount"
            type="number"
            min={1}
            label="Amount (BB)"
            value={data.amount}
            onChange={(event) => setData('amount', event.target.value)}
            error={errors.amount}
          />
          <TextInput
            id="award-reason"
            label="Reason"
            placeholder="Excellent work this week"
            value={data.reason}
            onChange={(event) => setData('reason', event.target.value)}
            error={errors.reason}
          />
          <Button leftIcon={Gift} isLoading={processing} disabled={!data.user_id || !data.amount || !data.reason} onClick={submit}>
            Award
          </Button>
        </div>
      </Card>
    </PageTransition>
  )
}

BreezeBucksAward.layout = appLayout
