import { Head } from '@inertiajs/react'
import { BookOpen, FolderOpen, Sparkles, Upload } from 'lucide-react'
import { ButtonLink, Card, EmptyState as EmptyStateBlock, SkeletonCard } from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES } from '@/constants'

const SUGGESTIONS = [
  {
    icon: Upload,
    title: 'Upload a drawing set',
    body: 'PDF, DWG, DXF, BIM and IFC files up to 100 MB each.',
  },
  {
    icon: Sparkles,
    title: 'Run an AI takeoff',
    body: 'Symbol detection, quantities and labour hours in minutes.',
  },
  {
    icon: BookOpen,
    title: 'Read the guide',
    body: 'Learn how to prepare sheets for the best detection accuracy.',
  },
]

/** Canonical "nothing here yet" screen. */
export default function EmptyState() {
  return (
    <PageTransition>
      <Head title="Projects" />

      <PageHeader
        title="Projects"
        subtitle="Everything you take off with AI lands here."
        breadcrumbs={[
          { label: 'AI Takeoff', href: ROUTES.upload },
          { label: 'Projects' },
        ]}
      />

      <Card padding="lg">
        <EmptyStateBlock
          icon={FolderOpen}
          size="lg"
          title="No takeoffs yet"
          description="Upload your first set of electrical drawings and Breeze will detect symbols, count devices and estimate material and labour for you."
          actions={
            <>
              <ButtonLink href={ROUTES.upload} leftIcon={Upload}>
                Upload drawings
              </ButtonLink>
              <ButtonLink href={ROUTES.history} variant="secondary">
                Browse your history
              </ButtonLink>
            </>
          }
          footer={
            <div className="grid gap-4 sm:grid-cols-3">
              {SUGGESTIONS.map(({ icon: Icon, title, body }) => (
                <div
                  key={title}
                  className="rounded-card border border-hairline bg-navy-950/30 p-5 text-left"
                >
                  <Icon size={20} aria-hidden className="mb-3 text-brand" />
                  <p className="text-md font-semibold text-white">{title}</p>
                  <p className="mt-1 text-sm text-white/85">{body}</p>
                </div>
              ))}
            </div>
          }
        />
      </Card>

      {/* Ghost placeholders hint at what the populated view will look like. */}
      <section className="mt-6" aria-label="Loading preview">
        <p className="mb-4 text-sm tracking-wide text-white/65 uppercase">
          Your dashboard will look like this
        </p>
        <div className="grid gap-5 opacity-60 lg:grid-cols-3">
          <SkeletonCard />
          <SkeletonCard />
          <SkeletonCard />
        </div>
      </section>
    </PageTransition>
  )
}

EmptyState.layout = appLayout
