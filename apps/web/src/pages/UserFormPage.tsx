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
import type { ApiResponse, AuthUser, Role, PlantationUnit } from '@/types'

const ROLES_REQUIRING_UNIT = ['Asisten Kebun', 'Kerani']

const schemaCreate = z.object({
  full_name:         z.string().min(1, 'Nama wajib diisi'),
  email:             z.string().email('Format email tidak valid'),
  password:          z.string().min(8, 'Minimal 8 karakter'),
  whatsapp_number:   z.string().min(10, 'Nomor tidak valid'),
  role_id:           z.string().uuid('Pilih role'),
  plantation_unit_id: z.preprocess(
    (v) => (v === '' ? null : v),
    z.string().uuid().nullable().optional(),
  ),
  is_active:         z.boolean(),
})

const schemaEdit = schemaCreate.extend({
  password: z.string().min(8).optional().or(z.literal('')),
})

type FormEdit = z.infer<typeof schemaEdit>

export function UserFormPage() {
  const { id }   = useParams<{ id: string }>()
  const navigate = useNavigate()
  const toast    = useToastStore((s) => s.push)
  const qc       = useQueryClient()
  const isEdit   = !!id

  const { data: roles } = useQuery({
    queryKey: ['roles'],
    queryFn: async () => {
      const res = await api.get<ApiResponse<Role[]>>('/roles')
      return res.data.data
    },
  })

  const { data: units } = useQuery({
    queryKey: ['plantation-units'],
    queryFn: async () => {
      const res = await api.get<ApiResponse<PlantationUnit[]>>('/plantation-units')
      return res.data.data
    },
  })

  const { data: existing } = useQuery({
    queryKey: ['user', id],
    queryFn: async () => {
      const res = await api.get<ApiResponse<AuthUser>>(`/users/${id}`)
      return res.data.data
    },
    enabled: isEdit,
  })

  const schema = isEdit ? schemaEdit : schemaCreate
  const { register, handleSubmit, reset, watch, setError, formState: { errors } } = useForm<FormEdit>({
    resolver: zodResolver(schema),
    defaultValues: { is_active: true },
  })

  const watchRoleId = watch('role_id')
  const selectedRole = roles?.find((r) => r.id === watchRoleId)
  const unitRequired = ROLES_REQUIRING_UNIT.includes(selectedRole?.name ?? '')

  useEffect(() => {
    if (existing) {
      reset({
        full_name:          existing.full_name,
        email:              existing.email,
        whatsapp_number:    existing.whatsapp_number,
        role_id:            (existing.role as Role & { id: string }).id,
        plantation_unit_id: existing.plantation_unit?.id ?? null,
        is_active:          existing.is_active,
        password:           '',
      })
    }
  }, [existing, reset])

  const save = useMutation({
    mutationFn: (data: FormEdit) => {
      const payload = { ...data }
      if (isEdit && !payload.password) delete payload.password
      return isEdit ? api.put(`/users/${id}`, payload) : api.post('/users', payload)
    },
    onSuccess: () => {
      toast(isEdit ? 'User berhasil diperbarui' : 'User berhasil dibuat')
      qc.invalidateQueries({ queryKey: ['users'] })
      navigate('/master')
    },
    onError: () => toast('Gagal menyimpan user', 'error'),
  })

  const onSubmit = handleSubmit((data) => {
    if (unitRequired && !data.plantation_unit_id) {
      setError('plantation_unit_id', { message: 'Unit Kebun wajib dipilih untuk role ini' })
      return
    }
    save.mutate(data)
  })

  return (
    <div className="max-w-lg">
      <div className="flex items-center gap-3 mb-6">
        <Button variant="secondary" size="sm" onClick={() => navigate('/master')}>
          <ArrowLeft className="w-4 h-4" />
        </Button>
        <h2 className="text-[28px] font-[950] text-ink">
          {isEdit ? 'Edit User' : 'Tambah User'}
        </h2>
      </div>

      <form onSubmit={onSubmit} className="card flex flex-col gap-4">
        <div>
          <label className="label">Nama Lengkap</label>
          <input {...register('full_name')} className="input-base" />
          {errors.full_name && <p className="field-error">{errors.full_name.message}</p>}
        </div>

        <div>
          <label className="label">Email</label>
          <input type="email" {...register('email')} className="input-base" />
          {errors.email && <p className="field-error">{errors.email.message}</p>}
        </div>

        <div>
          <label className="label">
            Password {isEdit && <span className="text-muted font-normal">(kosongkan jika tidak diubah)</span>}
          </label>
          <input type="password" {...register('password')} className="input-base" autoComplete="new-password" />
          {errors.password && <p className="field-error">{errors.password.message}</p>}
        </div>

        <div>
          <label className="label">Nomor WhatsApp</label>
          <input {...register('whatsapp_number')} className="input-base" placeholder="08xx" />
          {errors.whatsapp_number && <p className="field-error">{errors.whatsapp_number.message}</p>}
        </div>

        <div>
          <label className="label">Role</label>
          <select {...register('role_id')} className="input-base">
            <option value="">Pilih role...</option>
            {roles?.map((r) => (
              <option key={r.id} value={r.id}>{r.name}</option>
            ))}
          </select>
          {errors.role_id && <p className="field-error">{errors.role_id.message}</p>}
        </div>

        <div>
          <label className="label">
            Unit Kebun {unitRequired ? <span className="text-red-500">*</span> : <span className="text-muted font-normal">(opsional)</span>}
          </label>
          <select {...register('plantation_unit_id')} className="input-base">
            <option value="">— Tidak terikat unit —</option>
            {units?.map((u) => (
              <option key={u.id} value={u.id}>{u.code} — {u.name}</option>
            ))}
          </select>
          {errors.plantation_unit_id && <p className="field-error">{errors.plantation_unit_id.message}</p>}
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
