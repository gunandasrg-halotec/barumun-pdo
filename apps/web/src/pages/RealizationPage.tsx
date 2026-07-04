import { useState, useMemo } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { Button } from '@/components/ui/Button'
import { Modal } from '@/components/ui/Modal'
import { EmptyState } from '@/components/ui/EmptyState'
import { DateRangePickerButton } from '@/components/ui/DateRangePickerButton'
import { useToastStore } from '@/store/toast.store'
import { fmt, fmtDate } from '@/lib/format'
import { Upload, AlertCircle, Search, ChevronDown, ChevronRight } from 'lucide-react'
import type { ApiResponse, RealizationEntry } from '@/types'

const PAYMENT_LABEL: Record<string, string> = {
  tunai: 'Tunai', transfer: 'Transfer Bank',
}

const FUNDING_LABEL: Record<string, string> = {
  kas_kebun: 'Kas Kebun', rekening_kebun: 'Rekening Kebun', rekening_utama: 'Rekening Utama',
}

const COLS = ['PDO', 'Item PDO', 'No. Ref', 'Tanggal', 'Jumlah', 'Metode', 'Sumber Dana', 'Dicatat Oleh', 'Bukti', 'Aksi']

export function RealizationPage() {
  const toast = useToastStore((s) => s.push)
  const qc    = useQueryClient()
  const [uploadId,  setUploadId]  = useState<string | null>(null)
  const [file,      setFile]      = useState<File | null>(null)
  const [search,    setSearch]    = useState('')
  const [startDate, setStartDate] = useState('')
  const [endDate,   setEndDate]   = useState('')
  const [collapsed, setCollapsed] = useState<Record<string, boolean>>({})

  const hasFilter = !!(search.trim() || startDate || endDate)

  const { data: realizations, isLoading } = useQuery({
    queryKey: ['realizations'],
    queryFn: async () => {
      const res = await api.get<ApiResponse<RealizationEntry[]>>('/realization-entries')
      return res.data.data
    },
    enabled: hasFilter,
  })

  const uploadBukti = useMutation({
    mutationFn: () => {
      if (!file || !uploadId) throw new Error('File atau ID tidak tersedia')
      const fd = new FormData()
      fd.append('file', file)
      return api.post(`/realization-entries/${uploadId}/attachments`, fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
    },
    onSuccess: () => {
      toast('Bukti berhasil diupload')
      qc.invalidateQueries({ queryKey: ['realizations'] })
      setUploadId(null)
      setFile(null)
    },
    onError: () => toast('Gagal upload bukti', 'error'),
  })

  const filtered = useMemo(() => {
    if (!realizations) return []
    const q = search.trim().toLowerCase()
    return realizations.filter((r) => {
      const itemLabel = r.pdo_detail?.expense_item
        ? [
            r.pdo_detail.expense_item.subcategory?.category?.name,
            r.pdo_detail.expense_item.subcategory?.name,
            r.pdo_detail.expense_item.name,
          ].filter(Boolean).join(' ')
        : ''
      const matchSearch = !q ||
        r.proof_number.toLowerCase().includes(q) ||
        itemLabel.toLowerCase().includes(q) ||
        (r.recorder?.full_name ?? '').toLowerCase().includes(q) ||
        (r.pdo_detail?.pdo_header?.pdo_number ?? '').toLowerCase().includes(q)
      const matchDate =
        (!startDate || r.transaction_date >= startDate) &&
        (!endDate   || r.transaction_date <= endDate)
      return matchSearch && matchDate
    })
  }, [realizations, search, startDate, endDate])

  // Group by PDO, preserving order of first appearance
  const groups = useMemo(() => {
    const map = new Map<string, { pdoNumber: string; entries: RealizationEntry[] }>()
    for (const r of filtered) {
      const pdoId     = r.pdo_detail?.pdo_header?.id ?? 'unknown'
      const pdoNumber = r.pdo_detail?.pdo_header?.pdo_number ?? '—'
      if (!map.has(pdoId)) map.set(pdoId, { pdoNumber, entries: [] })
      map.get(pdoId)!.entries.push(r)
    }
    return Array.from(map.entries()).map(([id, v]) => ({ id, ...v }))
  }, [filtered])

  const toggleGroup = (id: string) =>
    setCollapsed((prev) => ({ ...prev, [id]: !prev[id] }))

  return (
    <div>
      <div className="flex items-start justify-between mb-6">
        <div>
          <h2 className="text-[28px] font-[950] text-ink">Realisasi Biaya</h2>
          <p className="text-muted text-sm mt-1">Daftar semua realisasi pengeluaran yang sudah dicatat.</p>
        </div>
      </div>

      {/* Filter Bar */}
      <div className="card mb-5 flex flex-wrap gap-3 items-end">
        <div className="flex items-end">
          <DateRangePickerButton
            startDate={startDate}
            endDate={endDate}
            min=""
            max=""
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
              placeholder="No. PDO, no. ref, item biaya, atau nama pencatat..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          </div>
        </div>
      </div>

      {/* Content */}
      {!hasFilter ? (
        <EmptyState message="Gunakan filter tanggal atau masukkan kata kunci pencarian untuk menampilkan data." />
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
          <table className="w-full border-collapse" style={{ minWidth: 1000 }}>
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
              {groups.map((group) => {
                const isCollapsed = collapsed[group.id] ?? false
                return (
                  <>
                    {/* Group header row */}
                    <tr
                      key={`group-${group.id}`}
                      className="bg-[#f0f7f0] cursor-pointer select-none hover:bg-[#e6f2e6]"
                      onClick={() => toggleGroup(group.id)}
                    >
                      <td colSpan={COLS.length} className="px-4 py-2.5 border-t border-line">
                        <div className="flex items-center gap-2">
                          {isCollapsed
                            ? <ChevronRight className="w-4 h-4 text-green shrink-0" />
                            : <ChevronDown  className="w-4 h-4 text-green shrink-0" />
                          }
                          <span className="font-bold text-sm text-green">{group.pdoNumber}</span>
                          <span className="text-xs text-muted">({group.entries.length} entri)</span>
                        </div>
                      </td>
                    </tr>

                    {/* Entry rows */}
                    {!isCollapsed && group.entries.map((r) => (
                      <tr key={r.id} className="border-t border-line hover:bg-[#fbfdfb]">
                        <td className="px-4 py-3 text-sm text-muted">{group.pdoNumber}</td>
                        <td className="px-4 py-3 text-sm">
                          {r.pdo_detail?.expense_item
                            ? [
                                r.pdo_detail.expense_item.subcategory?.category?.name,
                                r.pdo_detail.expense_item.subcategory?.name,
                                r.pdo_detail.expense_item.name,
                              ].filter(Boolean).join(' — ')
                            : r.pdo_detail_id}
                        </td>
                        <td className="px-4 py-3 font-bold text-sm">{r.proof_number}</td>
                        <td className="px-4 py-3 text-sm">{fmtDate(r.transaction_date)}</td>
                        <td className="px-4 py-3 text-sm font-bold">{fmt(r.amount)}</td>
                        <td className="px-4 py-3 text-sm">{PAYMENT_LABEL[r.payment_method]}</td>
                        <td className="px-4 py-3 text-sm">{FUNDING_LABEL[r.funding_source] ?? r.funding_source}</td>
                        <td className="px-4 py-3 text-sm">{r.recorder?.full_name ?? '—'}</td>
                        <td className="px-4 py-3">
                          {r.attachments?.length ? (
                            <span className="badge badge-approved">{r.attachments.length} file</span>
                          ) : (
                            <span className="flex items-center gap-1 text-xs text-amber-600">
                              <AlertCircle className="w-3 h-3" /> Belum
                            </span>
                          )}
                        </td>
                        <td className="px-4 py-3">
                          <button
                            className="text-sm font-bold text-green hover:underline"
                            onClick={() => setUploadId(r.id)}
                          >
                            Upload Bukti
                          </button>
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

      {/* Modal Upload Bukti */}
      <Modal open={!!uploadId} onClose={() => { setUploadId(null); setFile(null) }} title="Upload Bukti Pembayaran">
        <p className="text-sm text-muted mb-4">
          Upload foto/scan kuitansi, bon, atau bukti transfer. Format: JPG, PNG, PDF. Maks 10 MB.
        </p>
        <div
          className="border-2 border-dashed border-line rounded-drawer p-8 text-center cursor-pointer hover:border-green transition-colors"
          onClick={() => document.getElementById('file-upload-realisasi')?.click()}
        >
          <Upload className="w-8 h-8 text-muted mx-auto mb-2" />
          <p className="text-sm text-muted">
            {file ? file.name : 'Klik atau drag file ke sini'}
          </p>
          <input
            id="file-upload-realisasi"
            type="file"
            accept=".jpg,.jpeg,.png,.pdf"
            className="hidden"
            onChange={(e) => setFile(e.target.files?.[0] ?? null)}
          />
        </div>
        <div className="flex justify-end gap-2 mt-5">
          <Button variant="secondary" onClick={() => { setUploadId(null); setFile(null) }}>Tutup</Button>
          <Button loading={uploadBukti.isPending} disabled={!file} onClick={() => uploadBukti.mutate()}>
            Upload
          </Button>
        </div>
      </Modal>
    </div>
  )
}
