import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { fmt } from '@/lib/format'
import { Button } from '@/components/ui/Button'
import { EmptyState } from '@/components/ui/EmptyState'
import { useReportExport } from '@/hooks/useReportExport'
import type { RecapData, RecapCategory, ReportFilters } from '@/types/report'
import type { ApiResponse, PlantationUnit } from '@/types'
import { ChevronDown, ChevronRight, Download, FileText } from 'lucide-react'

const MONTHS = [
  'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
  'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
]
const currentYear  = new Date().getFullYear()
const currentMonth = new Date().getMonth() + 1

export function RekapitulasiPage() {
  const [year,   setYear]   = useState(currentYear)
  const [month,  setMonth]  = useState(currentMonth)
  const [unitId, setUnitId] = useState('')

  const { startExport, isExporting } = useReportExport()

  const { data: units } = useQuery({
    queryKey: ['plantation-units'],
    queryFn: async () => {
      const res = await api.get<ApiResponse<PlantationUnit[]>>('/plantation-units')
      return res.data.data
    },
  })

  const filters: ReportFilters = {
    period_year:  year,
    period_month: month,
    ...(unitId ? { unit_id: unitId } : {}),
  }

  const { data: recap, isFetching } = useQuery<RecapData>({
    queryKey: ['recap', year, month, unitId],
    queryFn: async () => {
      const res = await api.get<ApiResponse<RecapData>>('/reports/recap', {
        params: { period_year: year, period_month: month, unit_id: unitId || undefined },
      })
      return res.data.data
    },
  })

  return (
    <div>
      <div className="flex items-start justify-between mb-5">
        <div>
          <h2 className="text-[28px] font-[950] text-ink">Rekapitulasi</h2>
          <p className="text-muted text-sm mt-1">Ringkasan anggaran, transfer, dan realisasi per kategori.</p>
        </div>
      </div>

      {/* Filter Bar */}
      <div className="card mb-4 flex flex-wrap gap-3 items-end">
        <div>
          <label className="label">Tahun</label>
          <select className="input-base" value={year} onChange={(e) => setYear(+e.target.value)}>
            {[currentYear - 1, currentYear, currentYear + 1].map((y) => (
              <option key={y} value={y}>{y}</option>
            ))}
          </select>
        </div>
        <div>
          <label className="label">Bulan</label>
          <select className="input-base" value={month} onChange={(e) => setMonth(+e.target.value)}>
            {MONTHS.map((m, i) => (
              <option key={i + 1} value={i + 1}>{m}</option>
            ))}
          </select>
        </div>
        <div>
          <label className="label">Unit</label>
          <select className="input-base" value={unitId} onChange={(e) => setUnitId(e.target.value)}>
            <option value="">Semua Unit</option>
            {units?.map((u) => (
              <option key={u.id} value={u.id}>{u.code} — {u.name}</option>
            ))}
          </select>
        </div>
      </div>

      {/* Export Buttons */}
      <div className="flex gap-2 mb-4">
        <Button
          variant="secondary"
          size="sm"
          loading={isExporting}
          onClick={() => startExport({ report_type: 'recap', format: 'xlsx', filters })}
        >
          <Download className="w-4 h-4" /> Export Excel
        </Button>
        <Button
          variant="secondary"
          size="sm"
          loading={isExporting}
          onClick={() => startExport({ report_type: 'recap', format: 'pdf', filters })}
        >
          <FileText className="w-4 h-4" /> Export PDF
        </Button>
      </div>

      {isFetching ? (
        <div className="card animate-pulse h-64 bg-[#f0f4f0]" />
      ) : !recap || recap.categories.length === 0 ? (
        <EmptyState message="Tidak ada data rekapitulasi untuk periode ini." />
      ) : (
        <div className="card overflow-auto">
          <RecapTable recap={recap} />
        </div>
      )}
    </div>
  )
}

