import { useEffect, useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { Button } from '@/components/ui/Button'
import { useToastStore } from '@/store/toast.store'
import { fmt } from '@/lib/format'
import { ArrowLeft, ChevronDown, ChevronUp } from 'lucide-react'
import type { ApiResponse } from '@/types'

// ─── types ────────────────────────────────────────────────────────────────────

type TransferDest = 'rek_kebun' | 'pribadi' | 'vendor'

type TransferEntryRecord = {
  id: string
  transfer_date: string
  amount: number
  reference_number: string | null
  transfer_destination: TransferDest
  notes: string | null
}

interface ExpenseItemInfo {
  id: string
  code: string
  name: string
  split_transfer: boolean
  split_transfer_plantation_unit_ids: string[] | null
}

interface PdoDetailSummary {
  pdo_detail_id:     string
  expense_item:      ExpenseItemInfo | null
  description:       string
  amount_approved:   number
  total_transferred: number
  remaining:         number
  entries:           TransferEntryRecord[]
}

interface PdoSummaryData {
  pdo_number:      string
  period_month:    number
  period_year:     number
  plantation_unit: { id: string; code: string; name: string } | null
  details:         PdoDetailSummary[]
}

interface SplitRow {
  amount1:      number
  dest1:        TransferDest
  amount2:      number
  dest2:        TransferDest
}

interface NormalRow {
  amount:           number
  transfer_date:    string
  reference_number: string
  notes:            string
  dest:             TransferDest
}

type RowState = {
  pdo_detail_id: string
  isSplit: boolean
  normal: NormalRow
  split: SplitRow & { transfer_date: string; reference_number: string; notes: string }
}

const DEST_LABELS: Record<TransferDest, string> = {
  rek_kebun: 'Rek. Kebun',
  pribadi:   'Pribadi',
  vendor:    'Vendor',
}
const DEST_OPTIONS: TransferDest[] = ['rek_kebun', 'pribadi', 'vendor']

const today = new Date().toISOString().split('T')[0]

function fmtDate(dateStr: string): string {
  const d = new Date(dateStr)
  return d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' })
}

function isSplitForUnit(item: ExpenseItemInfo | null, unitId: string | null): boolean {
  if (!item?.split_transfer) return false
  const ids = item.split_transfer_plantation_unit_ids
  if (!ids || ids.length === 0) return true   // berlaku semua kebun
  if (!unitId) return true
  return ids.includes(unitId)
}

// ─── component ────────────────────────────────────────────────────────────────

export function TransferBulkPage() {
  const { pdoId }    = useParams<{ pdoId: string }>()
  const navigate     = useNavigate()
  const toast        = useToastStore((s) => s.push)
  const qc           = useQueryClient()
  const [rows, setRows]           = useState<RowState[]>([])
  const [expandedIds, setExpandedIds] = useState<Set<string>>(new Set())

  const { data: summary, isLoading } = useQuery({
    queryKey: ['transfer-summary', pdoId],
    queryFn: async () => {
      const res = await api.get<ApiResponse<PdoSummaryData>>(`/pdo/${pdoId}/transfers`)
      return res.data.data
    },
    enabled: !!pdoId,
  })

  const unitId = summary?.plantation_unit?.id ?? null

  useEffect(() => {
    if (!summary?.details) return
    setRows(
      summary.details.map((d) => ({
        pdo_detail_id: d.pdo_detail_id,
        isSplit:       isSplitForUnit(d.expense_item, unitId),
        normal: {
          amount:           d.remaining > 0 ? d.remaining : 0,
          transfer_date:    today,
          reference_number: '',
          notes:            '',
          dest:             'rek_kebun',
        },
        split: {
          amount1:          0,
          dest1:            'rek_kebun',
          amount2:          0,
          dest2:            'pribadi',
          transfer_date:    today,
          reference_number: '',
          notes:            '',
        },
      }))
    )
  }, [summary, unitId])

  const toggleExpand = (id: string) => {
    setExpandedIds((prev) => {
      const next = new Set(prev)
      next.has(id) ? next.delete(id) : next.add(id)
      return next
    })
  }

  const updateNormal = (idx: number, field: keyof NormalRow, value: string | number) => {
    setRows((prev) => prev.map((r, i) => i === idx ? { ...r, normal: { ...r.normal, [field]: value } } : r))
  }

  const updateSplit = (idx: number, field: keyof RowState['split'], value: string | number) => {
    setRows((prev) => prev.map((r, i) => i === idx ? { ...r, split: { ...r.split, [field]: value } } : r))
  }

  const save = useMutation({
    mutationFn: () => {
      const entries: object[] = []

      rows.forEach((row, idx) => {
        const detail = summary?.details[idx]
        if (!detail) return

        if (row.isSplit) {
          if (Number(row.split.amount1) > 0) {
            entries.push({
              pdo_detail_id:        row.pdo_detail_id,
              amount:               Number(row.split.amount1),
              transfer_date:        row.split.transfer_date,
              reference_number:     row.split.reference_number || null,
              notes:                row.split.notes || null,
              transfer_destination: row.split.dest1,
            })
          }
          if (Number(row.split.amount2) > 0) {
            entries.push({
              pdo_detail_id:        row.pdo_detail_id,
              amount:               Number(row.split.amount2),
              transfer_date:        row.split.transfer_date,
              reference_number:     row.split.reference_number || null,
              notes:                row.split.notes || null,
              transfer_destination: row.split.dest2,
            })
          }
        } else {
          if (Number(row.normal.amount) > 0) {
            entries.push({
              pdo_detail_id:        row.pdo_detail_id,
              amount:               Number(row.normal.amount),
              transfer_date:        row.normal.transfer_date,
              reference_number:     row.normal.reference_number || null,
              notes:                row.normal.notes || null,
              transfer_destination: row.normal.dest,
            })
          }
        }
      })

      if (!entries.length) throw new Error('Tidak ada item dengan jumlah > 0')
      return api.post(`/pdo/${pdoId}/transfers/bulk`, { entries })
    },
    onSuccess: () => {
      toast('Transfer berhasil dicatat')
      qc.invalidateQueries({ queryKey: ['transfer-summary', pdoId] })
      qc.invalidateQueries({ queryKey: ['transfer-pdo-summary'] })
    },
    onError: (err: unknown) => {
      const msg = (err as { response?: { data?: { error?: { message?: string } } }; message?: string })
        ?.response?.data?.error?.message ?? (err as { message?: string })?.message ?? 'Gagal menyimpan transfer'
      toast(msg, 'error')
    },
  })

  const handleSave = () => {
    for (let i = 0; i < rows.length; i++) {
      const row    = rows[i]
      const detail = summary?.details[i]
      if (!detail) continue
      const itemName = detail.expense_item?.name ?? detail.description

      if (row.isSplit) {
        const total = Number(row.split.amount1) + Number(row.split.amount2)
        if (total > detail.remaining) {
          toast(`"${itemName}": total split (${fmt(total)}) melebihi sisa dana (${fmt(detail.remaining)})`, 'error')
          return
        }
        if (Number(row.split.amount1) > 0 && Number(row.split.amount2) > 0 && row.split.dest1 === row.split.dest2) {
          toast(`"${itemName}": tujuan transfer 1 dan 2 tidak boleh sama`, 'error')
          return
        }
      } else {
        const amt = Number(row.normal.amount)
        if (amt > detail.remaining) {
          toast(`"${itemName}": jumlah melebihi sisa dana (${fmt(detail.remaining)})`, 'error')
          return
        }
      }
    }
    save.mutate()
  }

  const details = summary?.details ?? []

  return (
    <div>
      <div className="flex items-center gap-3 mb-6">
        <Button variant="secondary" size="sm" onClick={() => navigate('/transfer')}>
          <ArrowLeft className="w-4 h-4" />
        </Button>
        <div>
          <h2 className="text-[28px] font-[950] text-ink">
            Detail Transfer — {isLoading ? '...' : summary?.pdo_number ?? pdoId}
          </h2>
          <p className="text-muted text-sm mt-1">Catat transfer per item biaya untuk PDO ini.</p>
        </div>
      </div>

      <div className="overflow-auto border border-line rounded-drawer bg-white mb-4">
        <table className="w-full border-collapse" style={{ minWidth: 1200 }}>
          <thead>
            <tr>
              {['Item Biaya', 'Total Pengajuan', 'Transfer Sebelumnya', 'Sisa Dana', 'Tujuan Transfer', 'Jumlah (Rp)', 'Tanggal', 'No. Referensi', 'Catatan'].map((h) => (
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
                    <td key={j} className="px-3 py-3"><div className="h-4 bg-[#f0f4f0] rounded animate-pulse" /></td>
                  ))}
                </tr>
              ))
            ) : details.map((detail, idx) => {
              const row      = rows[idx]
              if (!row) return null
              const hasHistory  = detail.entries.length > 0
              const isExpanded  = expandedIds.has(detail.pdo_detail_id)
              const itemName    = detail.expense_item?.name ?? '—'

              const historyToggle = hasHistory ? (
                <button
                  type="button"
                  onClick={() => toggleExpand(detail.pdo_detail_id)}
                  className="ml-2 inline-flex items-center gap-0.5 text-xs text-blue-600 hover:text-blue-800 font-normal"
                >
                  Riwayat ({detail.entries.length})
                  {isExpanded ? <ChevronUp className="w-3 h-3" /> : <ChevronDown className="w-3 h-3" />}
                </button>
              ) : null

              if (row.isSplit) {
                const totalSplit  = Number(row.split.amount1) + Number(row.split.amount2)
                const overLimit   = totalSplit > detail.remaining
                const sameDestErr = Number(row.split.amount1) > 0 && Number(row.split.amount2) > 0 && row.split.dest1 === row.split.dest2

                return (
                  <>
                    {/* Baris header item */}
                    <tr key={`${detail.pdo_detail_id}-header`} className="border-t-2 border-line bg-[#f7faf7]">
                      <td className="px-3 py-2 font-bold text-sm">
                        {itemName}
                        <span className="ml-2 text-xs bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded font-normal">Split</span>
                        {historyToggle}
                      </td>
                      <td className="px-3 py-2 text-sm">{fmt(detail.amount_approved)}</td>
                      <td className="px-3 py-2 text-sm">{fmt(detail.total_transferred)}</td>
                      <td className="px-3 py-2 text-sm font-bold text-green-700">{fmt(detail.remaining)}</td>
                      <td colSpan={5} className="px-3 py-2 text-xs text-muted">
                        <div className="flex items-center gap-2 flex-wrap">
                          <div>
                            <span className="font-bold mr-1">Tgl:</span>
                            <input
                              type="date"
                              value={row.split.transfer_date}
                              onChange={(e) => updateSplit(idx, 'transfer_date', e.target.value)}
                              className="input-base w-36 text-sm"
                            />
                          </div>
                          <div>
                            <span className="font-bold mr-1">Ref:</span>
                            <input
                              type="text"
                              placeholder="No. Referensi"
                              value={row.split.reference_number}
                              onChange={(e) => updateSplit(idx, 'reference_number', e.target.value)}
                              className="input-base w-32 text-sm"
                            />
                          </div>
                          <div>
                            <span className="font-bold mr-1">Catatan:</span>
                            <input
                              type="text"
                              placeholder="Catatan"
                              value={row.split.notes}
                              onChange={(e) => updateSplit(idx, 'notes', e.target.value)}
                              className="input-base w-32 text-sm"
                            />
                          </div>
                        </div>
                        {overLimit && <p className="text-red-500 text-xs mt-1">Total split melebihi sisa dana</p>}
                        {sameDestErr && <p className="text-red-500 text-xs">Tujuan 1 dan 2 tidak boleh sama</p>}
                      </td>
                    </tr>
                    {/* Sub-baris 1 */}
                    <tr key={`${detail.pdo_detail_id}-s1`} className="border-t border-dashed border-line">
                      <td className="px-3 py-2 pl-8 text-xs text-muted">↳ Tujuan 1</td>
                      <td colSpan={3} />
                      <td className="px-3 py-2">
                        <select
                          value={row.split.dest1}
                          onChange={(e) => updateSplit(idx, 'dest1', e.target.value as TransferDest)}
                          className={`input-base w-32 text-sm ${sameDestErr ? 'border-red-400' : ''}`}
                        >
                          {DEST_OPTIONS.map((d) => (
                            <option key={d} value={d}>{DEST_LABELS[d]}</option>
                          ))}
                        </select>
                      </td>
                      <td className="px-3 py-2">
                        <input
                          type="number"
                          min={0}
                          value={row.split.amount1}
                          onChange={(e) => updateSplit(idx, 'amount1', e.target.value)}
                          className={`input-base w-36 ${overLimit ? 'border-red-400' : ''}`}
                        />
                      </td>
                      <td colSpan={3} />
                    </tr>
                    {/* Sub-baris 2 */}
                    <tr key={`${detail.pdo_detail_id}-s2`} className="border-t border-dashed border-line">
                      <td className="px-3 py-2 pl-8 text-xs text-muted">↳ Tujuan 2</td>
                      <td colSpan={3} />
                      <td className="px-3 py-2">
                        <select
                          value={row.split.dest2}
                          onChange={(e) => updateSplit(idx, 'dest2', e.target.value as TransferDest)}
                          className={`input-base w-32 text-sm ${sameDestErr ? 'border-red-400' : ''}`}
                        >
                          {DEST_OPTIONS.map((d) => (
                            <option key={d} value={d}>{DEST_LABELS[d]}</option>
                          ))}
                        </select>
                      </td>
                      <td className="px-3 py-2">
                        <input
                          type="number"
                          min={0}
                          value={row.split.amount2}
                          onChange={(e) => updateSplit(idx, 'amount2', e.target.value)}
                          className={`input-base w-36 ${overLimit ? 'border-red-400' : ''}`}
                        />
                      </td>
                      <td colSpan={3} />
                    </tr>
                    {/* Riwayat */}
                    {isExpanded && (
                      <tr key={`${detail.pdo_detail_id}-history`}>
                        <td colSpan={9} className="px-0 py-0 bg-gray-50 border-t border-dashed border-line">
                          <HistoryTable entries={detail.entries} />
                        </td>
                      </tr>
                    )}
                  </>
                )
              }

              // Normal mode
              const amtNum  = Number(row.normal.amount)
              const overLim = amtNum > detail.remaining

              return (
                <>
                  <tr key={detail.pdo_detail_id} className="border-t border-line hover:bg-[#fbfdfb]">
                    <td className="px-3 py-3 text-sm font-medium whitespace-nowrap">
                      {itemName}
                      {historyToggle}
                    </td>
                    <td className="px-3 py-3 text-sm">{fmt(detail.amount_approved)}</td>
                    <td className="px-3 py-3 text-sm">{fmt(detail.total_transferred)}</td>
                    <td className="px-3 py-3 text-sm font-bold text-green-700">{fmt(detail.remaining)}</td>
                    <td className="px-3 py-2">
                      <select
                        value={row.normal.dest}
                        onChange={(e) => updateNormal(idx, 'dest', e.target.value as TransferDest)}
                        className="input-base w-32 text-sm"
                      >
                        {DEST_OPTIONS.map((d) => (
                          <option key={d} value={d}>{DEST_LABELS[d]}</option>
                        ))}
                      </select>
                    </td>
                    <td className="px-3 py-2">
                      <input
                        type="number"
                        min={0}
                        max={detail.remaining}
                        value={row.normal.amount}
                        onChange={(e) => updateNormal(idx, 'amount', e.target.value)}
                        className={`input-base w-36 ${overLim ? 'border-red-400' : ''}`}
                      />
                      {overLim && <p className="text-red-500 text-xs mt-1">Melebihi sisa dana</p>}
                    </td>
                    <td className="px-3 py-2">
                      <input
                        type="date"
                        value={row.normal.transfer_date}
                        onChange={(e) => updateNormal(idx, 'transfer_date', e.target.value)}
                        className="input-base w-36"
                      />
                    </td>
                    <td className="px-3 py-2">
                      <input
                        type="text"
                        placeholder="TRF/2026/001"
                        value={row.normal.reference_number}
                        onChange={(e) => updateNormal(idx, 'reference_number', e.target.value)}
                        className="input-base w-36"
                      />
                    </td>
                    <td className="px-3 py-2">
                      <input
                        type="text"
                        value={row.normal.notes}
                        onChange={(e) => updateNormal(idx, 'notes', e.target.value)}
                        className="input-base w-40"
                      />
                    </td>
                  </tr>
                  {/* Riwayat */}
                  {isExpanded && (
                    <tr key={`${detail.pdo_detail_id}-history`}>
                      <td colSpan={9} className="px-0 py-0 bg-gray-50 border-t border-dashed border-line">
                        <HistoryTable entries={detail.entries} />
                      </td>
                    </tr>
                  )}
                </>
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

// ─── sub-component riwayat ────────────────────────────────────────────────────

const DEST_LABELS_MAP: Record<string, string> = {
  rek_kebun: 'Rek. Kebun',
  pribadi:   'Pribadi',
  vendor:    'Vendor',
}

function fmtRp(n: number): string {
  return 'Rp ' + n.toLocaleString('id-ID')
}

function fmtDate(dateStr: string): string {
  return new Date(dateStr).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' })
}

function HistoryTable({ entries }: { entries: TransferEntryRecord[] }) {
  return (
    <div className="px-6 py-3">
      <p className="text-xs font-bold text-muted mb-2 uppercase tracking-wider">Riwayat Transfer</p>
      <table className="w-full text-xs border-collapse">
        <thead>
          <tr className="text-left text-muted">
            <th className="pb-1 pr-4 font-semibold">Tanggal</th>
            <th className="pb-1 pr-4 font-semibold">Tujuan</th>
            <th className="pb-1 pr-4 font-semibold">Jumlah</th>
            <th className="pb-1 pr-4 font-semibold">No. Referensi</th>
            <th className="pb-1 font-semibold">Catatan</th>
          </tr>
        </thead>
        <tbody>
          {entries.map((e) => (
            <tr key={e.id} className="border-t border-line">
              <td className="py-1.5 pr-4">{fmtDate(e.transfer_date)}</td>
              <td className="py-1.5 pr-4">{DEST_LABELS_MAP[e.transfer_destination] ?? e.transfer_destination}</td>
              <td className="py-1.5 pr-4 font-medium">{fmtRp(e.amount)}</td>
              <td className="py-1.5 pr-4 text-muted">{e.reference_number ?? '—'}</td>
              <td className="py-1.5 text-muted">{e.notes ?? '—'}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
