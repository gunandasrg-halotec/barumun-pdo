import { useState, useMemo } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { Button } from '@/components/ui/Button'
import { Modal } from '@/components/ui/Modal'
import { EmptyState } from '@/components/ui/EmptyState'
import { DateRangePickerButton } from '@/components/ui/DateRangePickerButton'
import { UnitMultiSelectButton } from '@/components/ui/UnitMultiSelectButton'
import { useAuthStore } from '@/store/auth.store'
import { useToastStore } from '@/store/toast.store'
import { fmt, fmtDate } from '@/lib/format'
import { Upload, AlertCircle, AlertTriangle, Search, ChevronDown, ChevronRight, FileSpreadsheet } from 'lucide-react'
import type { ApiResponse, PlantationUnit, RealizationEntry } from '@/types'

// Role yang boleh export realisasi ke jurnal umum — mirror User::canExportJournal() di backend.
const JOURNAL_EXPORT_ROLES = ['STAFF_KEUANGAN', 'MANAJER_KEUANGAN', 'DIREKTUR_KEUANGAN']

const PAYMENT_LABEL: Record<string, string> = {
  tunai: 'Tunai', transfer: 'Transfer Bank',
}

const FUNDING_LABEL: Record<string, string> = {
  kas_kebun: 'Kas Kebun', rekening_kebun: 'Rekening Kebun', rekening_utama: 'Rekening Utama',
}

const COLS = ['', 'PDO', 'Item PDO', 'No. Ref', 'Tanggal', 'Jumlah', 'Metode', 'Sumber Dana', 'Dicatat Oleh', 'Bukti', 'Status Jurnal', 'Aksi']

interface JournalRowSide {
  account_code: string | null
  description: string
  debit: number | null
  credit: number | null
}

interface JournalRow {
  realization_entry_id: string
  transaction_number: string
  transaction_date: string
  debit_row: JournalRowSide
  credit_row: JournalRowSide
  memo: string
  tag: string
  already_exported: boolean
  already_exported_at: string | null
}

interface Stage2DebitRow {
  account_code: string | null
  description: string
  debit: number
}

interface Stage2Posting {
  tag: string
  debit_rows: Stage2DebitRow[]
  credit_row: JournalRowSide
}

interface Stage2Row {
  realization_entry_id: string
  transaction_number: string
  transaction_date: string
  memo: string
  postings: Stage2Posting[]
}

