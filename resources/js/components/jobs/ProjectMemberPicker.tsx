import { useEffect, useRef, useState } from 'react'
import { router } from '@inertiajs/react'
import { Plus, Users, UserPlus, X } from 'lucide-react'
import { Button, Checkbox, SelectField, StatusChip, TextInput } from '@/components/common'
import { ROUTES } from '@/constants'
import type { ClientTeamMemberOption, Tone } from '@/types'

const ROLE_TONE: Record<string, Tone> = {
  foreman: 'info',
  journeyman: 'neutral',
  apprentice: 'brand',
}

const ROLE_OPTIONS = [
  { value: 'journeyman', label: 'Journeyman' },
  { value: 'foreman', label: 'Foreman' },
  { value: 'apprentice', label: 'Apprentice' },
]

export interface ProjectMemberPickerProps {
  /** The client's crew — null until a client with no team yet is picked. */
  teamId: number | null
  teamName: string | null
  members: readonly ClientTeamMemberOption[]
  /** The ids (as strings) staffed to this project. */
  value: readonly string[]
  onChange: (ids: string[]) => void
  disabled?: boolean
  /** Hides the inline "Add a member" option. */
  allowInlineAdd?: boolean
  /** The heading above the list. */
  label?: string
  /** Marks the heading as required and shows `error` under the list. */
  required?: boolean
  error?: string
  /**
   * Shows the list and the add option even when no team is chosen yet. A member
   * added that way is on no team; the team is assigned later.
   */
  allowNoTeam?: boolean
}

/**
 * Who from the client's own crew is staffed to a project — and a way to add
 * someone the roster does not have yet, without leaving the form.
 *
 * Raising a project for a client whose crew is missing someone is normal.
 * Sending whoever is filling this form to the register and back would lose
 * every field they had already typed, so a new member is added from right
 * here and checked off the moment they arrive.
 */
