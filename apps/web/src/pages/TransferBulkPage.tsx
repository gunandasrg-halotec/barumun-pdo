import { useEffect, useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { Button } from '@/components/ui/Button'
import { useToastStore } from '@/store/toast.store'
import { fmt, fmtDate } from '@/lib/format'
import { ArrowLeft } from 'lucide-react'
import type { ApiResponse } from '@/types'

// ─── types ────────────────────────────────────────────────────────────────────

interface PdoDetailSummary {
  pdo_detail_id:     string
  expense_item:      { id: string; code: string; name: string } | null
  description:       string
  amount_approved:   number
  total_transferred: number
  remaining:         number
}

interface RowState {
  pdo_detail_id:     string
  amount:            number
  transfer_date:     string
  reference_number:  string
  notes:             string
}

const today = new Date().toISOString().split('T')[0]

// ─── component ────────────────────────────────────────────────────────────────

export function TransferBulkPage() {
  const { pdoId }    = useParams<{ pdoId: string }>()
  const navigate     = useNavigate()
  const toast        = useToastStore((s) => s.push)
  const qc           = useQueryClient()
  const [rows, setRows] = useState<RowState[]>([])

  const { data: summary, isLoading } = useQuery({
    queryKey: ['transfer-summary', pdoId],
    queryFn: async () => {
      const res = await api.get<ApiResponse<{ details: PdoDetailSummary[]; pdo_number: string }>>(`/pdo/${pdoId}/transfers`)
      return res.data.data
    },
    enabled: !!pdoId,
  })

  // inisialisasi rows dari summary
  useEffect(() => {
    if (!summary?.details) return
    setRows(
      summary.details.map((d) => ({
        pdo_detail_id:    d.pdo_detail_id,
        amount:           d.remaining > 0 ? d.remaining : 0,
        transfer_date:    today,
        reference_number: '',
        notes:            '',
      }))
    )
  }, [summary])

  const updateRow = (idx: number, field: keyof RowState, value: string | number) => {
    setRows((prev) => prev.map((r, i) => i === idx ? { ...r, [field]: value } : r))
  }

  const save = useMutation({
    mutationFn: () => {
      const entries = rows
        .filter((r) => Number(r.amount) > 0)
        .map((r) => ({
          pdo_detail_id:    r.pdo_detail_id,
          amount:           Number(r.amount),
          transfer_date:    r.transfer_date,
          reference_number: r.reference_number || null,
          notes:            r.notes || null,
        }))

      if (!entries.length) throw new Error('Tidak ada item dengan jumlah > 0')
      return api.post(`/pdo/${pdoId}/transfers/bulk`, { entries })
    },
    onSuccess: () => {
      toast('Transfer berhasil dicatat')
      qc.invalidateQueries({ queryKey: ['transfer-summary', pdoId] })
      qc.invalidateQueries({ queryKey: ['transfer-pdo-summary'] })
    },
    onError: (err: any) => {
      const msg = err?.response?.data?.error?.message ?? err?.message ?? 'Gagal menyimpan transfer'
      toast(msg, 'error')
    },
  })

  const details = summary?.details ?? []

  const handleSave = () => {
    // validasi: amount tidak boleh melebihi remaining
    for (let i = 0; i < rows.length; i++) {
      const row    = rows[i]
      const detail = details[i]
      if (!detail) continue
      const amt = Number(row.amount)
      if (amt > 0 && amt > detail.remaining) {
        toast(`"${detail.expense_item?.name ?? detail.description}": jumlah melebihi sisa dana (${fmt(detail.remaining)})`, 'error')
        return
      }
    }
    save.mutate()
  }

  return (
    <div>
      <div className="flex items-center gap-3 mb-6">
        <Button variant="secondary" size="sm" onClick={() => navigate('/transfer')}>
          <ArrowLeft className="w-4 h-4" />
        </Button>
        <div>
          <h2 className="text-[28px] font-[950] text-ink">
            Detail Transfer — {isLoading ? '...' : (summary as any)?.pdo_number ?? pdoId}
          </h2>
          <p className="text-muted text-sm mt-1">Catat transfer per item biaya untuk PDO ini.</p>
        </div>
      </div>

      <div className="overflow-auto border border-line rounded-drawer bg-white mb-4">
        <table className="w-full border-collapse" style={{ minWidth: 1100 }}>
          <thead>
            <tr>
              {['Item Biaya', 'Deskripsi', 'Total Pengajuan', 'Total Transfer Sebelumnya', 'Sisa Dana', 'Transfer Dana (Rp)', 'Tanggal', 'No. Referensi', 'Catatan'].map((h) => (
                <th key={h} className="px-3 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-muted bg-[#f7faf7] whitespace-nowrap">
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {isLoading ? (
              Array.from({ length: 5 }).map((_, i) => (
                <tr key={i}>
                  {Array.from({ length: 9 }).map((__, j) => (
                    <td key={j} className="px-3 py-3">
                      <div className="h-4 bg-[#f0f4f0] rounded animate-pulse" />
                    </td>
                  ))}
                </tr>
              ))
            ) : details.map((detail, idx) => {
              const row = rows[idx]
              if (!row) return null
              const amtNum    = Number(row.amount)
              const overLimit = amtNum > detail.remaining

              return (
                <tr key={detail.pdo_detail_id} className="border-t border-line hover:bg-[#fbfdfb]">
                  <td className="px-3 py-3 text-sm font-medium whitespace-nowrap">
                    {detail.expense_item?.name ?? '—'}
                  </td>
                  <td className="px-3 py-3 text-sm text-muted">{detail.description}</td>
                  <td className="px-3 py-3 text-sm">{fmt(detail.amount_approved)}</td>
                  <td className="px-3 py-3 text-sm">{fmt(detail.total_transferred)}</td>
                  <td className="px-3 py-3 text-sm font-bold text-green-700">
                    {fmt(detail.remaining)}
                  </td>
                  <td className="px-3 py-2">
                    <input
                      type="number"
                      min={0}
                      max={detail.remaining}
                      value={row.amount}
                      onChange={(e) => updateRow(idx, 'amount', e.target.value)}
                      className={`input-base w-36 ${overLimit ? 'border-red-400' : ''}`}
                    />
                    {overLimit && (
                      <p className="text-red-500 text-xs mt-1">Melebihi sisa dana</p>
                    )}
                  </td>
                  <td className="px-3 py-2">
                    <input
                      type="date"
                      value={row.transfer_date}
                      onChange={(e) => updateRow(idx, 'transfer_date', e.target.value)}
                      className="input-base w-36"
                    />
                  </td>
                  <td className="px-3 py-2">
                    <input
                      type="text"
                      placeholder="TRF/2026/001"
                      value={row.reference_number}
                      onChange={(e) => updateRow(idx, 'reference_number', e.target.value)}
                      className="input-base w-36"
                    />
                  </td>
                  <td className="px-3 py-2">
                    <input
                      type="text"
                      value={row.notes}
                      onChange={(e) => updateRow(idx, 'notes', e.target.value)}
                      className="input-base w-40"
                    />
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>

      <div className="flex justify-end gap-2">
        <Button variant="secondary" onClick={() => navigate('/transfer')}>Kembali</Button>
        <Button onClick={handleSave} loading={save.isPending}>
          Simpan Semua
        </Button>
      </div>
    </div>
  )
}
