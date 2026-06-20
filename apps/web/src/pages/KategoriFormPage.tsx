import { useEffect } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { Button } from '@/components/ui/Button'
import { useToastStore } from '@/store/toast.store'
import { ArrowLeft } from 'lucide-react'
import type { ApiResponse, ExpenseCategory } from '@/types'

const schema = z.object({
  code:             z.string().min(1, 'Kode wajib diisi'),
  name:             z.string().min(1, 'Nama wajib diisi'),
  display_order:    z.coerce.number().int().min(0),
  include_in_recap: z.boolean(),
  is_active:        z.boolean(),
  notes:            z.string().nullable().optional(),
})

type Form = z.infer<typeof schema>

export function KategoriFormPage() {
  const { id }   = useParams<{ id: string }>()
  const navigate = useNavigate()
  const toast    = useToastStore((s) => s.push)
  const qc       = useQueryClient()
  const isEdit   = !!id

  const { data: existing } = useQuery({
    queryKey: ['category', id],
    queryFn: async () => {
      const res = await api.get<ApiResponse<ExpenseCategory>>(`/expense-categories/${id}`)
      return res.data.data
    },
    enabled: isEdit,
  })

  const { register, handleSubmit, reset, formState: { errors } } = useForm<Form>({
    resolver: zodResolver(schema),
    defaultValues: { display_order: 1, include_in_recap: true, is_active: true },
  })

  useEffect(() => {
    if (existing) reset({ ...existing, notes: existing.notes ?? '' })
  }, [existing, reset])

  const save = useMutation({
    mutationFn: (data: Form) =>
      isEdit
        ? api.put(`/expense-categories/${id}`, data)
        : api.post('/expense-categories', data),
    onSuccess: () => {
      toast(isEdit ? 'Kategori berhasil diperbarui' : 'Kategori berhasil dibuat')
      qc.invalidateQueries({ queryKey: ['categories'] })
      navigate('/master')
    },
    onError: () => toast('Gagal menyimpan kategori', 'error'),
  })

  return (
    <div className="max-w-lg">
      <div className="flex items-center gap-3 mb-6">
        <Button variant="secondary" size="sm" onClick={() => navigate('/master')}>
          <ArrowLeft className="w-4 h-4" />
        </Button>
        <h2 className="text-[28px] font-[950] text-ink">
          {isEdit ? 'Edit Kategori' : 'Tambah Kategori'}
        </h2>
      </div>

      <form onSubmit={handleSubmit((d) => save.mutate(d))} className="card flex flex-col gap-4">
        <div className="grid grid-cols-1 desk:grid-cols-2 gap-4">
          <div>
            <label className="label">Kode</label>
            <input {...register('code')} className="input-base" placeholder="A" />
            {errors.code && <p className="field-error">{errors.code.message}</p>}
          </div>
          <div>
            <label className="label">Urutan Tampil</label>
            <input type="number" {...register('display_order')} className="input-base" />
          </div>
        </div>

        <div>
          <label className="label">Nama Kategori</label>
          <input {...register('name')} className="input-base" placeholder="Biaya Pemeliharaan" />
          {errors.name && <p className="field-error">{errors.name.message}</p>}
        </div>

        <div>
          <label className="label">Catatan</label>
          <textarea {...register('notes')} className="input-base resize-none" rows={3} />
        </div>

        <div className="flex gap-6">
          <label className="flex items-center gap-2 text-sm font-[700] cursor-pointer">
            <input type="checkbox" {...register('include_in_recap')} className="checkbox" />
            Masuk Rekap
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
