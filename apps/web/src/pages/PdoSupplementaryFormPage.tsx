import { useEffect, useRef } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useForm, useFieldArray } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { Button } from '@/components/ui/Button'
import { useToastStore } from '@/store/toast.store'
import { useItems } from '@/hooks/useMasterData'
import { fmt } from '@/lib/format'
import { ArrowLeft, Plus, Trash2 } from 'lucide-react'
import type { ApiResponse, PdoHeader, PdoSupplementaryHeader } from '@/types'

const detailSchema = z.object({
  id:              z.string().uuid().optional(),   // present when editing an existing detail
  expense_item_id: z.string().uuid('Pilih item'),
  description:     z.string().min(1, 'Deskripsi wajib diisi'),
  quantity:        z.coerce.number().nullable().optional(),
  unit:            z.string().nullable().optional(),
  rate:            z.coerce.number().nullable().optional(),
  amount:          z.coerce.number().min(1, 'Jumlah harus > 0'),
  notes:           z.string().nullable().optional(),
  justification:   z.string().min(5, 'Justifikasi wajib diisi (min. 5 karakter)'),
  display_order:   z.coerce.number().int().default(0),
})

const schema = z.object({
  parent_pdo_header_id: z.string().uuid('Pilih PDO Induk'),
  notes:                z.string().nullable().optional(),
  details:              z.array(detailSchema).min(1, 'Minimal 1 item biaya'),
})

type Form = z.infer<typeof schema>

