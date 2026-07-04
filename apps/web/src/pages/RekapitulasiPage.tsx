import { useState, useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { Button } from '@/components/ui/Button'
import { EmptyState } from '@/components/ui/EmptyState'
import { useReportExport } from '@/hooks/useReportExport'
import { useRecapData } from '@/hooks/useRecapData'
import { RecapTable } from '@/components/recap/RecapTable'
import { useAuthStore } from '@/store/auth.store'
import type { ApiResponse, PlantationUnit } from '@/types'
import { Download, FileText, Search } from 'lucide-react'
import type { RecapResponse } from '@/types/recap'

const MONTHS = [
  'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
  'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
]

const currentYear  = new Date().getFullYear()
const currentMonth = new Date().getMonth() + 1

// Roles that can see all units (cross-unit access)
const CROSS_UNIT_ROLES = ['ADMIN', 'MANAJER_KEBUN', 'MANAJER_KEUANGAN', 'STAFF_KEUANGAN', 'DIREKTUR_KEUANGAN', 'STAFF_PURCHASING']

export function RekapitulasiPage() {
  const user    = useAuthStore((s) => s.user)
  const role    = user?.role?.code ?? ''
  const isCrossUnit = CROSS_UNIT_ROLES.includes(role)

  const [year,               setYear]               = useState(currentYear)
  const [month,              setMonth]              = useState(currentMonth)
  const [unitId,             setUnitId]             = useState(user?.plantation_unit?.id ?? '')
  const [categoryId]                                = useState('')
  const [realizationFilter,  setRealizationFilter]  = useState<'all' | 'has' | 'no'>('all')
  const [search,             setSearch]             = useState('')

  const { startExport, isExporting } = useReportExport()

  const { data: units } = useQuery({
    queryKey: ['plantation-units'],
    queryFn: async () => {
      const res = await api.get<ApiResponse<PlantationUnit[]>>('/plantation-units')
      return res.data.data
    },
    enabled: isCrossUnit,
  })

  const resolvedUnitId = isCrossUnit ? unitId : (user?.plantation_unit?.id ?? '')

  const { data: recap, isFetching, isError } = useRecapData(
    { period_year: year, period_month: month, unit_id: resolvedUnitId || undefined, category_id: categoryId || undefined },
    !!resolvedUnitId,
  )

  const filteredRecap = useMemo((): RecapResponse | null => {
    if (!recap) return null
    const q = search.trim().toLowerCase()

    const categories = recap.categories
      .map((cat) => {
        const subcategories = cat.subcategories
          .map((sub) => {
            const items = sub.items.filter((item) => {
              const matchSearch = !q || item.item_name.toLowerCase().includes(q) || item.item_code.toLowerCase().includes(q)
              const matchFilter =
                realizationFilter === 'all' ||
                (realizationFilter === 'has' && item.total_realization > 0) ||
                (realizationFilter === 'no'  && item.total_realization === 0)
              return matchSearch && matchFilter
            })
            return { ...sub, items }
          })
          .filter((sub) => sub.items.length > 0)
        return { ...cat, subcategories }
      })
      .filter((cat) => cat.subcategories.length > 0)

    return { ...recap, categories }
  }, [recap, search, realizationFilter])

  const exportFilters = {
    period_year:  year,
    period_month: month,
    ...(resolvedUnitId ? { unit_id: resolvedUnitId } : {}),
    ...(categoryId     ? { category_id: categoryId } : {}),
  }

  return (
    <div>
      {/* Header */}
      <div className="flex items-start justify-between mb-5">
        <div>
          <h2 className="text-[28px] font-[950] text-ink">Rekapitulasi Digital</h2>
          <p className="text-muted text-sm mt-1">
            {recap ? `${recap.period_label}${recap.unit ? ` · ${recap.unit.name}` : ''}` : 'Pilih periode dan unit untuk menampilkan data.'}
          </p>
        </div>
        <div className="flex gap-2">
          <Button
            variant="secondary"
            size="sm"
            loading={isExporting}
            onClick={() => startExport({ report_type: 'recap', format: 'xlsx', filters: exportFilters as any })}
          >
            <Download className="w-4 h-4" /> Excel
          </Button>
          <Button
            variant="secondary"
            size="sm"
            loading={isExporting}
            onClick={() => startExport({ report_type: 'recap', format: 'pdf', filters: exportFilters as any })}
          >
            <FileText className="w-4 h-4" /> PDF
          </Button>
        </div>
      </div>

      {/* Filter Bar */}
      <div className="card mb-5 flex flex-wrap gap-3 items-end">
        <div>
          <label className="label">Tahun</label>
          <select className="input-base" value={year} onChange={(e) => setYear(+e.target.value)}>
            {Array.from({ length: 6 }, (_, i) => currentYear - 2 + i).map((y) => (
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

        {/* Unit dropdown — only for cross-unit roles */}
        {isCrossUnit && (
          <div>
            <label className="label">Unit Kebun</label>
            <select className="input-base" value={unitId} onChange={(e) => setUnitId(e.target.value)}>
              <option value="">— Pilih Unit —</option>
              {units?.map((u) => (
                <option key={u.id} value={u.id}>{u.code} — {u.name}</option>
              ))}
            </select>
          </div>
        )}

        <div>
          <label className="label">Realisasi</label>
          <select className="input-base" value={realizationFilter} onChange={(e) => setRealizationFilter(e.target.value as 'all' | 'has' | 'no')}>
            <option value="all">Semua</option>
            <option value="has">Sudah ada realisasi</option>
            <option value="no">Belum ada realisasi</option>
          </select>
        </div>

        <div className="flex-1 min-w-[200px]">
          <label className="label">Cari Item Biaya</label>
          <div className="relative">
            <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 w-4 h-4 text-muted pointer-events-none" />
            <input
              type="text"
              className="input-base pl-8"
              placeholder="Nama atau kode item..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          </div>
        </div>
      </div>

      {/* Content */}
      {isFetching ? (
        <div className="card space-y-3">
          {Array.from({ length: 5 }).map((_, i) => (
            <div key={i} className="h-8 bg-[#f0f4f0] rounded animate-pulse" />
          ))}
        </div>
      ) : isError ? (
        <div className="card text-sm text-red-600">Gagal memuat data rekapitulasi. Coba lagi.</div>
      ) : !recap || recap.categories.length === 0 ? (
        <EmptyState message="Tidak ada data rekapitulasi untuk periode dan unit ini." />
      ) : (
        <div className="card p-0 overflow-hidden">
          {/* Summary KPI — selalu pakai grand total keseluruhan (bukan filtered) */}
          <div className="grid grid-cols-4 border-b border-line">
            {[
              { label: 'Total Pengajuan',   value: recap.grand_total_amount },
              { label: 'Total Transfer',    value: recap.grand_total_transfer },
              { label: 'Total Realisasi',   value: recap.grand_total_realization },
              { label: 'Saldo',             value: recap.grand_total_saldo },
            ].map((k) => (
              <div key={k.label} className="p-4 text-center border-r border-line last:border-r-0">
                <div className="text-[10px] font-[850] text-muted uppercase tracking-wider mb-1">{k.label}</div>
                <div className={`text-[17px] font-[950] ${k.value < 0 ? 'text-red-600' : 'text-ink'}`}>
                  {new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(k.value)}
                </div>
              </div>
            ))}
          </div>

          {/* Hierarchical table — filtered by search/realisasi filter */}
          {filteredRecap && filteredRecap.categories.length > 0
            ? <RecapTable data={filteredRecap} />
            : <div className="p-8 text-center text-sm text-muted">Tidak ada item yang cocok dengan filter.</div>
          }
        </div>
      )}
    </div>
  )
}
