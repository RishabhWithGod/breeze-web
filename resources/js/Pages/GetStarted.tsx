import { Head, Link, router, usePage } from '@inertiajs/react'
import {
  ArrowRight,
  Building2,
  Check,
  ChevronRight,
  Clock,
  Contact,
  FolderKanban,
  Headphones,
  House,
  ListChecks,
  Lock,
  PlayCircle,
  BookOpen,
  Rocket,
  Users,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useState } from 'react'
import { Alert, Button, ButtonLink, Card } from '@/components/common'
import { appLayout, PageTransition } from '@/components/layout'
import { ROUTES } from '@/constants'
import type { SharedPageProps } from '@/types'
import { cn } from '@/utils'

type StepStatus = 'completed' | 'pending' | 'locked' | 'skipped'

interface Step {
  readonly key: string
  readonly title: string
  readonly description: string
  readonly status: StepStatus
  readonly href: string
  readonly action: string
  readonly skippable: boolean
  readonly lockedBecause: string | null
}

export interface GetStartedProps {
  checklist: {
    readonly steps: readonly Step[]
    readonly completed: number
    readonly total: number
    readonly percent: number
    /** The first step still to do; null when there is none. */
    readonly next: string | null
    readonly finished: boolean
    /** True once the steps that cannot be skipped are done. */
    readonly canFinish: boolean
  }
  /** Where the Need Help links go; only those that are set. */
  help: { readonly documentation?: string; readonly setup_guide?: string; readonly support?: string }
}

const STEP_ICON: Record<string, LucideIcon> = {
  company: Building2,
  team: Users,
  commodities: ListChecks,
  client: Contact,
  project: FolderKanban,
}

/**
 * Get Started — the setup checklist a company works through after it subscribes.
 *
 * Progress is read from what exists, so it follows the work wherever it is done. Continue
 * Setup goes to the first step still to do; two steps can be skipped; Finish Setup
 * closes the checklist once the ones that cannot be are done.
 */
