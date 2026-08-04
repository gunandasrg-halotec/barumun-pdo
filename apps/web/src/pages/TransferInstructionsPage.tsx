import { Fragment, useMemo, useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { EmptyState } from '@/components/ui/EmptyState'
import { Button } from '@/components/ui/Button'
import { DateRangePickerButton } from '@/components/ui/DateRangePickerButton'
import { VehicleAssignmentModal, type VehicleAssignmentItem } from '@/components/transfer/VehicleAssignmentModal'
import { useAuthStore } from '@/store/auth.store'
import { useToastStore } from '@/store/toast.store'
import { canMarkTransferExecuted } from '@/lib/auth'
import { fmt, fmtDate } from '@/lib/format'
import { INVENTORY_ITEM_CODES } from '@/lib/constants'
import { Search, ChevronDown, ChevronRight } from 'lucide-react'
import type { ApiResponse, RoleCode, TransferEntry } from '@/types'

function fullItemLabel(t: TransferEntry): string {
  const item = t.pdo_detail?.expense_item
  return [item?.subcategory?.category?.name, item?.subcategory?.name, item?.name].filter(Boolean).join(' — ')
}

/**
 * Baris bernilai negatif bukan perintah transfer — tidak ada yang bisa dikirim dari
 * nilai minus. Ini mencakup potongan panjar (dibuat sistem saat Simpan Permanen) dan
 * koreksi manual bernilai minus. Sengaja memakai nilai amount, bukan flag internal
 * `is_auto_generated`, supaya keduanya tertangani dengan aturan yang sama.
 *
 * Barisnya tetap ditampilkan supaya subtotal halaman ini = uang yang benar-benar
 * ditransfer, sama dengan Transfer Dana / Rekap Buku Kas / Dashboard.
 */
function isNonTransfer(t: TransferEntry): boolean {
  return t.amount < 0
}

type TransferDest = 'rek_kebun' | 'pribadi' | 'vendor'

interface DestTotals { rek_kebun: number; pribadi: number; vendor: number; total: number }

interface PdoGroup {
  pdoId: string
  pdoNumber: string
  entries: TransferEntry[]
}

function destTotals(entries: TransferEntry[]): DestTotals {
  return entries.reduce<DestTotals>(
    (acc, t) => {
      acc.total += t.amount
      if (t.transfer_destination === 'rek_kebun') acc.rek_kebun += t.amount
      else if (t.transfer_destination === 'pribadi') acc.pribadi += t.amount
      else if (t.transfer_destination === 'vendor')  acc.vendor  += t.amount
      return acc
    },
    { rek_kebun: 0, pribadi: 0, vendor: 0, total: 0 },
  )
}

// ─── cell helpers ─────────────────────────────────────────────────────────────

const DEST_COLS: TransferDest[] = ['rek_kebun', 'pribadi', 'vendor']

const DEST_CELL_VALUE: Record<TransferDest, string> =  {
  rek_kebun: 'px-4 py-3 text-sm font-bold text-right tabular-nums bg-[#edfaf3] text-[#085041]',
  pribadi:   'px-4 py-3 text-sm font-bold text-right tabular-nums bg-[#fdf6e8] text-[#633806]',
  vendor:    'px-4 py-3 text-sm font-bold text-right tabular-nums bg-[#eef5fd] text-[#0C447C]',
}
const DEST_CELL_EMPTY: Record<TransferDest, string> = {
  rek_kebun: 'px-4 py-3 text-sm text-right text-muted bg-[#f5fcf8]',
  pribadi:   'px-4 py-3 text-sm text-right text-muted bg-[#fefbf4]',
  vendor:    'px-4 py-3 text-sm text-right text-muted bg-[#f5f9fe]',
}
const DEST_SUB: Record<TransferDest, string> = {
  rek_kebun: 'px-4 py-2.5 text-sm font-bold text-right tabular-nums bg-[#c8ecdf] text-[#085041]',
  pribadi:   'px-4 py-2.5 text-sm font-bold text-right tabular-nums bg-[#f5ddb8] text-[#633806]',
  vendor:    'px-4 py-2.5 text-sm font-bold text-right tabular-nums bg-[#c2d9f5] text-[#0C447C]',
}
const DEST_GRAND: Record<TransferDest, string> = {
  rek_kebun: 'px-4 py-3 text-sm font-bold text-right tabular-nums bg-[#0F6E56] text-white',
  pribadi:   'px-4 py-3 text-sm font-bold text-right tabular-nums bg-[#854F0B] text-white',
  vendor:    'px-4 py-3 text-sm font-bold text-right tabular-nums bg-[#185FA5] text-white',
}

// ─── component ────────────────────────────────────────────────────────────────

export function TransferInstructionsPage() {
  const user      = useAuthStore((s) => s.user)
  const role      = user?.role.code as RoleCode | undefined
  const toast     = useToastStore((s) => s.push)
  const qc        = useQueryClient()
  const canToggle = !!role && canMarkTransferExecuted(role)

  const [search,    setSearch]    = useState('')
  const [startDate, setStartDate] = useState('')
  const [endDate,   setEndDate]   = useState('')
  const [collapsed, setCollapsed] = useState<Record<string, boolean>>({})
  const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set())
  const [onlyPending, setOnlyPending] = useState(false)
  const [vehicleModalOpen, setVehicleModalOpen] = useState(false)
  const [vehicleValues, setVehicleValues] = useState<Record<string, string>>({})

  const { data: entries, isLoading } = useQuery({
    queryKey: ['transfer-instructions'],
    queryFn: async () => {
      const res = await api.get<ApiResponse<TransferEntry[]>>('/transfer-entries')
      return res.data.data
    },
  })

  const filtered = useMemo(() => {
    if (!entries) return []
    const q = search.trim().toLowerCase()
    return entries.filter((t) => {
      const code = t.pdo_detail?.expense_item?.code ?? ''
      const label = fullItemLabel(t)
      const matchSearch = !q ||
        code.toLowerCase().includes(q) ||
        label.toLowerCase().includes(q)
      const matchDate =
        (!startDate || t.transfer_date >= startDate) &&
        (!endDate   || t.transfer_date <= endDate)
      // Dulu baris yang sudah ditransfer disembunyikan DIAM-DIAM (matchTransferred =
      // hasFilter || !t.is_transferred). Karena subtotal dihitung dari daftar yang
      // sudah tersaring, baris itu ikut hilang dari total — begitu Staff Purchasing
      // mulai mencentang, angka halaman ini menyusut dan tidak lagi cocok dengan
      // Transfer Dana. Sekarang semua baris tampil (statusnya sudah jelas lewat badge)
      // dan penyembunyian jadi filter eksplisit yang dikendalikan user.
      // Baris negatif tidak pernah dianggap "menunggu ditransfer" — tidak ada yang
      // bisa dikirim dari nilai minus, jadi jangan tampilkan sebagai pekerjaan tertunda.
      const matchTransferred = !onlyPending || (!t.is_transferred && !isNonTransfer(t))
      return matchSearch && matchDate && matchTransferred
    })
  }, [entries, search, startDate, endDate, onlyPending])

  const groups = useMemo<PdoGroup[]>(() => {
    const pdoMap = new Map<string, { pdoNumber: string; entries: TransferEntry[] }>()
    for (const t of filtered) {
      const pdoId     = t.pdo_detail?.pdo_header?.id ?? 'unknown'
      const pdoNumber = t.pdo_detail?.pdo_header?.pdo_number ?? '—'
      if (!pdoMap.has(pdoId)) pdoMap.set(pdoId, { pdoNumber, entries: [] })
      pdoMap.get(pdoId)!.entries.push(t)
    }
    return Array.from(pdoMap.entries()).map(([pdoId, { pdoNumber, entries }]) => ({ pdoId, pdoNumber, entries }))
  }, [filtered])

  const grandTotals = useMemo(() => destTotals(filtered), [filtered])

  const toggle = (key: string) =>
    setCollapsed((prev) => ({ ...prev, [key]: !prev[key] }))

  const errMsg = (err: unknown, fallback: string) =>
    (err as { response?: { data?: { error?: { message?: string } } }; message?: string })
      ?.response?.data?.error?.message ?? (err as { message?: string })?.message ?? fallback

  const markTransferred = useMutation({
    mutationFn: ({ ids, value, vehicles }: { ids: string[]; value: boolean; vehicles?: Record<string, string> }) =>
      api.patch<ApiResponse<{ updated: number; realizations_created: number }>>('/transfer-entries/mark-transferred', {
        entry_ids: ids, is_transferred: value, vehicles: vehicles ?? {},
      }),
    onSuccess: (res) => {
      const { updated, realizations_created } = res.data.data
      qc.invalidateQueries({ queryKey: ['transfer-instructions'] })
      qc.invalidateQueries({ queryKey: ['realizations-for-pdo-sequence'] })
      qc.invalidateQueries({ queryKey: ['realizations-available'] })
      qc.invalidateQueries({ queryKey: ['recap'] })
      toast(
        realizations_created > 0
          ? `${updated} transfer ditandai, ${realizations_created} realisasi otomatis dibuat`
          : `${updated} transfer ditandai`,
        'success',
      )
      setSelectedIds(new Set())
      setVehicleModalOpen(false)
      setVehicleValues({})
    },
    onError: (err) => toast(errMsg(err, 'Gagal memperbarui status transfer'), 'error'),
  })

  const selectableIds = useMemo(
    () => filtered.filter((t) => !t.is_transferred && !isNonTransfer(t)).map((t) => t.id),
    [filtered],
  )
  const allSelected = selectableIds.length > 0 && selectableIds.every((id) => selectedIds.has(id))

  const toggleSelect = (id: string) => {
    setSelectedIds((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  const toggleSelectAll = () => {
    setSelectedIds((prev) => {
      if (selectableIds.every((id) => prev.has(id))) return new Set()
      return new Set(selectableIds)
    })
  }

  const selectedEntries = useMemo(() => filtered.filter((t) => selectedIds.has(t.id)), [filtered, selectedIds])

  const inventoryItems = useMemo<VehicleAssignmentItem[]>(() => {
    const seen = new Set<string>()
    const items: VehicleAssignmentItem[] = []
    for (const t of selectedEntries) {
      const code = t.pdo_detail?.expense_item?.code ?? ''
      if (!INVENTORY_ITEM_CODES.includes(code) || seen.has(t.pdo_detail_id)) continue
      seen.add(t.pdo_detail_id)
      items.push({ pdoDetailId: t.pdo_detail_id, itemCode: code, itemName: t.pdo_detail?.expense_item?.name ?? '' })
    }
    return items
  }, [selectedEntries])

  const handleMark = () => {
    if (!selectedIds.size) return
    if (inventoryItems.length > 0) {
      setVehicleModalOpen(true)
      return
    }
    markTransferred.mutate({ ids: Array.from(selectedIds), value: true })
  }

  const handleConfirmVehicles = () => {
    markTransferred.mutate({ ids: Array.from(selectedIds), value: true, vehicles: vehicleValues })
  }

  // jumlah kolom total: ☑ + Kode + Nama + Jumlah + Kebun + Pribadi + Vendor + Tanggal + Dicatat + Status = 10
  const COL_TOTAL = 10

  return (
    <div>
      <div className="mb-6 flex items-start justify-between gap-4">
        <div>
          <h1 className="text-[26px] font-[950] text-ink">Daftar Perintah Transfer</h1>
          <p className="text-muted text-sm mt-1">
            Instruksi transfer dana yang sudah disetujui (Simpan Permanen) — tandai item yang dananya sudah benar-benar ditransfer.
          </p>
        </div>
        {canToggle && (
          <Button
            disabled={selectedIds.size === 0}
            loading={markTransferred.isPending}
            onClick={handleMark}
          >
            Tandai Sudah Ditransfer ({selectedIds.size})
          </Button>
        )}
      </div>

      {/* Filter Bar */}
      <div className="card mb-5 flex flex-wrap gap-3 items-end">
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
          <label className="label">Cari Item</label>
          <div className="relative">
            <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 w-4 h-4 text-muted pointer-events-none" />
            <input
              type="text"
              className="input-base pl-8"
              placeholder="Kode atau nama item..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          </div>
        </div>
        <label className="flex items-center gap-2 text-sm cursor-pointer select-none pb-2.5">
          <input
            type="checkbox"
            className="rounded border-line accent-green"
            checked={onlyPending}
            onChange={(e) => setOnlyPending(e.target.checked)}
          />
          Hanya yang belum ditransfer
        </label>
      </div>

      {/* Content */}
      {isLoading ? (
        <div className="card space-y-3">
          {Array.from({ length: 4 }).map((_, i) => (
            <div key={i} className="h-8 bg-[#f0f4f0] rounded animate-pulse" />
          ))}
        </div>
      ) : !groups.length ? (
        <EmptyState message="Tidak ada instruksi transfer yang cocok dengan filter." />
      ) : (
        <div className="border border-line rounded-drawer bg-white overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full border-collapse" style={{ minWidth: 1100 }}>
              <thead>
                <tr>
                  <th className="px-4 py-3 text-left border-b border-line bg-[#f7faf7]" style={{ width: 40 }}>
                    <input
                      type="checkbox"
                      checked={allSelected}
                      disabled={!canToggle || selectableIds.length === 0}
                      onChange={toggleSelectAll}
                    />
                  </th>
                  {['Kode Item', 'Nama Item', 'Jumlah'].map((h) => (
                    <th key={h} className="px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-muted bg-[#f7faf7] border-b border-line">
                      {h}
                    </th>
                  ))}
                  <th className="px-4 py-3 text-right text-[11px] font-bold uppercase tracking-wider border-b border-[#9FE1CB] bg-[#E1F5EE] text-[#0F6E56]">
                    Rek. Kebun
                  </th>
                  <th className="px-4 py-3 text-right text-[11px] font-bold uppercase tracking-wider border-b border-[#FAC775] bg-[#FAEEDA] text-[#854F0B]">
                    Rek. Pribadi
                  </th>
                  <th className="px-4 py-3 text-right text-[11px] font-bold uppercase tracking-wider border-b border-[#B5D4F4] bg-[#E6F1FB] text-[#185FA5]">
                    Vendor
                  </th>
                  {['Tanggal', 'Dicatat Oleh', 'Status'].map((h) => (
                    <th key={h} className="px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-muted bg-[#f7faf7] border-b border-line">
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {groups.map((pdo) => {
                  const pdoCollapsed = collapsed[`pdo-${pdo.pdoId}`] ?? false
                  const sub = destTotals(pdo.entries)

                  return (
                    <Fragment key={pdo.pdoId}>
                      {/* ── Header grup PDO ── */}
                      <tr
                        key={`pdo-${pdo.pdoId}`}
                        className="bg-[#f0f7f0] cursor-pointer select-none hover:bg-[#e6f2e6]"
                        onClick={() => toggle(`pdo-${pdo.pdoId}`)}
                      >
                        <td colSpan={COL_TOTAL} className="px-4 py-2.5 border-t border-line">
                          <div className="flex items-center gap-2">
                            {pdoCollapsed
                              ? <ChevronRight className="w-4 h-4 text-green shrink-0" />
                              : <ChevronDown  className="w-4 h-4 text-green shrink-0" />
                            }
                            <span className="font-bold text-sm text-green">{pdo.pdoNumber}</span>
                            <span className="text-xs text-muted">({pdo.entries.length} item)</span>
                          </div>
                        </td>
                      </tr>

                      {/* ── Baris item ── */}
                      {!pdoCollapsed && pdo.entries.map((t) => {
                        const dest = t.transfer_destination as TransferDest
                        const nonTransfer = isNonTransfer(t)
                        return (
                          <tr
                            key={t.id}
                            className={nonTransfer
                              ? 'border-t border-line bg-[#fdf6e8]'
                              : 'border-t border-line hover:bg-[#fbfdfb]'}
                          >
                            <td className="px-4 py-3">
                              {!nonTransfer && (
                                <input
                                  type="checkbox"
                                  checked={t.is_transferred || selectedIds.has(t.id)}
                                  disabled={!canToggle || t.is_transferred}
                                  onChange={() => toggleSelect(t.id)}
                                />
                              )}
                            </td>
                            <td className="px-4 py-3 text-sm">{t.pdo_detail?.expense_item?.code ?? '—'}</td>
                            <td className="px-4 py-3 text-sm">
                              {fullItemLabel(t)}
                              {nonTransfer && t.notes && (
                                <span className="block text-xs text-[#633806] mt-0.5">{t.notes}</span>
                              )}
                            </td>
                            <td className={nonTransfer
                              ? 'px-4 py-3 text-sm font-bold tabular-nums text-[#633806]'
                              : 'px-4 py-3 text-sm font-bold tabular-nums'}>
                              {fmt(t.amount)}
                            </td>
                            {DEST_COLS.map((col) => (
                              <td key={col} className={dest === col ? DEST_CELL_VALUE[col] : DEST_CELL_EMPTY[col]}>
                                {dest === col ? fmt(t.amount) : '—'}
                              </td>
                            ))}
                            <td className="px-4 py-3 text-sm">{fmtDate(t.transfer_date)}</td>
                            <td className="px-4 py-3 text-sm">{t.recorder?.full_name ?? '—'}</td>
                            <td className="px-4 py-3 text-sm">
                              {nonTransfer ? (
                                <span className="badge bg-[#f5ddb8] text-[#633806]">Bukan perintah transfer</span>
                              ) : t.is_transferred ? (
                                <span className="badge badge-approved">
                                  Sudah Ditransfer{t.transferred_at ? ` — ${fmtDate(t.transferred_at)}` : ''}
                                  {t.transferred_by_user ? `, oleh ${t.transferred_by_user.full_name}` : ''}
                                </span>
                              ) : (
                                <span className="badge badge-draft">Belum Ditransfer</span>
                              )}
                            </td>
                          </tr>
                        )
                      })}

                      {/* ── Subtotal per PDO ── */}
                      <tr key={`sub-${pdo.pdoId}`} className="border-t border-line">
                        <td colSpan={3} className="px-4 py-2.5 bg-[#e8f3e8]">
                          <span className="text-[10px] font-bold uppercase tracking-wider text-[#085041]">
                            Subtotal {pdo.pdoNumber}
                          </span>
                        </td>
                        <td className="px-4 py-2.5 text-sm font-bold text-right tabular-nums bg-[#e8f3e8] text-[#085041]">
                          {fmt(sub.total)}
                        </td>
                        {DEST_COLS.map((col) => (
                          <td key={col} className={DEST_SUB[col]}>
                            {sub[col] !== 0 ? fmt(sub[col]) : '—'}
                          </td>
                        ))}
                        <td colSpan={3} className="bg-[#e8f3e8]" />
                      </tr>
                    </Fragment>
                  )
                })}

                {/* ── Grand total ── */}
                <tr className="border-t-2 border-[#085041]">
                  <td colSpan={3} className="px-4 py-3 bg-[#085041]">
                    <span className="text-[10px] font-bold uppercase tracking-wider text-white/70">
                      Total Keseluruhan
                    </span>
                  </td>
                  <td className="px-4 py-3 text-sm font-bold text-right tabular-nums bg-[#085041] text-white">
                    {fmt(grandTotals.total)}
                  </td>
                  {DEST_COLS.map((col) => (
                    <td key={col} className={DEST_GRAND[col]}>
                      {grandTotals[col] !== 0 ? fmt(grandTotals[col]) : '—'}
                    </td>
                  ))}
                  <td colSpan={3} className="bg-[#085041]" />
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      )}

      <VehicleAssignmentModal
        open={vehicleModalOpen}
        items={inventoryItems}
        values={vehicleValues}
        onChange={(pdoDetailId, vehicleId) => setVehicleValues((prev) => ({ ...prev, [pdoDetailId]: vehicleId }))}
        onClose={() => setVehicleModalOpen(false)}
        onConfirm={handleConfirmVehicles}
        loading={markTransferred.isPending}
      />
    </div>
  )
}
