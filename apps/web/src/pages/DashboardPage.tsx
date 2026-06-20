import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useDashboard, useCategorySummary } from '@/hooks/useDashboard'
import { useAuthStore } from '@/store/auth.store'
import { KpiCard } from '@/components/ui/KpiCard'
import { ProgressBar } from '@/components/ui/ProgressBar'
import { Button } from '@/components/ui/Button'
import { fmtShort, fmtPeriode, fmtPct } from '@/lib/format'
import { isKerani } from '@/lib/auth'
import type { RoleCode } from '@/types'
import { BarChart2, AlertCircle } from 'lucide-react'

const MONTHS = [
  '', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
  'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
]

const YEARS = Array.from({ length: 5 }, (_, i) => new Date().getFullYear() - i)

export function DashboardPage() {
  const user     = useAuthStore((s) => s.user)
  const navigate = useNavigate()
  const now      = new Date()

  const [filters, setFilters] = useState({
    period_month: now.getMonth() + 1,
    period_year:  now.getFullYear(),
    plantation_unit_id: user?.plantation_unit?.id ?? undefined as string | undefined,
  })

  const { data: summary, isLoading } = useDashboard(filters)
  const { data: categories }         = useCategorySummary(filters)

  const role = user?.role.code as RoleCode | undefined

  return (
    <div>
      {/* Hero */}
      <div className="flex flex-col desk:flex-row desk:items-start desk:justify-between gap-3 mb-6">
        <div>
          <h2 className="text-[28px] font-[950] text-ink">
            Dashboard PDO {fmtPeriode(filters.period_month, filters.period_year)}
          </h2>
          <p className="text-muted text-sm mt-1">
            Ringkasan pengajuan, transfer, realisasi, saldo, dan selisih dana operasional
            {user?.plantation_unit ? ` ${user.plantation_unit.name}` : ' seluruh unit'}.
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
          value={filters.period_month}
          onChange={(e) => setFilters((f) => ({ ...f, period_month: Number(e.target.value) }))}
        >
          {MONTHS.slice(1).map((m, i) => (
            <option key={i + 1} value={i + 1}>{m}</option>
          ))}
        </select>

        <select
          className="input-base w-auto"
          value={filters.period_year}
          onChange={(e) => setFilters((f) => ({ ...f, period_year: Number(e.target.value) }))}
        >
          {YEARS.map((y) => <option key={y} value={y}>{y}</option>)}
        </select>
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
            hint="Klik untuk daftar PDO"
            clickable
            onClick={() => navigate('/pdo')}
          />
          <KpiCard
            label="Total Transfer"
            value={fmtShort(summary?.total_transferred)}
            hint={summary?.total_transferred === summary?.total_amount ? 'Sesuai PDO' : 'Ada selisih'}
          />
          <KpiCard
            label="Total Realisasi"
            value={fmtShort(summary?.total_realized)}
            hint="Klik untuk input item"
            clickable
            onClick={() => navigate('/realisasi')}
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
              {/* Simple donut using CSS conic-gradient */}
              <div className="flex justify-center mb-4">
                <div
                  className="relative rounded-full flex items-center justify-center"
                  style={{
                    width: 160,
                    height: 160,
                    background: buildDonut(categories.map((c) => c.percentage)),
                  }}
                >
                  <div
                    className="absolute inset-[42px] rounded-full bg-white flex flex-col items-center justify-center"
                  >
                    <div className="text-[13px] font-[950] text-green">
                      {fmtShort(summary?.total_transferred)}
                    </div>
                  </div>
                </div>
              </div>

              {/* Legend */}
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
