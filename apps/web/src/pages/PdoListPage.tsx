import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { usePdoList, useClosePdo } from '@/hooks/usePdo'
import { useAuthStore } from '@/store/auth.store'
import { PdoStatusBadge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Modal } from '@/components/ui/Modal'
import { EmptyState } from '@/components/ui/EmptyState'
import { api } from '@/lib/api'
import { fmt, fmtPeriode, fmtDate } from '@/lib/format'
import { isKerani, isMgrKeu, canDeleteDraftPdo } from '@/lib/auth'
import { useToastStore } from '@/store/toast.store'
import type { ApiResponse, PdoHeader, PlantationUnit, RoleCode } from '@/types'
import { Search } from 'lucide-react'

// ─── types untuk modal transfer detail ────────────────────────────────────────

interface TransferEntry {
  id:                   string
  transfer_date:        string
  amount:               number
  transfer_destination: string
  reference_number:     string | null
  notes:                string | null
}

interface TransferDetailItem {
  pdo_detail_id: string
  description:   string
  expense_item:  { id: string; code: string; name: string } | null
  entries:       TransferEntry[]
}

interface TransferDetailResponse {
  pdo_number:      string
  details:         TransferDetailItem[]
}

const DEST_LABEL: Record<string, string> = {
  rek_kebun: 'Rek. Kebun',
  pribadi:   'Pribadi',
  vendor:    'Vendor',
}

const MONTHS = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember']
const YEARS  = Array.from({ length: 5 }, (_, i) => new Date().getFullYear() - i + 1)

