import { cn } from '@/lib/cn'
import { Loader2 } from 'lucide-react'
import type { ButtonHTMLAttributes } from 'react'

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: 'primary' | 'secondary' | 'danger' | 'ghost' | 'warning'
  size?: 'sm' | 'md' | 'lg'
  loading?: boolean
}

const variants = {
  primary:   'bg-green text-white shadow-btn hover:bg-[#0d5e3c]',
  secondary: 'bg-white text-green border border-line hover:bg-mint',
  danger:    'bg-red text-white hover:bg-red-700',
  warning:   'bg-yellow text-white hover:bg-[#b97d05]',
  ghost:     'bg-[#eef5f0] text-[#18593e] hover:bg-mint',
}

const sizes = {
  sm: 'px-3 py-1.5 text-xs',
  md: 'px-4 py-2.5 text-sm',
  lg: 'px-5 py-3 text-sm',
}

export function Button({
  variant = 'primary',
  size = 'md',
  loading,
  disabled,
  children,
  className,
  ...props
}: ButtonProps) {
  return (
    <button
      disabled={disabled || loading}
      className={cn(
        'inline-flex items-center gap-2 rounded-btn font-bold cursor-pointer whitespace-nowrap transition-all',
        'disabled:opacity-50 disabled:cursor-not-allowed',
        variants[variant],
        sizes[size],
        className
      )}
      {...props}
    >
      {loading && <Loader2 className="w-4 h-4 animate-spin" />}
      {children}
    </button>
  )
}
