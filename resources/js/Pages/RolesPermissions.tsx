import { Head, router, usePage } from '@inertiajs/react'
import {
  Briefcase,
  CalendarDays,
  Check,
  ChevronUp,
  Clock,
  FileText,
  Folder,
  Pencil,
  Receipt,
  RotateCcw,
  Settings2,
  ShieldCheck,
  Sparkles,
  SquareCheck,
  Users,
  type LucideIcon,
} from 'lucide-react'
import { useMemo, useState } from 'react'
import { Alert, Button, Card } from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES } from '@/constants'
import type { SharedPageProps } from '@/types'
import { cn } from '@/utils'

interface RoleColumn {
  readonly key: string
  readonly label: string
  readonly locked: boolean
}

interface Module {
  readonly key: string
  readonly label: string
  readonly icon: string
  readonly permissions: readonly { readonly key: string; readonly label: string }[]
}

export interface RolesPermissionsProps {
  roles: readonly RoleColumn[]
  modules: readonly Module[]
  granted: Record<string, readonly string[]>
  defaults: Record<string, readonly string[]>
  customised: boolean
}

const ICONS: Record<string, LucideIcon> = {
  users: Users,
  folder: Folder,
  sparkles: Sparkles,
  file: FileText,
  briefcase: Briefcase,
  check: SquareCheck,
  pen: Pencil,
  calendar: CalendarDays,
  clock: Clock,
  receipt: Receipt,
  team: Users,
  settings: Settings2,
}

type Grants = Record<string, ReadonlySet<string>>

const toGrants = (source: Record<string, readonly string[]>): Grants =>
  Object.fromEntries(Object.entries(source).map(([role, keys]) => [role, new Set(keys)]))

const initials = (label: string) =>
  label
    .split(/\s+/)
    .map((word) => word[0])
    .join('')
    .slice(0, 2)
    .toUpperCase()

