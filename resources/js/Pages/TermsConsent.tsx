import { Head, Link, useForm } from '@inertiajs/react'
import { ArrowLeft, ArrowRight, CalendarDays, FileText } from 'lucide-react'
import { Button, Checkbox, TextInput } from '@/components/common'
import { buttonStyles } from '@/components/common/buttonStyles'
import { appLayout, PageTransition } from '@/components/layout'

interface LegalSection {
  readonly title: string
  readonly intro: string
  readonly points: readonly string[]
}

export interface TermsConsentProps {
  terms: LegalSection
  privacy: LegalSection
  /** Today, in the company's own time zone. */
  signedOn: string
  /** Who signed before, when the account comes back here from a later step. */
  signerName: string | null
}

/** "2026-09-23" → "09/23/2026" */
function usDate(iso: string): string {
  const [year, month, day] = iso.split('-')

  return `${month}/${day}/${year}`
}

/**
 * Terms and Consent — the second setup step.
 *
 * The terms are read in the box, agreed to one by one, and signed with a name.
 * The date is the day it is signed, set by the server, so it is shown but not
 * editable.
 */
export default function TermsConsent({ terms, privacy, signedOn, signerName }: TermsConsentProps) {
  const { data, setData, post, processing, errors, clearErrors } = useForm({
    accept_terms: false,
    accept_privacy: false,
    signer_name: signerName ?? '',
  })

  const scrollTo = (id: string) => document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' })

  const ready = data.accept_terms && data.accept_privacy && data.signer_name.trim().length >= 2

  return (
    <PageTransition>
      <Head title="Terms and Consent" />

      <header className="mb-6">
        <h1 className="text-4xl font-bold text-white">Breeze.Ai Application</h1>
        <p className="mt-1 text-2xl text-white/70">Terms and Consent</p>
      </header>

      <div className="mb-6 flex items-center gap-5 rounded-card border border-l-4 border-brand/40 border-l-brand glass px-6 py-5 shadow-glow">
        <span className="grid size-14 shrink-0 place-items-center rounded-full bg-brand/20 text-white">
          <FileText size={26} aria-hidden />
        </span>
        <div className="min-w-0">
          <p className="text-lg font-semibold text-white">
            Please review and accept the terms below to complete your setup.
          </p>
          <p className="text-md text-white/75">
            You must agree to the Terms of Service and Privacy Policy to use Breeze.Ai.
          </p>
        </div>
      </div>

      <form
        onSubmit={(event) => {
          event.preventDefault()
          post('/terms', { preserveScroll: true })
        }}
        noValidate
        className="rounded-card border border-hairline glass"
      >
        <div className="p-6 sm:p-8">
          <h2 className="text-2xl font-bold text-white">Terms of Service and Privacy Summary</h2>
          <p className="mt-1 text-md text-white/80">
            Please read the following information carefully. You can scroll to view the full terms.
          </p>

          <div
            tabIndex={0}
            role="region"
            aria-label="Terms of Service and Privacy Summary"
            className="mt-5 h-[26rem] overflow-y-auto rounded-card border border-hairline-strong bg-white/4 p-6 focus-visible:outline-2 focus-visible:outline-brand sm:p-8"
          >
            <LegalBlock id="terms-of-service" section={terms} />
            <hr className="my-8 border-hairline" />
            <LegalBlock id="privacy-policy" section={privacy} />
          </div>

          <div className="mt-6 space-y-3">
            <div>
              <Checkbox
                id="accept-terms"
                checked={data.accept_terms}
                onChange={(event) => {
                  setData('accept_terms', event.target.checked)
                  clearErrors('accept_terms')
                }}
                label={
                  <>
                    I have read and agree to the{' '}
                    <a
                      href="#terms-of-service"
                      onClick={(event) => {
                        event.preventDefault()
                        scrollTo('terms-of-service')
                      }}
                      className="font-medium text-brand underline underline-offset-2"
                    >
                      Terms of Service.
                    </a>{' '}
                    <Required />
                  </>
                }
              />
              {errors.accept_terms && <p className="mt-1.5 ml-8 text-sm text-red-300">{errors.accept_terms}</p>}
            </div>
            <div>
              <Checkbox
                id="accept-privacy"
                checked={data.accept_privacy}
                onChange={(event) => {
                  setData('accept_privacy', event.target.checked)
                  clearErrors('accept_privacy')
                }}
                label={
                  <>
                    I have read and agree to the{' '}
                    <a
                      href="#privacy-policy"
                      onClick={(event) => {
                        event.preventDefault()
                        scrollTo('privacy-policy')
                      }}
                      className="font-medium text-brand underline underline-offset-2"
                    >
                      Privacy Policy.
                    </a>{' '}
                    <Required />
                  </>
                }
              />
              {errors.accept_privacy && <p className="mt-1.5 ml-8 text-sm text-red-300">{errors.accept_privacy}</p>}
            </div>
          </div>

          <div className="mt-6 grid gap-5 sm:grid-cols-2">
            <TextInput
              id="signer-name"
              label="Authorized Signer Name"
              placeholder="Enter your full name"
              autoComplete="name"
              maxLength={255}
              value={data.signer_name}
              onChange={(event) => {
                setData('signer_name', event.target.value)
                clearErrors('signer_name')
              }}
              rightSlot={<Required />}
              {...(errors.signer_name ? { error: errors.signer_name } : {})}
            />
            <TextInput
              id="signed-on"
              label="Date"
              readOnly
              value={usDate(signedOn)}
              hint="Today's date — recorded when you sign."
              rightSlot={
                <span className="flex items-center gap-2 text-white/85">
                  <CalendarDays size={18} aria-hidden />
                  <Required />
                </span>
              }
            />
          </div>
        </div>

        <div className="flex items-center justify-between gap-4 border-t border-hairline px-6 py-5 sm:px-8">
          <Link href="/company-setup" className={buttonStyles({ variant: 'secondary', size: 'lg' })}>
            <ArrowLeft size={18} aria-hidden />
            Back
          </Link>
          <Button type="submit" size="lg" rightIcon={ArrowRight} isLoading={processing} disabled={!ready || processing}>
            Accept and Continue
          </Button>
        </div>
      </form>
    </PageTransition>
  )
}

TermsConsent.layout = appLayout

function Required() {
  return (
    <span aria-hidden className="text-status-danger">
      *
    </span>
  )
}

/** One document in the reading box: its heading, opening paragraph and key points. */
function LegalBlock({ id, section }: { id: string; section: LegalSection }) {
  return (
    <section id={id} className="scroll-mt-2">
      <h3 className="text-2xl font-bold text-white">{section.title}</h3>
      <p className="mt-3 max-w-4xl text-md leading-relaxed text-white/90">{section.intro}</p>
      <h4 className="mt-5 text-lg font-semibold text-white">Key Points</h4>
      <ul className="mt-3 space-y-2.5">
        {section.points.map((point) => (
          <li key={point} className="flex items-start gap-3 text-md text-white/90">
            <span aria-hidden className="mt-2 size-2.5 shrink-0 rounded-full bg-brand" />
            {point}
          </li>
        ))}
      </ul>
    </section>
  )
}
