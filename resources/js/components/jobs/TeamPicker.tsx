import { useEffect, useRef, useState } from 'react'
import { router } from '@inertiajs/react'
import { Plus, Users, X } from 'lucide-react'
import { Button, SearchSelect, TextInput } from '@/components/common'
import { ROUTES } from '@/constants'

export interface TeamPickerProps {
  teams: readonly { readonly id: number; readonly name: string }[]
  value: string
  onChange: (teamId: string) => void
  hint?: string
  error?: string
  disabled?: boolean
  /** Marks the field with the same asterisk every other required field on the form uses. */
  required?: boolean
  /** Hides the inline "Add a team" option — the register is the only way to add one from this form. */
  allowInlineAdd?: boolean
}

/**
 * Which crew a job is handed to — and a way to record one that is not on the
 * register yet.
 *
 * Raising a job for a crew nobody has written down is normal, especially on the
 * first job anyone raises: the register starts empty. Sending someone to Teams
 * and back to add one would lose everything they had typed on this form, so it
 * is recorded from here and picked the moment it arrives.
 */
export function TeamPicker({
  teams,
  value,
  onChange,
  hint,
  error,
  disabled = false,
  required = false,
  allowInlineAdd = true,
}: TeamPickerProps) {
  const [adding, setAdding] = useState(false)
  const [name, setName] = useState('')
  const [saving, setSaving] = useState(false)
  const [addError, setAddError] = useState<string | null>(null)

  /*
   * The name just typed, so the crew it becomes can be selected the moment it
   * arrives. A ref rather than state: this drives one call on the next render
   * and nothing about what is drawn.
   */
  const justAdded = useRef<string | null>(null)

  /*
   * The new crew comes back as part of the refreshed props, not in the
   * response — reading it off the response would mean knowing which prop the
   * parent keeps its teams under, which is not this component's business.
   */
  useEffect(() => {
    const typed = justAdded.current

    if (typed === null) return

    const added = teams.find((team) => team.name === typed)

    if (!added) return

    justAdded.current = null
    onChange(String(added.id))
  }, [teams, onChange])

  const reset = () => {
    setName('')
    setAddError(null)
    setAdding(false)
  }

  const save = () => {
    if (name.trim() === '') {
      setAddError('Name this team.')

      return
    }

    router.post(
      ROUTES.teams,
      // `inline` keeps the server from redirecting to the register and taking
      // this half-filled form with it.
      { name: name.trim(), inline: true },
      {
        preserveScroll: true,
        preserveState: true,
        onStart: () => {
          setSaving(true)
          setAddError(null)
        },
        onSuccess: () => {
          justAdded.current = name.trim()
          reset()
        },
        onError: (errors) => setAddError(errors['name'] ?? 'That team could not be saved.'),
        onFinish: () => setSaving(false),
      },
    )
  }

  return (
    <div>
      <SearchSelect
        id="job-team"
        label={required ? 'Team*' : 'Team'}
        options={teams.map((team) => ({ label: team.name, value: String(team.id) }))}
        value={value}
        onChange={onChange}
        placeholder={teams.length > 0 ? 'Select team' : 'No teams yet'}
        emptyLabel="No team yet"
        disabled={disabled}
        {...(hint ? { hint } : {})}
        {...(error ? { error } : {})}
      />

      {allowInlineAdd &&
        (adding ? (
          <div className="mt-3 rounded-panel border border-hairline bg-white/4 p-4">
            <div className="mb-3 flex items-center justify-between gap-3">
              <p className="text-sm font-medium text-white">New team</p>
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

            <TextInput
              id="new-team-name"
              label="Team Name*"
              placeholder="e.g. North Crew"
              autoComplete="off"
              value={name}
              disabled={saving}
              onChange={(event) => setName(event.target.value)}
              {...(addError ? { error: addError } : {})}
            />

            <Button
              type="button"
              size="sm"
              className="mt-4"
              leftIcon={Plus}
              isLoading={saving}
              onClick={save}
            >
              Add team
            </Button>
          </div>
        ) : (
          <Button
            type="button"
            variant="white"
            size="sm"
            className="mt-3"
            leftIcon={Users}
            disabled={disabled}
            onClick={() => setAdding(true)}
          >
            Add a team
          </Button>
        ))}
    </div>
  )
}
