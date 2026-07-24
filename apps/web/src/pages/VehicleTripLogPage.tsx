import { useEffect, useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useQuery } from '@tanstack/react-query'
import { Truck, Trash2 } from 'lucide-react'
import { api, getApiErrorMessage } from '@/lib/api'
import { Button } from '@/components/ui/Button'
import { Badge } from '@/components/ui/Badge'
import { EmptyState } from '@/components/ui/EmptyState'
import { useToastStore } from '@/store/toast.store'
import { useVehicles, useVehicleTripLogs, useCreateVehicleTripLog, useDeleteVehicleTripLog } from '@/hooks/useMasterData'
import type { ApiResponse, PdoHeader } from '@/types'

// 1 unit jarak = 5km, sampai 100km, lalu 1 kategori terbuka utk >100km.
const JARAK_OPTIONS = [
  ...Array.from({ length: 20 }, (_, i) => ({
    value: i + 1,
    label: `${i * 5}-${(i + 1) * 5} km`,
  })),
  { value: 21, label: '> 100 km' },
]

const schema = z.object({
  pdo_header_id: z.string().uuid('Pilih PDO'),
  vehicle_id:    z.string().uuid('Pilih kendaraan'),
  trip_date:     z.string().min(1, 'Tanggal wajib diisi'),
  driver_name:   z.string().min(1, 'Nama supir wajib diisi').max(150),
  trip_count:    z.preprocess((v) => Number(v), z.number().int().min(1, 'Minimal 1 trip')),
  trip_type:     z.enum(['angkut_tbs', 'perawatan'], { required_error: 'Pilih jenis trip' }),
  destination:   z.string().min(1, 'Tujuan wajib diisi').max(150),
  weight:        z.preprocess((v) => Number(v), z.number().int().min(1).max(21, 'Pilih jarak')),
  notes:         z.string().optional(),
})

type Form = z.infer<typeof schema>

