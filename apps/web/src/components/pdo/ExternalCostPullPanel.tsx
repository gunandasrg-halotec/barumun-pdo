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
  const tooltipLines = [
    'Auto External: data volume, satuan, harga, dan jumlah diambil dari Payroll.',
    showNeedsPull ? 'Baris ini wajib Ambil Data dulu sebelum submit PDO.' : undefined,
    showStale ? 'Snapshot external sudah stale. Ambil Data ulang sebelum submit PDO.' : undefined,
    showReadOnly ? 'Nilai volume, satuan, dan jumlah dikunci selama item masih Auto External aktif.' : undefined,
    errorMessage ? `Error: ${errorMessage}` : undefined,
    hasPulledMetadata ? `Komponen: ${payload?.component_label ?? snapshot?.external_component ?? '—'}` : undefined,
    hasPulledMetadata ? `Periode Sumber: ${payload?.period ?? '—'}` : undefined,
    hasPulledMetadata ? `Status Sumber: ${payload?.status ?? 'ok'}` : undefined,
    hasPulledMetadata ? `Tarik Terakhir: ${formatDateTime(snapshot?.external_amount_pulled_at)}` : 'Belum ada data Payroll ditarik untuk baris ini.',
    hasPulledMetadata ? `Dibuat Payroll: ${formatDateTime(payload?.generated_at)}` : undefined,
    hasPulledMetadata ? `Estate Payroll: ${payload?.estate_external_id ?? '—'}` : undefined,
  ].filter(Boolean).join('\n')

  return (
    <div className="space-y-1.5">
      <Button
        type="button"
        size="sm"
        data-testid="pull-external-button"
        title={tooltipLines}
        className="border border-transparent bg-[#2563eb] text-white hover:bg-[#1d4ed8] disabled:border-[#93c5fd] disabled:bg-[#dbeafe] disabled:text-[#1e3a8a] disabled:opacity-100 disabled:hover:bg-[#dbeafe]"
        loading={isPulling}
        disabled={!snapshot?.id}
        onClick={onPull}
      >
        <CloudDownload className="w-4 h-4" /> {isPulling ? 'Mengambil...' : 'Ambil Data'}
      </Button>

      {errorMessage && (
        <p className="text-[11px] font-semibold text-[#b91c1c]">{errorMessage}</p>
      )}
    </div>
  )
}
