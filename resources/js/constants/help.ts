import { BookOpen, Headset, PlayCircle, type LucideIcon } from 'lucide-react'

export interface HelpResource {
  readonly id: string
  readonly title: string
  readonly description: string
  readonly icon: LucideIcon
  readonly action: string
}

/** Static support copy — presentation only, so it stays out of the database. */
export const HELP_RESOURCES: readonly HelpResource[] = [
  {
    id: 'docs',
    title: 'Documentation',
    description: 'View our comprehensive guides on using AI Takeoff.',
    icon: BookOpen,
    action: 'Read the guides',
  },
  {
    id: 'videos',
    title: 'Tutorial Videos',
    description: 'Watch step-by-step tutorials on file preparation.',
    icon: PlayCircle,
    action: 'Watch tutorials',
  },
  {
    id: 'support',
    title: 'Support Team',
    description: 'Contact our experts for personalised assistance.',
    icon: Headset,
    action: 'Start a chat',
  },
]