export default function VehicleTripLogPage() {
  const toast = useToastStore((s) => s.push)
  const [selectedPdoId, setSelectedPdoId] = useState<string>('')

  const { data: pdoList, isLoading: loadingPdo } = useQuery({
    queryKey: ['pdo-final-for-trip-log'],
    queryFn: async () => {
      const res = await api.get<ApiResponse<PdoHeader[]>>('/pdo', {
        params: { status: 'final' },
      })
      return res.data.data
    },
  })

  const selectedPdo = pdoList?.find((p) => p.id === selectedPdoId)

  // Hanya kendaraan aktif yang sudah pernah direalisasikan pembelian BBM-nya
  const { data: vehicles } = useVehicles({ has_bbm_realization: true, is_active: true })

  const { data: tripLogs, isLoading: loadingLogs } = useVehicleTripLogs(selectedPdoId || undefined)

  const createMutation = useCreateVehicleTripLog()
  const deleteMutation = useDeleteVehicleTripLog()

  const {
    register,
    handleSubmit,
    reset,
    watch,
    formState: { errors, isSubmitting },
  } = useForm<Form>({
    resolver: zodResolver(schema),
    defaultValues: { trip_count: 1, trip_type: 'angkut_tbs' },
  })

  // Cek jarak terakhir yang tercatat ke tujuan yang sama (lingkup unit kebun
  // PDO ini), dipakai utk deteksi jika kerani mengisi jarak berbeda dari
  // catatan sebelumnya ke tujuan yang sama.
  const destinationValue = watch('destination')
  const weightValue       = watch('weight')
  const [destinationQuery, setDestinationQuery] = useState('')

  useEffect(() => {
    const t = setTimeout(() => setDestinationQuery(destinationValue?.trim() ?? ''), 400)
    return () => clearTimeout(t)
  }, [destinationValue])

  const { data: lastWeightInfo } = useQuery({
    queryKey: ['vehicle-trip-log-last-weight', selectedPdoId, destinationQuery],
    queryFn: async () => {
      const res = await api.get<ApiResponse<{ weight: number; trip_date: string } | null>>(
        '/vehicle-trip-logs/last-weight',
        { params: { pdo_header_id: selectedPdoId, destination: destinationQuery } }
      )
      return res.data.data
    },
    enabled: !!selectedPdoId && !!destinationQuery,
  })

  // Hanya dianggap mismatch kalau jarak baru LEBIH BESAR dari catatan
  // sebelumnya ke tujuan yang sama — jarak lebih kecil valid tanpa peringatan
  // (mis. trip pulang-pergi dipecah jadi 2 entry per arah).
  const hasWeightMismatch = !!lastWeightInfo && !!weightValue && Number(weightValue) > Number(lastWeightInfo.weight)

  const onSubmit = async (data: Form) => {
    if (hasWeightMismatch && !data.notes?.trim()) {
      toast('Jarak berbeda dari catatan sebelumnya ke tujuan ini. Isi Keterangan dengan alasan perbedaan jarak.', 'error')
      return
    }
    try {
      await createMutation.mutateAsync(data)
      toast('Log trip berhasil dicatat.', 'success')
      reset({ pdo_header_id: selectedPdoId, trip_count: 1, trip_type: 'angkut_tbs' })
      setDestinationQuery('')
    } catch (err) {
      toast(getApiErrorMessage(err), 'error')
    }
  }

  const handleDelete = async (id: string) => {
    if (!window.confirm('Hapus log trip ini?')) return
    try {
      await deleteMutation.mutateAsync({ id, pdoHeaderId: selectedPdoId })
      toast('Log trip dihapus.', 'success')
    } catch (err) {
      toast(getApiErrorMessage(err), 'error')
    }
  }

  return (
    <div>
      <div className="mb-6">
        <h2 className="text-[28px] font-[950] text-ink flex items-center gap-2">
          <Truck size={28} /> Log Trip Kendaraan
        </h2>
        <p className="text-sm text-muted mt-1">
          Catat penggunaan kendaraan (Angkut TBS / Perawatan) per PDO untuk perhitungan split jurnal persediaan.
        </p>
      </div>

      {/* PDO Selector */}
      <div className="mb-6 max-w-sm">
        <label className="block text-sm font-medium mb-1">Pilih PDO</label>
        <select
          className="input-base w-full"
          value={selectedPdoId}
          onChange={(e) => setSelectedPdoId(e.target.value)}
        >
          <option value="">— Pilih PDO —</option>
          {loadingPdo && <option disabled>Memuat...</option>}
          {pdoList?.map((p) => (
            <option key={p.id} value={p.id}>
              {p.pdo_number} — {p.plantation_unit?.name ?? p.plantation_unit_id}
            </option>
          ))}
        </select>
        {selectedPdo && (
          <p className="text-xs text-muted mt-1">
            Unit Kebun: <span className="font-semibold">{selectedPdo.plantation_unit?.name ?? '—'}</span>
          </p>
        )}
      </div>

      {selectedPdoId && (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
          {/* Form input */}
          <div className="border border-line rounded-drawer p-5 bg-white">
            <h3 className="font-bold text-sm mb-4">Catat Trip Baru</h3>
            <form onSubmit={handleSubmit(onSubmit)} className="space-y-3">
              <input type="hidden" {...register('pdo_header_id')} value={selectedPdoId} />

              <div>
                <label className="block text-sm font-medium mb-1">Kendaraan</label>
                <select {...register('vehicle_id')} className="input-base w-full">
                  <option value="">— Pilih kendaraan —</option>
                  {vehicles?.map((v) => (
                    <option key={v.id} value={v.id}>
                      {v.nomor_polisi} — {v.nama}
                    </option>
                  ))}
                </select>
                {errors.vehicle_id && (
                  <p className="text-red-500 text-xs mt-1">{errors.vehicle_id.message}</p>
                )}
                {(!vehicles || vehicles.length === 0) && (
                  <p className="text-xs text-amber-600 mt-1">
                    Belum ada kendaraan yang tercatat pembelian BBM-nya di PDO ini.
                  </p>
                )}
              </div>

              <div>
                <label className="block text-sm font-medium mb-1">Tanggal</label>
                <input type="date" {...register('trip_date')} className="input-base w-full" />
                {errors.trip_date && (
                  <p className="text-red-500 text-xs mt-1">{errors.trip_date.message}</p>
                )}
              </div>

              <div>
                <label className="block text-sm font-medium mb-1">Nama Supir</label>
                <input {...register('driver_name')} className="input-base w-full" placeholder="Nama supir" />
                {errors.driver_name && (
                  <p className="text-red-500 text-xs mt-1">{errors.driver_name.message}</p>
                )}
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-sm font-medium mb-1">Jumlah Trip</label>
                  <input
                    type="number"
                    min={1}
                    {...register('trip_count')}
                    className="input-base w-full"
                  />
                  {errors.trip_count && (
                    <p className="text-red-500 text-xs mt-1">{errors.trip_count.message}</p>
                  )}
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Jenis Trip</label>
                  <select {...register('trip_type')} className="input-base w-full">
                    <option value="angkut_tbs">Angkut TBS</option>
                    <option value="perawatan">Perawatan</option>
                  </select>
                </div>
              </div>

              <div>
                <label className="block text-sm font-medium mb-1">Tujuan</label>
                <input
                  {...register('destination')}
                  className="input-base w-full"
                  placeholder="Mis. PKS Aman, RAM Lokal"
                />
                {errors.destination && (
                  <p className="text-red-500 text-xs mt-1">{errors.destination.message}</p>
                )}
              </div>

              <div>
                <label className="block text-sm font-medium mb-1">Jarak</label>
                <select {...register('weight')} className="input-base w-full">
                  <option value="">— Pilih jarak —</option>
                  {JARAK_OPTIONS.map((o) => (
                    <option key={o.value} value={o.value}>{o.label}</option>
                  ))}
                </select>
                {errors.weight && (
                  <p className="text-red-500 text-xs mt-1">{errors.weight.message}</p>
                )}
                {hasWeightMismatch && lastWeightInfo && (
                  <p className="text-xs text-amber-600 mt-1">
                    Jarak ke "{destinationValue}" sebelumnya tercatat lebih kecil{' '}
                    ({JARAK_OPTIONS.find((o) => o.value === Number(lastWeightInfo.weight))?.label ?? `bobot ${lastWeightInfo.weight}`}
                    {' '}pada {lastWeightInfo.trip_date}). Jika memang jarak kali ini lebih jauh, jelaskan alasannya di Keterangan.
                  </p>
                )}
              </div>

              <div>
                <label className="block text-sm font-medium mb-1">
                  Keterangan{' '}
                  {hasWeightMismatch ? (
                    <span className="text-red-500 font-normal">(wajib — alasan perbedaan jarak)</span>
                  ) : (
                    <span className="text-gray-400 font-normal">(opsional)</span>
                  )}
                </label>
                <textarea
                  {...register('notes')}
                  className="input-base w-full"
                  rows={2}
                  placeholder="Rute, catatan lain, dll."
                />
              </div>

              <Button type="submit" disabled={isSubmitting} className="w-full">
                {isSubmitting ? 'Menyimpan...' : 'Catat Trip'}
              </Button>
            </form>
          </div>

          {/* Log list */}
          <div>
            <h3 className="font-bold text-sm mb-3">Log Trip untuk PDO ini</h3>
            {loadingLogs ? (
              <p className="text-sm text-muted">Memuat log...</p>
            ) : !tripLogs?.length ? (
              <EmptyState />
            ) : (
              <div className="border border-line rounded-drawer overflow-auto bg-white">
                <table className="w-full border-collapse text-sm">
                  <thead>
                    <tr>
                      {['Tanggal', 'Kendaraan', 'Supir', 'Trip', 'Jenis', 'Tujuan', 'Jarak', ''].map((h) => (
                        <th key={h} className="px-3 py-2 text-left text-[11px] font-bold uppercase text-[#526257] bg-[#f7faf7] sticky top-0">
                          {h}
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {tripLogs.map((log) => (
                      <tr key={log.id} className="border-t border-line hover:bg-[#fbfdfb]">
                        <td className="px-3 py-2 whitespace-nowrap">{log.trip_date}</td>
                        <td className="px-3 py-2 font-mono text-xs">
                          {log.vehicle?.nomor_polisi ?? '—'}
                        </td>
                        <td className="px-3 py-2">{log.driver_name}</td>
                        <td className="px-3 py-2 text-right tabular-nums">{log.trip_count}</td>
                        <td className="px-3 py-2">
                          <Badge variant={log.trip_type === 'angkut_tbs' ? 'approved' : 'purple'}>
                            {log.trip_type === 'angkut_tbs' ? 'Angkut TBS' : 'Perawatan'}
                          </Badge>
                        </td>
                        <td className="px-3 py-2">{log.destination}</td>
                        <td className="px-3 py-2 text-right tabular-nums">
                          {JARAK_OPTIONS.find((o) => o.value === log.weight)?.label ?? log.weight}
                        </td>
                        <td className="px-3 py-2">
                          <button
                            onClick={() => handleDelete(log.id)}
                            className="text-red-500 hover:text-red-700"
                            title="Hapus"
                          >
                            <Trash2 size={14} />
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  )
}
