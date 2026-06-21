import { useEffect } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { Button } from '@/components/ui/Button'
import { useToastStore } from '@/store/toast.store'
import { useSubcategories } from '@/hooks/useMasterData'
import { ArrowLeft } from 'lucide-react'
import type { ApiResponse, ExpenseItem } from '@/types'

const schema = z.object({
  subcategory_id:         z.string().uuid('Pilih sub-kategori'),
  code:                   z.string().min(1, 'Kode wajib diisi'),
  name:                   z.string().min(1, 'Nama wajib diisi'),
  default_account_number: z.string().nullable().optional(),
  default_unit:           z.string().nullable().optional(),
  default_rate:           z.coerce.number().nullable().optional(),
  mode_input:             z.enum(['manual', 'auto_external']),
  is_routine:             z.boolean(),
  is_active:              z.boolean(),
  notes:                  z.string().nullable().optional(),
})

type Form = z.infer<typeof schema>

export function ItemFormPage() {
  const { id }   = useParams<{ id: string }>()
  const navigate = useNavigate()
  const toast    = useToastStore((s) => s.push)
  const qc       = useQueryClient()
  const isEdit   = !!id

  const { data: subcategories } = useSubcategories({ is_active: true })

  const { data: existing } = useQuery({
    queryKey: ['item', id],
    queryFn: async () => {
      const res = await api.get<ApiResponse<ExpenseItem>>(`/expense-items/${id}`)
      return res.data.data
    },
    enabled: isEdit,
  })

  const { register, handleSubmit, reset, setError, formState: { errors } } = useForm<Form>({
    resolver: zodResolver(schema),
    defaultValues: { mode_input: 'manual', is_routine: true, is_active: true },
  })

  useEffect(() => {
    if (existing) reset({ ...existing, notes: existing.notes ?? '' })
  }, [existing, reset])

  const save = useMutation({
    mutationFn: (data: Form) =>
      isEdit
        ? api.put(`/expense-items/${id}`, data)
        : api.post('/expense-items', data),
    onSuccess: () => {
      toast(isEdit ? 'Item berhasil diperbarui' : 'Item berhasil dibuat')
      qc.invalidateQueries({ queryKey: ['items'] })
      navigate('/master')
    },
    onError: (err: unknown) => {
      type ApiErr = { response?: { data?: { error?: { details?: { field: string; message: string }[] } } } }
      const details = (err as ApiErr)?.response?.data?.error?.details
      if (details?.length) {
        const validFields = new Set<string>(['code', 'name', 'subcategory_id', 'default_account_number', 'default_unit', 'default_rate', 'mode_input', 'is_routine', 'is_active', 'notes'])
        let handled = false
        for (const { field, message } of details) {
          if (validFields.has(field)) {
            setError(field as keyof Form, { message })
            handled = true
          }
        }
        if (handled) return
      }
      toast('Gagal menyimpan item', 'error')
    },
  })

  return (
    <div className="max-w-lg">
      <div className="flex items-center gap-3 mb-6">
        <Button variant="secondary" size="sm" onClick={() => navigate('/master')}>
          <ArrowLeft className="w-4 h-4" />
        </Button>
        <h2 className="text-[28px] font-[950] text-ink">
          {isEdit ? 'Edit Item Biaya' : 'Tambah Item Biaya'}
        </h2>
      </div>

      <form onSubmit={handleSubmit((d) => save.mutate(d))} className="card flex flex-col gap-4">
        <div>
          <label className="label">Sub-Kategori Induk</label>
          <select {...register('subcategory_id')} className="input-base">
            <option value="">Pilih sub-kategori...</option>
            {subcategories?.map((s) => (
              <option key={s.id} value={s.id}>{s.code} — {s.name}</option>
            ))}
          </select>
          {errors.subcategory_id && <p className="field-error">{errors.subcategory_id.message}</p>}
        </div>

        <div className="grid grid-cols-1 desk:grid-cols-2 gap-4">
          <div>
            <label className="label">Kode</label>
            <input {...register('code')} className="input-base" placeholder="A1.001" />
            {errors.code && <p className="field-error">{errors.code.message}</p>}
          </div>
          <div>
            <label className="label">No. Akun (opsional)</label>
            <input {...register('default_account_number')} className="input-base" placeholder="6-1001" />
          </div>
        </div>

        <div>
          <label className="label">Nama Item</label>
          <input {...register('name')} className="input-base" placeholder="Pupuk Urea" />
          {errors.name && <p className="field-error">{errors.name.message}</p>}
        </div>

        <div className="grid grid-cols-1 desk:grid-cols-2 gap-4">
          <div>
            <label className="label">Satuan (opsional)</label>
            <input {...register('default_unit')} className="input-base" placeholder="kg" />
          </div>
          <div>
            <label className="label">Tarif (opsional)</label>
            <input type="number" {...register('default_rate')} className="input-base" placeholder="0" />
          </div>
        </div>

        <div>
          <label className="label">Mode Input</label>
          <select {...register('mode_input')} className="input-base">
            <option value="manual">Manual</option>
            <option value="auto_external">Auto External</option>
          </select>
        </div>

        <div>
          <label className="label">Catatan</label>
          <textarea {...register('notes')} className="input-base resize-none" rows={3} />
        </div>

        <div className="flex gap-6">
          <label className="flex items-center gap-2 text-sm font-[700] cursor-pointer">
            <input type="checkbox" {...register('is_routine')} className="checkbox" />
            Item Rutin
          </label>
          <label className="flex items-center gap-2 text-sm font-[700] cursor-pointer">
            <input type="checkbox" {...register('is_active')} className="checkbox" />
            Aktif
          </label>
        </div>

        <div className="flex gap-2 pt-2">
          <Button type="submit" loading={save.isPending}>Simpan</Button>
          <Button type="button" variant="secondary" onClick={() => navigate('/master')}>Batal</Button>
        </div>
      </form>
    </div>
  )
}
