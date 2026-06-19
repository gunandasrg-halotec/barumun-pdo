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
import { Plus } from 'lucide-react'
import type { ApiResponse, TransferEntry, PdoHeader, PdoDetail } from '@/types'

const schema = z.object({
  pdo_header_id:    z.string().uuid('Pilih PDO'),
  pdo_detail_id:    z.string().uuid('Pilih item biaya'),
  transfer_date:    z.string().min(1, 'Tanggal wajib diisi'),
  amount:           z.coerce.number().min(1, 'Jumlah harus > 0'),
  reference_number: z.string().min(1, 'No. referensi wajib diisi'),
  notes:            z.string().nullable().optional(),
})

type Form = z.infer<typeof schema>

export function TransferPage() {
  const toast = useToastStore((s) => s.push)
  const qc    = useQueryClient()
  const [open, setOpen] = useState(false)

  const { data: transfers, isLoading } = useQuery({
    queryKey: ['transfers'],
    queryFn: async () => {
      const res = await api.get<ApiResponse<TransferEntry[]>>('/transfer-entries')
      return res.data.data
    },
  })

  const { data: pdoList } = useQuery({
    queryKey: ['pdo-final'],
    queryFn: async () => {
      const res = await api.get<ApiResponse<PdoHeader[]>>('/pdo', { params: { status: 'final' } })
      return res.data.data
    },
  })

  const { register, handleSubmit, watch, reset, formState: { errors } } = useForm<Form>({
    resolver: zodResolver(schema),
    defaultValues: { transfer_date: new Date().toISOString().split('T')[0] },
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
      return api.post('/transfer-entries', payload)
    },
    onSuccess: () => {
      toast('Transfer berhasil dicatat')
      qc.invalidateQueries({ queryKey: ['transfers'] })
      setOpen(false)
      reset()
    },
    onError: () => toast('Gagal menyimpan transfer', 'error'),
  })

  return (
    <div>
      <div className="flex items-start justify-between mb-6">
        <div>
          <h2 className="text-[28px] font-[950] text-ink">Transfer Dana</h2>
          <p className="text-muted text-sm mt-1">Pencatatan transfer dana dari kantor pusat ke unit kebun.</p>
        </div>
        <Button onClick={() => setOpen(true)}>
          <Plus className="w-4 h-4" /> Catat Transfer
        </Button>
      </div>

      <div className="overflow-auto border border-line rounded-drawer bg-white">
        <table className="w-full border-collapse" style={{ minWidth: 840 }}>
          <thead>
            <tr>
              {['No. Referensi', 'Item PDO', 'Tanggal', 'Jumlah', 'Sumber', 'Dicatat Oleh', 'Catatan'].map((h) => (
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
                  {Array.from({ length: 7 }).map((__, j) => (
                    <td key={j} className="px-4 py-3">
                      <div className="h-4 bg-[#f0f4f0] rounded animate-pulse" />
                    </td>
                  ))}
                </tr>
              ))
            ) : !transfers?.length ? (
              <tr><td colSpan={7} className="p-8"><EmptyState /></td></tr>
            ) : transfers.map((t) => (
              <tr key={t.id} className="border-t border-line hover:bg-[#fbfdfb]">
                <td className="px-4 py-3 font-bold text-sm">{t.reference_number}</td>
                <td className="px-4 py-3 text-sm">{t.pdo_detail_id}</td>
                <td className="px-4 py-3 text-sm">{fmtDate(t.transfer_date)}</td>
                <td className="px-4 py-3 text-sm font-bold">{fmt(t.amount)}</td>
                <td className="px-4 py-3 text-sm">
                  <span className={`badge ${t.entry_source === 'system' ? 'badge-approved' : 'badge-draft'}`}>
                    {t.entry_source === 'system' ? 'Sistem' : 'Manual'}
                  </span>
                </td>
                <td className="px-4 py-3 text-sm">{t.recorder?.full_name ?? '—'}</td>
                <td className="px-4 py-3 text-sm text-muted">{t.notes ?? '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <Modal open={open} onClose={() => { setOpen(false); reset() }} title="Catat Transfer Dana">
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
                  {d.expense_item?.name ?? d.description} — Sisa: {fmt(d.amount - (d.total_transferred ?? 0))}
                </option>
              ))}
            </select>
            {errors.pdo_detail_id && <p className="field-error">{errors.pdo_detail_id.message}</p>}
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="label">Tanggal Transfer</label>
              <input type="date" {...register('transfer_date')} className="input-base" />
              {errors.transfer_date && <p className="field-error">{errors.transfer_date.message}</p>}
            </div>
            <div>
              <label className="label">Jumlah (Rp)</label>
              <input type="number" {...register('amount')} className="input-base" />
              {errors.amount && <p className="field-error">{errors.amount.message}</p>}
            </div>
          </div>

          <div>
            <label className="label">No. Referensi / No. Transfer</label>
            <input {...register('reference_number')} className="input-base" placeholder="TRF/2026/001" />
            {errors.reference_number && <p className="field-error">{errors.reference_number.message}</p>}
          </div>

          <div>
            <label className="label">Catatan (opsional)</label>
            <input {...register('notes')} className="input-base" />
          </div>

          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="secondary" onClick={() => { setOpen(false); reset() }}>Batal</Button>
            <Button type="submit" loading={save.isPending}>Simpan</Button>
          </div>
        </form>
      </Modal>
    </div>
  )
}
