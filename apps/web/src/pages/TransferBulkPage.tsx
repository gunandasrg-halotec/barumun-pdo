import { useEffect, useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { Button } from '@/components/ui/Button'
import { useToastStore } from '@/store/toast.store'
import { fmt } from '@/lib/format'
import { ArrowLeft, ChevronDown, ChevronUp, Download } from 'lucide-react'
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

interface CategoryInfo { code: string; name: string }

interface PdoDetailSummary {
  pdo_detail_id:     string
  expense_item:      ExpenseItemInfo | null
  category:          CategoryInfo | null
  subcategory:       CategoryInfo | null
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

const MONTH_NAMES = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember']

// Cols: A=#/label  B=Kode  C=Item  D=Pengajuan  E=RekKebun  F=Pribadi  G=Vendor  H=TotalTransfer  I=SisaDana  J=%
const NCOLS = 10
const NUM_FMT = '#,##0'
const PCT_FMT = '0%'

type XLStyle = {
  fill?: { type: 'pattern'; pattern: 'solid'; fgColor: { argb: string } }
  font?: { bold?: boolean; italic?: boolean; color?: { argb: string }; size?: number }
  alignment?: { horizontal?: 'center' | 'left' | 'right'; vertical?: 'middle' | 'top'; wrapText?: boolean }
  numFmt?: string
}

function xlfill(hex: string): XLStyle['fill'] {
  return { type: 'pattern', pattern: 'solid', fgColor: { argb: `FF${hex}` } }
}

const STYLES = {
  title:        { fill: xlfill('1F4E79'), font: { bold: true, color: { argb: 'FFFFFFFF' }, size: 13 }, alignment: { horizontal: 'center' as const, vertical: 'middle' as const } },
  meta:         { fill: xlfill('D6E4F7'), font: { color: { argb: 'FF0C447C' } }, alignment: { vertical: 'middle' as const } },
  hdr1:         { fill: xlfill('2E75B6'), font: { bold: true, color: { argb: 'FFFFFFFF' }, size: 11 }, alignment: { horizontal: 'center' as const, vertical: 'middle' as const, wrapText: true } },
  hdr2:         { fill: xlfill('5B9BD5'), font: { bold: true, color: { argb: 'FFFFFFFF' }, size: 10 }, alignment: { horizontal: 'center' as const, vertical: 'middle' as const } },
  cat:          { fill: xlfill('D6E4F7'), font: { bold: true, color: { argb: 'FF0C447C' } } },
  subcat:       { fill: xlfill('EEF4FB'), font: { italic: true, color: { argb: 'FF185FA5' } } },
  detail:       { fill: xlfill('FFFFFF') },
  subtotalSub:  { fill: xlfill('FFF2CC'), font: { bold: true, color: { argb: 'FF7B5800' } } },
  subtotalCat:  { fill: xlfill('F4B942'), font: { bold: true, color: { argb: 'FF412402' } } },
  grand:        { fill: xlfill('1F4E79'), font: { bold: true, color: { argb: 'FFFFFFFF' } } },
  sumTitle:     { fill: xlfill('1F4E79'), font: { bold: true, color: { argb: 'FFFFFFFF' } }, alignment: { horizontal: 'center' as const, vertical: 'middle' as const } },
  sumHdr:       { fill: xlfill('2E75B6'), font: { bold: true, color: { argb: 'FFFFFFFF' } }, alignment: { horizontal: 'center' as const, vertical: 'middle' as const } },
  sumKebun:     { fill: xlfill('E1F5EE'), font: { color: { argb: 'FF085041' } } },
  sumPribadi:   { fill: xlfill('FFF2CC'), font: { color: { argb: 'FF7B5800' } } },
  sumVendor:    { fill: xlfill('EEF4FB'), font: { color: { argb: 'FF185FA5' } } },
  sumTotal:     { fill: xlfill('1F4E79'), font: { bold: true, color: { argb: 'FFFFFFFF' } } },
}

async function exportToExcel(summary: PdoSummaryData) {
  const { default: ExcelJS } = await import('exceljs')
  const wb = new ExcelJS.Workbook()
  const ws = wb.addWorksheet('Transfer Dana')

  ws.columns = [
    { width: 45 }, { width: 12 }, { width: 30 }, { width: 18 },
    { width: 18 }, { width: 18 }, { width: 18 }, { width: 18 }, { width: 18 }, { width: 10 },
  ]

  let r = 1

  // numFmtMap: { colIndex: formatString } — format disertakan langsung di style tiap cell
  // agar tidak ada shared-reference antar cell yang menyebabkan format saling timpa.
  const applyRowStyle = (rowNum: number, baseStyle: XLStyle, numFmtMap: Record<number, string> = {}) => {
    const row = ws.getRow(rowNum)
    for (let c = 1; c <= NCOLS; c++) {
      const fmt = numFmtMap[c]
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      row.getCell(c).style = (fmt ? { ...baseStyle, numFmt: fmt } : { ...baseStyle }) as any
    }
  }

  const DATA_FMT: Record<number, string> = { 4: NUM_FMT, 5: NUM_FMT, 6: NUM_FMT, 7: NUM_FMT, 8: NUM_FMT, 9: NUM_FMT, 10: PCT_FMT }

  // ── Title ───────────────────────────────────────────────────────────────────
  ws.mergeCells(`A${r}:J${r}`)
  ws.getRow(r).height = 24
  ws.getRow(r).getCell(1).value = 'LAPORAN TRANSFER DANA PDO'
  applyRowStyle(r, STYLES.title)
  r++

  // ── Meta rows ────────────────────────────────────────────────────────────────
  const addMeta = (label: string, value: string) => {
    ws.mergeCells(`B${r}:J${r}`)
    ws.getRow(r).height = 16
    ws.getRow(r).getCell(1).value = label
    ws.getRow(r).getCell(2).value = value
    applyRowStyle(r, STYLES.meta)
    r++
  }
  addMeta('No. PDO', summary.pdo_number)
  addMeta('Unit', summary.plantation_unit ? `${summary.plantation_unit.code} — ${summary.plantation_unit.name}` : '—')
  addMeta('Periode', `${MONTH_NAMES[summary.period_month - 1]} ${summary.period_year}`)
  addMeta('Tanggal Cetak', new Date().toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric' }))
  r++ // kosong

  // ── Column headers (2 baris, merge rowspan untuk kolom non-tujuan) ────────────
  const hdrR1 = r, hdrR2 = r + 1
  ws.mergeCells(`A${hdrR1}:A${hdrR2}`)
  ws.mergeCells(`B${hdrR1}:B${hdrR2}`)
  ws.mergeCells(`C${hdrR1}:C${hdrR2}`)
  ws.mergeCells(`D${hdrR1}:D${hdrR2}`)
  ws.mergeCells(`E${hdrR1}:G${hdrR1}`) // "Tujuan Transfer" span E-G di baris 1
  ws.mergeCells(`H${hdrR1}:H${hdrR2}`)
  ws.mergeCells(`I${hdrR1}:I${hdrR2}`)
  ws.mergeCells(`J${hdrR1}:J${hdrR2}`)

  ws.getRow(hdrR1).height = 20
  ws.getRow(hdrR2).height = 16

  const hdr1Labels = ['#','Kode','Item Biaya','Total Pengajuan','Tujuan Transfer',null,null,'Total Transfer','Sisa Dana','% Transfer']
  hdr1Labels.forEach((v, i) => {
    ws.getRow(hdrR1).getCell(i+1).value = v ?? ''
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    ws.getRow(hdrR1).getCell(i+1).style = STYLES.hdr1 as any
  })
  ;['Rek. Kebun','Pribadi','Vendor'].forEach((v, i) => {
    ws.getRow(hdrR2).getCell(5+i).value = v
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    ws.getRow(hdrR2).getCell(5+i).style = STYLES.hdr2 as any
  })
  r = hdrR2 + 1

  // ── Kelompokkan detail per kategori → sub-kategori ───────────────────────────
  type GroupedSub = { code: string; name: string; items: PdoDetailSummary[] }
  type GroupedCat = { code: string; name: string; subs: Map<string, GroupedSub> }
  const catMap = new Map<string, GroupedCat>()
  for (const d of summary.details) {
    const catKey = d.category    ? `${d.category.code}|${d.category.name}`       : '__no_cat__'
    const subKey = d.subcategory ? `${d.subcategory.code}|${d.subcategory.name}` : '__no_sub__'
    if (!catMap.has(catKey)) catMap.set(catKey, { code: d.category?.code ?? '', name: d.category?.name ?? 'Tanpa Kategori', subs: new Map() })
    const cat = catMap.get(catKey)!
    if (!cat.subs.has(subKey)) cat.subs.set(subKey, { code: d.subcategory?.code ?? '', name: d.subcategory?.name ?? 'Tanpa Sub-Kategori', items: [] })
    cat.subs.get(subKey)!.items.push(d)
  }

  let itemNo = 1
  let grandApproved = 0, grandKebun = 0, grandPribadi = 0, grandVendor = 0

  for (const cat of catMap.values()) {
    // Baris kategori
    const catLabel = cat.code ? `${cat.code} — ${cat.name}` : cat.name
    ws.mergeCells(`A${r}:J${r}`)
    ws.getRow(r).height = 16
    ws.getRow(r).getCell(1).value = catLabel
    applyRowStyle(r, STYLES.cat)
    r++

    let catApproved = 0, catKebun = 0, catPribadi = 0, catVendor = 0

    for (const sub of cat.subs.values()) {
      // Baris sub-kategori
      const subLabel = sub.code ? `    ${sub.code} — ${sub.name}` : `    ${sub.name}`
      ws.mergeCells(`A${r}:J${r}`)
      ws.getRow(r).height = 16
      ws.getRow(r).getCell(1).value = subLabel
      applyRowStyle(r, STYLES.subcat)
      r++

      let subApproved = 0, subKebun = 0, subPribadi = 0, subVendor = 0

      for (const d of sub.items) {
        const kebun   = d.entries.filter(e => e.transfer_destination === 'rek_kebun').reduce((s,e) => s+e.amount, 0)
        const pribadi = d.entries.filter(e => e.transfer_destination === 'pribadi').reduce((s,e) => s+e.amount, 0)
        const vendor  = d.entries.filter(e => e.transfer_destination === 'vendor').reduce((s,e) => s+e.amount, 0)

        const dr = ws.getRow(r)
        dr.height = 16
        dr.getCell(1).value = itemNo++
        dr.getCell(2).value = d.expense_item?.code ?? ''
        dr.getCell(3).value = d.expense_item?.name ?? d.description
        dr.getCell(4).value = d.amount_approved
        dr.getCell(5).value = kebun
        dr.getCell(6).value = pribadi
        dr.getCell(7).value = vendor
        // Formula: Total Transfer = Rek.Kebun + Pribadi + Vendor
        dr.getCell(8).value = { formula: `E${r}+F${r}+G${r}` }
        // Formula: Sisa Dana = Total Pengajuan - Total Transfer
        dr.getCell(9).value = { formula: `D${r}-H${r}` }
        // Formula: % = Total Transfer / Total Pengajuan
        dr.getCell(10).value = { formula: `IF(D${r}=0,0,H${r}/D${r})` }

        applyRowStyle(r, STYLES.detail, DATA_FMT)
        r++

        subApproved += d.amount_approved
        subKebun    += kebun; subPribadi += pribadi; subVendor += vendor
      }

      // Subtotal sub-kategori
      const subTotal     = subKebun + subPribadi + subVendor
      const subLabel2    = sub.code ? `        Subtotal ${sub.code} — ${sub.name}` : `        Subtotal ${sub.name}`
      const ssr = ws.getRow(r)
      ssr.height = 16
      ssr.getCell(1).value = subLabel2
      ssr.getCell(4).value = subApproved
      ssr.getCell(5).value = subKebun
      ssr.getCell(6).value = subPribadi
      ssr.getCell(7).value = subVendor
      ssr.getCell(8).value = subTotal
      ssr.getCell(9).value = subApproved - subTotal
      ssr.getCell(10).value = subApproved > 0 ? subTotal / subApproved : 0
      applyRowStyle(r, STYLES.subtotalSub, DATA_FMT)
      r++

      catApproved += subApproved; catKebun += subKebun; catPribadi += subPribadi; catVendor += subVendor
    }

    // Subtotal kategori
    const catTotal     = catKebun + catPribadi + catVendor
    const catTotalLabel = cat.code ? `Total ${cat.code} — ${cat.name}` : `Total ${cat.name}`
    const csr = ws.getRow(r)
    csr.height = 16
    csr.getCell(1).value = catTotalLabel
    csr.getCell(4).value = catApproved
    csr.getCell(5).value = catKebun
    csr.getCell(6).value = catPribadi
    csr.getCell(7).value = catVendor
    csr.getCell(8).value = catTotal
    csr.getCell(9).value = catApproved - catTotal
    csr.getCell(10).value = catApproved > 0 ? catTotal / catApproved : 0
    applyRowStyle(r, STYLES.subtotalCat, DATA_FMT)
    r++; r++ // baris kosong antar kategori

    grandApproved += catApproved; grandKebun += catKebun; grandPribadi += catPribadi; grandVendor += catVendor
  }

  // ── Grand Total ──────────────────────────────────────────────────────────────
  const grandTotal = grandKebun + grandPribadi + grandVendor
  const gtr = ws.getRow(r)
  gtr.height = 20
  gtr.getCell(1).value = 'GRAND TOTAL'
  gtr.getCell(4).value = grandApproved
  gtr.getCell(5).value = grandKebun
  gtr.getCell(6).value = grandPribadi
  gtr.getCell(7).value = grandVendor
  gtr.getCell(8).value = grandTotal
  gtr.getCell(9).value = grandApproved - grandTotal
  gtr.getCell(10).value = grandApproved > 0 ? grandTotal / grandApproved : 0
  applyRowStyle(r, STYLES.grand, DATA_FMT)
  r += 2 // kosong sebelum summary

  // ── Summary Tujuan Transfer ───────────────────────────────────────────────────
  ws.mergeCells(`A${r}:J${r}`)
  ws.getRow(r).height = 20
  ws.getRow(r).getCell(1).value = 'RINGKASAN TRANSFER BERDASARKAN TUJUAN'
  applyRowStyle(r, STYLES.sumTitle)
  r++

  // Header summary
  ws.mergeCells(`A${r}:C${r}`)
  ws.mergeCells(`D${r}:G${r}`)
  ws.mergeCells(`H${r}:J${r}`)
  ws.getRow(r).height = 16
  ws.getRow(r).getCell(1).value = 'Tujuan Transfer'
  ws.getRow(r).getCell(4).value = 'Jumlah (Rp)'
  ws.getRow(r).getCell(8).value = '% dari Total Transfer'
  applyRowStyle(r, STYLES.sumHdr)
  r++

  // Baris per tujuan
  const sumDest = [
    { label: 'Rekening Kebun', value: grandKebun,   style: STYLES.sumKebun   },
    { label: 'Pribadi',        value: grandPribadi, style: STYLES.sumPribadi },
    { label: 'Vendor',         value: grandVendor,  style: STYLES.sumVendor  },
  ]
  for (const sd of sumDest) {
    ws.mergeCells(`A${r}:C${r}`)
    ws.mergeCells(`D${r}:G${r}`)
    ws.mergeCells(`H${r}:J${r}`)
    ws.getRow(r).height = 16
    ws.getRow(r).getCell(1).value = sd.label
    ws.getRow(r).getCell(4).value = sd.value
    ws.getRow(r).getCell(8).value = grandTotal > 0 ? sd.value / grandTotal : 0
    applyRowStyle(r, sd.style, { 4: NUM_FMT, 8: PCT_FMT })
    r++
  }

  // Total summary
  ws.mergeCells(`A${r}:C${r}`)
  ws.mergeCells(`D${r}:G${r}`)
  ws.mergeCells(`H${r}:J${r}`)
  ws.getRow(r).height = 18
  ws.getRow(r).getCell(1).value = 'TOTAL'
  ws.getRow(r).getCell(4).value = grandTotal
  ws.getRow(r).getCell(8).value = grandTotal > 0 ? 1 : 0
  applyRowStyle(r, STYLES.sumTotal, { 4: NUM_FMT, 8: PCT_FMT })

  // ── Download ─────────────────────────────────────────────────────────────────
  const buf  = await wb.xlsx.writeBuffer()
  const blob = new Blob([buf], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' })
  const url  = URL.createObjectURL(blob)
  const a    = document.createElement('a')
  a.href = url
  a.download = `Transfer_${summary.pdo_number}_${summary.plantation_unit?.code ?? 'PDO'}.xlsx`
  a.click()
  URL.revokeObjectURL(url)
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
        <div className="flex-1">
          <h2 className="text-[28px] font-[950] text-ink">
            Detail Transfer — {isLoading ? '...' : summary?.pdo_number ?? pdoId}
          </h2>
          <p className="text-muted text-sm mt-1">Catat transfer per item biaya untuk PDO ini.</p>
        </div>
        {summary && (
          <Button variant="secondary" size="sm" onClick={() => { void exportToExcel(summary) }}>
            <Download className="w-4 h-4 mr-1.5" />
            Download Excel
          </Button>
        )}
      </div>

      <div className="overflow-auto border border-line rounded-drawer bg-white mb-4">
        <table className="w-full border-collapse" style={{ minWidth: 1400 }}>
          <thead>
            <tr>
              {['Kategori / Sub-Kategori', 'Item Biaya', 'Total Pengajuan', 'Transfer Sebelumnya', 'Sisa Dana', 'Tujuan Transfer', 'Jumlah (Rp)', 'Tanggal', 'No. Referensi', 'Catatan'].map((h) => (
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
                  {Array.from({ length: 10 }).map((__, j) => (
                    <td key={j} className="px-3 py-3"><div className="h-4 bg-[#f0f4f0] rounded animate-pulse" /></td>
                  ))}
                </tr>
              ))
            ) : details.map((detail, idx) => {
              const row      = rows[idx]
              if (!row) return null
              const hasHistory    = detail.entries.length > 0
              const isExpanded   = expandedIds.has(detail.pdo_detail_id)
              const itemCode     = detail.expense_item?.code
              const itemName     = detail.expense_item?.name ?? '—'
              const itemLabel    = itemCode ? `[${itemCode}] ${itemName}` : itemName
              const categoryLabel = detail.category
                ? `${detail.category.code} — ${detail.category.name}`
                : '—'
              const subcategoryLabel = detail.subcategory
                ? `${detail.subcategory.code} — ${detail.subcategory.name}`
                : null

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
                      <td className="px-3 py-2 text-xs text-muted">
                        <div className="font-[700] text-ink">{categoryLabel}</div>
                        {subcategoryLabel && <div className="text-[11px] mt-0.5">{subcategoryLabel}</div>}
                      </td>
                      <td className="px-3 py-2 font-bold text-sm">
                        {itemLabel}
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
                      <td colSpan={4} />
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
                      <td colSpan={4} />
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
                        <td colSpan={10} className="px-0 py-0 bg-gray-50 border-t border-dashed border-line">
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
                    <td className="px-3 py-3 text-xs text-muted">
                      <div className="font-[700] text-ink">{categoryLabel}</div>
                      {subcategoryLabel && <div className="text-[11px] mt-0.5">{subcategoryLabel}</div>}
                    </td>
                    <td className="px-3 py-3 text-sm font-medium whitespace-nowrap">
                      {itemLabel}
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
