import { cn } from '@/lib/cn'

interface ProgressBarProps {
  value: number // 0–100
  className?: string
  danger?: boolean // merah saat mendekati 100%
}

export function ProgressBar({ value, className, danger }: ProgressBarProps) {
  const clamped = Math.min(Math.max(value, 0), 100)
  const isOver  = clamped >= 95

  return (
    <div className={cn('progress-bar', className)}>
      <span
        style={{
          width: `${clamped}%`,
          background: danger && isOver
            ? 'linear-gradient(90deg, #dc2626, #f87171)'
            : 'linear-gradient(90deg, #0f6b45, #26b87c)',
        }}
      />
    </div>
  )
}
