import { useToastStore } from '@/store/toast.store'
import { cn } from '@/lib/cn'
import { X } from 'lucide-react'

// Warna dipaksa lewat inline style (bukan cuma class Tailwind) karena aturan
// `body { color: var(--ink) }` di index.css (di dalam @layer base) menang atas
// utility class seperti text-red-700 pada elemen ini — lihat index.css.
const TOAST_STYLE: Record<string, { bg: string; border: string; color: string }> = {
  error:   { bg: '#fef2f2', border: '#dc2626', color: '#b91c1c' },
  success: { bg: '#123c2d', border: '#123c2d', color: '#ffffff' },
  info:    { bg: '#123c2d', border: '#123c2d', color: '#ffffff' },
}

export function ToastContainer() {
  const toasts = useToastStore((s) => s.toasts)
  const remove = useToastStore((s) => s.remove)

  return (
    // top-20 (bukan top-5) supaya tidak tertimpa topbar (sticky, z-20, ~64px).
    <div className="fixed top-20 right-5 z-50 flex flex-col gap-2 max-w-md">
      {toasts.map((t) => {
        const style = TOAST_STYLE[t.type] ?? TOAST_STYLE.info
        return (
          <div
            key={t.id}
            className={cn(
              'flex items-start gap-2 px-4 py-3 rounded-[14px] text-sm font-medium shadow-card border-2',
              'animate-in slide-in-from-top-2 fade-in duration-200'
            )}
            style={{ backgroundColor: style.bg, borderColor: style.border, color: style.color }}
          >
            <span className="flex-1" style={{ color: style.color }}>{t.message}</span>
            <button
              onClick={() => remove(t.id)}
              className="shrink-0 opacity-70 hover:opacity-100 transition-opacity"
              style={{ color: style.color }}
              aria-label="Tutup"
            >
              <X size={16} />
            </button>
          </div>
        )
      })}
    </div>
  )
}