export function PdoListPage() {
  const user     = useAuthStore((s) => s.user)
  const navigate = useNavigate()
  const toast    = useToastStore((s) => s.push)
  const role     = user?.role.code as RoleCode | undefined

  const [search, setSearch]               = useState('')
  const [statusFilter, setStatus]         = useState('')
  const [yearFilter, setYearFilter]       = useState('')
  const [monthFilter, setMonthFilter]     = useState('')
  const [unitFilter, setUnitFilter]       = useState('')
  const [closingPdo, setClosingPdo]       = useState<PdoHeader | null>(null)
  const [deletingPdo, setDeletingPdo]     = useState<PdoHeader | null>(null)
  const [closeDate, setCloseDate]         = useState('')
  const [closeNotes, setCloseNotes]       = useState('')
  const [transferDetailPdo, setTransferDetailPdo] = useState<PdoHeader | null>(null)

  const { data: units } = useQuery({
    queryKey: ['plantation-units'],
    queryFn: async () => {
      const res = await api.get<ApiResponse<PlantationUnit[]>>('/plantation-units')
      return res.data.data
    },
  })

  const { data, isLoading } = usePdoList({
    search:               search || undefined,
    status:               statusFilter || undefined,
    period_year:          yearFilter ? Number(yearFilter) : undefined,
    period_month:         monthFilter ? Number(monthFilter) : undefined,
    plantation_unit_id:   unitFilter || undefined,
  })

  const closePdo = useClosePdo(closingPdo?.id ?? '')
  const queryClient = useQueryClient()
  const deletePdo = useMutation({
    mutationFn: (id: string) => api.delete(`/pdo/${id}`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['pdo', 'list'] })
      toast('PDO berhasil dihapus')
      setDeletingPdo(null)
    },
    onError: () => toast('Gagal menghapus PDO', 'error'),
  })

  const { data: transferDetail, isLoading: transferLoading } = useQuery({
    queryKey: ['pdo-transfer-detail', transferDetailPdo?.id],
    queryFn: async () => {
      const res = await api.get<ApiResponse<TransferDetailResponse>>(`/pdo/${transferDetailPdo!.id}/transfers`)
      return res.data.data
    },
    enabled: !!transferDetailPdo,
  })

  const flatEntries = transferDetail?.details.flatMap((d) =>
    d.entries.map((e) => ({ ...e, item_name: d.expense_item?.name ?? d.description }))
  ) ?? []

  const destTotals = flatEntries.reduce<Record<string, number>>((acc, e) => {
    acc[e.transfer_destination] = (acc[e.transfer_destination] ?? 0) + e.amount
    return acc
  }, {})

  const handleClose = async () => {
    if (!closingPdo || !closeDate) return
    try {
      await closePdo.mutateAsync({ closure_date: closeDate, notes: closeNotes })
      toast('PDO berhasil ditutup')
      setClosingPdo(null)
    } catch {
      toast('Gagal menutup PDO', 'error')
    }
  }

  return (
    <div>
      {/* Hero */}
      <div className="flex items-start justify-between mb-6">
        <div>
          <h2 className="text-[28px] font-[950] text-ink">Daftar PDO</h2>
          <p className="text-muted text-sm mt-1">
            Detail PDO dibuka dari tombol Detail. Approval timeline dibuka dengan mengklik status PDO.
          </p>
        </div>
        {role && isKerani(role) && (
          <Button onClick={() => navigate('/pdo/buat')}>+ Buat PDO Baru</Button>
        )}
      </div>

      {/* Filter Bar */}
      <div className="flex items-center gap-3 mb-4 flex-wrap">
        {/* Search */}
        <div className="relative">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted" />
          <input
            className="input-base pl-9 w-56"
            placeholder="Cari nomor PDO..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </div>

        {/* Status */}
        <select className="input-base w-auto" value={statusFilter} onChange={(e) => setStatus(e.target.value)}>
          <option value="">Semua Status</option>
          <option value="draft">Draft</option>
          <option value="submitted">Submitted</option>
          <option value="reviewed_asisten">Reviewed Asisten</option>
          <option value="in_review_manager">In Review Manager</option>
          <option value="in_review_direktur">In Review Direktur</option>
          <option value="final">Final</option>
          <option value="closed">Closed</option>
        </select>

        {/* Tahun */}
        <select className="input-base w-auto" value={yearFilter} onChange={(e) => setYearFilter(e.target.value)}>
          <option value="">Semua Tahun</option>
          {YEARS.map((y) => <option key={y} value={y}>{y}</option>)}
        </select>

        {/* Bulan */}
        <select className="input-base w-auto" value={monthFilter} onChange={(e) => setMonthFilter(e.target.value)}>
          <option value="">Semua Bulan</option>
          {MONTHS.slice(1).map((m, i) => <option key={i + 1} value={i + 1}>{m}</option>)}
        </select>

        {/* Unit Kebun */}
        {units && units.length > 1 && (
          <select className="input-base w-auto" value={unitFilter} onChange={(e) => setUnitFilter(e.target.value)}>
            <option value="">Semua Kebun</option>
            {units.map((u) => <option key={u.id} value={u.id}>{u.code} — {u.name}</option>)}
          </select>
        )}

      </div>

      {/* Table */}
      <div className="overflow-auto border border-line rounded-drawer bg-white">
        <table className="w-full border-collapse" style={{ minWidth: 1060 }}>
          <thead>
            <tr>
              {['Nomor PDO', 'Unit', 'Periode', 'Total Pengajuan', 'Total Transfer',
                'Total Realisasi', 'Saldo', 'Status', 'Tipe', 'Dibuat Oleh', 'Aksi'].map((h) => (
                <th key={h} className="px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-[#526257] bg-[#f7faf7] sticky top-0">
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {isLoading ? (
              Array.from({ length: 5 }).map((_, i) => (
                <tr key={i}>
                  {Array.from({ length: 11 }).map((__, j) => (
                    <td key={j} className="px-4 py-3">
                      <div className="h-4 bg-[#f0f4f0] rounded animate-pulse" />
                    </td>
                  ))}
                </tr>
              ))
            ) : !data?.data?.length ? (
              <tr>
                <td colSpan={11} className="px-4 py-8">
                  <EmptyState message='Belum ada PDO. Klik "Buat PDO Baru" untuk memulai pengajuan.' />
                </td>
              </tr>
            ) : (
              data.data.map((pdo) => (
                <tr key={pdo.id} className="hover:bg-[#fbfdfb] border-t border-line">
                  <td className="px-4 py-3 font-bold text-ink text-sm">{pdo.pdo_number}</td>
                  <td className="px-4 py-3 text-sm">{pdo.plantation_unit?.code ?? '—'}</td>
                  <td className="px-4 py-3 text-sm">{fmtPeriode(pdo.period_month, pdo.period_year)}</td>
                  <td className="px-4 py-3 text-sm">{fmt(pdo.total_amount)}</td>
                  <td className="px-4 py-3 text-sm">
                    {pdo.status === 'final' && (pdo.total_transferred ?? 0) > 0 ? (
                      <button
                        className="font-bold text-green hover:underline"
                        onClick={() => setTransferDetailPdo(pdo)}
                      >
                        {fmt(pdo.total_transferred)}
                      </button>
                    ) : (
                      fmt(pdo.total_transferred)
                    )}
                  </td>
                  <td className="px-4 py-3 text-sm">{fmt(pdo.total_realized)}</td>
                  <td className="px-4 py-3 text-sm">{fmt(pdo.balance)}</td>
                  <td className="px-4 py-3">
                    <PdoStatusBadge
                      status={pdo.status}
                      onClick={() => navigate(`/pdo/${pdo.id}/approval`)}
                    />
                  </td>
                  <td className="px-4 py-3">
                    <span className="badge badge-draft">Bulanan</span>
                  </td>
                  <td className="px-4 py-3 text-sm">{pdo.creator?.full_name ?? '—'}</td>
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-2">
                      <button
                        className="text-sm font-bold text-green hover:underline"
                        onClick={() => navigate(`/pdo/${pdo.id}`)}
                      >
                        Detail
                      </button>
                      {role && isKerani(role) && pdo.status === 'draft' && (
                        <button
                          className="text-sm font-bold text-green hover:underline"
                          onClick={() => navigate(`/pdo/${pdo.id}/edit`)}
                        >
                          Edit
                        </button>
                      )}
                      {role && canDeleteDraftPdo(role) && pdo.status === 'draft' && (
                        <button
                          className="text-sm font-bold text-red hover:underline"
                          onClick={() => setDeletingPdo(pdo)}
                        >
                          Hapus
                        </button>
                      )}
                      {role && isMgrKeu(role) && pdo.status === 'final' && (
                        <button
                          className="text-sm font-bold text-red hover:underline"
                          onClick={() => {
                            setClosingPdo(pdo)
                            setCloseDate(new Date().toISOString().split('T')[0])
                          }}
                        >
                          Tutup PDO
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {/* Modal Riwayat Transfer */}
      <Modal
        open={!!transferDetailPdo}
        onClose={() => setTransferDetailPdo(null)}
        title={`Riwayat Transfer — ${transferDetailPdo?.pdo_number}`}
        width="w-[820px]"
      >
        {transferLoading ? (
          <div className="flex justify-center py-8 text-muted text-sm">Memuat data...</div>
        ) : (
          <div className="flex flex-col gap-5">
            {/* Summary per destinasi */}
            <div className="grid grid-cols-3 gap-3">
              {(['rek_kebun', 'pribadi', 'vendor'] as const).map((dest) => (
                <div key={dest} className="border border-line rounded-card p-3 bg-[#f7faf7]">
                  <p className="text-[11px] font-bold uppercase tracking-wider text-muted mb-1">{DEST_LABEL[dest]}</p>
                  <p className="text-base font-[850] text-ink">{fmt(destTotals[dest] ?? 0)}</p>
                </div>
              ))}
            </div>

            {/* Tabel riwayat flat */}
            <div className="overflow-auto border border-line rounded-card">
              <table className="w-full border-collapse text-sm">
                <thead>
                  <tr>
                    {['Tanggal', 'Item', 'Tujuan Transfer', 'Jumlah'].map((h) => (
                      <th key={h} className="px-3 py-2 text-left text-[11px] font-bold uppercase tracking-wider text-muted bg-[#f7faf7]">
                        {h}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {flatEntries.length === 0 ? (
                    <tr><td colSpan={4} className="px-3 py-6 text-center text-muted">Belum ada data transfer</td></tr>
                  ) : (
                    flatEntries
                      .sort((a, b) => a.transfer_date.localeCompare(b.transfer_date))
                      .map((e) => (
                        <tr key={e.id} className="border-t border-line hover:bg-[#fbfdfb]">
                          <td className="px-3 py-2 whitespace-nowrap">{fmtDate(e.transfer_date)}</td>
                          <td className="px-3 py-2">{e.item_name}</td>
                          <td className="px-3 py-2 whitespace-nowrap">{DEST_LABEL[e.transfer_destination] ?? e.transfer_destination}</td>
                          <td className="px-3 py-2 text-right font-bold whitespace-nowrap">{fmt(e.amount)}</td>
                        </tr>
                      ))
                  )}
                </tbody>
              </table>
            </div>

            <div className="flex justify-end">
              <Button variant="secondary" onClick={() => setTransferDetailPdo(null)}>Tutup</Button>
            </div>
          </div>
        )}
      </Modal>

      {/* Modal Hapus PDO */}
      <Modal
        open={!!deletingPdo}
        onClose={() => setDeletingPdo(null)}
        title={`Hapus PDO: ${deletingPdo?.pdo_number}`}
      >
        <p className="text-sm text-muted mb-6">
          PDO <span className="font-bold text-ink">{deletingPdo?.pdo_number}</span> akan dihapus secara permanen.
          Tindakan ini tidak dapat dibatalkan.
        </p>
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={() => setDeletingPdo(null)}>Batal</Button>
          <Button
            variant="danger"
            loading={deletePdo.isPending}
            onClick={() => deletingPdo && deletePdo.mutate(deletingPdo.id)}
          >
            Hapus PDO
          </Button>
        </div>
      </Modal>

      {/* Modal Tutup PDO */}
      <Modal
        open={!!closingPdo}
        onClose={() => setClosingPdo(null)}
        title={`Tutup PDO: ${closingPdo?.pdo_number}`}
      >
        <p className="text-sm text-muted mb-4">
          Menutup PDO akan mencegah input realisasi dan transfer baru. Tindakan ini tidak dapat dibatalkan.
        </p>
        <div className="flex flex-col gap-3">
          <div>
            <label className="block text-[12px] font-[850] text-muted mb-1.5">Tanggal Penutupan</label>
            <input
              type="date"
              className="input-base"
              value={closeDate}
              min={new Date().toISOString().split('T')[0]}
              onChange={(e) => setCloseDate(e.target.value)}
            />
          </div>
          <div>
            <label className="block text-[12px] font-[850] text-muted mb-1.5">Catatan (opsional)</label>
            <textarea
              className="input-base resize-none"
              rows={3}
              value={closeNotes}
              onChange={(e) => setCloseNotes(e.target.value)}
            />
          </div>
        </div>
        <div className="flex justify-end gap-2 mt-5">
          <Button variant="secondary" onClick={() => setClosingPdo(null)}>Batal</Button>
          <Button variant="danger" loading={closePdo.isPending} onClick={handleClose}>
            Tutup PDO
          </Button>
        </div>
      </Modal>
    </div>
  )
}
