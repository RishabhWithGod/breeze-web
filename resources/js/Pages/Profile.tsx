import { Head, useForm, usePage } from '@inertiajs/react'
import { Save, User as UserIcon } from 'lucide-react'
import { Alert, Button, Card, SectionHeading, TextInput } from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES } from '@/constants'
import type { SharedPageProps } from '@/types'

interface ProfileUser {
  name: string
  email: string
  phone: string | null
  role: string
  initials: string
}

export interface ProfileProps {
  user: ProfileUser
}

/**
 * The user's own profile. Only `name` is editable here — email, phone and
 * password all go through an OTP-verified challenge and stay on the
 * Security page, so they're shown read-only with a link across.
 */
export default function Profile({ user }: ProfileProps) {
  const { flash } = usePage<SharedPageProps>().props
  const { data, setData, put, processing, errors } = useForm({ name: user.name })

  const submit = (event: React.FormEvent) => {
    event.preventDefault()
    put(ROUTES.profile, { preserveScroll: true })
  }

  return (
    <PageTransition>
      <Head title="Profile" />

      <PageHeader
        title="Profile"
        subtitle="Your account details"
        breadcrumbs={[{ label: 'Profile' }]}
      />

      {flash.success && (
        <Alert tone="success" className="mb-6">
          {flash.success}
        </Alert>
      )}

      <form onSubmit={submit} noValidate>
        <Card padding="lg">
          <SectionHeading title="Account" />

          <div className="flex items-center gap-4 pb-6">
            <span className="grid size-16 shrink-0 place-items-center rounded-full bg-ocean-800 text-xl font-semibold text-white ring-1 ring-steel-600">
              {user.initials}
            </span>
            <div>
              <p className="text-md font-semibold text-white">{user.name}</p>
              <p className="text-xs text-white/70">{user.role}</p>
            </div>
          </div>

          <div className="space-y-6">
            <TextInput
              id="profile-name"
              label="Name*"
              leftIcon={UserIcon}
              value={data.name}
              onChange={(event) => setData('name', event.target.value)}
              error={errors.name}
            />

            <TextInput
              id="profile-email"
              label="Email"
              value={user.email}
              disabled
              hint={
                <>
                  Change your email from{' '}
                  <a href={ROUTES.security} className="text-brand underline">
                    Security settings
                  </a>
                  .
                </>
              }
            />

            <TextInput
              id="profile-phone"
              label="Phone"
              value={user.phone ?? 'Not set'}
              disabled
              hint={
                <>
                  Change your phone from{' '}
                  <a href={ROUTES.security} className="text-brand underline">
                    Security settings
                  </a>
                  .
                </>
              }
            />
          </div>

          <div className="mt-8 flex justify-end">
            <Button type="submit" leftIcon={Save} isLoading={processing}>
              Save changes
            </Button>
          </div>
        </Card>
      </form>
    </PageTransition>
  )
}

Profile.layout = appLayout
