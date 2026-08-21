import { useCallback, useState } from 'react'
import { router } from '@inertiajs/react'
import { ROUTES } from '@/constants'
import { useUploadStore } from '@/store'

/**
 * Posts the queued drawing set to Laravel.
 *
 * Inertia reports one aggregate progress figure for the request, which is
 * applied to every file in the queue — the whole set travels in a single
 * multipart POST. On success the server redirects to the processing screen for
 * the run it opened, so there is nothing to navigate to here.
 */
export function useFileUpload(): {
  isUploading: boolean
  startUpload: () => void
} {
  const [isUploading, setIsUploading] = useState(false)

  const startUpload = useCallback(() => {
    const {
      files,
      notes,
      projectId,
      setFileStatus,
      setFileProgress,
      setSubmitting,
      setFormError,
    } = useUploadStore.getState()

    const targets = files.filter((file) => file.status !== 'error')

    if (targets.length === 0) {
      setFormError('Add at least one supported drawing file before running a takeoff.')
      return
    }

    if (projectId === null) {
      setFormError('Select a project before running a takeoff.')
      return
    }

    setIsUploading(true)
    setSubmitting(true)
    targets.forEach((file) => setFileStatus(file.id, 'uploading', 0))

    router.post(
      ROUTES.upload,
      {
        project_id: projectId,
        files: targets.map((file) => file.source),
        notes,
      },
      {
        forceFormData: true,
        onProgress: (event) => {
          if (!event?.percentage) return
          targets.forEach((file) => setFileProgress(file.id, event.percentage ?? 0))
        },
        onSuccess: () => {
          targets.forEach((file) => setFileStatus(file.id, 'success', 100))
        },
        onError: (errors) => {
          // Laravel keys per-file errors as `files.0`, `files.1`, …
          targets.forEach((file, index) => {
            const message = errors[`files.${index}`] ?? errors['files']
            setFileStatus(file.id, message ? 'error' : 'ready', 0)
          })
          setFormError(errors['project_id'] ?? errors['files'] ?? errors['notes'] ?? null)
        },
        onFinish: () => {
          setIsUploading(false)
          setSubmitting(false)
        },
      },
    )
  }, [])

  return { isUploading, startUpload }
}
