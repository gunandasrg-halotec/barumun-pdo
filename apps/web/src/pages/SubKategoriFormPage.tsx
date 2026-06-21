import { useEffect } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { Button } from '@/components/ui/Button'
import { useToastStore } from '@/store/toast.store'
import { useCategories } from '@/hooks/useMasterData'
import { ArrowLeft } from 'lucide-react'
import type { ApiResponse, ExpenseSubcategory } from '@/types'

const schema = z.object({
  category_id:   z.string().uuid('Pilih kategori'),
  code:          z.string().min(1, 'Kode wajib diisi'),
  name:          z.string().min(1, 'Nama wajib diisi'),
  display_order: z.coerce.number().int().min(0),
  is_active:     z.boolean(),
  notes:         z.string().nullable().optional(),
})

type Form = z.infer<typeof schema>

export function SubKategoriFormPage() {
  const { id }   = useParams<{ id: string }>()
  const navigate = useNavigate()
  const toast    = useToastStore((s) => s.push)
  const qc       = useQueryClient()
  const isEdit   = !!id

  const { data: categories } = useCategories({ is_active: true })

  const { data: existing } = useQuery({
    queryKey: ['subcategory', id],
    queryFn: async () => {
      const res = await api.get<ApiResponse<ExpenseSubcategory>>(`/expense-subcategories/${id}`)
      return res.data.data
    },
    enabled: isEdit,
  })

  const { register, handleSubmit, reset, setError, formState: { errors } } = useForm<Form>({
    resolver: zodResolver(schema),
    defaultValues: { display_order: 1, is_active: true },
  })

  useEffect(() => {
    if (existing) reset({ ...existing, notes: existing.notes ?? '' })
  }, [existing, reset])

  const save = useMutation({
    mutationFn: (data: Form) =>
      isEdit
        ? api.put(`/expense-subcategories/${id}`, data)
        : api.post('/expense-subcategories', data),
    onSuccess: () => {
      toast(isEdit ? 'Sub-kategori berhasil diperbarui' : 'Sub-kategori berhasil dibuat')
      qc.invalidateQueries({ queryKey: ['subcategories'] })
      navigate('/master')
    },
    onError: (err: unknown) => {
      type ApiErr = { response?: { data?: { error?: { details?: { field: string; message: string }[] } } } }
      const details = (err as ApiErr)?.response?.data?.error?.details
      if (details?.length) {
        const validFields = new Set<string>(['code', 'name', 'category_id', 'display_order', 'is_active', 'notes'])
        let handled = false
        for (const { field, message } of details) {
          if (validFields.has(field)) {
            setError(field as keyof Form, { message })
            handled = true
          }
        }
        if (handled) return
      }
      toast('Gagal menyimpan sub-kategori', 'error')
    },
  })

  return (
    <div className="max-w-lg">
      <div className="flex items-center gap-3 mb-6">
        <Button variant="secondary" size="sm" onClick={() => navigate('/master')}>
          <ArrowLeft className="w-4 h-4" />
        </Button>
        <h2 className="text-[28px] font-[950] text-ink">
          {isEdit ? 'Edit Sub-Kategori' : 'Tambah Sub-Kategori'}
        </h2>
      </div>

      <form onSubmit={handleSubmit((d) => save.mutate(d))} className="card flex flex-col gap-4">
        <div>
          <label className="label">Kategori Induk</label>
          <select {...register('category_id')} className="input-base">
            <option value="">Pilih kategori...</option>
            {categories?.map((c) => (
              <option key={c.id} value={c.id}>{c.code} — {c.name}</option>
            ))}
          </select>
          {errors.category_id && <p className="field-error">{errors.category_id.message}</p>}
        </div>

        <div className="grid grid-cols-1 desk:grid-cols-2 gap-4">
          <div>
            <label className="label">Kode</label>
            <input {...register('code')} className="input-base" placeholder="A1" />
            {errors.code && <p className="field-error">{errors.code.message}</p>}
          </div>
          <div>
            <label className="label">Urutan Tampil</label>
            <input type="number" {...register('display_order')} className="input-base" />
          </div>
        </div>

        <div>
          <label className="label">Nama Sub-Kategori</label>
          <input {...register('name')} className="input-base" placeholder="Pemupukan" />
          {errors.name && <p className="field-error">{errors.name.message}</p>}
        </div>

        <div>
          <label className="label">Catatan</label>
          <textarea {...register('notes')} className="input-base resize-none" rows={3} />
        </div>

        <label className="flex items-center gap-2 text-sm font-[700] cursor-pointer">
          <input type="checkbox" {...register('is_active')} className="checkbox" />
          Aktif
        </label>

        <div className="flex gap-2 pt-2">
          <Button type="submit" loading={save.isPending}>Simpan</Button>
          <Button type="button" variant="secondary" onClick={() => navigate('/master')}>Batal</Button>
        </div>
      </form>
    </div>
  )
}
