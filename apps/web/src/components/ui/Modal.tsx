import { useEffect, type ReactNode } from 'react'
import { X } from 'lucide-react'
import { cn } from '@/lib/cn'

interface ModalProps {
  open: boolean
  onClose: () => void
  title: string
  children: ReactNode
  className?: string
  width?: string
}

export function Modal({ open, onClose, title, children, className, width = 'w-[520px]' }: ModalProps) {
  // Close on Escape
  useEffect(() => {
    const handler = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose() }
    if (open) document.addEventListener('keydown', handler)
    return () => document.removeEventListener('keydown', handler)
  }, [open, onClose])

  if (!open) return null

  return (
    <div
      className="fixed inset-0 z-30 grid place-items-center bg-[rgba(15,23,42,.35)]"
      onClick={onClose}
    >
      <div
        className={cn('bg-white rounded-modal shadow-card p-6 max-h-[90vh] overflow-y-auto max-w-[92vw]', width, className)}
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-center justify-between mb-4">
          <h3 className="text-[17px] font-[850] text-ink">{title}</h3>
          <button onClick={onClose} className="text-muted hover:text-ink transition-colors">
            <X className="w-5 h-5" />
          </button>
        </div>
        {children}
      </div>
    </div>
  )
}
