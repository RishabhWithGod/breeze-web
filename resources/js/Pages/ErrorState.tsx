import { Head, router } from '@inertiajs/react'
import { LifeBuoy, RotateCcw, Upload } from 'lucide-react'
import { Alert, Button, ButtonLink, Card, ErrorState as ErrorStateBlock } from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES } from '@/constants'

const SAMPLE_DETAILS = `POST /ai-takeoff/upload → 500
request_id: 9f3c21ae-77b1-4d0e-9a54-1c2f0f9b1d77
stage: detect_symbols
message: Symbol detection worker exited before returning a result`

export interface ErrorStateProps {
  /** HTTP status, set when this renders in place of a failed response. */
  status?: number
}

/** Failure screen for takeoff errors and unhandled server errors. */
export default function ErrorState({ status }: ErrorStateProps) {
  const details = status ? `HTTP ${status}\n${SAMPLE_DETAILS}` : SAMPLE_DETAILS

  return (
    <PageTransition>
      <Head title="Something went wrong" />

      <PageHeader
        title="Something went wrong"
        subtitle="The takeoff could not be completed."
        breadcrumbs={[{ label: 'AI Takeoff', href: ROUTES.upload }, { label: 'Error' }]}
      />

      <Card padding="lg">
        <ErrorStateBlock
          code={status ? `ERR_HTTP_${status}` : 'ERR_TAKEOFF_500'}
          title="We couldn't finish this takeoff"
          description="The analysis stopped while detecting symbols. Your files are safe — nothing was lost. Try running the takeoff again, or contact support if it keeps happening."
          details={details}
          actions={
            <>
              <Button leftIcon={RotateCcw} onClick={() => router.reload()}>
                Try again
              </Button>
              <ButtonLink href={ROUTES.upload} variant="secondary" leftIcon={Upload}>
                Back to upload
              </ButtonLink>
              <Button variant="ghost" leftIcon={LifeBuoy}>
                Contact support
              </Button>
            </>
          }
        />

        <Alert tone="info" title="What you can try" className="mt-8">
          <ul className="mt-2 list-disc space-y-1 pl-5">
            <li>Flatten the PDF and remove password protection before re-uploading.</li>
            <li>Split drawing sets larger than 100 MB into separate uploads.</li>
            <li>Make sure the legend page is included so symbols can be matched.</li>
          </ul>
        </Alert>
      </Card>
    </PageTransition>
  )
}

ErrorState.layout = appLayout
