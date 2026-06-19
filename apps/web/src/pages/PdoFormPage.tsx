import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useForm, useFieldArray } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { Button } from '@/components/ui/Button'
import { useToastStore } from '@/store/toast.store'
import { useAuthStore } from '@/store/auth.store'
import { useItems, useSubcategories, useCategories } from '@/hooks/useMasterData'
import { usePdo } from '@/hooks/usePdo'
import { fmt } from '@/lib/format'
import { ArrowLeft, Plus, Trash2 } from 'lucide-react'
import type { ApiResponse, PdoHeader, PlantationUnit } from '@/types'

const detailSchema = z.object({
  expense_item_id: z.string().uuid('Pilih item'),
  description:     z.string().min(1, 'Deskripsi wajib diisi'),
  quantity:        z.coerce.number().nullable().optional(),
  unit:            z.string().nullable().optional(),
  rate:            z.coerce.number().nullable().optional(),
  amount:          z.coerce.number().min(1, 'Jumlah harus > 0'),
  notes:           z.string().nullable().optional(),
  display_order:   z.coerce.number().int().default(0),
})

const schema = z.object({
  plantation_unit_id: z.string().uuid('Pilih unit kebun'),
  period_month:       z.coerce.number().int().min(1).max(12),
  period_year:        z.coerce.number().int().min(2020),
  notes:              z.string().nullable().optional(),
  details:            z.array(detailSchema).min(1, 'Minimal 1 item biaya'),
})

type Form = z.infer<typeof schema>

const MONTHS = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember']
const YEARS  = Array.from({ length: 5 }, (_, i) => new Date().getFullYear() - i + 1)

