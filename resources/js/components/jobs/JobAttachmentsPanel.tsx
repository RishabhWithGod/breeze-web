import { useRef, useState } from 'react'
import { router, useForm } from '@inertiajs/react'
import { AnimatePresence, motion } from 'framer-motion'
import { Download, Paperclip, Trash2, Upload } from 'lucide-react'
import { Button, IconButton } from '@/components/common'
import { routeTo } from '@/constants'
import type { JobAttachment } from '@/types'
import { formatFileSize, formatRelative } from '@/utils'

export interface JobAttachmentsPanelProps {
  jobId: number
  attachments: readonly JobAttachment[]
}

/** Real multipart uploads through JobAttachmentController. */
export function JobAttachmentsPanel({ jobId, attachments }: JobAttachmentsPanelProps) {
  const inputRef = useRef<HTMLInputElement>(null)
  const [selectedName, setSelectedName] = useState<string | null>(null)

  const { setData, post, processing, errors, reset, clearErrors } = useForm<{
    file: File | null
  }>({ file: null })

  const choose = (file: File | null) => {
    setData('file', file)
    setSelectedName(file?.name ?? null)
    if (errors.file) clearErrors('file')
  }

  const submit = (event: React.FormEvent) => {
    event.preventDefault()

    post(routeTo.jobAttachments(jobId), {
      preserveScroll: true,
      forceFormData: true,
      onSuccess: () => {
        reset()
        setSelectedName(null)
        if (inputRef.current) inputRef.current.value = ''
      },
    })
  }

  const remove = (attachmentId: number) => {
    router.delete(routeTo.jobAttachment(jobId, attachmentId), { preserveScroll: true })
  }

  return (
    <div>
      <form onSubmit={submit} className="mb-5">
        <div className="flex flex-wrap items-center gap-3">
          <input
            ref={inputRef}
            id="job-attachment-file"
            type="file"
            onChange={(event) => choose(event.target.files?.[0] ?? null)}
            className="sr-only"
          />
          <Button
            type="button"
            variant="secondary"
            size="sm"
            leftIcon={Paperclip}
            onClick={() => inputRef.current?.click()}
          >
            Choose file
          </Button>

          <span className="min-w-0 flex-1 truncate text-sm text-white/85">
            {selectedName ?? 'No file selected'}
          </span>

          <Button
            type="submit"
            size="sm"
            leftIcon={Upload}
            isLoading={processing}
            disabled={!selectedName}
          >
            Upload
          </Button>
        </div>

        {errors.file && <p className="mt-2 text-sm text-red-300">{errors.file}</p>}
      </form>

      {attachments.length === 0 ? (
        <p className="text-md text-white/75">No attachments yet.</p>
      ) : (
        <ul className="space-y-3">
          <AnimatePresence initial={false}>
            {attachments.map((attachment) => (
              <motion.li
                key={attachment.id}
                initial={{ opacity: 0, y: 8 }}
                animate={{ opacity: 1, y: 0 }}
                exit={{ opacity: 0, height: 0 }}
                className="flex items-center gap-3 rounded-panel border border-hairline bg-white/4 p-4"
              >
                <span className="grid size-9 shrink-0 place-items-center rounded-panel bg-brand/15 text-brand">
                  <Paperclip size={16} aria-hidden />
                </span>

                <div className="min-w-0 flex-1">
                  <p className="truncate text-md font-medium text-white">
                    {attachment.name}
                  </p>
                  <p className="mt-0.5 text-sm text-white/70">
                    {formatFileSize(attachment.size)} · {attachment.uploadedBy} ·{' '}
                    {formatRelative(attachment.createdAt)}
                  </p>
                </div>

                <a
                  href={attachment.downloadUrl}
                  className="grid size-9 shrink-0 place-items-center rounded-panel text-white/85 transition-colors hover:bg-white/10 hover:text-brand"
                  aria-label={`Download ${attachment.name}`}
                >
                  <Download size={16} aria-hidden />
                </a>

                <IconButton
                  icon={Trash2}
                  label={`Delete ${attachment.name}`}
                  size="sm"
                  className="shrink-0 text-white/70 hover:text-status-danger"
                  onClick={() => remove(attachment.id)}
                />
              </motion.li>
            ))}
          </AnimatePresence>
        </ul>
      )}
    </div>
  )
}
