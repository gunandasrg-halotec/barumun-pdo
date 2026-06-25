import { CloudDownload } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import type { PdoDetail } from '@/types'

type DetailSnapshot = Partial<PdoDetail> & { id?: string }

interface ExternalCostPullPanelProps {
  errorMessage?: string
  isPulling: boolean
  onPull: () => void
  snapshot?: DetailSnapshot
}

function formatDateTime(value: string | null | undefined): string {
  if (!value) {
    return '—'
  }

  return new Date(value).toLocaleString('id-ID')
}

export function ExternalCostPullPanel({
  errorMessage,
  isPulling,
  onPull,
  snapshot,
}: ExternalCostPullPanelProps) {
  const payload = snapshot?.external_payload
  const hasPulledMetadata = !!snapshot?.external_amount_pulled_at && !!payload
  const showNeedsPull = snapshot?.needs_pull
  const showStale = snapshot?.is_stale_external_snapshot
  const showReadOnly = snapshot?.is_external_read_only

  return (
    <div className="mt-3 p-3 bg-blue-50 border border-blue-200 rounded space-y-3">
      <div className="flex flex-col gap-3 desk:flex-row desk:items-center desk:justify-between">
        <p className="text-sm text-blue-700">
          <strong>Auto External:</strong> Data volume, satuan, harga, dan jumlah diambil dari Payroll.
        </p>
        <Button
          type="button"
          size="sm"
          data-testid="pull-external-button"
          className="bg-blue-600 text-white hover:bg-blue-700"
          loading={isPulling}
          disabled={!snapshot?.id}
          onClick={onPull}
        >
          <CloudDownload className="w-4 h-4" /> {isPulling ? 'Mengambil...' : 'Ambil Data'}
        </Button>
      </div>

      {showNeedsPull && (
        <p className="text-sm font-semibold text-amber-700">
          Baris ini wajib Ambil Data dulu sebelum submit PDO.
        </p>
      )}

      {showStale && (
        <p className="text-sm font-semibold text-red-700">
          Snapshot external sudah stale. Ambil Data ulang sebelum submit PDO.
        </p>
      )}

      {showReadOnly && (
        <p className="text-sm text-blue-800">
          Nilai volume, satuan, dan jumlah dikunci selama item masih Auto External aktif.
        </p>
      )}

      {errorMessage && (
        <p className="text-sm font-semibold text-red-700">{errorMessage}</p>
      )}

      {hasPulledMetadata ? (
        <div className="grid grid-cols-1 desk:grid-cols-2 gap-2 text-sm text-blue-900">
          <p data-testid="external-component"><strong>Komponen:</strong> {payload?.component_label ?? snapshot?.external_component ?? '—'}</p>
          <p data-testid="external-period"><strong>Periode Sumber:</strong> {payload?.period ?? '—'}</p>
          <p data-testid="external-status"><strong>Status Sumber:</strong> {payload?.status ?? 'ok'}</p>
          <p data-testid="external-pulled-at"><strong>Tarik Terakhir:</strong> {formatDateTime(snapshot?.external_amount_pulled_at)}</p>
          <p data-testid="external-generated-at"><strong>Dibuat Payroll:</strong> {formatDateTime(payload?.generated_at)}</p>
          <p data-testid="external-estate"><strong>Estate Payroll:</strong> {payload?.estate_external_id ?? '—'}</p>
        </div>
      ) : (
        <p className="text-sm text-blue-800">Belum ada data Payroll ditarik untuk baris ini.</p>
      )}
    </div>
  )
}
