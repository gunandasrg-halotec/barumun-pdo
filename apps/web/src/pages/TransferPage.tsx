import { useEffect, useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { api } from '@/lib/api'
import { Button } from '@/components/ui/Button'
import { Modal } from '@/components/ui/Modal'
import { EmptyState } from '@/components/ui/EmptyState'
import { useToastStore } from '@/store/toast.store'
import { fmt, fmtDate } from '@/lib/format'
import { Plus, FileText } from 'lucide-react'
import type { ApiResponse, PdoHeader, PdoDetail } from '@/types'

// ─── types ────────────────────────────────────────────────────────────────────

interface PdoSummaryRow {
  pdo_id:                  string
  pdo_number:              string
  plantation_unit:         { id: string; code: string; name: string } | null
  period_month:            number
  period_year:             number
  notes:                   string | null
  total_amount:            number
  transferred_rek_kebun:   number
  transferred_pribadi:     number
  transferred_vendor:      number
  total_transferred:       number
  remaining:               number
  last_transfer_date:      string | null
}

// ─── schema single entry ───────────────────────────────────────────────────────

const schema = z.object({
  pdo_header_id:    z.string().uuid('Pilih PDO'),
  pdo_detail_id:    z.string().uuid('Pilih item biaya'),
  transfer_date:    z.string().min(1, 'Tanggal wajib diisi'),
  amount:           z.coerce.number().min(1, 'Jumlah harus > 0'),
  reference_number: z.string().optional().nullable(),
  notes:            z.string().optional().nullable(),
})

type Form = z.infer<typeof schema>

const MONTHS = [
  '', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
  'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
]

// ─── component ────────────────────────────────────────────────────────────────

export function TransferPage() {
  const toast    = useToastStore((s) => s.push)
  const qc       = useQueryClient()
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)
  const [maxAmount, setMaxAmount] = useState<number>(0)

  // list PDO final dengan ringkasan transfer
  const { data: pdoSummary, isLoading } = useQuery({
    queryKey: ['transfer-pdo-summary'],
    queryFn: async () => {
      const res = await api.get<ApiResponse<PdoSummaryRow[]>>('/transfer-entries/pdo-summary')
      return res.data.data
    },
  })

  // list PDO final untuk dropdown di modal
  const { data: pdoList } = useQuery({
    queryKey: ['pdo-final'],
    queryFn: async () => {
      const res = await api.get<ApiResponse<PdoHeader[]>>('/pdo', { params: { status: 'final' } })
      return res.data.data
    },
    enabled: open,
  })

  const {
    register,
    handleSubmit,
    watch,
    reset,
    setValue,
    formState: { errors },
  } = useForm<Form>({
    resolver: zodResolver(schema),
    defaultValues: { transfer_date: new Date().toISOString().split('T')[0] },
  })

  const selectedPdoId    = watch('pdo_header_id')
  const selectedDetailId = watch('pdo_detail_id')

  // fetch detail items saat PDO dipilih
  const { data: pdoDetails } = useQuery({
    queryKey: ['pdo-details', selectedPdoId],
    queryFn: async () => {
      const res = await api.get<ApiResponse<PdoDetail[]>>(`/pdo/${selectedPdoId}/details`)
      return res.data.data
    },
    enabled: !!selectedPdoId,
  })

  // auto-fill amount = sisa dana saat item dipilih
  useEffect(() => {
    if (!selectedDetailId || !pdoDetails) return
    const detail = pdoDetails.find((d) => d.id === selectedDetailId)
    if (!detail) return
    const remaining = detail.amount - (detail.total_transferred ?? 0)
    setMaxAmount(remaining)
    setValue('amount', remaining, { shouldValidate: false })
  }, [selectedDetailId, pdoDetails, setValue])

  // reset detail & amount saat PDO berubah
  useEffect(() => {
    setValue('pdo_detail_id', '' as any)
    setValue('amount', 0 as any)
    setMaxAmount(0)
  }, [selectedPdoId, setValue])

  const sisaDana = (() => {
    if (!selectedDetailId || !pdoDetails) return null
    const detail = pdoDetails.find((d) => d.id === selectedDetailId)
    if (!detail) return null
    return detail.amount - (detail.total_transferred ?? 0)
  })()

  const save = useMutation({
    mutationFn: (data: Form) => {
      const { pdo_header_id: _h, pdo_detail_id, ...payload } = data
      return api.post(`/pdo-details/${pdo_detail_id}/transfers`, payload)
    },
    onSuccess: () => {
      toast('Transfer berhasil dicatat')
      qc.invalidateQueries({ queryKey: ['transfer-pdo-summary'] })
      setOpen(false)
      reset()
    },
    onError: (err: any) => {
      const msg = err?.response?.data?.error?.message ?? 'Gagal menyimpan transfer'
      toast(msg, 'error')
    },
  })

  const handleClose = () => { setOpen(false); reset(); setMaxAmount(0) }

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

      {/* ─── Tabel PDO-level ─────────────────────────────────────── */}
      <div className="overflow-auto border border-line rounded-drawer bg-white">
        <table className="w-full border-collapse" style={{ minWidth: 960 }}>
          <thead>
            <tr>
              {['No. Referensi PDO', 'Unit Kebun', 'Periode', 'Tgl. Terakhir Transfer', 'Total Pengajuan', 'Rek. Kebun', 'Pribadi', 'Vendor', 'Total Transfer', 'Catatan', ''].map((h) => (
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
                  {Array.from({ length: 11 }).map((__, j) => (
                    <td key={j} className="px-4 py-3">
                      <div className="h-4 bg-[#f0f4f0] rounded animate-pulse" />
                    </td>
                  ))}
                </tr>
              ))
            ) : !pdoSummary?.length ? (
              <tr><td colSpan={11} className="p-8"><EmptyState /></td></tr>
            ) : pdoSummary.map((row) => (
              <tr key={row.pdo_id} className="border-t border-line hover:bg-[#fbfdfb]">
                <td className="px-4 py-3 font-bold text-sm">{row.pdo_number}</td>
                <td className="px-4 py-3 text-sm">{row.plantation_unit?.name ?? '—'}</td>
                <td className="px-4 py-3 text-sm">{MONTHS[row.period_month]} {row.period_year}</td>
                <td className="px-4 py-3 text-sm">{row.last_transfer_date ? fmtDate(row.last_transfer_date) : '—'}</td>
                <td className="px-4 py-3 text-sm">{fmt(row.total_amount)}</td>
                <td className="px-4 py-3 text-sm">{row.transferred_rek_kebun > 0 ? fmt(row.transferred_rek_kebun) : '—'}</td>
                <td className="px-4 py-3 text-sm">{row.transferred_pribadi > 0 ? fmt(row.transferred_pribadi) : '—'}</td>
                <td className="px-4 py-3 text-sm">{row.transferred_vendor > 0 ? fmt(row.transferred_vendor) : '—'}</td>
                <td className="px-4 py-3 text-sm font-bold">{fmt(row.total_transferred)}</td>
                <td className="px-4 py-3 text-sm text-muted">{row.notes ?? '—'}</td>
                <td className="px-4 py-3">
                  <Button
                    variant="secondary"
                    size="sm"
                    onClick={() => navigate(`/transfer/${row.pdo_id}`)}
                  >
                    <FileText className="w-4 h-4" /> Detail
                  </Button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* ─── Modal Single Entry ───────────────────────────────────── */}
      <Modal open={open} onClose={handleClose} title="Catat Transfer Dana">
        <form onSubmit={handleSubmit((d) => {
          if (maxAmount > 0 && Number(d.amount) > maxAmount) {
            toast(`Jumlah melebihi sisa dana (${fmt(maxAmount)})`, 'error')
            return
          }
          save.mutate(d)
        })} className="flex flex-col gap-4">

          {/* PDO */}
          <div>
            <label className="label">Pilih PDO (status Final)</label>
            <select {...register('pdo_header_id')} className="input-base">
              <option value="">Pilih PDO...</option>
              {pdoList?.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.pdo_number} — {p.plantation_unit?.name}
                </option>
              ))}
            </select>
            {errors.pdo_header_id && <p className="field-error">{errors.pdo_header_id.message}</p>}
          </div>

          {/* Item Biaya */}
          <div>
            <label className="label">Item Biaya</label>
            <select {...register('pdo_detail_id')} className="input-base" disabled={!selectedPdoId}>
              <option value="">Pilih item...</option>
              {pdoDetails?.map((d) => (
                <option key={d.id} value={d.id}>
                  {d.expense_item?.name ?? d.description}
                </option>
              ))}
            </select>
            {errors.pdo_detail_id && <p className="field-error">{errors.pdo_detail_id.message}</p>}
          </div>

          {/* Sisa Dana (read-only) */}
          {sisaDana !== null && (
            <div>
              <label className="label">Sisa Dana</label>
              <input
                type="text"
                readOnly
                value={fmt(sisaDana)}
                className="input-base bg-[#f7faf7] text-muted cursor-not-allowed"
              />
            </div>
          )}

          <div className="grid grid-cols-1 desk:grid-cols-2 gap-3">
            <div>
              <label className="label">Tanggal Transfer</label>
              <input type="date" {...register('transfer_date')} className="input-base" />
              {errors.transfer_date && <p className="field-error">{errors.transfer_date.message}</p>}
            </div>
            <div>
              <label className="label">Jumlah (Rp)</label>
              <input
                type="number"
                {...register('amount')}
                className="input-base"
                max={maxAmount || undefined}
              />
              {errors.amount && <p className="field-error">{errors.amount.message}</p>}
            </div>
          </div>

          <div>
            <label className="label">No. Referensi / No. Transfer (opsional)</label>
            <input {...register('reference_number')} className="input-base" placeholder="TRF/2026/001" />
          </div>

          <div>
            <label className="label">Catatan (opsional)</label>
            <input {...register('notes')} className="input-base" />
          </div>

          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="secondary" onClick={handleClose}>Batal</Button>
            <Button type="submit" loading={save.isPending}>Simpan</Button>
          </div>
        </form>
      </Modal>
    </div>
  )
}
