import { useEffect, type ReactNode } from 'react'
import { X } from 'lucide-react'
import { cn } from '@/lib/cn'

interface DrawerProps {
  open: boolean
  onClose: () => void
  title: string
  children: ReactNode
  className?: string
}

export function Drawer({ open, onClose, title, children, className }: DrawerProps) {
  useEffect(() => {
    const handler = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose() }
    if (open) document.addEventListener('keydown', handler)
    return () => document.removeEventListener('keydown', handler)
  }, [open, onClose])

  return (
    <>
      {/* Overlay */}
      {open && (
        <div
          className="fixed inset-0 bg-[rgba(15,23,42,.25)] z-[9]"
          onClick={onClose}
        />
      )}

      {/* Drawer */}
      <div
        className={cn(
          'fixed top-0 right-0 h-full w-[460px] max-w-[95vw] bg-white z-10 overflow-auto p-6',
          'transition-transform duration-250 shadow-[-20px_0_50px_rgba(0,0,0,.16)]',
          open ? 'translate-x-0' : 'translate-x-full',
          className
        )}
      >
        <div className="flex items-center justify-between mb-5">
          <h3 className="text-[17px] font-[850] text-ink">{title}</h3>
          <button onClick={onClose} className="text-muted hover:text-ink transition-colors">
            <X className="w-5 h-5" />
          </button>
        </div>
        {children}
      </div>
    </>
  )
}
