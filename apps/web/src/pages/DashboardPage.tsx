import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useDashboard, useCategorySummary } from '@/hooks/useDashboard'
import { useAuthStore } from '@/store/auth.store'
import { KpiCard } from '@/components/ui/KpiCard'
import { ProgressBar } from '@/components/ui/ProgressBar'
import { Modal } from '@/components/ui/Modal'
import { Button } from '@/components/ui/Button'
import { api } from '@/lib/api'
import { fmt, fmtShort, fmtPeriode, fmtPct } from '@/lib/format'
import { isKerani } from '@/lib/auth'
import type { ApiResponse, PlantationUnit, RoleCode } from '@/types'
import { BarChart2, AlertCircle, ChevronDown } from 'lucide-react'

type ModalType = 'pengajuan' | 'transfer' | 'realisasi' | null

const MONTHS = [
  '', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
  'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
]

const YEARS = Array.from({ length: 5 }, (_, i) => new Date().getFullYear() - i)

export function DashboardPage() {
  const user     = useAuthStore((s) => s.user)
  const navigate = useNavigate()
  const now      = new Date()

  const [month, setMonth]                     = useState(now.getMonth() + 1)
  const [year, setYear]                       = useState(now.getFullYear())
  const [selectedUnitIds, setSelectedUnitIds] = useState<string[]>([])
  const [unitDropOpen, setUnitDropOpen]       = useState(false)
  const [activeModal, setActiveModal]         = useState<ModalType>(null)

  const { data: units } = useQuery({
    queryKey: ['plantation-units'],
    queryFn: async () => {
      const res = await api.get<ApiResponse<PlantationUnit[]>>('/plantation-units')
      return res.data.data
    },
  })

  const unitFilterParams = selectedUnitIds.length > 0
    ? { plantation_unit_ids: selectedUnitIds }
    : {}

  const { data: summary, isLoading } = useDashboard({
    period_month: month,
    period_year: year,
    ...unitFilterParams,
  })
  const { data: categories } = useCategorySummary({
    year,
    month,
    ...unitFilterParams,
  })

  const role = user?.role.code as RoleCode | undefined

  const toggleUnit = (id: string) => {
    setSelectedUnitIds((prev) =>
      prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]
    )
  }

  const unitLabel = selectedUnitIds.length === 0
    ? 'Semua Kebun'
    : selectedUnitIds.length === 1
      ? (units?.find((u) => u.id === selectedUnitIds[0])?.name ?? '1 Kebun')
      : `${selectedUnitIds.length} Kebun Dipilih`

  const isSingleUnit = selectedUnitIds.length === 1

  const handlePengajuanClick = () => {
    if (isSingleUnit) navigate('/pdo')
    else setActiveModal('pengajuan')
  }
  const handleTransferClick = () => {
    if (isSingleUnit) navigate('/transfer')
    else setActiveModal('transfer')
  }
  const handleRealisasiClick = () => {
    if (isSingleUnit) navigate('/realisasi')
    else setActiveModal('realisasi')
  }

  return (
    <div>
      {/* Hero */}
      <div className="flex flex-col desk:flex-row desk:items-start desk:justify-between gap-3 mb-6">
        <div>
          <h2 className="text-[28px] font-[950] text-ink">
            Dashboard PDO {fmtPeriode(month, year)}
          </h2>
          <p className="text-muted text-sm mt-1">
            Ringkasan pengajuan, transfer, realisasi, saldo, dan selisih dana operasional
            {selectedUnitIds.length === 0 ? ' seluruh unit' : ` — ${unitLabel}`}.
          </p>
        </div>
        {role && isKerani(role) && (
          <Button onClick={() => navigate('/pdo/buat')}>
            + Buat PDO Baru
          </Button>
        )}
      </div>

      {/* Filter Bar */}
      <div className="flex items-center gap-3 mb-6 flex-wrap">
        <select
          className="input-base w-auto"
          value={month}
          onChange={(e) => setMonth(Number(e.target.value))}
        >
          {MONTHS.slice(1).map((m, i) => (
            <option key={i + 1} value={i + 1}>{m}</option>
          ))}
        </select>

        <select
          className="input-base w-auto"
          value={year}
          onChange={(e) => setYear(Number(e.target.value))}
        >
          {YEARS.map((y) => <option key={y} value={y}>{y}</option>)}
        </select>

        {/* Multi-select Unit Kebun */}
        {units && units.length > 1 && (
          <div className="relative">
            <button
              type="button"
              onClick={() => setUnitDropOpen((o) => !o)}
              className={`input-base flex items-center gap-2 cursor-pointer min-w-[160px] ${selectedUnitIds.length > 0 ? 'border-green font-bold text-ink' : ''}`}
            >
              <span className="flex-1 text-left">{unitLabel}</span>
              <ChevronDown className={`w-3 h-3 text-muted transition-transform ${unitDropOpen ? 'rotate-180' : ''}`} />
            </button>

            {unitDropOpen && (
              <>
                {/* backdrop */}
                <div className="fixed inset-0 z-10" onClick={() => setUnitDropOpen(false)} />
                <div className="absolute top-full left-0 mt-1 z-20 bg-white border border-line rounded-card shadow-lg min-w-[220px]">
                  <div className="p-2">
                    <label className="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-[#f7faf7] cursor-pointer text-sm">
                      <input
                        type="checkbox"
                        checked={selectedUnitIds.length === 0}
                        onChange={() => setSelectedUnitIds([])}
                        className="checkbox"
                      />
                      <span className="font-bold">Semua Kebun</span>
                    </label>
                    <div className="border-t border-line my-1" />
                    {units.map((unit) => (
                      <label key={unit.id} className="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-[#f7faf7] cursor-pointer text-sm">
                        <input
                          type="checkbox"
                          checked={selectedUnitIds.includes(unit.id)}
                          onChange={() => toggleUnit(unit.id)}
                          className="checkbox"
                        />
                        <span>{unit.code} — {unit.name}</span>
                      </label>
                    ))}
                  </div>
                  {selectedUnitIds.length > 0 && (
                    <div className="px-2 pb-2">
                      <button
                        type="button"
                        onClick={() => { setSelectedUnitIds([]); setUnitDropOpen(false) }}
                        className="text-xs text-muted hover:text-ink underline"
                      >
                        Reset pilihan
                      </button>
                    </div>
                  )}
                </div>
              </>
            )}
          </div>
        )}
      </div>

      {/* KPI Cards */}
      {isLoading ? (
        <div className="grid grid-cols-1 desk:grid-cols-5 gap-4 mb-6">
          {Array.from({ length: 5 }).map((_, i) => (
            <div key={i} className="card animate-pulse h-24 bg-[#f0f4f0]" />
          ))}
        </div>
      ) : (
        <div className="grid grid-cols-1 desk:grid-cols-5 gap-4 mb-6">
          <KpiCard
            label="Total Pengajuan"
            value={fmtShort(summary?.total_amount)}
            hint={isSingleUnit ? 'Klik untuk daftar PDO' : 'Klik untuk detail per kebun'}
            clickable
            onClick={handlePengajuanClick}
          />
          <KpiCard
            label="Total Transfer"
            value={fmtShort(summary?.total_transferred)}
            hint={isSingleUnit ? 'Klik untuk detail transfer' : 'Klik untuk detail per tujuan'}
            clickable
            onClick={handleTransferClick}
          />
          <KpiCard
            label="Total Realisasi"
            value={fmtShort(summary?.total_realized)}
            hint={isSingleUnit ? 'Klik untuk detail realisasi' : 'Klik untuk detail per kebun'}
            clickable
            onClick={handleRealisasiClick}
          />
          <KpiCard
            label="Saldo"
            value={fmtShort(summary?.balance)}
            hint={`${summary?.total_amount ? Math.round(((summary.total_realized ?? 0) / summary.total_amount) * 100) : 0}% terealisasi`}
          />
          <KpiCard
            label="Item Belum Bukti"
            value={String(summary?.items_without_proof ?? 0)}
            hint="Butuh upload bukti"
            clickable
            trend={summary?.items_without_proof ? 'down' : null}
            onClick={() => navigate('/realisasi')}
          />
        </div>
      )}

      {/* Charts */}
      <div className="grid grid-cols-1 desk:grid-cols-[1.15fr_0.85fr] gap-4">
        {/* Bar Chart */}
        <div className="card">
          <div className="flex items-center justify-between mb-4">
            <h3 className="text-[17px] font-[850]">Pengajuan vs Realisasi per Kategori</h3>
            <BarChart2 className="w-4 h-4 text-muted" />
          </div>

          {!categories || categories.length === 0 ? (
            <div className="empty-state">Belum ada data untuk periode ini.</div>
          ) : (
            <div className="flex flex-col gap-3">
              {categories.map((cat) => (
                <div key={cat.category_id} className="flex items-center gap-3">
                  <div className="text-sm text-ink truncate" style={{ width: 210 }}>
                    {cat.category_name}
                  </div>
                  <div className="flex-1">
                    <ProgressBar value={Math.round((cat.total_realized / cat.total_amount) * 100)} />
                  </div>
                  <div className="text-sm font-bold text-ink text-right" style={{ width: 90 }}>
                    {fmtShort(cat.total_amount)}
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>

        {/* Donut / Proporsi */}
        <div className="card">
          <div className="flex items-center justify-between mb-4">
            <h3 className="text-[17px] font-[850]">Proporsi Biaya</h3>
            {summary && (
              <span className="badge badge-approved">
                {fmtPct(summary.total_realized, summary.total_transferred)} terealisasi
              </span>
            )}
          </div>

          {categories && categories.length > 0 ? (
            <>
              <div className="flex justify-center mb-4">
                <div
                  className="relative rounded-full flex items-center justify-center"
                  style={{
                    width: 160,
                    height: 160,
                    background: buildDonut(categories.map((c) => c.percentage)),
                  }}
                >
                  <div className="absolute inset-[42px] rounded-full bg-white flex flex-col items-center justify-center">
                    <div className="text-[13px] font-[950] text-green">
                      {fmtShort(summary?.total_transferred)}
                    </div>
                  </div>
                </div>
              </div>

              <div className="flex flex-col gap-1.5">
                {categories.slice(0, 5).map((cat, i) => (
                  <div key={cat.category_id} className="flex items-center gap-2 text-xs">
                    <div
                      className="w-2.5 h-2.5 rounded-full shrink-0"
                      style={{ background: DONUT_COLORS[i % DONUT_COLORS.length] }}
                    />
                    <span className="text-muted truncate">{cat.category_name}</span>
                    <span className="ml-auto font-bold">{cat.percentage}%</span>
                  </div>
                ))}
              </div>
            </>
          ) : (
            <div className="flex items-center gap-2 text-muted text-sm">
              <AlertCircle className="w-4 h-4" />
              Belum ada data kategori.
            </div>
          )}
        </div>
      </div>

      {/* ── Modal Pengajuan per Kebun ── */}
      <Modal
        open={activeModal === 'pengajuan'}
        onClose={() => setActiveModal(null)}
        title={`Total Pengajuan per Kebun — ${MONTHS[month]} ${year}`}
        width="w-[560px]"
      >
        <table className="w-full border-collapse text-sm">
          <thead>
            <tr>
              {['Unit Kebun', 'Total Pengajuan'].map((h) => (
                <th key={h} className="px-3 py-2 text-left text-[11px] font-bold uppercase tracking-wider text-muted bg-[#f7faf7]">{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {!summary?.by_unit?.length ? (
              <tr><td colSpan={2} className="px-3 py-6 text-center text-muted">Tidak ada data</td></tr>
            ) : (summary.by_unit as any[]).map((u) => (
              <tr key={u.unit_id} className="border-t border-line hover:bg-[#fbfdfb]">
                <td className="px-3 py-2 font-bold">{u.unit_code} — {u.unit_name}</td>
                <td className="px-3 py-2 text-right">{fmt(u.total_amount)}</td>
              </tr>
            ))}
          </tbody>
        </table>
        <div className="flex justify-end mt-4">
          <Button variant="secondary" onClick={() => setActiveModal(null)}>Tutup</Button>
        </div>
      </Modal>

      {/* ── Modal Transfer per Tujuan ── */}
      <Modal
        open={activeModal === 'transfer'}
        onClose={() => setActiveModal(null)}
        title={`Detail Transfer — ${MONTHS[month]} ${year}`}
        width="w-[560px]"
      >
        <div className="flex flex-col gap-3 mb-4">
          {([['rek_kebun', 'Rekening Kebun'], ['pribadi', 'Rekening Pribadi'], ['vendor', 'Vendor']] as const).map(([key, label]) => (
            <div key={key} className="flex items-center justify-between border border-line rounded-card px-4 py-3 bg-[#f7faf7]">
              <span className="text-sm font-[700]">{label}</span>
              <span className="text-sm font-[850]">{fmt((summary?.transferred_by_destination as any)?.[key] ?? 0)}</span>
            </div>
          ))}
          <div className="flex items-center justify-between border-t border-line pt-3 mt-1">
            <span className="text-sm font-[850] text-ink">Total Transfer</span>
            <span className="text-sm font-[950] text-green">{fmt(summary?.total_transferred ?? 0)}</span>
          </div>
        </div>
        <div className="flex justify-end">
          <Button variant="secondary" onClick={() => setActiveModal(null)}>Tutup</Button>
        </div>
      </Modal>

      {/* ── Modal Realisasi per Kebun ── */}
      <Modal
        open={activeModal === 'realisasi'}
        onClose={() => setActiveModal(null)}
        title={`Total Realisasi per Kebun — ${MONTHS[month]} ${year}`}
        width="w-[560px]"
      >
        <table className="w-full border-collapse text-sm">
          <thead>
            <tr>
              {['Unit Kebun', 'Total Realisasi'].map((h) => (
                <th key={h} className="px-3 py-2 text-left text-[11px] font-bold uppercase tracking-wider text-muted bg-[#f7faf7]">{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {!summary?.by_unit?.length ? (
              <tr><td colSpan={2} className="px-3 py-6 text-center text-muted">Tidak ada data</td></tr>
            ) : (summary.by_unit as any[]).map((u) => (
              <tr key={u.unit_id} className="border-t border-line hover:bg-[#fbfdfb]">
                <td className="px-3 py-2 font-bold">{u.unit_code} — {u.unit_name}</td>
                <td className="px-3 py-2 text-right">{fmt(u.total_realized)}</td>
              </tr>
            ))}
          </tbody>
        </table>
        <div className="flex justify-end mt-4">
          <Button variant="secondary" onClick={() => setActiveModal(null)}>Tutup</Button>
        </div>
      </Modal>
    </div>
  )
}

const DONUT_COLORS = ['#0f6b45', '#16a36d', '#f0a61f', '#2563eb', '#94a3b8']

function buildDonut(percentages: number[]): string {
  let cumul = 0
  const stops = percentages.map((p, i) => {
    const from = cumul
    cumul += p
    return `${DONUT_COLORS[i % DONUT_COLORS.length]} ${from}% ${cumul}%`
  })
  return `conic-gradient(${stops.join(', ')})`
}