export function PdoFormPage() {
  const { id }   = useParams<{ id: string }>()
  const navigate = useNavigate()
  const toast    = useToastStore((s) => s.push)
  const qc       = useQueryClient()
  const user     = useAuthStore((s) => s.user)
  const isEdit   = !!id

  const { data: units } = useQuery({
    queryKey: ['plantation-units'],
    queryFn: async () => {
      const res = await api.get<ApiResponse<PlantationUnit[]>>('/plantation-units')
      return res.data.data
    },
  })

  const { data: items }         = useItems({ is_active: true })
  const { data: subcategories } = useSubcategories({ is_active: true })
  const { data: categories }    = useCategories({ is_active: true })

  const { data: existing } = usePdo(id)

  const now = new Date()
  const { register, control, handleSubmit, watch, reset, setValue, formState: { errors } } = useForm<Form>({
    resolver: zodResolver(schema),
    defaultValues: {
      plantation_unit_id: user?.plantation_unit?.id ?? '',
      period_month: now.getMonth() + 1,
      period_year:  now.getFullYear(),
      details: [],
    },
  })

  const { fields, append, remove } = useFieldArray({ control, name: 'details' })

  const detailValues = watch('details')

  const totalAmount = detailValues?.reduce((sum, d) => sum + (Number(d.amount) || 0), 0) ?? 0

  useEffect(() => {
    if (existing) {
      reset({
        plantation_unit_id: existing.plantation_unit_id,
        period_month: existing.period_month,
        period_year:  existing.period_year,
        notes:        existing.notes ?? '',
        details: [],
      })
      // load details from backend separately
      api.get<ApiResponse<unknown[]>>(`/pdo/${id}/details`).then((res) => {
        const details = res.data.data as Form['details']
        details.forEach((d) => append(d))
      })
    }
  }, [existing])

  const save = useMutation({
    mutationFn: (data: Form) =>
      isEdit
        ? api.put(`/pdo/${id}`, data)
        : api.post('/pdo', data),
    onSuccess: (res) => {
      const created = (res.data as ApiResponse<PdoHeader>).data
      toast(isEdit ? 'PDO berhasil diperbarui' : 'PDO berhasil dibuat')
      qc.invalidateQueries({ queryKey: ['pdo'] })
      navigate(`/pdo/${created.id}`)
    },
    onError: () => toast('Gagal menyimpan PDO', 'error'),
  })

  const submit = useMutation({
    mutationFn: async (data: Form) => {
      const res = isEdit
        ? await api.put(`/pdo/${id}`, data)
        : await api.post('/pdo', data)
      const header = (res.data as ApiResponse<PdoHeader>).data
      await api.post(`/pdo/${header.id}/submit`)
      return header
    },
    onSuccess: (header) => {
      toast('PDO berhasil diajukan ke Asisten')
      qc.invalidateQueries({ queryKey: ['pdo'] })
      navigate(`/pdo/${header.id}/approval`)
    },
    onError: () => toast('Gagal mengajukan PDO', 'error'),
  })

  const handleItemChange = (idx: number, itemId: string) => {
    const item = items?.find((i) => i.id === itemId)
    if (!item) return
    setValue(`details.${idx}.description`, item.name)
    if (item.default_unit) setValue(`details.${idx}.unit`, item.default_unit)
    if (item.default_rate) setValue(`details.${idx}.rate`, item.default_rate)
  }

  const getItemLabel = (itemId: string) => {
    const item = items?.find((i) => i.id === itemId)
    if (!item) return ''
    const sub = subcategories?.find((s) => s.id === item.subcategory_id)
    const cat = categories?.find((c) => c.id === sub?.category_id)
    return `${cat?.code ?? '?'} / ${sub?.code ?? '?'} / ${item.code}`
  }

  return (
    <div className="max-w-4xl">
      <div className="flex items-center gap-3 mb-6">
        <Button variant="secondary" size="sm" onClick={() => navigate('/pdo')}>
          <ArrowLeft className="w-4 h-4" />
        </Button>
        <h2 className="text-[28px] font-[950] text-ink">
          {isEdit ? 'Edit PDO' : 'Buat PDO Baru'}
        </h2>
      </div>

      <form onSubmit={handleSubmit((d) => save.mutate(d))}>
        {/* Header */}
        <div className="card mb-4">
          <h3 className="text-[17px] font-[850] mb-4">Informasi Umum</h3>
          <div className="grid grid-cols-3 gap-4">
            <div>
              <label className="label">Unit Kebun</label>
              <select {...register('plantation_unit_id')} className="input-base">
                <option value="">Pilih unit...</option>
                {units?.map((u) => (
                  <option key={u.id} value={u.id}>{u.code} — {u.name}</option>
                ))}
              </select>
              {errors.plantation_unit_id && <p className="field-error">{errors.plantation_unit_id.message}</p>}
            </div>
            <div>
              <label className="label">Bulan</label>
              <select {...register('period_month')} className="input-base">
                {MONTHS.map((m, i) => <option key={i + 1} value={i + 1}>{m}</option>)}
              </select>
            </div>
            <div>
              <label className="label">Tahun</label>
              <select {...register('period_year')} className="input-base">
                {YEARS.map((y) => <option key={y} value={y}>{y}</option>)}
              </select>
            </div>
          </div>
          <div className="mt-4">
            <label className="label">Catatan (opsional)</label>
            <textarea {...register('notes')} className="input-base resize-none" rows={2} />
          </div>
        </div>

        {/* Detail Items */}
        <div className="card mb-4">
          <div className="flex items-center justify-between mb-4">
            <h3 className="text-[17px] font-[850]">Rencana Biaya</h3>
            <Button
              type="button"
              size="sm"
              variant="secondary"
              onClick={() => append({
                expense_item_id: '', description: '', quantity: null,
                unit: null, rate: null, amount: 0, notes: null, display_order: fields.length,
              })}
            >
              <Plus className="w-4 h-4" /> Tambah Item
            </Button>
          </div>

          {fields.length === 0 ? (
            <p className="text-muted text-sm text-center py-6">
              Klik "Tambah Item" untuk memulai input rencana biaya.
            </p>
          ) : (
            <div className="flex flex-col gap-3">
              {fields.map((field, idx) => (
                <div key={field.id} className="border border-line rounded-card p-4 relative">
                  <button
                    type="button"
                    className="absolute top-3 right-3 text-muted hover:text-red"
                    onClick={() => remove(idx)}
                  >
                    <Trash2 className="w-4 h-4" />
                  </button>

                  <div className="grid grid-cols-2 gap-3 mb-3">
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
                          <option key={item.id} value={item.id}>
                            {getItemLabel(item.id)} — {item.name}
                          </option>
                        ))}
                      </select>
                      {errors.details?.[idx]?.expense_item_id && (
                        <p className="field-error">{errors.details[idx]?.expense_item_id?.message}</p>
                      )}
                    </div>
                    <div>
                      <label className="label">Deskripsi</label>
                      <input {...register(`details.${idx}.description`)} className="input-base" />
                    </div>
                  </div>

                  <div className="grid grid-cols-4 gap-3">
                    <div>
                      <label className="label">Volume</label>
                      <input type="number" {...register(`details.${idx}.quantity`)} className="input-base" step="0.01" />
                    </div>
                    <div>
                      <label className="label">Satuan</label>
                      <input {...register(`details.${idx}.unit`)} className="input-base" />
                    </div>
                    <div>
                      <label className="label">Harga Satuan</label>
                      <input type="number" {...register(`details.${idx}.rate`)} className="input-base" />
                    </div>
                    <div>
                      <label className="label">Jumlah (Rp)</label>
                      <input type="number" {...register(`details.${idx}.amount`)} className="input-base font-bold" />
                      {errors.details?.[idx]?.amount && (
                        <p className="field-error">{errors.details[idx]?.amount?.message}</p>
                      )}
                    </div>
                  </div>

                  <div className="mt-3">
                    <label className="label">Catatan Item</label>
                    <input {...register(`details.${idx}.notes`)} className="input-base" />
                  </div>
                </div>
              ))}
            </div>
          )}

          {errors.details && typeof errors.details.message === 'string' && (
            <p className="field-error mt-2">{errors.details.message}</p>
          )}

          {fields.length > 0 && (
            <div className="flex items-center justify-end gap-2 mt-4 pt-4 border-t border-line">
              <span className="text-sm text-muted">Total Pengajuan:</span>
              <span className="text-[20px] font-[950] text-green">{fmt(totalAmount)}</span>
            </div>
          )}
        </div>

        {/* Actions */}
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
          <Button type="button" variant="secondary" onClick={() => navigate('/pdo')}>Batal</Button>
        </div>
      </form>
    </div>
  )
}
