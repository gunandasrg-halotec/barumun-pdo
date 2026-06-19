import { cn } from '@/lib/cn'
import type { ReactNode } from 'react'

interface KpiCardProps {
  label: string
  value: string
  hint?: string
  clickable?: boolean
  onClick?: () => void
  trend?: 'up' | 'down' | null
  children?: ReactNode
}

export function KpiCard({ label, value, hint, clickable, onClick, trend, children }: KpiCardProps) {
  return (
    <div
      className={cn(
        'card',
        clickable && 'cursor-pointer hover:-translate-y-0.5 transition-transform'
      )}
      onClick={clickable ? onClick : undefined}
    >
      <div className="text-[12px] font-[800] uppercase tracking-wider text-muted">{label}</div>
      <div
        className={cn(
          'text-[24px] font-[950] my-2',
          trend === 'up'   && 'text-green2',
          trend === 'down' && 'text-red',
          !trend           && 'text-ink'
        )}
      >
        {value}
      </div>
      {hint && <div className="text-[12px] text-muted">{hint}</div>}
      {children}
    </div>
  )
}
