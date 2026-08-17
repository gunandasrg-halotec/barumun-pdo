import { useEffect, useState } from 'react'
import { useForm, useFieldArray, Controller } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation } from '@tanstack/react-query'
import { Plus, Trash2 } from 'lucide-react'
import { api, getApiErrorMessage } from '@/lib/api'
import { Button } from '@/components/ui/Button'
import { Modal } from '@/components/ui/Modal'
import { SearchableSelect } from '@/components/ui/SearchableSelect'
import { useToastStore } from '@/store/toast.store'
import { fmt } from '@/lib/format'
import { INVENTORY_ITEM_CODES } from '@/lib/constants'
import type { Vehicle } from '@/types'
import type { PettyCashVoucher } from '@/types/pettycash'
import type { RealizationAvailableItem } from '@/pages/RekapitulasiPage'

const lineSchema = z.object({
  pdo_detail_id: z.string().min(1, 'Pilih item biaya'),
  vehicle_id:    z.string().nullable().optional(),
  description:   z.string().min(1, 'Deskripsi wajib diisi'),
  amount:        z.coerce.number().min(1, 'Jumlah harus > 0'),
})

const voucherSchema = z.object({
  paid_to:      z.string().min(1, 'Wajib diisi'),
  voucher_date: z.string().min(1, 'Tanggal wajib diisi'),
  lines:        z.array(lineSchema).min(1, 'Minimal 1 baris item'),
})

type VoucherFormValues = z.infer<typeof voucherSchema>

const emptyLine = { pdo_detail_id: '', vehicle_id: null, description: '', amount: 0 }

interface VoucherFormModalProps {
  open: boolean
  onClose: () => void
  pdo: { id: string; pdo_number: string } | undefined
  voucher: PettyCashVoucher | null
  availableItems: RealizationAvailableItem[]
  remainingKantong: number | null
  eligibleVehicles: Vehicle[] | undefined
  onSaved: () => void
}