export default function GetStarted({ checklist, help }: GetStartedProps) {
  const { flash } = usePage<SharedPageProps>().props
  const [busy, setBusy] = useState(false)

  const next = checklist.steps.find((step) => step.key === checklist.next)
  const allDone = checklist.completed === checklist.total

  const message =
    checklist.finished || allDone
      ? { title: "You're all set!", body: 'Your workspace is ready to go.' }
      : checklist.completed >= 3
        ? { title: 'Almost there!', body: 'Complete the remaining steps to unlock the full power of Breeze.Ai.' }
        : { title: "Let's get going!", body: 'A few quick steps and your workspace is ready for real work.' }

  const skip = (step: Step, undo = false) =>
    router.post(`/get-started/skip/${step.key}`, undo ? { undo: true } : {}, { preserveScroll: true })

  const finish = () =>
    router.post('/get-started/finish', {}, { onStart: () => setBusy(true), onFinish: () => setBusy(false) })

  const helpLinks = [
    { label: 'View Documentation', icon: BookOpen, href: help.documentation },
    { label: 'Watch Setup Guide', icon: PlayCircle, href: help.setup_guide },
    { label: 'Contact Support', icon: Headphones, href: help.support },
  ].filter((link): link is { label: string; icon: LucideIcon; href: string } => Boolean(link.href))

  return (
    <PageTransition>
      <Head title="Get Started" />

      <header className="mb-5">
        <p className="text-xs font-semibold tracking-wider text-brand uppercase">Get started</p>
        <h1 className="mt-1 text-3xl font-bold text-white">Welcome to Breeze.Ai</h1>
        <p className="mt-2 max-w-2xl text-md text-white/85">
          Let&apos;s get your workspace ready. Complete the setup checklist below to start managing your clients,
          commodities, estimates, and projects.
        </p>
      </header>

      {flash.warning && (
        <Alert key={flash.warning} tone="warning" className="mb-5">
          {flash.warning}
        </Alert>
      )}

      {/* ---------------------------------------------------------------- progress -- */}
      <Card padding="md" className="mb-4">
        <div className="grid items-center gap-5 lg:grid-cols-[1fr_auto_minmax(0,22rem)] lg:divide-x lg:divide-hairline">
          <div className="lg:pr-8">
            <h2 className="text-lg font-semibold text-white">Setup Progress</h2>
            <div className="mt-3 flex items-center gap-4">
              <div
                role="progressbar"
                aria-label="Setup progress"
                aria-valuemin={0}
                aria-valuemax={checklist.total}
                aria-valuenow={checklist.completed}
                className="h-3 flex-1 overflow-hidden rounded-full bg-white/10"
              >
                <div
                  className="h-full rounded-full bg-linear-to-r from-brand to-blue-500 transition-[width] duration-500"
                  style={{ width: `${checklist.percent}%` }}
                />
              </div>
              <span className="shrink-0 text-sm font-semibold text-white tabular-nums">
                {checklist.completed} of {checklist.total} complete
              </span>
            </div>
          </div>
          <span className="hidden lg:block" />
          <div className="flex items-center gap-3 lg:pl-8">
            <span className="grid size-12 shrink-0 place-items-center rounded-full bg-brand/15 text-brand ring-1 ring-brand/30">
              <Rocket size={22} aria-hidden />
            </span>
            <div>
              <p className="text-md font-semibold text-white">{message.title}</p>
              <p className="text-xs text-white/80">{message.body}</p>
            </div>
          </div>
        </div>
      </Card>

      <div className="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_20rem]">
        {/* ---------------------------------------------------------------- checklist -- */}
        <Card padding="md">
          <h2 className="text-lg font-semibold text-white">Setup Checklist</h2>
          <p className="mt-0.5 text-xs text-white/75">
            Follow these steps to configure your account and start working.
          </p>

          <ul className="mt-4 space-y-2.5">
            {checklist.steps.map((step) => (
              <StepRow key={step.key} step={step} onSkip={() => skip(step)} onUndo={() => skip(step, true)} />
            ))}
          </ul>
        </Card>

        <aside className="min-w-0 space-y-4">
          {helpLinks.length > 0 && (
            <Card padding="md">
              <h2 className="text-lg font-semibold text-white">Need Help?</h2>
              <p className="mt-0.5 text-xs text-white/75">Explore our resources to get the most out of Breeze.Ai.</p>
              <ul className="mt-3 space-y-2.5">
                {helpLinks.map(({ label, icon: Icon, href }) => (
                  <li key={label}>
                    <a
                      href={href}
                      target="_blank"
                      rel="noreferrer"
                      className="flex items-center gap-3 rounded-panel border border-hairline bg-white/5 px-4 py-3 text-sm font-semibold text-white transition-colors hover:border-brand/50 hover:bg-white/10"
                    >
                      <Icon size={18} aria-hidden className="text-white/90" />
                      {label}
                    </a>
                  </li>
                ))}
              </ul>
            </Card>
          )}

          <Card padding="none" className="overflow-hidden">
            <h2 className="border-b border-hairline px-5 py-3.5 text-lg font-semibold text-white">Quick Actions</h2>
            <ul className="divide-y divide-hairline">
              {[
                // The dashboard is this screen until setup is done, so a link to it would only come back here.
                ...(checklist.finished || allDone ? ([['Go to Dashboard', House, ROUTES.home]] as const) : []),
                ['Manage Team', Users, ROUTES.teams] as const,
              ].map(([label, Icon, href]) => (
                <li key={label}>
                  <Link
                    href={href}
                    className="flex items-center gap-3 px-5 py-3 text-sm font-semibold text-brand transition-colors hover:bg-white/6"
                  >
                    <Icon size={18} aria-hidden />
                    {label}
                  </Link>
                </li>
              ))}
            </ul>
          </Card>
        </aside>
      </div>

      {/* ------------------------------------------------------------------- actions -- */}
      <div className="mt-5 flex flex-wrap items-center gap-4">
        {next ? (
          <ButtonLink href={next.href} variant="blue" size="md" rightIcon={ArrowRight}>
            Continue Setup
          </ButtonLink>
        ) : checklist.finished ? (
          <ButtonLink href={ROUTES.home} variant="blue" size="md" rightIcon={ArrowRight}>
            Go to Dashboard
          </ButtonLink>
        ) : (
          <Button
            variant="blue"
            size="lg"
            rightIcon={ArrowRight}
            isLoading={busy}
            disabled={!checklist.canFinish}
            onClick={finish}
          >
            Finish Setup
          </Button>
        )}
        {next && (
          <p className="text-xs text-white/70">
            Next: <span className="font-semibold text-white">{next.title}</span>
          </p>
        )}
      </div>
    </PageTransition>
  )
}

