import { useState } from 'react'
import { Button } from '@/components/ui/Button'
import { usePdoClose } from '@/hooks/usePdoClose'
import { fmt } from '@/lib/format'

interface DraftVoucherWarning {
  voucher_number: string
  paid_to: string
  total: number
}

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
  const [draftWarning, setDraftWarning] = useState<DraftVoucherWarning[] | null>(null)

  const close = usePdoClose(pdoId, { onSuccess })

  if (!isOpen) return null

  const attemptClose = (acknowledgeDraftVouchers: boolean) => {
    close.mutate(
      {
        closed_date: closedDate,
        closure_notes: closureNotes || undefined,
        acknowledge_draft_vouchers: acknowledgeDraftVouchers || undefined,
      },
      {
        onError: (err) => {
          const response = (err as { response?: { data?: { error?: { code?: string; vouchers?: DraftVoucherWarning[] } } } })?.response
          const error = response?.data?.error
          if (error?.code === 'PDO_HAS_DRAFT_VOUCHERS') {
            setDraftWarning(error.vouchers ?? [])
          }
        },
      }
    )
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    attemptClose(false)
  }

  if (draftWarning) {
    return (
      <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
        <div className="card w-full max-w-md">
          <h2 className="text-base font-[850] text-ink mb-1">Masih Ada Voucher Draft</h2>
          <p className="text-sm text-muted mb-4">
            PDO ini masih punya {draftWarning.length} Petty Cash Voucher berstatus draft (belum di-scan).
            Realisasi dari voucher ini belum tercatat. Tutup PDO tetap bisa dilanjutkan, tapi voucher draft
            akan tetap tertinggal — pastikan ini memang disengaja.
          </p>
          <div className="flex flex-col gap-1.5 mb-5 max-h-48 overflow-y-auto">
            {draftWarning.map((v) => (
              <div key={v.voucher_number} className="flex items-center justify-between text-sm px-3 py-2 bg-amber-50 border border-amber-200 rounded">
                <div>
                  <div className="font-bold">{v.voucher_number}</div>
                  <div className="text-xs text-muted">{v.paid_to}</div>
                </div>
                <div className="font-bold">{fmt(v.total)}</div>
              </div>
            ))}
          </div>
          <div className="flex gap-2 justify-end pt-2">
            <Button type="button" variant="ghost" onClick={() => setDraftWarning(null)} disabled={close.isPending}>
              Batal
            </Button>
            <Button
              type="button"
              loading={close.isPending}
              onClick={() => attemptClose(true)}
              className="bg-red text-white hover:bg-red/90"
            >
              Tetap Tutup PDO
            </Button>
          </div>
        </div>
      </div>
    )
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