/** Roles & Permissions: what each role can open and do across Breeze, per company. */
export default function RolesPermissions({ roles, modules, granted, defaults, customised }: RolesPermissionsProps) {
  const { flash, errors } = usePage<SharedPageProps>().props
  const [grants, setGrants] = useState<Grants>(() => toGrants(granted))
  const [collapsed, setCollapsed] = useState<ReadonlySet<string>>(new Set())
  const [busy, setBusy] = useState(false)

  const dirty = useMemo(
    () =>
      roles.some((role) => {
        const now = grants[role.key] ?? new Set<string>()
        const saved = granted[role.key] ?? []

        return now.size !== saved.length || saved.some((key) => !now.has(key))
      }),
    [grants, granted, roles],
  )

  const toggle = (role: string, module: Module, key: string) => {
    setGrants((current) => {
      const next = new Set(current[role])
      const viewKey = `${module.key}.view`
      const hasView = module.permissions.some((permission) => permission.key === viewKey)

      if (next.has(key)) {
        next.delete(key)
        // View is what opens the module: without it nothing else in it can be on.
        if (key === viewKey) module.permissions.forEach((permission) => next.delete(permission.key))
      } else {
        next.add(key)
        if (hasView) next.add(viewKey)
      }

      return { ...current, [role]: next }
    })
  }

  const toggleModule = (name: string) =>
    setCollapsed((current) => {
      const next = new Set(current)
      if (!next.delete(name)) next.add(name)

      return next
    })

  const save = () =>
    router.put(
      ROUTES.rolesPermissions,
      {
        granted: Object.fromEntries(
          roles.filter((role) => !role.locked).map((role) => [role.key, Array.from(grants[role.key] ?? [])]),
        ),
      },
      { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) },
    )

  const problem = Object.values(errors as Record<string, string | undefined>)[0]

  return (
    <PageTransition>
      <Head title="Roles & Permissions" />

      <PageHeader
        title="Roles & Permissions"
        subtitle="Control what each role can access and do across Breeze."
        breadcrumbs={[
          { label: 'Jobs', href: ROUTES.jobs },
          { label: 'Teams', href: ROUTES.teams },
          { label: 'Roles & Permissions' },
        ]}
        className="mb-5"
      />

      {flash.success && (
        <Alert key={flash.success} tone="success" className="mb-4">
          {flash.success}
        </Alert>
      )}
      {problem && (
        <Alert tone="danger" className="mb-4">
          {problem}
        </Alert>
      )}

      <Card padding="md" className="mb-4">
        <div className="flex items-center gap-4">
          <span className="grid size-11 shrink-0 place-items-center rounded-full bg-brand/15 text-brand">
            <ShieldCheck size={22} aria-hidden />
          </span>
          <div>
            <p className="font-semibold text-white">Role-based access control</p>
            <p className="text-sm text-white/80">
              Set permissions for each role. Changes apply to all team members with the selected role. A Project Manager
              always has full access.
            </p>
          </div>
        </div>
      </Card>

      <div className="overflow-x-auto rounded-panel border border-hairline glass">
        <table className="w-full min-w-[46rem] border-collapse text-left text-sm">
          <thead>
            <tr className="border-b border-hairline">
              <th scope="col" className="w-2/5 px-5 py-4 text-md font-semibold text-white">
                Module / Permission
              </th>
              {roles.map((role) => (
                <th key={role.key} scope="col" className="border-l border-hairline px-3 py-4 text-center">
                  <span className="mx-auto mb-2 grid size-10 place-items-center rounded-full bg-brand/25 text-xs font-bold text-white ring-1 ring-brand/40">
                    {initials(role.label)}
                  </span>
                  <span className="block text-xs font-semibold text-white">{role.label}</span>
                  {role.locked && <span className="block text-2xs font-normal text-white/60">Always full access</span>}
                </th>
              ))}
            </tr>
          </thead>

          {modules.map((module) => {
            const Icon = ICONS[module.icon] ?? Settings2
            const isCollapsed = collapsed.has(module.key)

            return (
              <tbody key={module.key}>
                <tr className="border-y border-hairline bg-white/6">
                  <th scope="rowgroup" className="px-5 py-2 text-left">
                    <button
                      type="button"
                      aria-expanded={!isCollapsed}
                      onClick={() => toggleModule(module.key)}
                      className="flex w-full items-center gap-3 font-semibold text-white"
                    >
                      <Icon size={17} aria-hidden className="text-white/80" />
                      {module.label}
                      <ChevronUp
                        size={16}
                        aria-hidden
                        className={cn('ml-auto text-white/70 transition-transform', isCollapsed && 'rotate-180')}
                      />
                    </button>
                  </th>
                  {roles.map((role) => (
                    <td key={role.key} className="border-l border-hairline" />
                  ))}
                </tr>

                {!isCollapsed &&
                  module.permissions.map((permission) => (
                    <tr key={permission.key} className="border-b border-hairline/60 hover:bg-white/4">
                      <th scope="row" className="py-2 pr-3 pl-14 text-left font-normal text-white/90">
                        {permission.label}
                      </th>
                      {roles.map((role) => {
                        const on = role.locked || (grants[role.key]?.has(permission.key) ?? false)

                        return (
                          <td key={role.key} className="border-l border-hairline px-3 py-2 text-center">
                            <label className="inline-flex cursor-pointer items-center has-[input:disabled]:cursor-not-allowed has-[input:disabled]:opacity-60">
                              <input
                                type="checkbox"
                                role="switch"
                                className="peer sr-only"
                                checked={on}
                                disabled={role.locked}
                                aria-label={`${role.label}: ${permission.label}`}
                                onChange={() => toggle(role.key, module, permission.key)}
                              />
                              <span
                                aria-hidden
                                className={cn(
                                  'relative inline-flex h-5 w-9 items-center rounded-full border transition-colors',
                                  on ? 'border-brand bg-brand' : 'border-hairline-strong bg-white/12',
                                  'peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-brand',
                                )}
                              >
                                <span
                                  className={cn(
                                    'inline-block size-3.5 rounded-full bg-white transition-transform',
                                    on ? 'translate-x-[1.125rem]' : 'translate-x-0.5',
                                  )}
                                />
                              </span>
                            </label>
                          </td>
                        )
                      })}
                    </tr>
                  ))}
              </tbody>
            )
          })}
        </table>
      </div>

      <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
        <Button variant="ghost" leftIcon={RotateCcw} onClick={() => setGrants(toGrants(defaults))}>
          Restore defaults
        </Button>
        <div className="flex items-center gap-3">
          {dirty && <span className="text-sm text-white/80">Unsaved changes</span>}
          <Button variant="secondary" disabled={!dirty} onClick={() => setGrants(toGrants(granted))}>
            Discard changes
          </Button>
          <Button leftIcon={Check} isLoading={busy} disabled={!dirty} onClick={save}>
            Save permissions
          </Button>
        </div>
      </div>
      {!customised && !dirty && (
        <p className="mt-3 text-sm text-white/70">These are the default permissions; nothing has been changed yet.</p>
      )}
    </PageTransition>
  )
}

RolesPermissions.layout = appLayout