GetStarted.layout = appLayout

const CHIP: Record<StepStatus, { label: string; className: string; icon: LucideIcon | null }> = {
  completed: {
    label: 'Completed',
    className: 'border-status-success/60 bg-status-success/15 text-status-success',
    icon: Check,
  },
  pending: {
    label: 'Pending',
    className: 'border-status-warning/60 bg-status-warning/12 text-status-warning',
    icon: Clock,
  },
  locked: { label: 'Locked', className: 'border-hairline-strong bg-white/6 text-white/80', icon: Lock },
  skipped: { label: 'Skipped', className: 'border-hairline-strong bg-white/6 text-white/80', icon: null },
}

function StepRow({ step, onSkip, onUndo }: { step: Step; onSkip: () => void; onUndo: () => void }) {
  const Icon = STEP_ICON[step.key] ?? ListChecks
  const chip = CHIP[step.status]
  const ChipIcon = chip.icon
  const open = step.status === 'pending' || step.status === 'skipped'

  const body = (
    <>
      <span
        className={cn(
          'grid size-10 shrink-0 place-items-center rounded-full',
          step.status === 'completed'
            ? // A solid, deeper green with a white tick, so the check reads clearly.
              'bg-green-600 text-white ring-2 ring-green-400/40'
            : 'bg-white/10 text-white/85 ring-1 ring-hairline',
        )}
      >
        {step.status === 'completed' ? (
          <Check size={20} strokeWidth={3} aria-hidden />
        ) : step.status === 'locked' ? (
          <Lock size={18} aria-hidden />
        ) : (
          <Icon size={18} aria-hidden />
        )}
      </span>
      <span className="min-w-0 flex-1">
        <span className="block text-md font-semibold text-white">{step.title}</span>
        <span className="block text-xs text-white/75">{step.lockedBecause ?? step.description}</span>
      </span>
    </>
  )

  return (
    <li className="flex flex-wrap items-center gap-3 rounded-panel border border-hairline bg-white/4 px-4 py-3 transition-colors hover:border-brand/40 sm:flex-nowrap">
      {open ? (
        <Link href={step.href} className="flex min-w-0 flex-1 items-center gap-4">
          {body}
        </Link>
      ) : (
        <div className="flex min-w-0 flex-1 items-center gap-4">{body}</div>
      )}

      <div className="flex shrink-0 items-center gap-3">
        {step.skippable && step.status === 'pending' && (
          <button
            type="button"
            onClick={onSkip}
            className="text-xs font-medium text-white/70 underline-offset-2 hover:text-white hover:underline"
          >
            Skip
          </button>
        )}
        {step.status === 'skipped' && (
          <button
            type="button"
            onClick={onUndo}
            className="text-xs font-medium text-brand underline-offset-2 hover:underline"
          >
            Undo skip
          </button>
        )}
        <span
          className={cn(
            'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold',
            chip.className,
          )}
        >
          {ChipIcon && <ChipIcon size={13} aria-hidden />}
          {chip.label}
        </span>
        {open ? <ChevronRight size={18} aria-hidden className="text-white/80" /> : <span className="w-5" />}
      </div>
    </li>
  )
}
