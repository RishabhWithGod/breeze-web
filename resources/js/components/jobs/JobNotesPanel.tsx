import { router, useForm } from '@inertiajs/react'
import { AnimatePresence, motion } from 'framer-motion'
import { Send, Trash2 } from 'lucide-react'
import { Button, IconButton, TextArea } from '@/components/common'
import { routeTo } from '@/constants'
import type { JobNote } from '@/types'
import { formatRelative } from '@/utils'

export interface JobNotesPanelProps {
  jobId: number
  notes: readonly JobNote[]
}

/** Notes list plus composer; both go through JobNoteController. */
export function JobNotesPanel({ jobId, notes }: JobNotesPanelProps) {
  const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
    body: '',
  })

  const submit = (event: React.FormEvent) => {
    event.preventDefault()

    post(routeTo.jobNotes(jobId), {
      preserveScroll: true,
      onSuccess: () => reset(),
    })
  }

  const remove = (noteId: number) => {
    router.delete(routeTo.jobNote(jobId, noteId), { preserveScroll: true })
  }

  return (
    <div>
      <form onSubmit={submit} className="mb-5">
        <TextArea
          id="job-note-body"
          rows={3}
          placeholder="Add a note for the crew…"
          value={data.body}
          onChange={(event) => {
            setData('body', event.target.value)
            if (errors.body) clearErrors('body')
          }}
          {...(errors.body ? { error: errors.body } : {})}
        />
        <div className="mt-3 flex justify-end">
          <Button type="submit" size="sm" leftIcon={Send} isLoading={processing}>
            Add note
          </Button>
        </div>
      </form>

      {notes.length === 0 ? (
        <p className="text-md text-white/50">No notes yet.</p>
      ) : (
        <ul className="space-y-3">
          <AnimatePresence initial={false}>
            {notes.map((note) => (
              <motion.li
                key={note.id}
                initial={{ opacity: 0, y: 8 }}
                animate={{ opacity: 1, y: 0 }}
                exit={{ opacity: 0, height: 0 }}
                className="rounded-panel border border-hairline bg-white/4 p-4"
              >
                <div className="flex items-start justify-between gap-3">
                  <p className="min-w-0 text-md whitespace-pre-line text-white/90">
                    {note.body}
                  </p>
                  <IconButton
                    icon={Trash2}
                    label={`Delete note by ${note.author}`}
                    size="sm"
                    className="shrink-0 text-white/45 hover:text-status-danger"
                    onClick={() => remove(note.id)}
                  />
                </div>
                <p className="mt-2 text-sm text-white/45">
                  {note.author} · {formatRelative(note.createdAt)}
                </p>
              </motion.li>
            ))}
          </AnimatePresence>
        </ul>
      )}
    </div>
  )
}
