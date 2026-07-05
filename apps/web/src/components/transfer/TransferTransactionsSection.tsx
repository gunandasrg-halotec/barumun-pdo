import { useState, useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { EmptyState } from '@/components/ui/EmptyState'
import { DateRangePickerButton } from '@/components/ui/DateRangePickerButton'
import { UnitMultiSelectButton } from '@/components/ui/UnitMultiSelectButton'
import { useAuthStore } from '@/store/auth.store'
import { fmt, fmtDate } from '@/lib/format'
import { Search, ChevronDown, ChevronRight } from 'lucide-react'
import type { ApiResponse, PlantationUnit, TransferEntry } from '@/types'

const DEST_LABEL: Record<string, string> = {
  rek_kebun: 'Rek. Kebun', pribadi: 'Pribadi', vendor: 'Vendor',
}
const SOURCE_LABEL: Record<string, string> = {
  manual: 'Manual', system: 'Otomatis',
}

// Columns for entry rows (No. Ref removed)
const COLS = ['PDO', 'Item PDO', 'Tanggal', 'Total Pengajuan', 'Dana yang Ditransfer', 'Saldo', 'Tujuan', 'Sumber', 'Catatan', 'Dicatat Oleh']

interface ItemGroup {
  detailId: string
  itemLabel: string
  totalPengajuan: number
  entries: TransferEntry[]
}

interface PdoGroup {
  pdoId: string
  pdoNumber: string
  items: ItemGroup[]
}

function buildItemLabel(t: TransferEntry): string {
  return t.pdo_detail?.expense_item
    ? [
        t.pdo_detail.expense_item.subcategory?.category?.name,
        t.pdo_detail.expense_item.subcategory?.name,
        t.pdo_detail.expense_item.name,
      ].filter(Boolean).join(' — ')
    : t.pdo_detail_id
}

export function TransferTransactionsSection() {
  const user = useAuthStore((s) => s.user)
  const [search,    setSearch]    = useState('')
  const [startDate, setStartDate] = useState('')
  const [endDate,   setEndDate]   = useState('')
  // collapsed tracks both PDO groups ("pdo-<id>") and item groups ("item-<detailId>")
  const [collapsed, setCollapsed] = useState<Record<string, boolean>>({})
  const [unitIds,   setUnitIds]   = useState<string[]>([])

  const isHO = !user?.plantation_unit?.id

  const { data: units } = useQuery({
    queryKey: ['plantation-units'],
    queryFn: async () => {
      const res = await api.get<ApiResponse<PlantationUnit[]>>('/plantation-units')
      return res.data.data
    },
    enabled: isHO,
  })

  const hasFilter = !!(search.trim() || startDate || endDate || unitIds.length)

  const { data: transfers, isLoading } = useQuery({
    queryKey: ['transfer-transactions', unitIds],
    queryFn: async () => {
      const params = new URLSearchParams()
      unitIds.forEach((id) => params.append('unit_ids[]', id))
      const res = await api.get<ApiResponse<TransferEntry[]>>('/transfer-entries', { params })
      return res.data.data
    },
    enabled: hasFilter,
  })

  const filtered = useMemo(() => {
    if (!transfers) return []
    const q = search.trim().toLowerCase()
    return transfers.filter((t) => {
      const itemLabel = buildItemLabel(t)
      const matchSearch = !q ||
        itemLabel.toLowerCase().includes(q) ||
        (t.recorder?.full_name ?? '').toLowerCase().includes(q) ||
        (t.pdo_detail?.pdo_header?.pdo_number ?? '').toLowerCase().includes(q)
      const matchDate =
        (!startDate || t.transfer_date >= startDate) &&
        (!endDate   || t.transfer_date <= endDate)
      return matchSearch && matchDate
    })
  }, [transfers, search, startDate, endDate])

  // Two-level grouping: PDO → Item Biaya
  const groups = useMemo<PdoGroup[]>(() => {
    const pdoMap = new Map<string, { pdoNumber: string; itemMap: Map<string, ItemGroup> }>()

    for (const t of filtered) {
      const pdoId     = t.pdo_detail?.pdo_header?.id ?? 'unknown'
      const pdoNumber = t.pdo_detail?.pdo_header?.pdo_number ?? '—'
      const detailId  = t.pdo_detail_id
      const itemLabel = buildItemLabel(t)
      const totalPengajuan = t.pdo_detail?.amount ?? 0

      if (!pdoMap.has(pdoId)) pdoMap.set(pdoId, { pdoNumber, itemMap: new Map() })
      const pdo = pdoMap.get(pdoId)!

      if (!pdo.itemMap.has(detailId)) {
        pdo.itemMap.set(detailId, { detailId, itemLabel, totalPengajuan, entries: [] })
      }
      pdo.itemMap.get(detailId)!.entries.push(t)
    }

    return Array.from(pdoMap.entries()).map(([pdoId, { pdoNumber, itemMap }]) => ({
      pdoId,
      pdoNumber,
      items: Array.from(itemMap.values()),
    }))
  }, [filtered])

  const toggle = (key: string) =>
    setCollapsed((prev) => ({ ...prev, [key]: !prev[key] }))

  return (
    <div className="mt-10">
      <div className="mb-4">
        <h3 className="text-[20px] font-[950] text-ink">Transaksi Transfer Dana</h3>
        <p className="text-muted text-sm mt-1">Daftar semua transaksi transfer yang sudah dicatat.</p>
      </div>

      {/* Filter Bar */}
      <div className="card mb-5 flex flex-wrap gap-3 items-end">
        {isHO && units && (
          <div className="flex items-end">
            <UnitMultiSelectButton units={units} selected={unitIds} onChange={setUnitIds} />
          </div>
        )}
        <div className="flex items-end">
          <DateRangePickerButton
            startDate={startDate}
            endDate={endDate}
            min=""
            max=""
            label="Tanggal Transfer"
            onChange={(s, e) => { setStartDate(s); setEndDate(e) }}
          />
        </div>
        <div className="flex-1 min-w-[200px]">
          <label className="label">Cari</label>
          <div className="relative">
            <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 w-4 h-4 text-muted pointer-events-none" />
            <input
              type="text"
              className="input-base pl-8"
              placeholder="No. PDO, item biaya, atau nama pencatat..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          </div>
        </div>
      </div>

      {/* Content */}
      {!hasFilter ? (
        <EmptyState message="Gunakan filter tanggal, kebun, atau kata kunci pencarian untuk menampilkan data." />
      ) : isLoading ? (
        <div className="card space-y-3">
          {Array.from({ length: 4 }).map((_, i) => (
            <div key={i} className="h-8 bg-[#f0f4f0] rounded animate-pulse" />
          ))}
        </div>
      ) : !groups.length ? (
        <EmptyState message="Tidak ada data yang cocok dengan filter." />
      ) : (
        <div className="border border-line rounded-drawer bg-white overflow-hidden">
          <table className="w-full border-collapse" style={{ minWidth: 1100 }}>
            <thead>
              <tr>
                {COLS.map((h) => (
                  <th key={h} className="px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-muted bg-[#f7faf7] border-b border-line">
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {groups.map((pdo) => {
                const pdoCollapsed = collapsed[`pdo-${pdo.pdoId}`] ?? false
                return (
                  <>
                    {/* PDO group header */}
                    <tr
                      key={`pdo-${pdo.pdoId}`}
                      className="bg-[#f0f7f0] cursor-pointer select-none hover:bg-[#e6f2e6]"
                      onClick={() => toggle(`pdo-${pdo.pdoId}`)}
                    >
                      <td colSpan={COLS.length} className="px-4 py-2.5 border-t border-line">
                        <div className="flex items-center gap-2">
                          {pdoCollapsed
                            ? <ChevronRight className="w-4 h-4 text-green shrink-0" />
                            : <ChevronDown  className="w-4 h-4 text-green shrink-0" />
                          }
                          <span className="font-bold text-sm text-green">{pdo.pdoNumber}</span>
                          <span className="text-xs text-muted">
                            ({pdo.items.reduce((s, it) => s + it.entries.length, 0)} transaksi,{' '}
                            {pdo.items.length} item biaya)
                          </span>
                        </div>
                      </td>
                    </tr>

                    {!pdoCollapsed && pdo.items.map((item) => {
                      const itemCollapsed = collapsed[`item-${item.detailId}`] ?? false
                      const totalTransferred = item.entries.reduce((s, t) => s + t.amount, 0)
                      const saldo = item.totalPengajuan - totalTransferred

                      return (
                        <>
                          {/* Item biaya sub-header */}
                          <tr
                            key={`item-${item.detailId}`}
                            className="bg-[#f7fbf7] cursor-pointer select-none hover:bg-[#eef6ee]"
                            onClick={() => toggle(`item-${item.detailId}`)}
                          >
                            {/* PDO col */}
                            <td className="px-4 py-2 border-t border-line text-sm text-muted pl-8">
                              {pdo.pdoNumber}
                            </td>
                            {/* Item PDO col */}
                            <td className="px-4 py-2 border-t border-line" colSpan={1}>
                              <div className="flex items-center gap-2">
                                {itemCollapsed
                                  ? <ChevronRight className="w-3.5 h-3.5 text-muted shrink-0" />
                                  : <ChevronDown  className="w-3.5 h-3.5 text-muted shrink-0" />
                                }
                                <span className="text-sm font-semibold text-ink">{item.itemLabel}</span>
                                <span className="text-xs text-muted">({item.entries.length} transaksi)</span>
                              </div>
                            </td>
                            {/* Tanggal — empty */}
                            <td className="px-4 py-2 border-t border-line" />
                            {/* Total Pengajuan */}
                            <td className="px-4 py-2 border-t border-line text-sm">
                              {fmt(item.totalPengajuan)}
                            </td>
                            {/* Dana yang Ditransfer — total transferred */}
                            <td className="px-4 py-2 border-t border-line text-sm font-bold">
                              {fmt(totalTransferred)}
                            </td>
                            {/* Saldo */}
                            <td className={`px-4 py-2 border-t border-line text-sm font-bold ${saldo < 0 ? 'text-red-600' : saldo === 0 ? 'text-green' : ''}`}>
                              {fmt(saldo)}
                            </td>
                            {/* Remaining cols — empty */}
                            <td className="px-4 py-2 border-t border-line" colSpan={4} />
                          </tr>

                          {/* Entry rows */}
                          {!itemCollapsed && item.entries.map((t) => (
                            <tr key={t.id} className="border-t border-line hover:bg-[#fbfdfb]">
                              <td className="px-4 py-3 text-sm text-muted pl-10">{pdo.pdoNumber}</td>
                              <td className="px-4 py-3 text-sm pl-8">{item.itemLabel}</td>
                              <td className="px-4 py-3 text-sm">{fmtDate(t.transfer_date)}</td>
                              {/* Total Pengajuan & Saldo — summarized at item level, not per row */}
                              <td className="px-4 py-3 text-sm text-muted">—</td>
                              <td className={`px-4 py-3 text-sm font-bold ${t.amount < 0 ? 'text-red-600' : ''}`}>{fmt(t.amount)}</td>
                              <td className="px-4 py-3 text-sm text-muted">—</td>
                              <td className="px-4 py-3 text-sm">{DEST_LABEL[t.transfer_destination] ?? t.transfer_destination}</td>
                              <td className="px-4 py-3 text-sm">
                                <span className={`badge ${t.entry_source === 'system' ? 'badge-draft' : 'badge-approved'}`}>
                                  {SOURCE_LABEL[t.entry_source] ?? t.entry_source}
                                </span>
                              </td>
                              <td className="px-4 py-3 text-sm text-muted">{t.notes || '—'}</td>
                              <td className="px-4 py-3 text-sm">{t.recorder?.full_name ?? '—'}</td>
                            </tr>
                          ))}
                        </>
                      )
                    })}
                  </>
                )
              })}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
