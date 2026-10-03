import { useState } from 'react'
import { Plus, Users, X } from 'lucide-react'
import { Button, SearchSelect, TextInput } from '@/components/common'

/** The sentinel prefix a drafted-but-not-yet-real team's id carries. */
const DRAFT_PREFIX = 'new:'

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
 * and back to add one would lose everything they had typed on this form, so a
 * typed name is kept as a draft option here and only turns into a real team
 * when the form around it is actually saved — never the moment "Add team" is
 * clicked, which used to leave a team behind in the register even when the
 * form it was meant for was abandoned.
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
  const [addError, setAddError] = useState<string | null>(null)
  /** Names typed in this session but not yet real teams — shown as options so one can be picked right away. */
  const [drafts, setDrafts] = useState<readonly string[]>([])

  const reset = () => {
    setName('')
    setAddError(null)
    setAdding(false)
  }

  const save = () => {
    const trimmed = name.trim()

    if (trimmed === '') {
      setAddError('Name this team.')

      return
    }

    setDrafts((current) => (current.includes(trimmed) ? current : [...current, trimmed]))
    onChange(DRAFT_PREFIX + trimmed)
    reset()
  }

  const options = [
    ...teams.map((team) => ({ label: team.name, value: String(team.id) })),
    ...drafts.map((draftName) => ({ label: `${draftName} (new)`, value: DRAFT_PREFIX + draftName })),
  ]

  return (
    <div>
      <SearchSelect
        id="job-team"
        label={required ? 'Team*' : 'Team'}
        options={options}
        value={value}
        onChange={onChange}
        placeholder={options.length > 0 ? 'Select team' : 'No teams yet'}
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
              <Button type="button" variant="ghost" size="sm" leftIcon={X} onClick={reset}>
                Cancel
              </Button>
            </div>

            <TextInput
              id="new-team-name"
              label="Team Name*"
              placeholder="e.g. North Crew"
              autoComplete="off"
              value={name}
              onChange={(event) => setName(event.target.value)}
              {...(addError ? { error: addError } : {})}
            />

            <p className="mt-2 text-xs text-white/70">
              Saved once this form is — not before.
            </p>

            <Button type="button" size="sm" className="mt-3" leftIcon={Plus} onClick={save}>
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
