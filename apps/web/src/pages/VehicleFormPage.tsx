import { useEffect } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { ArrowLeft } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { useToastStore } from '@/store/toast.store'
import { resolveMasterDataReturnTo } from '@/lib/masterDataState'
import { useVehicle, useCreateVehicle, useUpdateVehicle, useItems } from '@/hooks/useMasterData'
import { getApiErrorMessage } from '@/lib/api'

const schema = z.object({
  nomor_polisi:    z.string().min(1, 'Nomor polisi wajib diisi').max(20),
  nama:            z.string().min(1, 'Nama/jenis kendaraan wajib diisi').max(100),
  expense_item_id: z.string().uuid().nullable().optional(),
  is_active:       z.boolean(),
})

type Form = z.infer<typeof schema>

const INVENTORY_ITEM_CODES = ['BBM-TRK-001', 'BBM-TRK-002', 'PHD-SPK-001', 'PBB-TRK-001', 'PBB-TRK-002']

export default function VehicleFormPage() {
  const { id } = useParams<{ id: string }>()
  const isEdit = !!id
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const returnTo = resolveMasterDataReturnTo(searchParams.get('returnTo'))
  const addToast = useToastStore((s) => s.addToast)

  const { data: existing, isLoading: loadingExisting } = useVehicle(id ?? '')
  const { data: items } = useItems({ is_active: true })
  const inventoryItems = items?.filter((i) => INVENTORY_ITEM_CODES.includes(i.code)) ?? []

  const createMutation = useCreateVehicle()
  const updateMutation = useUpdateVehicle(id ?? '')

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<Form>({
    resolver: zodResolver(schema),
    defaultValues: { is_active: true, expense_item_id: null },
  })

  useEffect(() => {
    if (existing) {
      reset({
        nomor_polisi:    existing.nomor_polisi,
        nama:            existing.nama,
        expense_item_id: existing.expense_item_id ?? null,
        is_active:       existing.is_active,
      })
    }
  }, [existing, reset])

  const onSubmit = async (data: Form) => {
    const payload = {
      ...data,
      expense_item_id: data.expense_item_id || null,
    }
    try {
      if (isEdit) {
        await updateMutation.mutateAsync(payload)
        addToast({ type: 'success', message: 'Kendaraan berhasil diperbarui.' })
      } else {
        await createMutation.mutateAsync(payload)
        addToast({ type: 'success', message: 'Kendaraan berhasil ditambahkan.' })
      }
      navigate(returnTo)
    } catch (err) {
      addToast({ type: 'error', message: getApiErrorMessage(err) })
    }
  }

  if (isEdit && loadingExisting) {
    return <div className="p-8 text-center text-gray-500">Memuat data kendaraan...</div>
  }

  return (
    <div className="max-w-lg mx-auto p-6">
      <button
        type="button"
        onClick={() => navigate(returnTo)}
        className="flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700 mb-6"
      >
        <ArrowLeft size={16} /> Kembali
      </button>

      <h1 className="text-xl font-semibold mb-6">
        {isEdit ? 'Edit Kendaraan' : 'Tambah Kendaraan'}
      </h1>

      <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
        <div>
          <label className="block text-sm font-medium mb-1">Nomor Polisi</label>
          <input
            {...register('nomor_polisi')}
            className="input-base w-full uppercase"
            placeholder="Contoh: BK 1234 CC"
          />
          {errors.nomor_polisi && (
            <p className="text-red-500 text-xs mt-1">{errors.nomor_polisi.message}</p>
          )}
        </div>

        <div>
          <label className="block text-sm font-medium mb-1">Nama / Jenis Kendaraan</label>
          <input
            {...register('nama')}
            className="input-base w-full"
            placeholder="Contoh: TRUCK, TAFT, EXCAVATOR"
          />
          {errors.nama && (
            <p className="text-red-500 text-xs mt-1">{errors.nama.message}</p>
          )}
        </div>

        <div>
          <label className="block text-sm font-medium mb-1">
            Item Biaya Terkait <span className="text-gray-400 font-normal">(opsional)</span>
          </label>
          <select {...register('expense_item_id')} className="input-base w-full">
            <option value="">— Pilih item biaya —</option>
            {inventoryItems.map((item) => (
              <option key={item.id} value={item.id}>
                {item.code} — {item.name}
              </option>
            ))}
          </select>
          <p className="text-xs text-gray-500 mt-1">
            Hanya item biaya BBM/Sparepart kendaraan yang ditampilkan.
          </p>
        </div>

        <div className="flex items-center gap-2">
          <input
            type="checkbox"
            id="is_active"
            {...register('is_active')}
            className="h-4 w-4"
          />
          <label htmlFor="is_active" className="text-sm font-medium">Aktif</label>
        </div>

        <div className="flex gap-3 pt-2">
          <Button type="submit" disabled={isSubmitting}>
            {isSubmitting ? 'Menyimpan...' : isEdit ? 'Simpan Perubahan' : 'Tambah Kendaraan'}
          </Button>
          <Button type="button" variant="outline" onClick={() => navigate(returnTo)}>
            Batal
          </Button>
        </div>
      </form>
    </div>
  )
}
