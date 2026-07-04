import { useState, useRef, useEffect } from 'react'
import { Building2, ChevronDown, X } from 'lucide-react'
import type { PlantationUnit } from '@/types'

interface Props {
  units: PlantationUnit[]
  selected: string[]          // array of unit IDs
  onChange: (ids: string[]) => void
}

export function UnitMultiSelectButton({ units, selected, onChange }: Props) {
  const [open, setOpen] = useState(false)
  const ref = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!open) return
    const handler = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false)
    }
    document.addEventListener('mousedown', handler)
    return () => document.removeEventListener('mousedown', handler)
  }, [open])

  const toggle = (id: string) => {
    onChange(selected.includes(id) ? selected.filter((x) => x !== id) : [...selected, id])
  }

  const selectAll  = () => onChange(units.map((u) => u.id))
  const clearAll   = () => onChange([])

  const hasFilter  = selected.length > 0
  const label = hasFilter
    ? selected.length === units.length
      ? 'Semua Kebun'
      : selected.length === 1
        ? (units.find((u) => u.id === selected[0])?.code ?? '1 Kebun')
        : `${selected.length} Kebun`
    : 'Filter Kebun'

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
        <Building2 className="w-4 h-4 shrink-0" />
        <span>{label}</span>
        {hasFilter
          ? <X className="w-3.5 h-3.5 ml-0.5 shrink-0" onClick={(e) => { e.stopPropagation(); clearAll() }} />
          : <ChevronDown className="w-3.5 h-3.5 ml-0.5 shrink-0" />
        }
      </button>

      {open && (
        <div className="absolute left-0 top-full mt-1.5 z-50 bg-white border border-line rounded-drawer shadow-lg w-56">
          <div className="flex justify-between items-center px-3 py-2 border-b border-line">
            <span className="text-[11px] font-bold uppercase tracking-wider text-muted">Unit Kebun</span>
            <div className="flex gap-2 text-xs">
              <button type="button" className="text-green hover:underline" onClick={selectAll}>Semua</button>
              <button type="button" className="text-muted hover:underline" onClick={clearAll}>Reset</button>
            </div>
          </div>
          <div className="py-1 max-h-60 overflow-y-auto">
            {units.map((u) => (
              <label
                key={u.id}
                className="flex items-center gap-2.5 px-3 py-2 hover:bg-[#f7faf7] cursor-pointer"
              >
                <input
                  type="checkbox"
                  className="accent-green"
                  checked={selected.includes(u.id)}
                  onChange={() => toggle(u.id)}
                />
                <span className="text-sm">
                  <span className="font-bold text-ink">{u.code}</span>
                  <span className="text-muted ml-1">— {u.name}</span>
                </span>
              </label>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}
