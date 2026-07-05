import { useState, useRef, useEffect } from 'react'
import { Calendar, X } from 'lucide-react'

interface Props {
  startDate: string
  endDate: string
  min: string
  max: string
  onChange: (start: string, end: string) => void
  label?: string
}

function formatDisplayDate(dateStr: string): string {
  if (!dateStr) return ''
  const [, m, d] = dateStr.split('-')
  const months = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Ags','Sep','Okt','Nov','Des']
  return `${parseInt(d)} ${months[parseInt(m) - 1]}`
}

export function DateRangePickerButton({ startDate, endDate, min, max, onChange, label = 'Filter Tanggal Realisasi' }: Props) {
  const [open, setOpen]           = useState(false)
  const [draftStart, setDraftStart] = useState(startDate)
  const [draftEnd,   setDraftEnd]   = useState(endDate)
  const ref = useRef<HTMLDivElement>(null)

  // Sync draft when external value resets (e.g. period changes)
  useEffect(() => {
    setDraftStart(startDate)
    setDraftEnd(endDate)
  }, [startDate, endDate])

  // Close on outside click
  useEffect(() => {
    if (!open) return
    const handler = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) {
        setOpen(false)
        setDraftStart(startDate)
        setDraftEnd(endDate)
      }
    }
    document.addEventListener('mousedown', handler)
    return () => document.removeEventListener('mousedown', handler)
  }, [open, startDate, endDate])

  const startError = draftStart && min && max && (draftStart < min || draftStart > max)
    ? `Di luar periode (${min} — ${max})`
    : null
  const endError = draftEnd && min && max && (draftEnd < min || draftEnd > max)
    ? `Di luar periode (${min} — ${max})`
    : draftEnd && draftStart && draftEnd < draftStart
    ? 'Tanggal akhir tidak boleh sebelum tanggal mulai'
    : null

  const hasError  = !!(startError || endError)
  const hasFilter = !!(startDate || endDate)
  const canApply  = !hasError && !!(draftStart || draftEnd)

  const handleApply = () => {
    if (hasError) return
    onChange(draftStart, draftEnd)
    setOpen(false)
  }

  const handleReset = (e: React.MouseEvent) => {
    e.stopPropagation()
    setDraftStart('')
    setDraftEnd('')
    onChange('', '')
    setOpen(false)
  }

  return (
    <div ref={ref} className="relative">
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        className={`flex items-center gap-1.5 px-3 h-9 rounded-drawer border text-sm font-medium transition-colors
          ${hasFilter
            ? 'border-green bg-[#f0f7f0] text-green'
            : 'border-line bg-white text-muted hover:border-green hover:text-ink'
          }`}
      >
        <Calendar className="w-4 h-4 shrink-0" />
        {hasFilter
          ? <span>{formatDisplayDate(startDate) || '…'} — {formatDisplayDate(endDate) || '…'}</span>
          : <span>{label}</span>
        }
        {hasFilter && (
          <X className="w-3.5 h-3.5 ml-0.5 shrink-0" onClick={handleReset} />
        )}
      </button>

      {open && (
        <div className="absolute left-0 top-full mt-1.5 z-50 bg-white border border-line rounded-drawer shadow-lg p-4 w-72">
          <p className="text-[11px] font-bold uppercase tracking-wider text-muted mb-3">Rentang Tanggal Realisasi</p>

          <div className="flex flex-col gap-3">
            <div>
              <label className="label">Dari</label>
              <input
                type="date"
                className={`input-base ${startError ? 'border-red-400' : ''}`}
                value={draftStart}
                min={min || undefined}
                max={draftEnd || max || undefined}
                onChange={(e) => setDraftStart(e.target.value)}
              />
              {startError && <p className="text-[11px] text-red-500 mt-0.5">{startError}</p>}
            </div>

            <div>
              <label className="label">Sampai</label>
              <input
                type="date"
                className={`input-base ${endError ? 'border-red-400' : ''}`}
                value={draftEnd}
                min={draftStart || min || undefined}
                max={max || undefined}
                onChange={(e) => setDraftEnd(e.target.value)}
              />
              {endError && <p className="text-[11px] text-red-500 mt-0.5">{endError}</p>}
            </div>
          </div>

          <div className="flex justify-end gap-2 mt-4">
            <button
              type="button"
              className="text-sm text-muted hover:text-ink px-2"
              onClick={() => { setDraftStart(''); setDraftEnd('') }}
            >
              Reset
            </button>
            <button
              type="button"
              disabled={!canApply}
              onClick={handleApply}
              className="px-3 py-1.5 text-sm font-bold rounded-drawer bg-green text-white disabled:opacity-40 disabled:cursor-not-allowed hover:bg-green/90 transition-colors"
            >
              Terapkan
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
