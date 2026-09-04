import { useEffect, useRef, useState } from 'react'
import { router } from '@inertiajs/react'
import { Plus, UserPlus, X } from 'lucide-react'
import { Button, SelectField, TextInput } from '@/components/common'
import { ROUTES } from '@/constants'

export interface CrewMemberPickerProps {
  id: string
  label: string
  /** What this picker is for. Decides the role a new person is recorded with. */
  role: 'foreman' | 'supervisor'
  people: readonly { readonly id: number; readonly name: string }[]
  value: string
  onChange: (id: string) => void
  /**
   * The crew a new person joins. Null when the job has no crew — they go on the
   * register with none, which is the honest answer rather than a guess.
   */
  teamId: number | null
  teamName: string | null
  /** The first row: a prompt for a required picker, a real choice for an optional one. */
  emptyLabel: string
  error?: string
  disabled?: boolean
}

/**
 * Who on the crew runs a task, or supervises it — and a way to record someone
 * the crew does not have yet.
 *
 * A crew with no supervisor on it, or a job whose team was only just created,
 * both leave a picker with nothing in it. Sending the planner to Teams and back
 * would lose every task row they had typed, so the person is recorded from here
 * and picked the moment they arrive.
 *
 * They join the job's own crew, so the narrowing that made the list short is
 * the same rule that puts them in it.
 */
export function CrewMemberPicker({
  id,
  label,
  role,
  people,
  value,
  onChange,
  teamId,
  teamName,
  emptyLabel,
  error,
  disabled = false,
}: CrewMemberPickerProps) {
  const [adding, setAdding] = useState(false)
  const [name, setName] = useState('')
  const [saving, setSaving] = useState(false)
  const [addError, setAddError] = useState<string | null>(null)

  /*
   * The name just typed, so the person it becomes can be selected the moment
   * they arrive. A ref rather than state: this drives one call on the next
   * render and nothing about what is drawn.
   */
  const justAdded = useRef<string | null>(null)

  useEffect(() => {
    const typed = justAdded.current

    if (typed === null) return

    const added = people.find((person) => person.name === typed)

    if (!added) return

    justAdded.current = null
    onChange(String(added.id))
  }, [people, onChange])

  const reset = () => {
    setName('')
    setAddError(null)
    setAdding(false)
  }

  const save = () => {
    if (name.trim() === '') {
      setAddError('Enter their name.')

      return
    }

    router.post(
      ROUTES.foremen,
      {
        name: name.trim(),
        role,
        // Straight onto the job's crew, so the list this picker offers is the
        // list they land in.
        team_id: teamId,
        // `inline` keeps the server from redirecting to the register and taking
        // this half-filled form with it.
        inline: true,
      },
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
        onError: (errors) =>
          setAddError(errors['name'] ?? errors['role'] ?? 'They could not be saved.'),
        onFinish: () => setSaving(false),
      },
    )
  }

  return (
    <div>
      <SelectField
        id={id}
        label={label}
        options={[
          { label: emptyLabel, value: '' },
          ...people.map((person) => ({ label: person.name, value: String(person.id) })),
        ]}
        value={value}
        disabled={disabled}
        onChange={(event) => onChange(event.target.value)}
        {...(error ? { error } : {})}
      />

      {adding ? (
        <div className="mt-3 rounded-panel border border-hairline bg-white/4 p-4">
          <div className="mb-3 flex items-center justify-between gap-3">
            <p className="text-sm font-medium text-white">
              {teamName === null ? `New ${role}` : `New ${role} for ${teamName}`}
            </p>
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
            id={`${id}-new-name`}
            label="Name*"
            placeholder="e.g. Dana Wu"
            autoComplete="off"
            value={name}
            disabled={saving}
            onChange={(event) => setName(event.target.value)}
            {...(addError ? { error: addError } : {})}
          />

          {/* Said plainly: a person recorded here with no crew shows on the
              register under "Not on a team", and this is where that starts. */}
          {teamName === null && (
            <p className="mt-2 text-sm text-white/70">
              This job has no crew, so they go on the register without one.
            </p>
          )}

          <Button
            type="button"
            size="sm"
            className="mt-4"
            leftIcon={Plus}
            isLoading={saving}
            onClick={save}
          >
            Add {role}
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
          Add a {role}
        </Button>
      )}
    </div>
  )
}
