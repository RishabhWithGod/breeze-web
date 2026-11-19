import { Head, useForm, usePage } from '@inertiajs/react'
import { Mail, Phone, Save, ShieldCheck, User as UserIcon } from 'lucide-react'
import { Alert, Avatar, Badge, Button, Panel, Stat, TextInput } from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES } from '@/constants'
import { toTitleCase } from '@/utils'
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

      <div className="grid items-start gap-5 xl:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
      <form onSubmit={submit} noValidate>
        <Panel title="Account" sub="Your name and sign-in details" className="h-auto min-w-0">
          <div className="space-y-6">
            <TextInput
              id="profile-name"
              label="Name*"
              leftIcon={UserIcon}
              value={data.name}
              onChange={(event) => setData('name', toTitleCase(event.target.value))}
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
        </Panel>
      </form>

        <div className="min-w-0 space-y-4">
          <div className="glass relative overflow-hidden rounded-card border border-hairline p-[22px] text-center">
            <span
              aria-hidden
              className="pointer-events-none absolute -top-12 -right-12 size-40 rounded-full bg-[radial-gradient(circle,var(--glow-a),transparent_70%)]"
            />
            <Avatar person={{ name: user.name, initials: user.initials }} className="relative mx-auto size-20 text-2xl" />
            <p className="relative mt-3 font-display text-xl font-[620] text-white">{user.name}</p>
            <div className="relative mt-1.5 flex justify-center">
              <Badge tone="brand">{user.role}</Badge>
            </div>
          </div>

          <Stat label="Email" icon={<Mail size={13} aria-hidden />} value={<span className="block truncate text-lg">{user.email}</span>} className="h-full" />
          <Stat
            label="Phone"
            icon={<Phone size={13} aria-hidden />}
            value={<span className="block truncate text-lg">{user.phone ?? 'Not set'}</span>}
            delta={
              <span className="inline-flex items-center gap-1">
                <ShieldCheck size={12} aria-hidden /> Changed from Security settings
              </span>
            }
            className="h-full"
          />
        </div>
      </div>
    </PageTransition>
  )
}

Profile.layout = appLayout
