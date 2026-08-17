import { Printer, Upload, Pencil, Trash2, FileText } from 'lucide-react'
import { EmptyState } from '@/components/ui/EmptyState'
import { fmt, fmtDate } from '@/lib/format'
import type { PettyCashVoucher } from '@/types/pettycash'

interface VoucherListPanelProps {
  vouchers: PettyCashVoucher[] | undefined
  isLoading: boolean
  canManage: boolean
  printingId: string | null
  onEdit: (voucher: PettyCashVoucher) => void
  onDelete: (voucher: PettyCashVoucher) => void
  onUploadScan: (voucher: PettyCashVoucher) => void
  onPrint: (voucher: PettyCashVoucher) => void
  onViewScan: (voucherId: string) => void
}

export function VoucherListPanel({
  vouchers, isLoading, canManage, printingId, onEdit, onDelete, onUploadScan, onPrint, onViewScan,
}: VoucherListPanelProps) {
  if (isLoading) {
    return (
      <div className="card space-y-3">
        {Array.from({ length: 3 }).map((_, i) => (
          <div key={i} className="h-8 bg-[#f0f4f0] rounded animate-pulse" />
        ))}
      </div>
    )
  }

  if (!vouchers || vouchers.length === 0) {
    return <EmptyState message="Belum ada Petty Cash Voucher untuk periode dan unit ini." />
  }

  return (
    <div className="card p-0 overflow-hidden">
      <div className="overflow-x-auto">
        <table className="w-full border-collapse text-sm">
          <thead>
            <tr>
              {['No. Voucher', 'Tgl', 'Dibayarkan Kepada', 'Jml Baris', 'Total', 'Status', 'Aksi'].map((h) => (
                <th key={h} className="px-3 py-2 text-left text-[11px] font-bold uppercase tracking-wider bg-[#f7faf7] border-b border-line">
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {vouchers.map((v) => {
              const isDraft = v.status === 'draft'
              return (
                <tr key={v.id} className="border-t border-line hover:bg-[#fbfdfb] align-top">
                  <td className="px-3 py-2 font-bold whitespace-nowrap">{v.voucher_number}</td>
                  <td className="px-3 py-2 whitespace-nowrap">{fmtDate(v.voucher_date)}</td>
                  <td className="px-3 py-2">{v.paid_to}</td>
                  <td className="px-3 py-2 text-center">{v.lines.length}</td>
                  <td className="px-3 py-2 text-right font-bold tabular-nums">{fmt(v.total_amount)}</td>
                  <td className="px-3 py-2">
                    <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold ${
                      isDraft ? 'bg-amber-100 text-amber-700' : 'bg-[#e8f6ef] text-[#0F6E56]'
                    }`}>
                      {isDraft ? 'Draft' : 'Terkunci'}
                    </span>
                    {!isDraft && v.lines.some((l) => l.realization_entry) && (
                      <div className="flex flex-wrap gap-1 mt-1.5">
                        {v.lines.filter((l) => l.realization_entry).map((l) => (
                          <span key={l.id} className="text-[10px] font-mono px-1.5 py-0.5 bg-[#f7faf7] border border-line rounded">
                            {l.realization_entry!.proof_number}
                          </span>
                        ))}
                      </div>
                    )}
                  </td>
                  <td className="px-3 py-2">
                    <div className="flex flex-wrap gap-2">
                      <button
                        type="button"
                        className="text-xs font-bold text-[#0F6E56] hover:underline inline-flex items-center gap-1 disabled:opacity-50 disabled:cursor-not-allowed"
                        onClick={() => onPrint(v)}
                        disabled={printingId === v.id}
                      >
                        <Printer className="w-3.5 h-3.5" /> {printingId === v.id ? 'Mencetak…' : 'Cetak'}
                      </button>
                      {isDraft && canManage && (
                        <>
                          <button
                            type="button"
                            className="text-xs font-bold text-[#185FA5] hover:underline inline-flex items-center gap-1"
                            onClick={() => onUploadScan(v)}
                          >
                            <Upload className="w-3.5 h-3.5" /> Upload Scan
                          </button>
                          <button
                            type="button"
                            className="text-xs font-bold text-ink hover:underline inline-flex items-center gap-1"
                            onClick={() => onEdit(v)}
                          >
                            <Pencil className="w-3.5 h-3.5" /> Edit
                          </button>
                          <button
                            type="button"
                            className="text-xs font-bold text-red-600 hover:underline inline-flex items-center gap-1"
                            onClick={() => onDelete(v)}
                          >
                            <Trash2 className="w-3.5 h-3.5" /> Hapus
                          </button>
                        </>
                      )}
                      {!isDraft && v.scan_file_name && (
                        <button
                          type="button"
                          className="text-xs font-bold text-[#185FA5] hover:underline inline-flex items-center gap-1"
                          onClick={() => onViewScan(v.id)}
                        >
                          <FileText className="w-3.5 h-3.5" /> Lihat Scan
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
    </div>
  )
}
