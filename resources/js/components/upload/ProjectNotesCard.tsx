import { Info } from 'lucide-react'
import { Button, Card, CardHeader, TextArea } from '@/components/common'
import { MAX_NOTES_LENGTH } from '@/constants'

export interface ProjectNotesCardProps {
  value: string
  onChange: (notes: string) => void
  /** Server-side validation message for the notes field. */
  error?: string
  index?: number
}

/**
 * Free-text project notes, posted alongside the drawing set.
 *
 * Controlled by the upload store so the value survives leaving the page; the
 * length cap matches StoreUploadRequest.
 */
export function ProjectNotesCard({
  value,
  onChange,
  error,
  index,
}: ProjectNotesCardProps) {
  const tooLong = value.length > MAX_NOTES_LENGTH
  const message =
    error ?? (tooLong ? `Notes are limited to ${MAX_NOTES_LENGTH} characters` : undefined)

  return (
    <Card {...(index !== undefined ? { index } : {})}>
      <CardHeader title="Project Notes" />

      <TextArea
        id="project-notes"
        rows={4}
        placeholder="Add any specific instructions or details about the project…"
        addon={`${value.length}/${MAX_NOTES_LENGTH}`}
        value={value}
        onChange={(event) => onChange(event.target.value)}
        {...(message ? { error: message } : {})}
      />

      <div className="mt-4 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <p className="flex items-start gap-3 text-md text-white/70">
          <Info size={18} aria-hidden className="mt-0.5 shrink-0 text-brand" />
          Notes help our AI understand your project better.
        </p>
        <Button
          type="button"
          variant="white"
          size="sm"
          onClick={() => onChange('')}
          disabled={!value}
          className="self-start sm:self-auto"
        >
          Clear
        </Button>
      </div>
    </Card>
  )
}
