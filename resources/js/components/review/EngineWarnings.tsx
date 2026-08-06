import { useState } from 'react'
import { TriangleAlert } from 'lucide-react'
import { Alert } from '@/components/common'

export interface EngineWarningsProps {
  warnings: readonly string[]
  className?: string
}

/**
 * Warnings the AI engine raised about the drawing — missing catalog prices,
 * unreadable regions, and so on.
 *
 * Dismissible, because they are advisory: the takeoff is still usable. Dismissal is
 * per visit, so reloading brings them back rather than hiding a real problem
 * permanently.
 */
export function EngineWarnings({ warnings, className }: EngineWarningsProps) {
  const [dismissed, setDismissed] = useState(false)

  if (warnings.length === 0 || dismissed) return null

  return (
    <Alert
      tone="warning"
      icon={TriangleAlert}
      title={`${warnings.length} warning${warnings.length === 1 ? '' : 's'} from the AI engine`}
      onDismiss={() => setDismissed(true)}
      className={className}
    >
      <ul className="mt-1 flex flex-col gap-1">
        {warnings.map((warning) => (
          <li key={warning} className="text-sm">
            {warning}
          </li>
        ))}
      </ul>
    </Alert>
  )
}
