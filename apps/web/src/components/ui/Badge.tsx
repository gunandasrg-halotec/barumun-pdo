import { cn } from '@/lib/cn'
import type { PdoStatus, SupplementaryStatus } from '@/types'

interface BadgeProps {
  variant: 'approved' | 'review' | 'draft' | 'warnb' | 'reject' | 'over' | 'ok' | 'purple' | 'closed' | 'auto'
  children: React.ReactNode
  className?: string
}

export function Badge({ variant, children, className }: BadgeProps) {
  return (
    <span className={cn('badge', `badge-${variant}`, className)}>
      {children}
    </span>
  )
}

// Badge khusus status PDO
export function PdoStatusBadge({
  status,
  onClick,
}: {
  status: PdoStatus
  onClick?: () => void
}) {
  const map: Record<PdoStatus, { variant: BadgeProps['variant']; label: string }> = {
    draft:               { variant: 'draft',    label: 'Draft' },
    submitted:           { variant: 'review',   label: 'In Review' },
    reviewed_asisten:    { variant: 'review',   label: 'In Review' },
    in_review_manager:   { variant: 'review',   label: 'In Review' },
    in_review_direktur:  { variant: 'review',   label: 'In Review' },
    final:               { variant: 'approved', label: 'Final' },
    closed:              { variant: 'closed',   label: 'Closed' },
  }

  const { variant, label } = map[status]

  return (
    <Badge
      variant={variant}
      className={onClick ? 'cursor-pointer hover:opacity-80 transition-opacity' : ''}
      {...(onClick ? { onClick } : {})}
    >
      {label}
    </Badge>
  )
}

export function SupplementaryStatusBadge({ status }: { status: SupplementaryStatus }) {
  const map: Record<SupplementaryStatus, { variant: BadgeProps['variant']; label: string }> = {
    draft:               { variant: 'draft',    label: 'Draft' },
    submitted:           { variant: 'review',   label: 'In Review' },
    reviewed_asisten:    { variant: 'review',   label: 'In Review' },
    in_review_manager:   { variant: 'review',   label: 'In Review' },
    in_review_direktur:  { variant: 'review',   label: 'In Review' },
    final_merged:        { variant: 'approved', label: 'Final Merged' },
    rejected:            { variant: 'reject',   label: 'Ditolak' },
  }

  const { variant, label } = map[status]
  return <Badge variant={variant}>{label}</Badge>
}
