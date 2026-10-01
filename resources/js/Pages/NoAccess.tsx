import { Head } from '@inertiajs/react'
import { ArrowLeft, LayoutDashboard, ShieldAlert } from 'lucide-react'
import { Button, ButtonLink, Card, EmptyState } from '@/components/common'
import { appLayout, PageTransition } from '@/components/layout'
import { ROUTES } from '@/constants'

export interface NoAccessProps {
  message?: string
}

/** Where a page opened directly lands when the person's role may not open it. */
export default function NoAccess({ message }: NoAccessProps) {
  return (
    <PageTransition>
      <Head title="No access" />

      <Card padding="lg" className="mx-auto mt-6 max-w-2xl">
        <EmptyState
          icon={ShieldAlert}
          title="You don't have access to this page"
          description={message ?? "Your role doesn't allow this. Ask a manager if you need access."}
          actions={
            <div className="flex flex-wrap justify-center gap-3">
              <ButtonLink href={ROUTES.home} leftIcon={LayoutDashboard}>
                Go to dashboard
              </ButtonLink>
              <Button variant="secondary" leftIcon={ArrowLeft} onClick={() => window.history.back()}>
                Go back
              </Button>
            </div>
          }
        />
      </Card>
    </PageTransition>
  )
}

NoAccess.layout = appLayout
