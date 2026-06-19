import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { Button } from '@/components/ui/Button'
import { EmptyState } from '@/components/ui/EmptyState'
import { fmt, fmtPeriode } from '@/lib/format'
import { FileDown } from 'lucide-react'
import type { ApiResponse, PlantationUnit } from '@/types'

interface LaporanRow {
  category_name: string
  category_code: string
  total_amount:  number
  total_transferred: number
  total_realized:    number
  balance:           number
  realization_pct:   number
}

interface LaporanData {
  pdo_number:         string
  period_month:       number
  period_year:        number
  plantation_unit:    PlantationUnit
  total_amount:       number
  total_transferred:  number
  total_realized:     number
  balance:            number
  rows:               LaporanRow[]
}

const MONTHS = ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember']
const YEARS  = Array.from({ length: 5 }, (_, i) => new Date().getFullYear() - i)

export function LaporanPage() {
  const now = new Date()
  const [filters, setFilters] = useState({
    period_month:       now.getMonth() + 1,
    period_year:        now.getFullYear(),
    plantation_unit_id: '',
  })

  const { data: units } = useQuery({
    queryKey: ['plantation-units'],
    queryFn: async () => {
      const res = await api.get<ApiResponse<PlantationUnit[]>>('/plantation-units')
      return res.data.data
    },
  })

  const { data: laporan, isLoading } = useQuery({
    queryKey: ['laporan', filters],
    queryFn: async () => {
      const params: Record<string, string | number> = {
        period_month: filters.period_month,
        period_year:  filters.period_year,
      }
      if (filters.plantation_unit_id) params.plantation_unit_id = filters.plantation_unit_id
      const res = await api.get<ApiResponse<LaporanData[]>>('/reports/pdo-summary', { params })
      return res.data.data
    },
  })

  const handleExport = async () => {
    const params = new URLSearchParams({
      period_month: String(filters.period_month),
      period_year:  String(filters.period_year),
      ...(filters.plantation_unit_id ? { plantation_unit_id: filters.plantation_unit_id } : {}),
    })
    window.open(`/api/reports/export-excel?${params.toString()}`, '_blank')
  }

  return (
    <div>
      <div className="flex items-start justify-between mb-6">
        <div>
          <h2 className="text-[28px] font-[950] text-ink">Laporan PDO</h2>
          <p className="text-muted text-sm mt-1">
            Rekap pengajuan, transfer, dan realisasi per kategori biaya.
          </p>
        </div>
        <Button variant="secondary" onClick={handleExport}>
          <FileDown className="w-4 h-4" /> Export Excel
        </Button>
      </div>

      {/* Filter Bar */}
      <div className="flex items-center gap-3 mb-5 flex-wrap">
        <select
          className="input-base w-auto"
          value={filters.period_month}
          onChange={(e) => setFilters((f) => ({ ...f, period_month: Number(e.target.value) }))}
        >
          {MONTHS.slice(1).map((m, i) => <option key={i + 1} value={i + 1}>{m}</option>)}
        </select>
        <select
          className="input-base w-auto"
          value={filters.period_year}
          onChange={(e) => setFilters((f) => ({ ...f, period_year: Number(e.target.value) }))}
        >
          {YEARS.map((y) => <option key={y} value={y}>{y}</option>)}
        </select>
        <select
          className="input-base w-auto"
          value={filters.plantation_unit_id}
          onChange={(e) => setFilters((f) => ({ ...f, plantation_unit_id: e.target.value }))}
        >
          <option value="">Semua Unit</option>
          {units?.map((u) => <option key={u.id} value={u.id}>{u.code} — {u.name}</option>)}
        </select>
      </div>

      {isLoading ? (
        <div className="flex flex-col gap-4">
          {Array.from({ length: 2 }).map((_, i) => (
            <div key={i} className="card animate-pulse h-64 bg-[#f0f4f0]" />
          ))}
        </div>
      ) : !laporan?.length ? (
        <EmptyState message="Belum ada data laporan untuk periode ini." />
      ) : (
        <div className="flex flex-col gap-6">
          {laporan.map((report) => (
            <div key={report.pdo_number} className="card">
              {/* Report Header */}
              <div className="flex items-start justify-between mb-4">
                <div>
                  <div className="text-[17px] font-[850]">{report.pdo_number}</div>
                  <div className="text-sm text-muted">
                    {fmtPeriode(report.period_month, report.period_year)} · {report.plantation_unit.name}
                  </div>
                </div>
                <div className="grid grid-cols-4 gap-4 text-right">
                  {[
                    { label: 'Pengajuan',  val: report.total_amount },
                    { label: 'Transfer',   val: report.total_transferred },
                    { label: 'Realisasi',  val: report.total_realized },
                    { label: 'Saldo',      val: report.balance },
                  ].map((k) => (
                    <div key={k.label}>
                      <div className="text-[10px] font-[850] text-muted uppercase tracking-wider">{k.label}</div>
                      <div className="text-[15px] font-[950] text-ink">{fmt(k.val)}</div>
                    </div>
                  ))}
                </div>
              </div>

              {/* Per-category breakdown */}
              <table className="w-full border-collapse border-t border-line">
                <thead>
                  <tr>
                    {['Kategori', 'Pengajuan', 'Transfer', 'Realisasi', 'Saldo', '% Real.'].map((h) => (
                      <th key={h} className="px-3 py-2 text-left text-[11px] font-bold uppercase tracking-wider text-muted bg-[#f7faf7]">
                        {h}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {report.rows.map((row) => (
                    <tr key={row.category_code} className="border-t border-line hover:bg-[#fbfdfb]">
                      <td className="px-3 py-2 text-sm font-bold">{row.category_name}</td>
                      <td className="px-3 py-2 text-sm">{fmt(row.total_amount)}</td>
                      <td className="px-3 py-2 text-sm">{fmt(row.total_transferred)}</td>
                      <td className="px-3 py-2 text-sm">{fmt(row.total_realized)}</td>
                      <td className="px-3 py-2 text-sm font-bold text-green">{fmt(row.balance)}</td>
                      <td className="px-3 py-2 text-sm">
                        <div className="flex items-center gap-2">
                          <div className="flex-1 h-1.5 rounded-full bg-[#e8f0e8] overflow-hidden" style={{ width: 60 }}>
                            <div
                              className="h-full bg-green rounded-full"
                              style={{ width: `${Math.min(row.realization_pct, 100)}%` }}
                            />
                          </div>
                          <span>{row.realization_pct}%</span>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