function RecapTable({ recap }: { recap: RecapData }) {
  const [expanded, setExpanded] = useState<Record<string, boolean>>({})

  const toggle = (key: string) => setExpanded((e) => ({ ...e, [key]: !e[key] }))

  const colClass = 'px-3 py-2 text-right text-sm'
  const thClass  = 'px-3 py-2.5 text-right text-[11px] font-bold uppercase tracking-wider text-muted bg-[#f7faf7]'

  return (
    <table className="w-full border-collapse" style={{ minWidth: 750 }}>
      <thead>
        <tr>
          <th className="px-3 py-2.5 text-left text-[11px] font-bold uppercase tracking-wider text-muted bg-[#f7faf7] w-8">No</th>
          <th className="px-3 py-2.5 text-left text-[11px] font-bold uppercase tracking-wider text-muted bg-[#f7faf7] w-28">Kode Akun</th>
          <th className="px-3 py-2.5 text-left text-[11px] font-bold uppercase tracking-wider text-muted bg-[#f7faf7]">Uraian</th>
          <th className={thClass}>Anggaran</th>
          <th className={thClass}>Transfer</th>
          <th className={thClass}>Realisasi</th>
          <th className={thClass}>Saldo</th>
        </tr>
      </thead>
      <tbody>
        {recap.categories.map((cat) => {
          const catKey = cat.category_code
          const catOpen = expanded[catKey] ?? true
          return [
            /* Category row */
            <tr key={catKey} className="bg-[#eaf4ea] cursor-pointer" onClick={() => toggle(catKey)}>
              <td className="px-3 py-2 text-sm font-bold">{cat.no}</td>
              <td className="px-3 py-2 text-sm font-bold">{cat.category_code}</td>
              <td className="px-3 py-2 text-sm font-bold flex items-center gap-1">
                {catOpen ? <ChevronDown className="w-3.5 h-3.5" /> : <ChevronRight className="w-3.5 h-3.5" />}
                {cat.category_name.toUpperCase()}
              </td>
              <td className={`${colClass} font-bold`}>{fmt(cat.subtotal_amount)}</td>
              <td className={`${colClass} font-bold`}>{fmt(cat.subtotal_transfer)}</td>
              <td className={`${colClass} font-bold`}>{fmt(cat.subtotal_realization)}</td>
              <td className={`${colClass} font-bold`}>{fmt(cat.subtotal_saldo)}</td>
            </tr>,

            /* Subcategories */
            ...(catOpen ? cat.subcategories.flatMap((sub) => {
              const subKey = `${catKey}__${sub.subcategory_code}`
              const subOpen = expanded[subKey] ?? false
              return [
                <tr key={subKey} className="bg-[#f4fbf4] cursor-pointer" onClick={() => toggle(subKey)}>
                  <td className="px-3 py-2 text-sm" />
                  <td className="px-3 py-2 text-sm text-muted">{sub.subcategory_code}</td>
                  <td className="px-3 py-2 text-sm font-semibold flex items-center gap-1 pl-6">
                    {subOpen ? <ChevronDown className="w-3 h-3" /> : <ChevronRight className="w-3 h-3" />}
                    {sub.subcategory_name}
                  </td>
                  <td className={colClass}>{fmt(sub.subtotal_amount)}</td>
                  <td className={colClass}>{fmt(sub.subtotal_transfer)}</td>
                  <td className={colClass}>{fmt(sub.subtotal_realization)}</td>
                  <td className={colClass}>{fmt(sub.subtotal_saldo)}</td>
                </tr>,

                /* Items */
                ...(subOpen ? sub.items.map((item) => (
                  <tr key={item.item_code} className="border-t border-line hover:bg-[#fbfdfb]">
                    <td className="px-3 py-2 text-sm" />
                    <td className="px-3 py-2 text-xs text-muted">{item.account_number}</td>
                    <td className="px-3 py-2 text-sm pl-12">{item.item_name}</td>
                    <td className={colClass}>{fmt(item.amount)}</td>
                    <td className={colClass}>{fmt(item.total_transfer)}</td>
                    <td className={colClass}>{fmt(item.total_realization)}</td>
                    <td className={colClass}>{fmt(item.saldo)}</td>
                  </tr>
                )) : []),
              ]
            }) : []),
          ]
        })}

        {/* Grand Total */}
        <tr className="border-t-2 border-line bg-[#d9ead3]">
          <td colSpan={3} className="px-3 py-2.5 text-sm font-[950]">JUMLAH TOTAL</td>
          <td className={`${colClass} font-[950]`}>{fmt(recap.grand_total_amount)}</td>
          <td className={`${colClass} font-[950]`}>{fmt(recap.grand_total_transfer)}</td>
          <td className={`${colClass} font-[950]`}>{fmt(recap.grand_total_realization)}</td>
          <td className={`${colClass} font-[950]`}>{fmt(recap.grand_total_saldo)}</td>
        </tr>
      </tbody>
    </table>
  )
}
