import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { Button } from '@/components/ui/Button'
import { Modal } from '@/components/ui/Modal'
import { EmptyState } from '@/components/ui/EmptyState'
import { useToastStore } from '@/store/toast.store'
import { fmt, fmtDate } from '@/lib/format'
import { Plus, Upload, AlertCircle } from 'lucide-react'
import type { ApiResponse, RealizationEntry, PdoHeader, PdoDetail } from '@/types'

const schema = z.object({
  pdo_header_id:    z.string().uuid('Pilih PDO'),
  pdo_detail_id:    z.string().uuid('Pilih item biaya'),
  transaction_date: z.string().min(1, 'Tanggal wajib diisi'),
  amount:           z.coerce.number().min(1, 'Jumlah harus > 0'),
  payment_method:   z.enum(['tunai', 'transfer', 'kas_kecil']),
  funding_source:   z.enum(['kas_kebun', 'rekening_kebun', 'rekening_utama']),
  reference_number: z.string().min(1, 'No. referensi wajib diisi'),
  explanation:      z.string().nullable().optional(),
})

type Form = z.infer<typeof schema>

export function RealizationPage() {
  const toast = useToastStore((s) => s.push)
  const qc    = useQueryClient()
  const [open, setOpen]           = useState(false)
  const [uploadId, setUploadId]   = useState<string | null>(null)
  const [file, setFile]           = useState<File | null>(null)

  const { data: realizations, isLoading } = useQuery({
    queryKey: ['realizations'],
    queryFn: async () => {
      const res = await api.get<ApiResponse<RealizationEntry[]>>('/realization-entries')
      return res.data.data
    },
  })

  const { data: pdoList } = useQuery({
    queryKey: ['pdo-active'],
    queryFn: async () => {
      const res = await api.get<ApiResponse<PdoHeader[]>>('/pdo', { params: { status: 'final' } })
      return res.data.data
    },
  })

  const { register, handleSubmit, watch, reset, formState: { errors } } = useForm<Form>({
    resolver: zodResolver(schema),
    defaultValues: {
      transaction_date: new Date().toISOString().split('T')[0],
      payment_method: 'tunai',
      funding_source: 'kas_kebun',
    },
  })

  const selectedPdoId = watch('pdo_header_id')

  const { data: pdoDetails } = useQuery({
    queryKey: ['pdo-details', selectedPdoId],
    queryFn: async () => {
      const res = await api.get<ApiResponse<PdoDetail[]>>(`/pdo/${selectedPdoId}/details`)
      return res.data.data
    },
    enabled: !!selectedPdoId,
  })

  const save = useMutation({
    mutationFn: (data: Form) => {
      const { pdo_header_id: _h, ...payload } = data
      return api.post<ApiResponse<RealizationEntry>>('/realization-entries', payload)
    },
    onSuccess: (res) => {
      toast('Realisasi berhasil dicatat')
      qc.invalidateQueries({ queryKey: ['realizations'] })
      const entry = res.data.data
      setOpen(false)
      reset()
      setUploadId(entry.id)
    },
    onError: () => toast('Gagal menyimpan realisasi', 'error'),
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

  const PAYMENT_LABEL: Record<string, string> = {
    tunai: 'Tunai', transfer: 'Transfer Bank', kas_kecil: 'Kas Kecil',
  }

  return (
    <div>
      <div className="flex items-start justify-between mb-6">
        <div>
          <h2 className="text-[28px] font-[950] text-ink">Realisasi Biaya</h2>
          <p className="text-muted text-sm mt-1">Input pengeluaran aktual dan upload bukti pembayaran.</p>
        </div>
        <Button onClick={() => setOpen(true)}>
          <Plus className="w-4 h-4" /> Input Realisasi
        </Button>
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
                <td className="px-4 py-3 font-bold text-sm">{r.reference_number}</td>
                <td className="px-4 py-3 text-sm">{r.pdo_detail_id}</td>
                <td className="px-4 py-3 text-sm">{fmtDate(r.transaction_date)}</td>
                <td className="px-4 py-3 text-sm font-bold">{fmt(r.amount)}</td>
                <td className="px-4 py-3 text-sm">{PAYMENT_LABEL[r.payment_method]}</td>
                <td className="px-4 py-3 text-sm">{r.funding_source}</td>
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

      {/* Modal Input Realisasi */}
      <Modal open={open} onClose={() => { setOpen(false); reset() }} title="Input Realisasi Biaya">
        <form onSubmit={handleSubmit((d) => save.mutate(d))} className="flex flex-col gap-4">
          <div>
            <label className="label">Pilih PDO (status Final)</label>
            <select {...register('pdo_header_id')} className="input-base">
              <option value="">Pilih PDO...</option>
              {pdoList?.map((p) => (
                <option key={p.id} value={p.id}>{p.pdo_number} — {p.plantation_unit?.name}</option>
              ))}
            </select>
            {errors.pdo_header_id && <p className="field-error">{errors.pdo_header_id.message}</p>}
          </div>

          <div>
            <label className="label">Item Biaya</label>
            <select {...register('pdo_detail_id')} className="input-base" disabled={!selectedPdoId}>
              <option value="">Pilih item...</option>
              {pdoDetails?.map((d) => (
                <option key={d.id} value={d.id}>
                  {d.expense_item?.name ?? d.description} — Saldo: {fmt((d.total_transferred ?? 0) - (d.total_realized ?? 0))}
                </option>
              ))}
            </select>
            {errors.pdo_detail_id && <p className="field-error">{errors.pdo_detail_id.message}</p>}
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="label">Tanggal Transaksi</label>
              <input type="date" {...register('transaction_date')} className="input-base" />
            </div>
            <div>
              <label className="label">Jumlah (Rp)</label>
              <input type="number" {...register('amount')} className="input-base" />
              {errors.amount && <p className="field-error">{errors.amount.message}</p>}
            </div>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="label">Metode Pembayaran</label>
              <select {...register('payment_method')} className="input-base">
                <option value="tunai">Tunai</option>
                <option value="transfer">Transfer Bank</option>
                <option value="kas_kecil">Kas Kecil</option>
              </select>
            </div>
            <div>
              <label className="label">Sumber Dana</label>
              <select {...register('funding_source')} className="input-base">
                <option value="kas_kebun">Kas Kebun</option>
                <option value="rekening_kebun">Rekening Kebun</option>
                <option value="rekening_utama">Rekening Utama</option>
              </select>
            </div>
          </div>

          <div>
            <label className="label">No. Referensi / Kuitansi</label>
            <input {...register('reference_number')} className="input-base" placeholder="KWT/2026/001" />
            {errors.reference_number && <p className="field-error">{errors.reference_number.message}</p>}
          </div>

          <div>
            <label className="label">Penjelasan (opsional)</label>
            <input {...register('explanation')} className="input-base" />
          </div>

          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="secondary" onClick={() => { setOpen(false); reset() }}>Batal</Button>
            <Button type="submit" loading={save.isPending}>Simpan Realisasi</Button>
          </div>
        </form>
      </Modal>

      {/* Modal Upload Bukti */}
      <Modal open={!!uploadId && !open} onClose={() => { setUploadId(null); setFile(null) }} title="Upload Bukti Pembayaran">
        <p className="text-sm text-muted mb-4">
          Upload foto/scan kuitansi, bon, atau bukti transfer. Format: JPG, PNG, PDF. Maks 10 MB.
        </p>
        <div
          className="border-2 border-dashed border-line rounded-drawer p-8 text-center cursor-pointer hover:border-green transition-colors"
          onClick={() => document.getElementById('file-upload')?.click()}
        >
          <Upload className="w-8 h-8 text-muted mx-auto mb-2" />
          <p className="text-sm text-muted">
            {file ? file.name : 'Klik atau drag file ke sini'}
          </p>
          <input
            id="file-upload"
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