export function PdoSupplementaryFormPage() {
  const { id }   = useParams<{ id: string }>()
  const navigate = useNavigate()
  const toast    = useToastStore((s) => s.push)
  const qc       = useQueryClient()
  const isEdit   = !!id

  const { data: pdoList } = useQuery({
    queryKey: ['pdo-approved'],
    queryFn: async () => {
      const res = await api.get<ApiResponse<PdoHeader[]>>('/pdo', { params: { status: 'final' } })
      return res.data.data
    },
  })

  const { data: items } = useItems({ is_active: true })

  const { data: existing } = useQuery({
    queryKey: ['pdo-supplementary', id],
    queryFn: async () => {
      const res = await api.get<ApiResponse<PdoSupplementaryHeader>>(`/pdo-supplementary/${id}`)
      return res.data.data
    },
    enabled: isEdit,
  })

  const { register, control, handleSubmit, watch, reset, setValue, formState: { errors } } = useForm<Form>({
    resolver: zodResolver(schema),
    defaultValues: { details: [] },
  })

  const { fields, append, remove } = useFieldArray({ control, name: 'details' })
  const detailValues = watch('details')
  const totalAmount  = detailValues?.reduce((s, d) => s + (Number(d.amount) || 0), 0) ?? 0

  // Tracks detail IDs that existed on the server when the form loaded, so we can
  // detect which ones were removed in this edit session and issue DELETE for them.
  const originalDetailIds = useRef<string[]>([])

  useEffect(() => {
    if (existing) {
      reset({ parent_pdo_header_id: existing.parent_pdo_header_id, notes: existing.notes ?? '' })
      originalDetailIds.current = (existing.details ?? []).map((d) => d.id)
      existing.details?.forEach((d) => append({
        id:              d.id,
        expense_item_id: d.expense_item_id,
        description:     d.description,
        quantity:        d.quantity,
        unit:            d.unit,
        rate:            d.rate,
        amount:          d.amount,
        notes:           null,
        justification:   d.notes ?? '',
        display_order:   d.display_order,
      }))
    }
  }, [existing])

  // Auto-fill description, unit, rate from master item; auto-compute amount if qty available
  const handleItemChange = (idx: number, itemId: string) => {
    const item = items?.find((i) => i.id === itemId)
    if (!item) return
    setValue(`details.${idx}.description`, item.name)
    if (item.default_unit) setValue(`details.${idx}.unit`, item.default_unit)
    if (item.default_rate) {
      setValue(`details.${idx}.rate`, item.default_rate)
      const qty = Number(detailValues[idx]?.quantity)
      if (qty > 0) setValue(`details.${idx}.amount`, Math.round(qty * item.default_rate))
    }
  }

  // Recalculate amount = quantity × rate
  const recalcAmount = (idx: number, qty: number, rate: number) => {
    if (qty > 0 && rate > 0) setValue(`details.${idx}.amount`, Math.round(qty * rate))
  }

  // Build payload for one detail row (maps justification → notes for the API)
  const buildDetailPayload = (d: Form['details'][number]) => ({
    expense_item_id: d.expense_item_id,
    description:     d.description,
    quantity:        d.quantity ?? null,
    unit:            d.unit     ?? null,
    rate:            d.rate     ?? null,
    amount:          d.amount,
    notes:           d.justification || d.notes || null,
    display_order:   d.display_order,
  })

  // Delete detail rows that existed originally but are no longer in the submitted form
  const deleteRemovedDetails = async (supplementaryId: string, data: Form) => {
    const currentIds = new Set(data.details.map((d) => d.id).filter(Boolean))
    const removedIds = originalDetailIds.current.filter((origId) => !currentIds.has(origId))
    for (const detailId of removedIds) {
      await api.delete(`/pdo-supplementary/${supplementaryId}/details/${detailId}`)
    }
  }

  const save = useMutation({
    mutationFn: async (data: Form) => {
      const headerPayload = { parent_pdo_header_id: data.parent_pdo_header_id, notes: data.notes }

      // Step 1 — create or update the header
      const headerRes = isEdit
        ? await api.put(`/pdo-supplementary/${id}`, headerPayload)
        : await api.post('/pdo-supplementary', headerPayload)
      const header = (headerRes.data as ApiResponse<PdoSupplementaryHeader>).data

      // Step 2 — delete rows removed from the form
      if (isEdit) await deleteRemovedDetails(header.id, data)

      // Step 3 — persist each remaining detail row individually
      for (const d of data.details) {
        const payload = buildDetailPayload(d)
        if (d.id) {
          await api.put(`/pdo-supplementary/${header.id}/details/${d.id}`, payload)
        } else {
          await api.post(`/pdo-supplementary/${header.id}/details`, payload)
        }
      }

      return header
    },
    onSuccess: (header) => {
      toast(isEdit ? 'PDO Tambahan berhasil diperbarui' : 'PDO Tambahan berhasil dibuat')
      qc.invalidateQueries({ queryKey: ['pdo-supplementary'] })
      navigate(`/pdo-tambahan/${header.id}`)
    },
    onError: () => toast('Gagal menyimpan PDO Tambahan', 'error'),
  })

  const submit = useMutation({
    mutationFn: async (data: Form) => {
      const headerPayload = { parent_pdo_header_id: data.parent_pdo_header_id, notes: data.notes }

      const headerRes = isEdit
        ? await api.put(`/pdo-supplementary/${id}`, headerPayload)
        : await api.post('/pdo-supplementary', headerPayload)
      const header = (headerRes.data as ApiResponse<PdoSupplementaryHeader>).data

      if (isEdit) await deleteRemovedDetails(header.id, data)

      for (const d of data.details) {
        const payload = buildDetailPayload(d)
        if (d.id) {
          await api.put(`/pdo-supplementary/${header.id}/details/${d.id}`, payload)
        } else {
          await api.post(`/pdo-supplementary/${header.id}/details`, payload)
        }
      }

      await api.post(`/pdo-supplementary/${header.id}/submit`, {
        submission_date: new Date().toISOString().split('T')[0],
      })
      return header
    },
    onSuccess: (header) => {
      toast('PDO Tambahan berhasil diajukan')
      qc.invalidateQueries({ queryKey: ['pdo-supplementary'] })
      navigate(`/pdo-tambahan/${header.id}`)
    },
    onError: () => toast('Gagal mengajukan PDO Tambahan', 'error'),
  })

  return (
    <div className="max-w-4xl">
      <div className="flex items-center gap-3 mb-6">
        <Button variant="secondary" size="sm" onClick={() => navigate('/pdo-tambahan')}>
          <ArrowLeft className="w-4 h-4" />
        </Button>
        <h2 className="text-[28px] font-[950] text-ink">
          {isEdit ? 'Edit PDO Tambahan' : 'Buat PDO Tambahan'}
        </h2>
      </div>

      <form onSubmit={handleSubmit((d) => save.mutate(d))}>
        <div className="card mb-4">
          <h3 className="text-[17px] font-[850] mb-4">Informasi Umum</h3>
          <div>
            <label className="label">PDO Induk (yang sudah disetujui Direktur)</label>
            <select {...register('parent_pdo_header_id')} className="input-base">
              <option value="">Pilih PDO...</option>
              {pdoList?.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.pdo_number} — {p.plantation_unit?.name}
                </option>
              ))}
            </select>
            {errors.parent_pdo_header_id && <p className="field-error">{errors.parent_pdo_header_id.message}</p>}
          </div>
          <div className="mt-4">
            <label className="label">Catatan Umum (opsional)</label>
            <textarea {...register('notes')} className="input-base resize-none" rows={2} />
          </div>
        </div>

        <div className="card mb-4">
          <div className="flex items-center justify-between mb-4">
            <h3 className="text-[17px] font-[850]">Item Biaya Tambahan</h3>
            <Button
              type="button"
              size="sm"
              variant="secondary"
              onClick={() => append({
                expense_item_id: '', description: '', quantity: null,
                unit: null, rate: null, amount: 0, notes: null,
                justification: '', display_order: fields.length,
              })}
            >
              <Plus className="w-4 h-4" /> Tambah Item
            </Button>
          </div>

          {fields.length === 0 ? (
            <p className="text-muted text-sm text-center py-6">Klik "Tambah Item" untuk memulai.</p>
          ) : (
            <div className="flex flex-col gap-4">
              {fields.map((field, idx) => (
                <div key={field.id} className="border border-line rounded-card p-4 relative">
                  <button type="button" className="absolute top-3 right-3 text-muted hover:text-red" onClick={() => remove(idx)}>
                    <Trash2 className="w-4 h-4" />
                  </button>

                  <div className="grid grid-cols-1 desk:grid-cols-2 gap-3 mb-3">
                    <div>
                      <label className="label">Item Biaya</label>
                      <select
                        {...register(`details.${idx}.expense_item_id`)}
                        className="input-base"
                        onChange={(e) => {
                          register(`details.${idx}.expense_item_id`).onChange(e)
                          handleItemChange(idx, e.target.value)
                        }}
                      >
                        <option value="">Pilih item...</option>
                        {items?.map((item) => (
                          <option key={item.id} value={item.id}>{item.code} — {item.name}</option>
                        ))}
                        {/* Keep a currently-selected but now-inactive item visible so the dropdown doesn't render blank */}
                        {detailValues[idx]?.expense_item_id &&
                          !items?.some((item) => item.id === detailValues[idx].expense_item_id) && (
                            <option value={detailValues[idx].expense_item_id}>
                              {detailValues[idx].description} (nonaktif)
                            </option>
                        )}
                      </select>
                    </div>
                    <div>
                      <label className="label">Deskripsi</label>
                      <input {...register(`details.${idx}.description`)} className="input-base" />
                    </div>
                  </div>

                  <div className="grid grid-cols-2 desk:grid-cols-4 gap-3 mb-3">
                    <div>
                      <label className="label">Volume</label>
                      <input
                        type="number"
                        {...register(`details.${idx}.quantity`)}
                        className="input-base"
                        step="0.01"
                        onChange={(e) => {
                          register(`details.${idx}.quantity`).onChange(e)
                          recalcAmount(idx, parseFloat(e.target.value), Number(detailValues[idx]?.rate))
                        }}
                      />
                    </div>
                    <div>
                      <label className="label">Satuan</label>
                      <input {...register(`details.${idx}.unit`)} className="input-base" />
                    </div>
                    <div>
                      <label className="label">Harga Satuan</label>
                      <input
                        type="number"
                        {...register(`details.${idx}.rate`)}
                        className="input-base"
                        onChange={(e) => {
                          register(`details.${idx}.rate`).onChange(e)
                          recalcAmount(idx, Number(detailValues[idx]?.quantity), parseFloat(e.target.value))
                        }}
                      />
                    </div>
                    <div>
                      <label className="label">Jumlah (Rp)</label>
                      <input type="number" {...register(`details.${idx}.amount`)} className="input-base font-bold" />
                      {errors.details?.[idx]?.amount && (
                        <p className="field-error">{errors.details[idx]?.amount?.message}</p>
                      )}
                    </div>
                  </div>

                  <div>
                    <label className="label">Justifikasi / Alasan Tambahan <span className="text-red">*</span></label>
                    <textarea {...register(`details.${idx}.justification`)} className="input-base resize-none" rows={2}
                      placeholder="Jelaskan mengapa biaya ini tidak tercakup di PDO Bulanan..." />
                    {errors.details?.[idx]?.justification && (
                      <p className="field-error">{errors.details[idx]?.justification?.message}</p>
                    )}
                  </div>
                </div>
              ))}
            </div>
          )}

          {fields.length > 0 && (
            <div className="flex items-center justify-end gap-2 mt-4 pt-4 border-t border-line">
              <span className="text-sm text-muted">Total Pengajuan Tambahan:</span>
              <span className="text-[20px] font-[950] text-green">{fmt(totalAmount)}</span>
            </div>
          )}
        </div>

        <div className="flex gap-2">
          <Button type="submit" loading={save.isPending}>Simpan Draft</Button>
          <Button
            type="button"
            variant="secondary"
            loading={submit.isPending}
            onClick={handleSubmit((d) => submit.mutate(d))}
          >
            Simpan & Ajukan
          </Button>
          <Button type="button" variant="secondary" onClick={() => navigate('/pdo-tambahan')}>Batal</Button>
        </div>
      </form>
    </div>
  )
}
