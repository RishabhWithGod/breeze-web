import { create } from 'zustand'
import { MAX_FILES } from '@/constants'
import type { RejectedUploadFile, UploadFile, UploadFileStatus } from '@/types'
import { toUploadFile } from '@/utils'

interface UploadState {
  files: UploadFile[]
  rejected: RejectedUploadFile[]
  /** The client this run will be attached to; null until one is chosen. */
  projectId: number | null
  /** Set while the mock upload timer is running — disables the dropzone. */
  isSubmitting: boolean
  /** Form-level error shown above the dropzone. */
  formError: string | null
}

interface UploadActions {
  addFiles: (files: File[]) => void
  removeFile: (id: string) => void
  /** Swaps a single file in place, keeping its slot in the list. */
  replaceFile: (id: string, file: File) => void
  setFileStatus: (id: string, status: UploadFileStatus, progress?: number) => void
  setFileProgress: (id: string, progress: number) => void
  setRejected: (rejected: RejectedUploadFile[]) => void
  clearRejected: () => void
  setProjectId: (projectId: number | null) => void
  setSubmitting: (isSubmitting: boolean) => void
  setFormError: (message: string | null) => void
  reset: () => void
}

const initialState: UploadState = {
  files: [],
  rejected: [],
  projectId: null,
  isSubmitting: false,
  formError: null,
}

/**
 * Local-only upload state. Nothing here talks to a server; the store exists so
 * the dropzone, the file list and the processing page can share one source of
 * truth across routes.
 */
export const useUploadStore = create<UploadState & UploadActions>()((set) => ({
  ...initialState,

  addFiles: (incoming) =>
    set((state) => {
      const existingKeys = new Set(state.files.map((f) => `${f.name}:${f.size}`))
      const deduped = incoming.filter((f) => !existingKeys.has(`${f.name}:${f.size}`))
      const remainingSlots = Math.max(0, MAX_FILES - state.files.length)
      const accepted = deduped.slice(0, remainingSlots).map(toUploadFile)
      const overflow = deduped.length - accepted.length

      return {
        files: [...state.files, ...accepted],
        formError:
          overflow > 0
            ? `Only ${MAX_FILES} files can be queued at once — ${overflow} file(s) were skipped.`
            : null,
      }
    }),

  removeFile: (id) =>
    set((state) => ({
      files: state.files.filter((file) => file.id !== id),
      formError: null,
    })),

  replaceFile: (id, file) =>
    set((state) => ({
      files: state.files.map((existing) =>
        existing.id === id ? { ...toUploadFile(file), id } : existing,
      ),
      formError: null,
    })),

  setFileStatus: (id, status, progress) =>
    set((state) => ({
      files: state.files.map((file) =>
        file.id === id
          ? { ...file, status, progress: progress ?? file.progress }
          : file,
      ),
    })),

  setFileProgress: (id, progress) =>
    set((state) => ({
      files: state.files.map((file) =>
        file.id === id ? { ...file, progress } : file,
      ),
    })),

  setRejected: (rejected) => set({ rejected }),
  clearRejected: () => set({ rejected: [] }),
  setProjectId: (projectId) => set({ projectId }),
  setSubmitting: (isSubmitting) => set({ isSubmitting }),
  setFormError: (formError) => set({ formError }),
  reset: () => set({ ...initialState }),
}))

/** Derived selectors — keep components free of filtering logic. */
export const selectValidFiles = (state: UploadState): UploadFile[] =>
  state.files.filter((file) => file.status !== 'error')

export const selectHasValidFiles = (state: UploadState): boolean =>
  state.files.some((file) => file.status !== 'error')

export const selectTotalSize = (state: UploadState): number =>
  state.files.reduce((total, file) => total + file.size, 0)
