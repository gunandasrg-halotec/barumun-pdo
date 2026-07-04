import { useState } from 'react'
import { ChevronDown, ChevronRight } from 'lucide-react'
import type { RecapResponse } from '@/types/recap'

const idr = (n: number) =>
  new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(n)

function SaldoCell({ value, className = '' }: { value: number; className?: string }) {
  return (
    <td className={`px-3 py-2 text-right text-sm ${value < 0 ? 'text-red-600' : ''} ${className}`}>
      {idr(value)}
    </td>
  )
}

interface Props {
  data: RecapResponse
  onRealizationClick?: (pdoDetailId: string, itemName: string) => void
}

const COL_HEADERS = ['No', 'Kode', 'Uraian', 'Pengajuan', 'Total Transfer', 'Total Realisasi', 'Saldo']

export function RecapTable({ data, onRealizationClick }: Props) {
  const allCodes = data.categories.map((c) => c.category_code)
  const [expanded, setExpanded] = useState<Set<string>>(new Set(allCodes))

  const toggle = (code: string) =>
    setExpanded((prev) => {
      const next = new Set(prev)
      next.has(code) ? next.delete(code) : next.add(code)
      return next
    })

  return (
    <div className="overflow-auto">
      <table className="w-full border-collapse text-sm" style={{ minWidth: 860 }}>
        {/* Sticky header */}
        <thead className="sticky top-0 z-10">
          <tr>
            {COL_HEADERS.map((h) => (
              <th
                key={h}
                className="px-3 py-2.5 text-left text-[11px] font-bold uppercase tracking-wider bg-[#f3f4f6] border border-[#d1d5db]"
              >
                {h}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {data.categories.map((cat) => {
            const isOpen = expanded.has(cat.category_code)
            return [
              /* ── Category header row ─────────────────────────────────── */
              <tr
                key={`cat-${cat.category_code}`}
                className="cursor-pointer select-none bg-[#e5e7eb] hover:bg-[#d1d5db] transition-colors"
                onClick={() => toggle(cat.category_code)}
              >
                <td className="px-3 py-2 text-sm font-bold border border-[#d1d5db]">
                  {cat.no}
                </td>
                <td className="px-3 py-2 text-sm font-bold border border-[#d1d5db]">
                  {cat.category_code}
                </td>
                <td className="px-3 py-2 border border-[#d1d5db]">
                  <span className="flex items-center gap-1 font-bold">
                    {isOpen
                      ? <ChevronDown className="w-3.5 h-3.5 shrink-0" />
                      : <ChevronRight className="w-3.5 h-3.5 shrink-0" />}
                    {cat.category_name}
                  </span>
                </td>
                <td className="px-3 py-2 text-right font-bold border border-[#d1d5db]">{idr(cat.subtotal_amount)}</td>
                <td className="px-3 py-2 text-right font-bold border border-[#d1d5db]">{idr(cat.subtotal_transfer)}</td>
                <td className="px-3 py-2 text-right font-bold border border-[#d1d5db]">{idr(cat.subtotal_realization)}</td>
                <SaldoCell value={cat.subtotal_saldo} className="border border-[#d1d5db] font-bold" />
              </tr>,

              /* ── Sub-categories & items (when expanded) ──────────────── */
              ...(!isOpen ? [] : cat.subcategories.flatMap((sub) => [
                /* Sub-category row */
                <tr
                  key={`sub-${cat.category_code}-${sub.subcategory_code}`}
                  className="bg-[#f9fafb]"
                >
                  <td className="border border-[#e5e7eb]" />
                  <td className="px-3 py-2 text-[11px] font-semibold text-muted border border-[#e5e7eb]">
                    {sub.subcategory_code}
                  </td>
                  <td className="px-3 py-1.5 border border-[#e5e7eb]">
                    <span className="pl-4 font-semibold">{sub.subcategory_name}</span>
                  </td>
                  <td className="px-3 py-1.5 text-right text-[12px] font-semibold border border-[#e5e7eb]">{idr(sub.subtotal_amount)}</td>
                  <td className="px-3 py-1.5 text-right text-[12px] font-semibold border border-[#e5e7eb]">{idr(sub.subtotal_transfer)}</td>
                  <td className="px-3 py-1.5 text-right text-[12px] font-semibold border border-[#e5e7eb]">{idr(sub.subtotal_realization)}</td>
                  <SaldoCell value={sub.subtotal_saldo} className="border border-[#e5e7eb] text-[12px] font-semibold" />
                </tr>,

                /* Item rows */
                ...sub.items.map((item) => (
                  <tr key={`item-${item.no}`} className="hover:bg-[#f0f9f0] transition-colors">
                    <td className="px-3 py-1.5 text-center text-[11px] text-muted border border-[#e5e7eb]">
                      {item.no}
                    </td>
                    <td className="px-3 py-1.5 text-[11px] text-muted border border-[#e5e7eb]">
                      {item.account_number}
                    </td>
                    <td className="px-3 py-1.5 border border-[#e5e7eb]">
                      <span className="pl-8">{item.item_name}</span>
                    </td>
                    <td className="px-3 py-1.5 text-right border border-[#e5e7eb]">{idr(item.amount)}</td>
                    <td className="px-3 py-1.5 text-right border border-[#e5e7eb]">{idr(item.total_transfer)}</td>
                    <td className="px-3 py-1.5 text-right border border-[#e5e7eb]">
                      {item.total_realization > 0 && onRealizationClick ? (
                        <button
                          className="text-green font-semibold hover:underline w-full text-right"
                          onClick={() => onRealizationClick(item.pdo_detail_id, item.item_name)}
                        >
                          {idr(item.total_realization)}
                        </button>
                      ) : (
                        idr(item.total_realization)
                      )}
                    </td>
                    <SaldoCell value={item.saldo} className="border border-[#e5e7eb]" />
                  </tr>
                )),
              ])),
            ]
          })}

          {/* Grand total row */}
          <tr className="bg-[#d1d5db]">
            <td colSpan={3} className="px-3 py-2.5 font-[950] text-sm border border-[#9ca3af] uppercase tracking-wide">
              Grand Total
            </td>
            <td className="px-3 py-2.5 text-right font-[950] border border-[#9ca3af]">{idr(data.grand_total_amount)}</td>
            <td className="px-3 py-2.5 text-right font-[950] border border-[#9ca3af]">{idr(data.grand_total_transfer)}</td>
            <td className="px-3 py-2.5 text-right font-[950] border border-[#9ca3af]">{idr(data.grand_total_realization)}</td>
            <td className={`px-3 py-2.5 text-right font-[950] border border-[#9ca3af] ${data.grand_total_saldo < 0 ? 'text-red-700' : ''}`}>
              {idr(data.grand_total_saldo)}
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  )
}
