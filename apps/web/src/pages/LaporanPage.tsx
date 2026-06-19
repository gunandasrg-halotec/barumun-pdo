import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { fmt, fmtDate } from '@/lib/format'
import { Button } from '@/components/ui/Button'
import { EmptyState } from '@/components/ui/EmptyState'
import { useReportExport } from '@/hooks/useReportExport'
import type { RealizationRow, MissingProofRow, ReportFilters, ReportType } from '@/types/report'
import type { ApiResponse, PlantationUnit } from '@/types'
import { Download, FileText } from 'lucide-react'

const MONTHS = [
  'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
  'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
]

const REPORT_TABS: { key: ReportType; label: string }[] = [
  { key: 'realization',   label: 'Realisasi Dana' },
  { key: 'over_budget',   label: 'Over Budget' },
  { key: 'missing_proof', label: 'Bukti Belum Lengkap' },
]

const STATUS_LABEL: Record<string, string> = {
  sesuai:           'Sesuai',
  over_budget:      'Over Budget',
  belum_realisasi:  'Belum Realisasi',
  belum_bukti:      'Belum Bukti',
  partial:          'Parsial',
}

const STATUS_CLASS: Record<string, string> = {
  sesuai:           'bg-[#d4edda] text-[#155724]',
  over_budget:      'bg-[#f8d7da] text-[#721c24]',
  belum_realisasi:  'bg-[#e2e3e5] text-[#383d41]',
  belum_bukti:      'bg-[#fff3cd] text-[#856404]',
  partial:          'bg-[#cce5ff] text-[#004085]',
}

const currentYear  = new Date().getFullYear()
const currentMonth = new Date().getMonth() + 1

export function LaporanPage() {
  const [tab,    setTab]    = useState<ReportType>('realization')
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

  const apiPath =
    tab === 'realization'   ? '/reports/realization'   :
    tab === 'over_budget'   ? '/reports/over-budget'   :
                              '/reports/missing-proof'

  const { data, isFetching } = useQuery<RealizationRow[] | MissingProofRow[]>({
    queryKey: ['report', tab, year, month, unitId],
    queryFn: async () => {
      const res = await api.get<ApiResponse<RealizationRow[] | MissingProofRow[]>>(apiPath, {
        params: { period_year: year, period_month: month, unit_id: unitId || undefined },
      })
      return res.data.data
    },
  })

  const rows = data ?? []

  return (
    <div>
      <div className="flex items-start justify-between mb-5">
        <div>
          <h2 className="text-[28px] font-[950] text-ink">Laporan</h2>
          <p className="text-muted text-sm mt-1">Realisasi, over budget, dan bukti transaksi.</p>
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

      {/* Tabs */}
      <div className="flex gap-1 mb-4 border-b border-line">
        {REPORT_TABS.map((t) => (
          <button
            key={t.key}
            onClick={() => setTab(t.key)}
            className={`px-4 py-2 text-sm font-bold border-b-2 transition-colors ${
              tab === t.key
                ? 'border-primary text-primary'
                : 'border-transparent text-muted hover:text-ink'
            }`}
          >
            {t.label}
          </button>
        ))}
      </div>

      {/* Export Buttons */}
      <div className="flex gap-2 mb-4">
        <Button
          variant="secondary"
          size="sm"
          loading={isExporting}
          onClick={() => startExport({ report_type: tab, format: 'xlsx', filters })}
        >
          <Download className="w-4 h-4" /> Export Excel
        </Button>
        <Button
          variant="secondary"
          size="sm"
          loading={isExporting}
          onClick={() => startExport({ report_type: tab, format: 'pdf', filters })}
        >
          <FileText className="w-4 h-4" /> Export PDF
        </Button>
      </div>

      {/* Table */}
      <div className="card overflow-auto">
        {isFetching ? (
          <div className="text-muted text-sm py-6 text-center">Memuat data…</div>
        ) : rows.length === 0 ? (
          <EmptyState message="Tidak ada data untuk periode ini." />
        ) : tab === 'missing_proof' ? (
          <MissingProofTable rows={rows as MissingProofRow[]} />
        ) : (
          <RealizationTable rows={rows as RealizationRow[]} />
        )}
      </div>
    </div>
  )
}

function RealizationTable({ rows }: { rows: RealizationRow[] }) {
  return (
    <table className="w-full border-collapse" style={{ minWidth: 900 }}>
      <thead>
        <tr>
          {['No. PDO', 'Unit', 'Kategori', 'Item Biaya', 'Anggaran', 'Transfer', 'Realisasi', 'Saldo', '%', 'Status'].map((h) => (
            <th key={h} className="px-3 py-2.5 text-left text-[11px] font-bold uppercase tracking-wider text-muted bg-[#f7faf7]">
              {h}
            </th>
          ))}
        </tr>
      </thead>
      <tbody>
        {rows.map((r) => (
          <tr key={r.detail_id} className="border-t border-line hover:bg-[#fbfdfb]">
            <td className="px-3 py-2 text-sm font-bold">{r.pdo_number}</td>
            <td className="px-3 py-2 text-sm">{r.unit_name}</td>
            <td className="px-3 py-2 text-sm">{r.category_name}</td>
            <td className="px-3 py-2 text-sm">{r.item_name}</td>
            <td className="px-3 py-2 text-sm text-right">{fmt(r.amount)}</td>
            <td className="px-3 py-2 text-sm text-right">{fmt(r.total_transfer)}</td>
            <td className="px-3 py-2 text-sm text-right">{fmt(r.total_realization)}</td>
            <td className="px-3 py-2 text-sm text-right font-bold">{fmt(r.saldo)}</td>
            <td className="px-3 py-2 text-sm text-center">{r.realization_pct}%</td>
            <td className="px-3 py-2 text-sm">
              <span className={`text-[10px] font-bold px-2 py-0.5 rounded-full ${STATUS_CLASS[r.status] ?? ''}`}>
                {STATUS_LABEL[r.status] ?? r.status}
              </span>
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  )
}

function MissingProofTable({ rows }: { rows: MissingProofRow[] }) {
  return (
    <table className="w-full border-collapse" style={{ minWidth: 700 }}>
      <thead>
        <tr>
          {['#', 'No. PDO', 'Unit', 'Item Biaya', 'Keterangan', 'Tgl Transaksi', 'Nominal', 'Dicatat Oleh'].map((h) => (
            <th key={h} className="px-3 py-2.5 text-left text-[11px] font-bold uppercase tracking-wider text-muted bg-[#fffdf0]">
              {h}
            </th>
          ))}
        </tr>
      </thead>
      <tbody>
        {rows.map((r, i) => (
          <tr key={i} className="border-t border-line hover:bg-[#fffdf0]">
            <td className="px-3 py-2 text-sm">{i + 1}</td>
            <td className="px-3 py-2 text-sm font-bold">{r.pdo_number}</td>
            <td className="px-3 py-2 text-sm">{r.unit_name}</td>
            <td className="px-3 py-2 text-sm">{r.item_name}</td>
            <td className="px-3 py-2 text-sm">{r.keterangan}</td>
            <td className="px-3 py-2 text-sm">{fmtDate(r.transaction_date)}</td>
            <td className="px-3 py-2 text-sm text-right">{fmt(r.amount)}</td>
            <td className="px-3 py-2 text-sm">{r.recorded_by}</td>
          </tr>
        ))}
      </tbody>
    </table>
  )
}
