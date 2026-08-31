import { Button, Modal } from '@/components/common'

export interface RecoveryCodesModalProps {
  isOpen: boolean
  onClose: () => void
  codes: readonly string[]
}

/**
 * Shown exactly once, right after generation — only the hash of each code is
 * ever stored, so this is the only place the plaintext values exist.
 */
export function RecoveryCodesModal({ isOpen, onClose, codes }: RecoveryCodesModalProps) {
  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title="Your Recovery Codes"
      description="Save these somewhere safe. Each code works once, and we cannot show them to you again."
      footer={<Button onClick={onClose}>I've saved these codes</Button>}
    >
      <div className="grid grid-cols-2 gap-2 rounded-panel border border-hairline bg-white/4 p-4 font-mono text-sm text-white">
        {codes.map((code) => (
          <span key={code}>{code}</span>
        ))}
      </div>
    </Modal>
  )
}
