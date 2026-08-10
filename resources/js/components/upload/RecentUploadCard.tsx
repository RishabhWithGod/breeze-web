import { Card, StatusChip } from '@/components/common'
import type { RecentUpload, Tone } from '@/types'
import { formatFileSize, formatRelative } from '@/utils'
import { FileTypeIcon } from './FileTypeIcon'

const STATUS_TONE: Record<RecentUpload['status'], Tone> = {
  completed: 'success',
  processing: 'brand',
  failed: 'danger',
}

const STATUS_LABEL: Record<RecentUpload['status'], string> = {
  completed: 'Completed',
  processing: 'Processing',
  failed: 'Failed',
}

export interface RecentUploadCardProps {
  upload: RecentUpload
  index?: number
}

/** Tile in the "Recent Activity" grid on the upload page. */
export function RecentUploadCard({ upload, index }: RecentUploadCardProps) {
  return (
    <Card
      hoverable
      padding="lg"
      className="h-full text-center"
      {...(index !== undefined ? { index } : {})}
    >
      <FileTypeIcon extension={upload.format} size="xl" className="mx-auto mb-5" />

      <p
        className="truncate text-md font-bold text-white"
        title={upload.name}
      >
        {upload.name}
      </p>
      <p className="mt-1 text-sm text-white/75">
        Uploaded {formatRelative(upload.uploadedAt)} · {formatFileSize(upload.sizeBytes)}
      </p>

      <StatusChip
        tone={STATUS_TONE[upload.status]}
        label={STATUS_LABEL[upload.status]}
        pulse={upload.status === 'processing'}
        className="mt-4 justify-center"
      />
    </Card>
  )
}
