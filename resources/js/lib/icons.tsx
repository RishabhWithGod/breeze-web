import {
  Bot,
  Briefcase,
  CalendarCheck,
  FileText,
  ReceiptText,
  TriangleAlert,
  type LucideProps,
} from 'lucide-react'

/**
 * Icon keys the server may name.
 *
 * Feed rows and dashboard tiles are database records, so they carry an icon
 * *key* rather than a component.
 */
export type FeedIconKey =
  | 'bot'
  | 'briefcase'
  | 'calendar-check'
  | 'file-text'
  | 'receipt-text'
  | 'triangle-alert'

export interface FeedIconProps extends LucideProps {
  name: FeedIconKey | string
}

/**
 * Resolves a server-supplied icon key to its glyph.
 *
 * Written as a switch rather than a lookup table so each branch renders a
 * statically known component — a dynamic component assigned during render would
 * remount on every pass. Unknown keys fall back to `FileText` instead of
 * breaking the panel.
 */
export function FeedIcon({ name, ...props }: FeedIconProps) {
  switch (name) {
    case 'bot':
      return <Bot {...props} />
    case 'briefcase':
      return <Briefcase {...props} />
    case 'calendar-check':
      return <CalendarCheck {...props} />
    case 'receipt-text':
      return <ReceiptText {...props} />
    case 'triangle-alert':
      return <TriangleAlert {...props} />
    case 'file-text':
    default:
      return <FileText {...props} />
  }
}