export function ProjectMemberPicker({
  teamId,
  teamName,
  members,
  value,
  onChange,
  disabled = false,
  allowInlineAdd = true,
  label = 'Project Members',
  required = false,
  error,
  allowNoTeam = false,
}: ProjectMemberPickerProps) {
  const [adding, setAdding] = useState(false)
  const [name, setName] = useState('')
  const [role, setRole] = useState('journeyman')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
  const [saving, setSaving] = useState(false)
  const [addErrors, setAddErrors] = useState<Record<string, string>>({})

  /*
   * The name just typed, so the member it becomes can be checked off the
   * moment they arrive. A ref rather than state: this drives one effect on
   * the next render and nothing about what is drawn.
   */
  const justAdded = useRef<string | null>(null)

  useEffect(() => {
    const typed = justAdded.current

    if (typed === null) return

    const added = members.find((member) => member.name === typed)

    if (!added) return

    justAdded.current = null
    onChange([...value, String(added.id)])
    // Only `members` should re-run this — `value`/`onChange` would fire it
    // again on every toggle.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [members])

  const toggle = (id: string) => {
    onChange(value.includes(id) ? value.filter((existing) => existing !== id) : [...value, id])
  }

  const reset = () => {
    setName('')
    setRole('journeyman')
    setEmail('')
    setPassword('')
    setPasswordConfirmation('')
    setAddErrors({})
    setAdding(false)
  }

  const save = () => {
    if (name.trim() === '') {
      setAddErrors({ name: 'Enter their name.' })

      return
    }

    router.post(
      ROUTES.foremen,
      {
        name: name.trim(),
        role,
        team_id: teamId,
        email: email.trim(),
        password,
        password_confirmation: passwordConfirmation,
        // `inline` keeps the server from redirecting to the register and
        // taking this half-filled project form with it.
        inline: true,
      },
      {
        preserveScroll: true,
        preserveState: true,
        onStart: () => {
          setSaving(true)
          setAddErrors({})
        },
        onSuccess: () => {
          justAdded.current = name.trim()
          reset()
        },
        onError: (errors) => setAddErrors(errors as Record<string, string>),
        onFinish: () => setSaving(false),
      },
    )
  }

  return (
    <div>
      <label className="mb-2 flex items-center gap-2 text-md font-medium text-white">
        <Users size={16} aria-hidden className="text-white/60" />
        {label}
        {required && <span className="text-red-300">*</span>}
      </label>

      {teamId === null && !allowNoTeam ? (
        <p className="text-sm text-white/60">
          This client has no crew on file yet — add one from their profile to staff a project.
        </p>
      ) : (
        <>
          {members.length > 0 ? (
            <div className="space-y-2.5 rounded-panel border border-hairline bg-white/4 p-4">
              {members.map((member) => (
                <div key={member.id} className="flex items-center justify-between gap-3">
                  <Checkbox
                    id={`project-member-${member.id}`}
                    label={member.name}
                    checked={value.includes(String(member.id))}
                    onChange={() => toggle(String(member.id))}
                    disabled={disabled}
                  />
                  <StatusChip
                    hideDot
                    tone={ROLE_TONE[member.role] ?? 'neutral'}
                    label={member.roleLabel}
                  />
                </div>
              ))}
            </div>
          ) : (
            <p className="text-sm text-white/60">
              {teamName ? `No one is on “${teamName}” yet.` : 'No members yet — add one below.'}
            </p>
          )}

          {allowInlineAdd &&
            (adding ? (
              <div className="mt-3 rounded-panel border border-hairline bg-white/4 p-4">
                <div className="mb-3 flex items-center justify-between gap-3">
                  <p className="text-sm font-medium text-white">New member for {teamName ?? 'this client'}</p>
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    leftIcon={X}
                    disabled={saving}
                    onClick={reset}
                  >
                    Cancel
                  </Button>
                </div>

                <div className="space-y-4">
                  <TextInput
                    id="new-project-member-name"
                    label="Name*"
                    placeholder="e.g. Dana Wu"
                    autoComplete="off"
                    value={name}
                    disabled={saving}
                    onChange={(event) => setName(event.target.value)}
                    {...(addErrors.name ? { error: addErrors.name } : {})}
                  />

                  <SelectField
                    id="new-project-member-role"
                    label="Role*"
                    options={ROLE_OPTIONS}
                    value={role}
                    disabled={saving}
                    onChange={(event) => setRole(event.target.value)}
                    {...(addErrors.role ? { error: addErrors.role } : {})}
                  />

                  <TextInput
                    id="new-project-member-email"
                    label="Email*"
                    type="email"
                    placeholder="e.g. dana@example.com"
                    autoComplete="off"
                    value={email}
                    disabled={saving}
                    onChange={(event) => setEmail(event.target.value)}
                    {...(addErrors.email ? { error: addErrors.email } : {})}
                  />

                  <div className="grid gap-4 sm:grid-cols-2">
                    <TextInput
                      id="new-project-member-password"
                      label="Password*"
                      type="password"
                      autoComplete="new-password"
                      value={password}
                      disabled={saving}
                      onChange={(event) => setPassword(event.target.value)}
                      {...(addErrors.password ? { error: addErrors.password } : {})}
                    />

                    <TextInput
                      id="new-project-member-password-confirmation"
                      label="Confirm Password*"
                      type="password"
                      autoComplete="new-password"
                      value={passwordConfirmation}
                      disabled={saving}
                      onChange={(event) => setPasswordConfirmation(event.target.value)}
                    />
                  </div>
                </div>

                <p className="mt-3 text-sm text-white/70">
                  They also get a mobile-app login with the email and password above.
                </p>

                <Button
                  type="button"
                  size="sm"
                  className="mt-4"
                  leftIcon={Plus}
                  isLoading={saving}
                  onClick={save}
                >
                  Add member
                </Button>
              </div>
            ) : (
              <Button
                type="button"
                variant="white"
                size="sm"
                className="mt-3"
                leftIcon={UserPlus}
                disabled={disabled}
                onClick={() => setAdding(true)}
              >
                Add a member
              </Button>
            ))}
        </>
      )}

      {error && <p className="mt-2 text-sm text-red-300">{error}</p>}
    </div>
  )
}
