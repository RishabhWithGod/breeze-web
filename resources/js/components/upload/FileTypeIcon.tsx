import { FileBox, FileCode2, FileText, FileType, type LucideIcon } from 'lucide-react'
import type { Tone } from '@/types'
import { IconBubble } from '@/components/common'
import type { IconBubbleProps } from '@/components/common'

interface FileTypeVisual {
  readonly icon: LucideIcon
  readonly tone: Tone
}

const BY_EXTENSION: Record<string, FileTypeVisual> = {
  PDF: { icon: FileText, tone: 'danger' },
  DWG: { icon: FileCode2, tone: 'brand' },
  DXF: { icon: FileCode2, tone: 'brand' },
  BIM: { icon: FileBox, tone: 'info' },
  IFC: { icon: FileBox, tone: 'info' },
  RVT: { icon: FileBox, tone: 'warning' },
  CAD: { icon: FileCode2, tone: 'brand' },
}

const FALLBACK: FileTypeVisual = { icon: FileType, tone: 'neutral' }

export interface FileTypeIconProps extends Omit<IconBubbleProps, 'icon' | 'tone'> {
  /** File extension or format label, case-insensitive. */
  extension: string
}

/** Maps a file extension onto a consistent icon + colour pairing. */
export function FileTypeIcon({ extension, ...props }: FileTypeIconProps) {
  const visual = BY_EXTENSION[extension.toUpperCase()] ?? FALLBACK
  return <IconBubble icon={visual.icon} tone={visual.tone} {...props} />
}