export function VoucherFormModal({
  open, onClose, pdo, voucher, availableItems, remainingKantong, eligibleVehicles, onSaved,
}: VoucherFormModalProps) {
  const toast  = useToastStore((s) => s.push)
  const isEdit = !!voucher
  const [apiError, setApiError] = useState<string | null>(null)

  const { control, register, handleSubmit, reset, watch, setValue, formState: { errors } } = useForm<VoucherFormValues>({
    resolver: zodResolver(voucherSchema),
    defaultValues: {
      paid_to: '',
      voucher_date: new Date().toISOString().split('T')[0],
      lines: [emptyLine],
    },
  })
  const { fields, append, remove } = useFieldArray({ control, name: 'lines' })

  useEffect(() => {
    if (!open) return
    if (voucher) {
      reset({
        paid_to:      voucher.paid_to,
        voucher_date: voucher.voucher_date.split('T')[0],
        lines: voucher.lines.map((l) => ({
          pdo_detail_id: l.pdo_detail_id,
          vehicle_id:    l.vehicle_id,
          description:   l.description,
          amount:        l.amount,
        })),
      })
    } else {
      reset({
        paid_to: '',
        voucher_date: new Date().toISOString().split('T')[0],
        lines: [emptyLine],
      })
    }
    setApiError(null)
  }, [open, voucher, reset])

  // Item pengembalian sisa dana bulan lalu tidak melalui voucher (keputusan #8) —
  // satu-satunya filter di sini. SENGAJA TIDAK memfilter berdasarkan saldo item
  // (lihat §0-bis di plan): item yang saldonya sudah 0/negatif tetap boleh dipilih,
  // selama total voucher masih di bawah sisa kantong (dicek server-side).
  const itemOptions = availableItems
    .filter((d) => !d.is_fund_return)
    .map((d) => ({
      value: d.pdo_detail_id,
      label: [d.expense_item?.subcategory?.category?.name, d.expense_item?.subcategory?.name, d.expense_item?.name ?? d.description].filter(Boolean).join(' — '),
      sublabel: `Saldo: ${fmt(d.saldo)}`,
    }))

  const watchedLines = watch('lines')
  const total = watchedLines.reduce((s, l) => s + (Number(l.amount) || 0), 0)
  const overKantong = remainingKantong !== null && total > remainingKantong

  const save = useMutation({
    mutationFn: (data: VoucherFormValues) =>
      isEdit
        ? api.put(`/petty-cash-vouchers/${voucher!.id}`, data)
        : api.post(`/pdo/${pdo!.id}/petty-cash-vouchers`, data),
    onSuccess: () => {
      toast(isEdit ? 'Voucher berhasil diperbarui' : 'Voucher berhasil dibuat')
      onSaved()
      onClose()
    },
    onError: (error) => {
      const message = getApiErrorMessage(error)
      setApiError(message)
      toast(message, 'error')
    },
  })

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={isEdit ? `Edit Voucher — ${voucher?.voucher_number}` : 'Buat Voucher Kas Kecil'}
      width="w-[760px]"
    >
      {!pdo && !isEdit ? (
        <p className="text-sm text-muted">Tidak ada PDO aktif (status Final) untuk periode dan unit ini.</p>
      ) : (
        <form onSubmit={handleSubmit((d) => save.mutate(d))} className="flex flex-col gap-4">
          <div className="p-3 bg-[#f7faf7] border border-line rounded text-sm">
            PDO: <span className="font-bold">{pdo?.pdo_number}</span>
          </div>

          {remainingKantong !== null && (
            <div className={`rounded-lg px-4 py-3 border ${remainingKantong <= 0 ? 'bg-red-50 border-red-300' : 'bg-amber-50 border-amber-300'}`}>
              <p className={`text-xs font-bold uppercase tracking-wider mb-0.5 ${remainingKantong <= 0 ? 'text-red-500' : 'text-amber-600'}`}>
                Sisa Dana Kas Kebun
              </p>
              <p className={`text-2xl font-black ${remainingKantong <= 0 ? 'text-red-700' : 'text-amber-800'}`}>
                {fmt(remainingKantong)}
              </p>
            </div>
          )}

          {apiError && (
            <div className="p-3 bg-red-50 border border-red-200 rounded text-sm text-red-700">{apiError}</div>
          )}

          <div className="grid grid-cols-1 desk:grid-cols-2 gap-3">
            <div>
              <label className="label">Dibayarkan Kepada</label>
              <input {...register('paid_to')} className="input-base" placeholder="Nama penerima" />
              {errors.paid_to && <p className="field-error">{errors.paid_to.message}</p>}
            </div>
            <div>
              <label className="label">Tanggal Voucher</label>
              <input type="date" {...register('voucher_date')} className="input-base" />
              {errors.voucher_date && <p className="field-error">{errors.voucher_date.message}</p>}
            </div>
          </div>

          <div>
            <div className="flex items-center justify-between mb-2">
              <label className="label mb-0">Baris Item</label>
              <Button type="button" size="sm" variant="secondary" onClick={() => append(emptyLine)}>
                <Plus className="w-3.5 h-3.5" /> Tambah Baris
              </Button>
            </div>

            <div className="flex flex-col gap-3">
              {fields.map((field, i) => {
                const selectedItem = availableItems.find((d) => d.pdo_detail_id === watchedLines[i]?.pdo_detail_id)
                const itemCode     = selectedItem?.expense_item?.code ?? ''
                const isInventory  = INVENTORY_ITEM_CODES.includes(itemCode)

                return (
                  <div key={field.id} className="p-3 border border-line rounded-lg">
                    <div className="flex items-start gap-2">
                      <div className="flex-1 flex flex-col gap-2">
                        <Controller
                          name={`lines.${i}.pdo_detail_id`}
                          control={control}
                          render={({ field: f }) => (
                            <SearchableSelect
                              value={f.value}
                              onChange={(v) => {
                                f.onChange(v)
                                setValue(`lines.${i}.vehicle_id`, null)
                              }}
                              placeholder="Cari & pilih item..."
                              noOptionsMessage="Item tidak ditemukan"
                              options={itemOptions}
                            />
                          )}
                        />
                        {errors.lines?.[i]?.pdo_detail_id && (
                          <p className="field-error">{errors.lines[i]?.pdo_detail_id?.message}</p>
                        )}

                        <div className="grid grid-cols-1 desk:grid-cols-[1fr_180px] gap-2">
                          <div>
                            <input
                              {...register(`lines.${i}.description`)}
                              className="input-base"
                              placeholder="Keterangan"
                            />
                            {errors.lines?.[i]?.description && (
                              <p className="field-error">{errors.lines[i]?.description?.message}</p>
                            )}
                          </div>
                          <div>
                            <input
                              type="number"
                              {...register(`lines.${i}.amount`)}
                              className="input-base"
                              placeholder="Jumlah (Rp)"
                            />
                            {errors.lines?.[i]?.amount && (
                              <p className="field-error">{errors.lines[i]?.amount?.message}</p>
                            )}
                          </div>
                        </div>

                        {isInventory && (
                          <div>
                            <select {...register(`lines.${i}.vehicle_id`)} className="input-base">
                              <option value="">— Pilih kendaraan —</option>
                              {eligibleVehicles?.map((v) => (
                                <option key={v.id} value={v.id}>{v.nomor_polisi} — {v.nama}</option>
                              ))}
                            </select>
                          </div>
                        )}
                      </div>

                      {fields.length > 1 && (
                        <button
                          type="button"
                          onClick={() => remove(i)}
                          className="mt-2 text-muted hover:text-red-600 transition-colors"
                          title="Hapus baris"
                        >
                          <Trash2 className="w-4 h-4" />
                        </button>
                      )}
                    </div>
                  </div>
                )
              })}
            </div>
            {errors.lines?.root && <p className="field-error">{errors.lines.root.message}</p>}
          </div>

          <div className={`flex items-center justify-between p-3 rounded-lg border ${overKantong ? 'bg-red-50 border-red-300' : 'bg-[#f7faf7] border-line'}`}>
            <span className="text-sm font-bold text-ink">Total Voucher</span>
            <span className={`text-lg font-black ${overKantong ? 'text-red-700' : 'text-ink'}`}>{fmt(total)}</span>
          </div>
          {overKantong && (
            <p className="text-xs font-bold text-red-600 -mt-2">
              Total melebihi sisa dana kas kebun. Kurangi jumlah baris sebelum menyimpan.
            </p>
          )}

          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="secondary" onClick={onClose}>Batal</Button>
            <Button type="submit" loading={save.isPending}>
              {isEdit ? 'Simpan Perubahan' : 'Simpan Voucher'}
            </Button>
          </div>
        </form>
      )}
    </Modal>
  )
}
