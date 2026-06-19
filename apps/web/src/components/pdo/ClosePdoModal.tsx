import { useState } from 'react'
import { Button } from '@/components/ui/Button'
import { usePdoClose } from '@/hooks/usePdoClose'

interface Props {
  isOpen:      boolean
  pdoId:       string
  periodYear:  number
  periodMonth: number
  onSuccess:   () => void
  onClose:     () => void
}

function lastDayOfPeriod(year: number, month: number): string {
  // month basisnya 1–12, Date bulan berikutnya hari ke-0 = hari terakhir bulan ini
  return new Date(year, month, 0).toISOString().split('T')[0]
}

export function ClosePdoModal({ isOpen, pdoId, periodYear, periodMonth, onSuccess, onClose }: Props) {
  const today    = new Date().toISOString().split('T')[0]
  const maxDate  = lastDayOfPeriod(periodYear, periodMonth)

  const [closedDate, setClosedDate]     = useState(today)
  const [closureNotes, setClosureNotes] = useState('')

  const close = usePdoClose(pdoId, { onSuccess })

  if (!isOpen) return null

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    close.mutate({ closed_date: closedDate, closure_notes: closureNotes || undefined })
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
      <div className="card w-full max-w-md">
        <h2 className="text-base font-[850] text-ink mb-1">Tutup PDO</h2>
        <p className="text-sm text-muted mb-5">
          Setelah ditutup, PDO tidak dapat ditambah realisasi atau koreksi transfer.
        </p>

        <form onSubmit={handleSubmit} className="flex flex-col gap-4">
          <div>
            <label className="label">Tanggal Penutupan <span className="text-red">*</span></label>
            <input
              type="date"
              required
              min={today}
              max={maxDate}
              value={closedDate}
              onChange={(e) => setClosedDate(e.target.value)}
              className="input-base"
            />
            <p className="text-xs text-muted mt-1">Maks: {maxDate} (akhir bulan periode)</p>
          </div>

          <div>
            <label className="label">Catatan Penutupan <span className="text-muted">(opsional)</span></label>
            <textarea
              rows={3}
              maxLength={500}
              value={closureNotes}
              onChange={(e) => setClosureNotes(e.target.value)}
              placeholder="Alasan penutupan, catatan akhir, dll."
              className="input-base resize-none"
            />
            <p className="text-xs text-muted mt-1 text-right">{closureNotes.length}/500</p>
          </div>

          <div className="flex gap-2 justify-end pt-2">
            <Button type="button" variant="ghost" onClick={onClose} disabled={close.isPending}>
              Batal
            </Button>
            <Button
              type="submit"
              loading={close.isPending}
              disabled={!closedDate}
              className="bg-red text-white hover:bg-red/90"
            >
              Ya, Tutup PDO
            </Button>
          </div>
        </form>
      </div>
    </div>
  )
}
