import { Head } from '@inertiajs/react'
import { Compass, Home } from 'lucide-react'
import { ButtonLink, Card, EmptyState } from '@/components/common'
import { appLayout, PageTransition } from '@/components/layout'
import { ROUTES } from '@/constants'

/** Rendered for any 404, including unmatched URLs. */
export default function NotFound() {
  return (
    <PageTransition>
      <Head title="Page not found" />

      <Card padding="lg" className="mt-6">
        <EmptyState
          icon={Compass}
          size="lg"
          title="404 — Page not found"
          description="The page you're looking for doesn't exist. Head back to the dashboard or start a new takeoff."
          actions={
            <>
              <ButtonLink href={ROUTES.home} leftIcon={Home}>
                Go to dashboard
              </ButtonLink>
              <ButtonLink href={ROUTES.upload} variant="secondary">
                Start a takeoff
              </ButtonLink>
            </>
          }
        />
      </Card>
    </PageTransition>
  )
}

NotFound.layout = appLayout
