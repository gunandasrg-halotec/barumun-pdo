import { Modal } from '@/components/ui/Modal'
import { Button } from '@/components/ui/Button'
import { useVehicles } from '@/hooks/useMasterData'

export interface VehicleAssignmentItem {
  pdoDetailId: string
  itemCode: string
  itemName: string
}

interface VehicleAssignmentModalProps {
  open: boolean
  items: VehicleAssignmentItem[]
  values: Record<string, string>
  onChange: (pdoDetailId: string, vehicleId: string) => void
  onClose: () => void
  onConfirm: () => void
  loading?: boolean
}

export function VehicleAssignmentModal({
  open, items, values, onChange, onClose, onConfirm, loading,
}: VehicleAssignmentModalProps) {
  const { data: vehicles } = useVehicles()

  const allFilled = items.length > 0 && items.every((item) => !!values[item.pdoDetailId])

  return (
    <Modal open={open} onClose={onClose} title="Pilih Kendaraan" width="w-[640px]">
      <p className="text-sm text-muted mb-4">
        Item berikut termasuk biaya persediaan (BBM/suku cadang) — pilih kendaraan untuk tiap item sebelum menandai transfer sebagai sudah ditransfer.
      </p>

      <div className="space-y-4 mb-6">
        {items.map((item) => (
          <div key={item.pdoDetailId}>
            <label className="label">{item.itemCode} — {item.itemName}</label>
            <select
              className="input-base"
              value={values[item.pdoDetailId] ?? ''}
              onChange={(e) => onChange(item.pdoDetailId, e.target.value)}
            >
              <option value="">— Pilih kendaraan —</option>
              {(vehicles ?? []).map((v) => (
                <option key={v.id} value={v.id}>{v.nomor_polisi} — {v.nama}</option>
              ))}
            </select>
          </div>
        ))}
      </div>

      <div className="flex justify-end gap-2">
        <Button variant="secondary" onClick={onClose} type="button">Batal</Button>
        <Button onClick={onConfirm} disabled={!allFilled} loading={loading} type="button">
          Konfirmasi & Tandai Sudah Ditransfer
        </Button>
      </div>
    </Modal>
  )
}
