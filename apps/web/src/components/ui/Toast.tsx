import { useToastStore } from '@/store/toast.store'
import { cn } from '@/lib/cn'

export function ToastContainer() {
  const toasts = useToastStore((s) => s.toasts)

  return (
    <div className="fixed bottom-5 right-5 z-50 flex flex-col gap-2">
      {toasts.map((t) => (
        <div
          key={t.id}
          className={cn(
            'px-4 py-3 rounded-[14px] text-white text-sm font-medium shadow-card',
            'animate-in slide-in-from-bottom-2 fade-in duration-200',
            t.type === 'error' ? 'bg-red-700' : 'bg-[#123c2d]'
          )}
        >
          {t.message}
        </div>
      ))}
    </div>
  )
}