export function RealizationPage() {
  const user  = useAuthStore((s) => s.user)
  const toast = useToastStore((s) => s.push)
  const qc    = useQueryClient()
  const [uploadId,   setUploadId]  = useState<string | null>(null)
  const [file,       setFile]      = useState<File | null>(null)
  const [search,     setSearch]    = useState('')
  const [startDate,  setStartDate] = useState('')
  const [endDate,    setEndDate]   = useState('')
  const [collapsed,  setCollapsed] = useState<Record<string, boolean>>({})
  const [unitIds,    setUnitIds]   = useState<string[]>([])
  const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set())
  const [previewRows, setPreviewRows] = useState<JournalRow[] | null>(null)
  const [stage2Rows,  setStage2Rows]  = useState<Stage2Row[]>([])
  const [stage2SkippedIds, setStage2SkippedIds] = useState<string[]>([])
  const [includeInventoryUsage, setIncludeInventoryUsage] = useState(false)
  const [showPreview,  setShowPreview] = useState(false)

  const isHO = !user?.plantation_unit?.id
  const canExportJournal = !!user?.role?.code && JOURNAL_EXPORT_ROLES.includes(user.role.code)

  const { data: units } = useQuery({
    queryKey: ['plantation-units'],
    queryFn: async () => {
      const res = await api.get<ApiResponse<PlantationUnit[]>>('/plantation-units')
      return res.data.data
    },
    enabled: isHO,
  })

  const hasFilter = !!(search.trim() || startDate || endDate || unitIds.length)

  const { data: realizations, isLoading } = useQuery({
    queryKey: ['realizations', unitIds],
    queryFn: async () => {
      const params = new URLSearchParams()
      unitIds.forEach((id) => params.append('unit_ids[]', id))
      const res = await api.get<ApiResponse<RealizationEntry[]>>('/realization-entries', { params })
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

  const previewJournal = useMutation({
    mutationFn: async () => {
      const res = await api.post<ApiResponse<{
        rows: JournalRow[]
        stage2_rows: Stage2Row[]
        stage2_skipped_entry_ids: string[]
      }>>('/realization-entries/export-journal', {
        entry_ids: Array.from(selectedIds),
        preview: true,
        include_inventory_usage: includeInventoryUsage,
      })
      return res.data.data
    },
    onSuccess: (data) => {
      setPreviewRows(data.rows)
      setStage2Rows(data.stage2_rows ?? [])
      setStage2SkippedIds(data.stage2_skipped_entry_ids ?? [])
      setShowPreview(true)
    },
    onError: () => toast('Gagal memuat preview jurnal', 'error'),
  })

  const downloadJournal = useMutation({
    mutationFn: async () => {
      const res = await api.post('/realization-entries/export-journal', {
        entry_ids: Array.from(selectedIds),
        preview: false,
        include_inventory_usage: includeInventoryUsage,
      }, { responseType: 'blob' })
      return res.data
    },
    onSuccess: (blob) => {
      const url  = URL.createObjectURL(new Blob([blob]))
      const a    = document.createElement('a')
      a.href     = url
      a.download = `JurnalUmum-${new Date().toISOString().slice(0, 10)}.csv`
      a.click()
      URL.revokeObjectURL(url)
      toast('CSV jurnal berhasil diunduh')
      setShowPreview(false)
      setPreviewRows(null)
      setStage2Rows([])
      setStage2SkippedIds([])
      setSelectedIds(new Set())
      qc.invalidateQueries({ queryKey: ['realizations'] })
    },
    onError: () => toast('Gagal mengunduh CSV jurnal', 'error'),
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

  const toggleSelect = (id: string) => {
    setSelectedIds((prev) => {
      const next = new Set(prev)
      next.has(id) ? next.delete(id) : next.add(id)
      return next
    })
  }

  const allVisibleIds = filtered.map((r) => r.id)
  const allSelected = allVisibleIds.length > 0 && allVisibleIds.every((id) => selectedIds.has(id))

  const toggleSelectAll = () => {
    setSelectedIds(() => {
      if (allSelected) return new Set()
      return new Set(allVisibleIds)
    })
  }

  return (
    <div>
      <div className="flex items-start justify-between mb-6">
        <div>
          <h2 className="text-[28px] font-[950] text-ink">Realisasi Biaya</h2>
          <p className="text-muted text-sm mt-1">Daftar semua realisasi pengeluaran yang sudah dicatat.</p>
        </div>
        {canExportJournal && (
          <div className="flex flex-col items-end gap-2">
            <label className="flex items-center gap-2 text-sm cursor-pointer select-none">
              <input
                type="checkbox"
                checked={includeInventoryUsage}
                onChange={(e) => setIncludeInventoryUsage(e.target.checked)}
                className="rounded"
              />
              Sertakan jurnal pemakaian persediaan
            </label>
            <Button
              disabled={selectedIds.size === 0}
              loading={previewJournal.isPending}
              onClick={() => previewJournal.mutate()}
            >
              <FileSpreadsheet className="w-4 h-4" /> Export ke Jurnal Sementara
              {selectedIds.size > 0 && ` (${selectedIds.size})`}
            </Button>
          </div>
        )}
      </div>

      {/* Filter Bar */}
      <div className="card mb-5 flex flex-wrap gap-3 items-end">
        {isHO && units && (
          <div className="flex items-end">
            <UnitMultiSelectButton
              units={units}
              selected={unitIds}
              onChange={setUnitIds}
            />
          </div>
        )}
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
                {COLS.map((h, i) => (
                  <th key={h || i} className="px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-muted bg-[#f7faf7] border-b border-line">
                    {i === 0 ? (
                      <input
                        type="checkbox"
                        checked={allSelected}
                        onChange={toggleSelectAll}
                      />
                    ) : h}
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
                        <td className="px-4 py-3">
                          <input
                            type="checkbox"
                            checked={selectedIds.has(r.id)}
                            onChange={() => toggleSelect(r.id)}
                          />
                        </td>
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
                          {r.exported_to_journal_at ? (
                            <span className="badge badge-approved">Sudah di-export {fmtDate(r.exported_to_journal_at)}</span>
                          ) : (
                            <span className="badge badge-draft">Belum</span>
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

      {/* Modal Preview Jurnal */}
      <Modal
        open={showPreview}
        onClose={() => { setShowPreview(false); setPreviewRows(null); setStage2Rows([]); setStage2SkippedIds([]) }}
        title="Preview Export Jurnal Umum"
        width="w-[95vw] max-w-[1400px]"
      >
        {previewRows && previewRows.some((r) => r.already_exported) && (
          <div className="flex items-center gap-2 mb-3 px-3 py-2.5 rounded-card bg-amber-50 border border-amber-200 text-sm text-amber-800">
            <AlertTriangle className="w-4 h-4 shrink-0" />
            {previewRows.filter((r) => r.already_exported).length} item sudah pernah di-export sebelumnya.
          </div>
        )}

        <div className="border border-line rounded-card overflow-y-auto overflow-x-auto max-h-[60vh]">
          <table className="w-full border-collapse text-sm" style={{ minWidth: 900 }}>
            <thead>
              <tr>
                {['No. Transaksi', 'Tanggal', 'Kode Akun', 'Deskripsi', 'Debit', 'Credit', 'Memo', 'Tag'].map((h) => (
                  <th key={h} className="px-3 py-2.5 text-left text-[11px] font-bold uppercase tracking-wider text-muted bg-[#f7faf7] sticky top-0 z-10">
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {previewRows?.map((row) => (
                <>
                  <tr key={`${row.realization_entry_id}-debit`} className="border-t border-line">
                    <td className="px-3 py-2 font-bold whitespace-nowrap" rowSpan={2}>{row.transaction_number}</td>
                    <td className="px-3 py-2 whitespace-nowrap" rowSpan={2}>{row.transaction_date}</td>
                    <td className={`px-3 py-2 whitespace-nowrap ${!row.debit_row.account_code ? 'bg-amber-50' : ''}`}>
                      {row.debit_row.account_code || (
                        <span className="flex items-center gap-1 text-amber-700">
                          <AlertTriangle className="w-3 h-3" /> Kosong
                        </span>
                      )}
                    </td>
                    <td className="px-3 py-2">{row.debit_row.description}</td>
                    <td className="px-3 py-2 text-right font-bold whitespace-nowrap">{fmt(row.debit_row.debit ?? 0)}</td>
                    <td className="px-3 py-2 text-right whitespace-nowrap">—</td>
                    <td className="px-3 py-2" rowSpan={2}>{row.memo}</td>
                    <td className="px-3 py-2 whitespace-nowrap" rowSpan={2}>{row.tag}</td>
                  </tr>
                  <tr key={`${row.realization_entry_id}-credit`} className="border-t border-dashed border-line">
                    <td className={`px-3 py-2 whitespace-nowrap ${!row.credit_row.account_code ? 'bg-amber-50' : ''}`}>
                      {row.credit_row.account_code || (
                        <span className="flex items-center gap-1 text-amber-700">
                          <AlertTriangle className="w-3 h-3" /> Kosong
                        </span>
                      )}
                    </td>
                    <td className="px-3 py-2">{row.credit_row.description}</td>
                    <td className="px-3 py-2 text-right whitespace-nowrap">—</td>
                    <td className="px-3 py-2 text-right font-bold whitespace-nowrap">{fmt(row.credit_row.credit ?? 0)}</td>
                  </tr>
                </>
              ))}
            </tbody>
          </table>
        </div>

        {stage2Rows.length > 0 && (
          <div className="mt-6">
            <h4 className="text-sm font-bold mb-2">Jurnal Pemakaian Persediaan (Tahap 2)</h4>
            <div className="border border-line rounded-card overflow-y-auto overflow-x-auto max-h-[40vh]">
              <table className="w-full border-collapse text-sm" style={{ minWidth: 900 }}>
                <thead>
                  <tr>
                    {['No. Transaksi', 'Tanggal', 'Kode Akun', 'Deskripsi', 'Debit', 'Credit', 'Memo', 'Tag'].map((h) => (
                      <th key={h} className="px-3 py-2.5 text-left text-[11px] font-bold uppercase tracking-wider text-muted bg-[#f7faf7] sticky top-0 z-10">
                        {h}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {stage2Rows.map((row) =>
                    row.postings.map((posting, postingIdx) => {
                      const spanLen = posting.debit_rows.length + 1
                      return (
                        <>
                          {posting.debit_rows.map((dr, idx) => (
                            <tr key={`${row.realization_entry_id}-s2-${postingIdx}-debit-${idx}`} className="border-t border-line">
                              {idx === 0 && postingIdx === 0 && (
                                <>
                                  <td className="px-3 py-2 font-bold whitespace-nowrap" rowSpan={row.postings.reduce((n, p) => n + p.debit_rows.length + 1, 0)}>{row.transaction_number}</td>
                                  <td className="px-3 py-2 whitespace-nowrap" rowSpan={row.postings.reduce((n, p) => n + p.debit_rows.length + 1, 0)}>{row.transaction_date}</td>
                                </>
                              )}
                              <td className={`px-3 py-2 whitespace-nowrap ${!dr.account_code ? 'bg-amber-50' : ''}`}>
                                {dr.account_code || (
                                  <span className="flex items-center gap-1 text-amber-700">
                                    <AlertTriangle className="w-3 h-3" /> Kosong
                                  </span>
                                )}
                              </td>
                              <td className="px-3 py-2">{dr.description}</td>
                              <td className="px-3 py-2 text-right font-bold whitespace-nowrap">{fmt(dr.debit)}</td>
                              <td className="px-3 py-2 text-right whitespace-nowrap">—</td>
                              {idx === 0 && postingIdx === 0 && (
                                <td className="px-3 py-2" rowSpan={row.postings.reduce((n, p) => n + p.debit_rows.length + 1, 0)}>{row.memo}</td>
                              )}
                              {idx === 0 && (
                                <td className="px-3 py-2 whitespace-nowrap" rowSpan={spanLen}>{posting.tag}</td>
                              )}
                            </tr>
                          ))}
                          <tr key={`${row.realization_entry_id}-s2-${postingIdx}-credit`} className="border-t border-dashed border-line">
                            <td className={`px-3 py-2 whitespace-nowrap ${!posting.credit_row.account_code ? 'bg-amber-50' : ''}`}>
                              {posting.credit_row.account_code || (
                                <span className="flex items-center gap-1 text-amber-700">
                                  <AlertTriangle className="w-3 h-3" /> Kosong
                                </span>
                              )}
                            </td>
                            <td className="px-3 py-2">{posting.credit_row.description}</td>
                            <td className="px-3 py-2 text-right whitespace-nowrap">—</td>
                            <td className="px-3 py-2 text-right font-bold whitespace-nowrap">{fmt(posting.credit_row.credit ?? 0)}</td>
                          </tr>
                        </>
                      )
                    })
                  )}
                </tbody>
              </table>
            </div>
          </div>
        )}

        {stage2SkippedIds.length > 0 && (
          <div className="flex items-start gap-2 mt-4 px-3 py-2.5 rounded-card bg-amber-50 border border-amber-200 text-sm text-amber-800">
            <AlertTriangle className="w-4 h-4 shrink-0 mt-0.5" />
            <span>
              <strong>{stage2SkippedIds.length} realisasi</strong> dilewati karena belum ada log trip kendaraan untuk PDO ini.
              Jurnal tahap 1 (persediaan atas kas) tetap di-generate normal.
            </span>
          </div>
        )}

        <div className="flex justify-end gap-2 mt-5">
          <Button variant="secondary" onClick={() => { setShowPreview(false); setPreviewRows(null); setStage2Rows([]); setStage2SkippedIds([]) }}>Batal</Button>
          <Button loading={downloadJournal.isPending} onClick={() => downloadJournal.mutate()}>
            Unduh CSV
          </Button>
        </div>
      </Modal>
    </div>
  )
}
