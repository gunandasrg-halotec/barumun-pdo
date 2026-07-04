import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { Button } from '@/components/ui/Button'
import { Modal } from '@/components/ui/Modal'
import { EmptyState } from '@/components/ui/EmptyState'
import { useToastStore } from '@/store/toast.store'
import { fmt, fmtDate } from '@/lib/format'
import { Upload, AlertCircle } from 'lucide-react'
import type { ApiResponse, RealizationEntry } from '@/types'

const PAYMENT_LABEL: Record<string, string> = {
  tunai: 'Tunai', transfer: 'Transfer Bank',
}

const FUNDING_LABEL: Record<string, string> = {
  kas_kebun: 'Kas Kebun', rekening_kebun: 'Rekening Kebun', rekening_utama: 'Rekening Utama',
}

export function RealizationPage() {
  const toast = useToastStore((s) => s.push)
  const qc    = useQueryClient()
  const [uploadId, setUploadId] = useState<string | null>(null)
  const [file, setFile]         = useState<File | null>(null)

  const { data: realizations, isLoading } = useQuery({
    queryKey: ['realizations'],
    queryFn: async () => {
      const res = await api.get<ApiResponse<RealizationEntry[]>>('/realization-entries')
      return res.data.data
    },
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

  return (
    <div>
      <div className="flex items-start justify-between mb-6">
        <div>
          <h2 className="text-[28px] font-[950] text-ink">Realisasi Biaya</h2>
          <p className="text-muted text-sm mt-1">Daftar semua realisasi pengeluaran yang sudah dicatat.</p>
        </div>
      </div>

      <div className="overflow-auto border border-line rounded-drawer bg-white">
        <table className="w-full border-collapse" style={{ minWidth: 900 }}>
          <thead>
            <tr>
              {['No. Ref', 'Item PDO', 'Tanggal', 'Jumlah', 'Metode', 'Sumber Dana', 'Dicatat Oleh', 'Bukti', 'Aksi'].map((h) => (
                <th key={h} className="px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-muted bg-[#f7faf7]">
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {isLoading ? (
              Array.from({ length: 4 }).map((_, i) => (
                <tr key={i}>
                  {Array.from({ length: 9 }).map((__, j) => (
                    <td key={j} className="px-4 py-3">
                      <div className="h-4 bg-[#f0f4f0] rounded animate-pulse" />
                    </td>
                  ))}
                </tr>
              ))
            ) : !realizations?.length ? (
              <tr><td colSpan={9} className="p-8"><EmptyState /></td></tr>
            ) : realizations.map((r) => (
              <tr key={r.id} className="border-t border-line hover:bg-[#fbfdfb]">
                <td className="px-4 py-3 font-bold text-sm">{r.proof_number}</td>
                <td className="px-4 py-3 text-sm">
                  {r.pdo_detail?.expense_item
                    ? [
                        r.pdo_detail.expense_item.subcategory?.category?.name,
                        r.pdo_detail.expense_item.subcategory?.name,
                        r.pdo_detail.expense_item.name,
                      ].filter(Boolean).join(' — ')
                    : r.pdo_detail_id}
                </td>
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
          </tbody>
        </table>
      </div>

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
