import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Modal } from '@/components/ui/Modal'
import { Button } from '@/components/ui/Button'
import { PdoStatusBadge } from '@/components/ui/Badge'
import { usePdoList } from '@/hooks/usePdo'
import { useAuthStore } from '@/store/auth.store'
import { fmtPeriode } from '@/lib/format'
import type { PdoHeader } from '@/types'

interface Props {
  open: boolean
  onClose: () => void
}

export function CreatePdoMethodModal({ open, onClose }: Props) {
  const navigate = useNavigate()
  const user     = useAuthStore((s) => s.user)

  const [step, setStep]               = useState<1 | 2>(1)
  const [selectedPdo, setSelectedPdo] = useState<PdoHeader | null>(null)
  const [copyAmounts, setCopyAmounts] = useState(false)
  const [search, setSearch]           = useState('')

  const { data, isLoading } = usePdoList({
    plantation_unit_id: user?.plantation_unit?.id,
  })

  const pdoList = (data?.data ?? []).filter((p) =>
    !search || p.pdo_number.toLowerCase().includes(search.toLowerCase())
  )

  const handleClose = () => {
    setStep(1)
    setSelectedPdo(null)
    setCopyAmounts(false)
    setSearch('')
    onClose()
  }

  const handleUseTemplate = () => {
    handleClose()
    navigate('/pdo/buat')
  }

  const handleLanjutkan = () => {
    if (!selectedPdo) return
    handleClose()
    navigate('/pdo/buat', {
      state: {
        sourcePdoId:     selectedPdo.id,
        sourcePdoNumber: selectedPdo.pdo_number,
        copyAmounts,
      },
    })
  }

  return (
    <Modal open={open} onClose={handleClose} title="Buat PDO Baru" width="w-[520px]">
      {step === 1 ? (
        <div className="flex flex-col gap-3">
          <p className="text-sm text-muted mb-1">Pilih metode pembuatan PDO baru:</p>

          <button
            className="border border-line rounded-card p-4 text-left hover:border-green hover:bg-[#f7faf7] transition-colors"
            onClick={handleUseTemplate}
          >
            <p className="text-sm font-[850] text-ink mb-1">Gunakan Template</p>
            <p className="text-xs text-muted">
              Item biaya disisipkan otomatis dari daftar item rutin yang aktif.
            </p>
          </button>

          <button
            className="border border-line rounded-card p-4 text-left hover:border-green hover:bg-[#f7faf7] transition-colors"
            onClick={() => setStep(2)}
          >
            <p className="text-sm font-[850] text-ink mb-1">Gunakan PDO yang Telah Ada</p>
            <p className="text-xs text-muted">
              Salin item biaya dari PDO yang sudah pernah dibuat sebelumnya.
            </p>
          </button>

          <div className="flex justify-end mt-2">
            <Button variant="secondary" onClick={handleClose}>Batal</Button>
          </div>
        </div>
      ) : (
        <div className="flex flex-col gap-4">
          <p className="text-sm text-muted -mb-1">Pilih PDO sumber:</p>

          <input
            className="input-base w-full"
            placeholder="Cari nomor PDO..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            autoFocus
          />

          <div className="border border-line rounded-card overflow-hidden max-h-56 overflow-y-auto">
            {isLoading ? (
              <div className="p-4 text-center text-sm text-muted">Memuat data...</div>
            ) : pdoList.length === 0 ? (
              <div className="p-4 text-center text-sm text-muted">Tidak ada PDO ditemukan.</div>
            ) : (
              pdoList.map((pdo) => (
                <button
                  key={pdo.id}
                  className={[
                    'w-full flex items-center justify-between px-3 py-2.5 text-left',
                    'border-b border-line last:border-b-0 hover:bg-[#f7faf7] transition-colors',
                    selectedPdo?.id === pdo.id ? 'bg-[#edf7f2]' : '',
                  ].join(' ')}
                  onClick={() => setSelectedPdo(pdo)}
                >
                  <div>
                    <p className="text-sm font-[850] text-ink">{pdo.pdo_number}</p>
                    <p className="text-xs text-muted">{fmtPeriode(pdo.period_month, pdo.period_year)}</p>
                  </div>
                  <PdoStatusBadge status={pdo.status} />
                </button>
              ))
            )}
          </div>

          <label className="flex items-start gap-2.5 cursor-pointer select-none">
            <input
              type="checkbox"
              className="mt-0.5 shrink-0"
              checked={copyAmounts}
              onChange={(e) => setCopyAmounts(e.target.checked)}
            />
            <div>
              <p className="text-sm font-medium text-ink">Salin jumlah biaya dari PDO yang dipilih</p>
              <p className="text-xs text-muted">
                Tidak berlaku untuk item dengan biaya dari sistem external (Auto External).
              </p>
            </div>
          </label>

          <div className="flex justify-between mt-1">
            <Button variant="secondary" onClick={() => { setStep(1); setSelectedPdo(null) }}>
              Kembali
            </Button>
            <Button onClick={handleLanjutkan} disabled={!selectedPdo}>
              Lanjutkan
            </Button>
          </div>
        </div>
      )}
    </Modal>
  )
}
