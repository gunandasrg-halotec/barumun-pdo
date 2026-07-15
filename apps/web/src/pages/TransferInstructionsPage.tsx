import { useMemo, useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { EmptyState } from '@/components/ui/EmptyState'
import { DateRangePickerButton } from '@/components/ui/DateRangePickerButton'
import { useAuthStore } from '@/store/auth.store'
import { useToastStore } from '@/store/toast.store'
import { canMarkTransferExecuted } from '@/lib/auth'
import { fmt, fmtDate } from '@/lib/format'
import { Search, ChevronDown, ChevronRight } from 'lucide-react'
import type { ApiResponse, RoleCode, TransferEntry } from '@/types'

const DEST_LABEL: Record<string, string> = {
  rek_kebun: 'Rek. Kebun', pribadi: 'Pribadi', vendor: 'Vendor',
}

interface PdoGroup {
  pdoId: string
  pdoNumber: string
  entries: TransferEntry[]
}

export function TransferInstructionsPage() {
  const user   = useAuthStore((s) => s.user)
  const role   = user?.role.code as RoleCode | undefined
  const toast  = useToastStore((s) => s.push)
  const qc     = useQueryClient()
  const canToggle = !!role && canMarkTransferExecuted(role)

  const [search,         setSearch]         = useState('')
  const [startDate,      setStartDate]      = useState('')
  const [endDate,        setEndDate]        = useState('')
  const [showTransferred, setShowTransferred] = useState(false)
  const [collapsed,      setCollapsed]      = useState<Record<string, boolean>>({})

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
      const name = t.pdo_detail?.expense_item?.name ?? ''
      const matchSearch = !q ||
        code.toLowerCase().includes(q) ||
        name.toLowerCase().includes(q)
      const matchDate =
        (!startDate || t.transfer_date >= startDate) &&
        (!endDate   || t.transfer_date <= endDate)
      const matchTransferred = showTransferred || !t.is_transferred
      return matchSearch && matchDate && matchTransferred
    })
  }, [entries, search, startDate, endDate, showTransferred])

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

  const toggle = (key: string) =>
    setCollapsed((prev) => ({ ...prev, [key]: !prev[key] }))

  const errMsg = (err: unknown, fallback: string) =>
    (err as { response?: { data?: { error?: { message?: string } } }; message?: string })
      ?.response?.data?.error?.message ?? (err as { message?: string })?.message ?? fallback

  const markTransferred = useMutation({
    mutationFn: ({ ids, value }: { ids: string[]; value: boolean }) =>
      api.patch('/transfer-entries/mark-transferred', { entry_ids: ids, is_transferred: value }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['transfer-instructions'] }),
    onError: (err) => toast(errMsg(err, 'Gagal memperbarui status transfer'), 'error'),
  })

  const allChecked = filtered.length > 0 && filtered.every((t) => t.is_transferred)

  const handleToggleAll = () => {
    if (!filtered.length) return
    markTransferred.mutate({ ids: filtered.map((t) => t.id), value: !allChecked })
  }

  const handleToggleRow = (t: TransferEntry) => {
    markTransferred.mutate({ ids: [t.id], value: !t.is_transferred })
  }

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-[26px] font-[950] text-ink">Daftar Perintah Transfer</h1>
        <p className="text-muted text-sm mt-1">
          Instruksi transfer dana yang sudah disetujui (Simpan Permanen) — tandai item yang dananya sudah benar-benar ditransfer.
        </p>
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
        <label className="flex items-center gap-2 cursor-pointer select-none pb-[1px]">
          <input
            type="checkbox"
            checked={showTransferred}
            onChange={(e) => setShowTransferred(e.target.checked)}
            className="w-4 h-4 accent-green"
          />
          <span className="text-sm text-muted">Tampilkan sudah ditransfer</span>
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
          <table className="w-full border-collapse" style={{ minWidth: 1000 }}>
            <thead>
              <tr>
                <th className="px-4 py-3 text-left border-b border-line bg-[#f7faf7]" style={{ width: 40 }}>
                  <input
                    type="checkbox"
                    checked={allChecked}
                    disabled={!canToggle || markTransferred.isPending}
                    onChange={handleToggleAll}
                  />
                </th>
                {['Kode Item', 'Nama Item', 'Jumlah', 'Tujuan Transfer', 'Tanggal', 'Dicatat Oleh', 'Status'].map((h) => (
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
                    <tr
                      key={`pdo-${pdo.pdoId}`}
                      className="bg-[#f0f7f0] cursor-pointer select-none hover:bg-[#e6f2e6]"
                      onClick={() => toggle(`pdo-${pdo.pdoId}`)}
                    >
                      <td colSpan={8} className="px-4 py-2.5 border-t border-line">
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

                    {!pdoCollapsed && pdo.entries.map((t) => (
                      <tr key={t.id} className="border-t border-line hover:bg-[#fbfdfb]">
                        <td className="px-4 py-3">
                          <input
                            type="checkbox"
                            checked={t.is_transferred}
                            disabled={!canToggle || markTransferred.isPending}
                            onChange={() => handleToggleRow(t)}
                          />
                        </td>
                        <td className="px-4 py-3 text-sm">{t.pdo_detail?.expense_item?.code ?? '—'}</td>
                        <td className="px-4 py-3 text-sm">{t.pdo_detail?.expense_item?.name ?? '—'}</td>
                        <td className="px-4 py-3 text-sm font-bold">{fmt(t.amount)}</td>
                        <td className="px-4 py-3 text-sm">{DEST_LABEL[t.transfer_destination] ?? t.transfer_destination}</td>
                        <td className="px-4 py-3 text-sm">{fmtDate(t.transfer_date)}</td>
                        <td className="px-4 py-3 text-sm">{t.recorder?.full_name ?? '—'}</td>
                        <td className="px-4 py-3 text-sm">
                          {t.is_transferred ? (
                            <span className="badge badge-approved">
                              Sudah Ditransfer{t.transferred_at ? ` — ${fmtDate(t.transferred_at)}` : ''}
                              {t.transferred_by_user ? `, oleh ${t.transferred_by_user.full_name}` : ''}
                            </span>
                          ) : (
                            <span className="badge badge-draft">Belum Ditransfer</span>
                          )}
                        </td>
                      </tr>
                    ))}
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
