import { useEffect, useMemo, useRef, useState } from 'react'
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
  status?: 'draft' | 'committed'
}

interface ExpenseItemInfo {
  id: string
  code: string
  name: string
  is_deduction: boolean
  split_transfer: boolean
  split_transfer_plantation_unit_ids: string[] | null
}

interface CategoryInfo { code: string; name: string }

type DestBreakdown = Record<TransferDest, number>

interface PdoDetailSummary {
  pdo_detail_id:     string
  expense_item:      ExpenseItemInfo | null
  category:          CategoryInfo | null
  subcategory:       CategoryInfo | null
  description:       string
  amount_approved:   number
  total_transferred: number          // committed (final) only
  final_by_dest:     DestBreakdown
  draft_total:       number
  draft_by_dest:     DestBreakdown
  combined_total:    number
  remaining:         number          // amount - final - draft
  entries:           TransferEntryRecord[]        // committed history
  draft_entries:     TransferEntryRecord[]        // editable drafts
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

// ═══ Ekspor Excel ═══════════════════════════════════════════════════════════════
// Kolom: A=#  B=Kode  C=Item  D=Pengajuan
//        E,F,G=Final(Rek,Pribadi,Vendor)  H=Final Subtotal
//        I,J,K=Draft(Rek,Pribadi,Vendor)  L=Draft Subtotal
//        M=Total(Final+Draft)  N=Sisa  O=%
const NCOLS = 15
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
  hdr:          { fill: xlfill('2E75B6'), font: { bold: true, color: { argb: 'FFFFFFFF' }, size: 11 }, alignment: { horizontal: 'center' as const, vertical: 'middle' as const, wrapText: true } },
  grpFinal:     { fill: xlfill('1D9E75'), font: { bold: true, color: { argb: 'FFFFFFFF' } }, alignment: { horizontal: 'center' as const, vertical: 'middle' as const } },
  grpDraft:     { fill: xlfill('EF9F27'), font: { bold: true, color: { argb: 'FF412402' } }, alignment: { horizontal: 'center' as const, vertical: 'middle' as const } },
  subFinal:     { fill: xlfill('E1F5EE'), font: { bold: true, color: { argb: 'FF085041' }, size: 10 }, alignment: { horizontal: 'center' as const, vertical: 'middle' as const } },
  subDraft:     { fill: xlfill('FAEEDA'), font: { bold: true, color: { argb: 'FF633806' }, size: 10 }, alignment: { horizontal: 'center' as const, vertical: 'middle' as const } },
  cat:          { fill: xlfill('D6E4F7'), font: { bold: true, color: { argb: 'FF0C447C' } } },
  subcat:       { fill: xlfill('EEF4FB'), font: { italic: true, color: { argb: 'FF185FA5' } } },
  detail:       { fill: xlfill('FFFFFF') },
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
    { width: 5 }, { width: 14 }, { width: 30 }, { width: 16 },
    { width: 14 }, { width: 12 }, { width: 12 }, { width: 15 },
    { width: 14 }, { width: 12 }, { width: 12 }, { width: 15 },
    { width: 16 }, { width: 15 }, { width: 8 },
  ]

  let r = 1

  const applyRowStyle = (rowNum: number, baseStyle: XLStyle, numFmtMap: Record<number, string> = {}) => {
    const row = ws.getRow(rowNum)
    for (let c = 1; c <= NCOLS; c++) {
      const fmt = numFmtMap[c]
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      row.getCell(c).style = (fmt ? { ...baseStyle, numFmt: fmt } : { ...baseStyle }) as any
    }
  }

  // Kolom numerik: D..N angka, O persen
  const DATA_FMT: Record<number, string> = {
    4: NUM_FMT, 5: NUM_FMT, 6: NUM_FMT, 7: NUM_FMT, 8: NUM_FMT,
    9: NUM_FMT, 10: NUM_FMT, 11: NUM_FMT, 12: NUM_FMT, 13: NUM_FMT, 14: NUM_FMT, 15: PCT_FMT,
  }

  // ── Title ─────────────────────────────────────────────────────────────────────
  ws.mergeCells(`A${r}:O${r}`)
  ws.getRow(r).height = 24
  ws.getRow(r).getCell(1).value = 'LAPORAN TRANSFER DANA PDO'
  applyRowStyle(r, STYLES.title)
  r++

  // ── Meta rows ──────────────────────────────────────────────────────────────────
  const addMeta = (label: string, value: string) => {
    ws.mergeCells(`B${r}:O${r}`)
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

  // ── Header 3 baris ──────────────────────────────────────────────────────────────
  const h1 = r, h2 = r + 1, h3 = r + 2
  // kolom tunggal rowspan penuh
  ;['A','B','C','D','M','N','O'].forEach((col) => ws.mergeCells(`${col}${h1}:${col}${h3}`))
  // grup Final (E-H) & Draft (I-L)
  ws.mergeCells(`E${h1}:H${h1}`)
  ws.mergeCells(`I${h1}:L${h1}`)
  // baris 2: tujuan (E-G, I-K) + subtotal rowspan2 (H, L)
  ws.mergeCells(`E${h2}:G${h2}`)
  ws.mergeCells(`I${h2}:K${h2}`)
  ws.mergeCells(`H${h2}:H${h3}`)
  ws.mergeCells(`L${h2}:L${h3}`)

  ws.getRow(h1).height = 18; ws.getRow(h2).height = 16; ws.getRow(h3).height = 16

  const set = (rowN: number, col: number, val: string, style: XLStyle) => {
    ws.getRow(rowN).getCell(col).value = val
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    ws.getRow(rowN).getCell(col).style = style as any
  }
  // apply base header style ke seluruh area header dulu (agar sel kosong tetap berwarna)
  ;[h1, h2, h3].forEach((rr) => applyRowStyle(rr, STYLES.hdr))

  set(h1, 1, '#', STYLES.hdr)
  set(h1, 2, 'Kode', STYLES.hdr)
  set(h1, 3, 'Item Biaya', STYLES.hdr)
  set(h1, 4, 'Total Pengajuan', STYLES.hdr)
  set(h1, 5, 'Dana yang sudah ditransfer', STYLES.grpFinal)
  set(h1, 9, 'Draft (belum permanen)', STYLES.grpDraft)
  set(h1, 13, 'Total (Final + Draft)', STYLES.hdr)
  set(h1, 14, 'Sisa Dana', STYLES.hdr)
  set(h1, 15, '%', STYLES.hdr)

  set(h2, 5, 'Tujuan', STYLES.subFinal)
  set(h2, 8, 'Subtotal', STYLES.subFinal)
  set(h2, 9, 'Tujuan', STYLES.subDraft)
  set(h2, 12, 'Subtotal', STYLES.subDraft)

  set(h3, 5, 'Rek. Kebun', STYLES.subFinal)
  set(h3, 6, 'Pribadi', STYLES.subFinal)
  set(h3, 7, 'Vendor', STYLES.subFinal)
  set(h3, 9, 'Rek. Kebun', STYLES.subDraft)
  set(h3, 10, 'Pribadi', STYLES.subDraft)
  set(h3, 11, 'Vendor', STYLES.subDraft)
  r = h3 + 1

  // ── Kelompokkan detail per kategori → sub-kategori ────────────────────────────
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

  // akumulator tipe [approved, fRek, fPri, fVen, dRek, dPri, dVen]
  type Acc = [number, number, number, number, number, number, number]
  const zero = (): Acc => [0, 0, 0, 0, 0, 0, 0]
  const addTo = (a: Acc, b: Acc) => { for (let i = 0; i < 7; i++) a[i] += b[i] }

  // Tulis baris subtotal/kategori/grand dengan nilai statis (breakdown final & draft)
  const writeAggRow = (label: string, a: Acc, style: XLStyle) => {
    const fSub = a[1] + a[2] + a[3]
    const dSub = a[4] + a[5] + a[6]
    const combined = fSub + dSub
    const row = ws.getRow(r)
    row.height = 16
    row.getCell(1).value = label
    row.getCell(4).value = a[0]
    row.getCell(5).value = a[1]; row.getCell(6).value = a[2]; row.getCell(7).value = a[3]; row.getCell(8).value = fSub
    row.getCell(9).value = a[4]; row.getCell(10).value = a[5]; row.getCell(11).value = a[6]; row.getCell(12).value = dSub
    row.getCell(13).value = combined
    row.getCell(14).value = a[0] - combined
    row.getCell(15).value = a[0] > 0 ? combined / a[0] : 0
    applyRowStyle(r, style, DATA_FMT)
    r++
  }

  let itemNo = 1
  const grand = zero()

  for (const cat of catMap.values()) {
    ws.mergeCells(`A${r}:O${r}`)
    ws.getRow(r).height = 16
    ws.getRow(r).getCell(1).value = cat.code ? `${cat.code} — ${cat.name}` : cat.name
    applyRowStyle(r, STYLES.cat)
    r++

    const catAcc = zero()

    for (const sub of cat.subs.values()) {
      ws.mergeCells(`A${r}:O${r}`)
      ws.getRow(r).height = 16
      ws.getRow(r).getCell(1).value = sub.code ? `    ${sub.code} — ${sub.name}` : `    ${sub.name}`
      applyRowStyle(r, STYLES.subcat)
      r++

      const subAcc = zero()

      for (const d of sub.items) {
        // Item potongan: Total Pengajuan tampil sebagai nilai minus (mengurangi total).
        const signedApproved = d.expense_item?.is_deduction ? -d.amount_approved : d.amount_approved
        const dr = ws.getRow(r)
        dr.height = 16
        dr.getCell(1).value = itemNo++
        dr.getCell(2).value = d.expense_item?.code ?? ''
        dr.getCell(3).value = d.expense_item?.name ?? d.description
        dr.getCell(4).value = signedApproved
        dr.getCell(5).value = d.final_by_dest.rek_kebun
        dr.getCell(6).value = d.final_by_dest.pribadi
        dr.getCell(7).value = d.final_by_dest.vendor
        dr.getCell(8).value = { formula: `E${r}+F${r}+G${r}` }        // Final subtotal
        dr.getCell(9).value  = d.draft_by_dest.rek_kebun
        dr.getCell(10).value = d.draft_by_dest.pribadi
        dr.getCell(11).value = d.draft_by_dest.vendor
        dr.getCell(12).value = { formula: `I${r}+J${r}+K${r}` }        // Draft subtotal
        dr.getCell(13).value = { formula: `H${r}+L${r}` }              // Total (final+draft)
        dr.getCell(14).value = { formula: `D${r}-M${r}` }              // Sisa
        dr.getCell(15).value = { formula: `IF(D${r}=0,0,M${r}/D${r})` } // %
        applyRowStyle(r, STYLES.detail, DATA_FMT)
        r++

        const rowAcc: Acc = [
          signedApproved,
          d.final_by_dest.rek_kebun, d.final_by_dest.pribadi, d.final_by_dest.vendor,
          d.draft_by_dest.rek_kebun, d.draft_by_dest.pribadi, d.draft_by_dest.vendor,
        ]
        addTo(subAcc, rowAcc)
      }

      writeAggRow(sub.code ? `        Subtotal ${sub.code} — ${sub.name}` : `        Subtotal ${sub.name}`, subAcc, STYLES.subtotalCat)
      addTo(catAcc, subAcc)
    }

    writeAggRow(cat.code ? `Total ${cat.code} — ${cat.name}` : `Total ${cat.name}`, catAcc, STYLES.subtotalCat)
    r++ // baris kosong antar kategori
    addTo(grand, catAcc)
  }

  writeAggRow('GRAND TOTAL', grand, STYLES.grand)
  r += 1 // kosong sebelum ringkasan

  // ── Ringkasan tujuan (Final vs Draft) ──────────────────────────────────────────
  ws.mergeCells(`A${r}:O${r}`)
  ws.getRow(r).height = 20
  ws.getRow(r).getCell(1).value = 'RINGKASAN TRANSFER BERDASARKAN TUJUAN'
  applyRowStyle(r, STYLES.sumTitle)
  r++

  const sumMerge = (rowN: number) => {
    ws.mergeCells(`A${rowN}:C${rowN}`)
    ws.mergeCells(`D${rowN}:F${rowN}`)
    ws.mergeCells(`G${rowN}:I${rowN}`)
    ws.mergeCells(`J${rowN}:L${rowN}`)
    ws.mergeCells(`M${rowN}:O${rowN}`)
  }

  sumMerge(r)
  ws.getRow(r).height = 16
  ws.getRow(r).getCell(1).value = 'Tujuan Transfer'
  ws.getRow(r).getCell(4).value = 'Sudah ditransfer'
  ws.getRow(r).getCell(7).value = 'Draft'
  ws.getRow(r).getCell(10).value = 'Total (Final + Draft)'
  ws.getRow(r).getCell(13).value = '% dari Total'
  applyRowStyle(r, STYLES.sumHdr)
  r++

  const gFinal: DestBreakdown = { rek_kebun: grand[1], pribadi: grand[2], vendor: grand[3] }
  const gDraft: DestBreakdown = { rek_kebun: grand[4], pribadi: grand[5], vendor: grand[6] }
  const grandCombined = grand[1] + grand[2] + grand[3] + grand[4] + grand[5] + grand[6]

  const sumRows: { label: string; dest: TransferDest; style: XLStyle }[] = [
    { label: 'Rekening Kebun', dest: 'rek_kebun', style: STYLES.sumKebun },
    { label: 'Pribadi',        dest: 'pribadi',   style: STYLES.sumPribadi },
    { label: 'Vendor',         dest: 'vendor',    style: STYLES.sumVendor },
  ]
  for (const sr of sumRows) {
    const fin = gFinal[sr.dest], drf = gDraft[sr.dest], tot = fin + drf
    sumMerge(r)
    ws.getRow(r).height = 16
    ws.getRow(r).getCell(1).value = sr.label
    ws.getRow(r).getCell(4).value = fin
    ws.getRow(r).getCell(7).value = drf
    ws.getRow(r).getCell(10).value = tot
    ws.getRow(r).getCell(13).value = grandCombined > 0 ? tot / grandCombined : 0
    applyRowStyle(r, sr.style, { 4: NUM_FMT, 7: NUM_FMT, 10: NUM_FMT, 13: PCT_FMT })
    r++
  }

  const totFin = grand[1] + grand[2] + grand[3]
  const totDrf = grand[4] + grand[5] + grand[6]
  sumMerge(r)
  ws.getRow(r).height = 18
  ws.getRow(r).getCell(1).value = 'TOTAL'
  ws.getRow(r).getCell(4).value = totFin
  ws.getRow(r).getCell(7).value = totDrf
  ws.getRow(r).getCell(10).value = totFin + totDrf
  ws.getRow(r).getCell(13).value = grandCombined > 0 ? 1 : 0
  applyRowStyle(r, STYLES.sumTotal, { 4: NUM_FMT, 7: NUM_FMT, 10: NUM_FMT, 13: PCT_FMT })

  // ── Download ────────────────────────────────────────────────────────────────────
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
    // Form = single source of truth untuk draft. Hindari refetch tak terduga
    // (mis. window focus) yang akan menimpa input user; hanya refetch saat kita invalidate.
    refetchOnWindowFocus: false,
  })

  const unitId = summary?.plantation_unit?.id ?? null

  // Form = single source of truth untuk draft. Aturan prefill kolom Jumlah:
  //  • Sebelum user pernah menyimpan (draft/permanen) di halaman ini → prefill
  //       = sisa dana tersedia (jumlah pengajuan − yang sudah ditransfer permanen).
  //  • Setelah user menyimpan draft/permanen (refetch) →
  //       - item yang PUNYA draft → tampilkan nilai draft-nya,
  //       - item tanpa draft      → 0 (mis. sengaja di-nol-kan user).
  //
  // PENTING: flag ini ditandai oleh AKSI SIMPAN user (lihat onSuccess di bawah),
  // bukan oleh berapa kali efek ini berjalan. React Query bisa mengembalikan data
  // cache (stale) lalu data segar menyusul untuk pdoId yang sama — kalau flag
  // ditandai berdasarkan siklus efek, kedatangan data kedua akan salah dianggap
  // "bukan pertama kali" dan menol-kan kolom Jumlah secara keliru.
  const hasSavedRef = useRef(false)

  useEffect(() => {
    hasSavedRef.current = false
  }, [pdoId])

  useEffect(() => {
    if (!summary?.details) return
    const firstLoad = !hasSavedRef.current

    setRows(
      summary.details.map((d) => {
        // Item potongan (is_deduction): Jumlah dikunci 0. Tujuan transfer bisa
        // dipilih user selama BELUM committed. Bila sudah committed, ambil tujuan
        // dari entri potongan (breakdown final yang bernilai negatif).
        if (d.expense_item?.is_deduction) {
          const committedDest = DEST_OPTIONS.find((k) => d.final_by_dest[k] < 0)
          return {
            pdo_detail_id: d.pdo_detail_id,
            isSplit:       false,
            normal: { amount: 0, transfer_date: today, reference_number: '', notes: '', dest: committedDest ?? 'rek_kebun' },
            split: {
              amount1: 0, dest1: 'rek_kebun' as TransferDest,
              amount2: 0, dest2: 'pribadi' as TransferDest,
              transfer_date: today, reference_number: '', notes: '',
            },
          }
        }

        const isSplit   = isSplitForUnit(d.expense_item, unitId)
        const available = Math.max(d.amount_approved - d.total_transferred, 0)
        const drafts    = d.draft_entries

        if (isSplit) {
          const a1 = drafts[0]
          const a2 = drafts[1]
          return {
            pdo_detail_id: d.pdo_detail_id,
            isSplit:       true,
            normal: { amount: 0, transfer_date: today, reference_number: '', notes: '', dest: 'rek_kebun' as TransferDest },
            split: {
              amount1:          a1 ? a1.amount : 0,
              dest1:            (a1?.transfer_destination ?? 'rek_kebun') as TransferDest,
              amount2:          a2 ? a2.amount : 0,
              dest2:            (a2?.transfer_destination ?? 'pribadi') as TransferDest,
              transfer_date:    today,
              reference_number: '',
              notes:            '',
            },
          }
        }

        // Jangan prefill dengan sisa dana jika PDO ini sudah pernah punya draft ATAU
        // committed transfer di item manapun (bukan cuma item ini) — hormati pilihan
        // eksplisit user sebelumnya (termasuk item yang sengaja di-nol-kan), dan cegah
        // item baru (mis. dari PDO Tambahan yang baru digabung) ikut ter-prefill diam-diam
        // di PDO yang sebenarnya sudah pernah dipakai untuk transfer sebelumnya. Prefill
        // hanya terjadi pada kunjungan pertama yang benar-benar belum pernah ada transfer
        // sama sekali di PDO ini.
        const pdoHasAnyHistory = summary.details.some((x) => x.draft_total > 0 || x.total_transferred > 0)
        const amount = d.draft_total > 0
          ? d.draft_total
          : (firstLoad && !pdoHasAnyHistory ? available : 0)
        return {
          pdo_detail_id: d.pdo_detail_id,
          isSplit:       false,
          normal: {
            amount,
            transfer_date:    today,
            reference_number: '',
            notes:            '',
            dest:             (drafts[0]?.transfer_destination ?? 'rek_kebun') as TransferDest,
          },
          split: {
            amount1: 0, dest1: 'rek_kebun' as TransferDest,
            amount2: 0, dest2: 'pribadi' as TransferDest,
            transfer_date: today, reference_number: '', notes: '',
          },
        }
      })
    )
  }, [summary, unitId, pdoId])

  const details = useMemo(() => summary?.details ?? [], [summary])

  // ── Cards ringkasan: hanya transfer yang SUDAH tercatat (committed + draft) ─────
  // Nilai kolom "Jumlah" pada form (input yang belum disimpan) TIDAK diikutkan —
  // itu hanya usulan/prefill, kalau dijumlahkan membuat rek kebun membengkak dan
  // Sisa Dana selalu 0.
  const cards = useMemo(() => {
    // Total Pengajuan signed: item potongan (is_deduction) MENGURANGI total —
    // harus sama dengan grand_total_amount di halaman Daftar PDO.
    const totalPengajuan = details.reduce(
      (s, d) => s + (d.expense_item?.is_deduction ? -d.amount_approved : d.amount_approved),
      0,
    )
    // Total potongan (nominal seluruh item is_deduction), ditampilkan sebagai minus.
    const totalPotongan = details.reduce(
      (s, d) => s + (d.expense_item?.is_deduction ? d.amount_approved : 0),
      0,
    )
    const dest: DestBreakdown = { rek_kebun: 0, pribadi: 0, vendor: 0 }

    for (const d of details) {
      // Hanya transfer yang SUDAH tercatat: committed (final) + draft tersimpan.
      // Potongan sudah menjadi entri negatif committed (di rek_kebun) saat simpan
      // permanen, jadi otomatis ikut ter-net di sini — tidak perlu proyeksi lagi.
      dest.rek_kebun += d.final_by_dest.rek_kebun + d.draft_by_dest.rek_kebun
      dest.pribadi   += d.final_by_dest.pribadi   + d.draft_by_dest.pribadi
      dest.vendor    += d.final_by_dest.vendor    + d.draft_by_dest.vendor
    }
    const totalTransfer = dest.rek_kebun + dest.pribadi + dest.vendor
    return { totalPengajuan, totalPotongan, dest, sisa: totalPengajuan - totalTransfer }
  }, [details])

  const hasDrafts = useMemo(() => details.some((d) => d.draft_entries.length > 0), [details])

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

  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ['transfer-summary', pdoId] })
    qc.invalidateQueries({ queryKey: ['transfer-pdo-summary'] })
  }

  const errMsg = (err: unknown, fallback: string) =>
    (err as { response?: { data?: { error?: { message?: string } } }; message?: string })
      ?.response?.data?.error?.message ?? (err as { message?: string })?.message ?? fallback

  const save = useMutation({
    mutationFn: () => {
      const entries: object[] = []
      rows.forEach((row) => {
        if (row.isSplit) {
          if (Number(row.split.amount1) > 0) entries.push({ pdo_detail_id: row.pdo_detail_id, amount: Number(row.split.amount1), transfer_date: row.split.transfer_date, reference_number: row.split.reference_number || null, notes: row.split.notes || null, transfer_destination: row.split.dest1 })
          if (Number(row.split.amount2) > 0) entries.push({ pdo_detail_id: row.pdo_detail_id, amount: Number(row.split.amount2), transfer_date: row.split.transfer_date, reference_number: row.split.reference_number || null, notes: row.split.notes || null, transfer_destination: row.split.dest2 })
        } else if (Number(row.normal.amount) > 0) {
          entries.push({ pdo_detail_id: row.pdo_detail_id, amount: Number(row.normal.amount), transfer_date: row.normal.transfer_date, reference_number: row.normal.reference_number || null, notes: row.normal.notes || null, transfer_destination: row.normal.dest })
        }
      })
      // entries kosong = hapus semua draft (sinkronisasi form → draft)
      return api.post(`/pdo/${pdoId}/transfers/bulk`, { entries })
    },
    onSuccess: () => { hasSavedRef.current = true; toast('Draft transfer berhasil disimpan'); invalidate() },
    onError: (err) => toast(errMsg(err, 'Gagal menyimpan draft'), 'error'),
  })

  const commit = useMutation({
    mutationFn: () => {
      // Tujuan potongan pilihan user (item is_deduction yang belum committed).
      const deduction_destinations: Record<string, TransferDest> = {}
      details.forEach((d, i) => {
        if (d.expense_item?.is_deduction && d.total_transferred >= 0 && rows[i]) {
          deduction_destinations[d.pdo_detail_id] = rows[i].normal.dest
        }
      })
      return api.post(`/pdo/${pdoId}/transfers/commit`, { deduction_destinations })
    },
    onSuccess: () => { hasSavedRef.current = true; toast('Semua draft berhasil disimpan permanen'); invalidate() },
    onError: (err) => toast(errMsg(err, 'Gagal menyimpan permanen'), 'error'),
  })

  const handleSave = () => {
    for (let i = 0; i < rows.length; i++) {
      const row    = rows[i]
      const detail = details[i]
      if (!detail) continue
      const itemName = detail.expense_item?.name ?? detail.description
      // Draft menggantikan draft lama → batas = pengajuan − yang sudah permanen.
      const available = Math.max(detail.amount_approved - detail.total_transferred, 0)

      if (row.isSplit) {
        const total = Number(row.split.amount1) + Number(row.split.amount2)
        if (total > available) {
          toast(`"${itemName}": total split (${fmt(total)}) melebihi sisa dana (${fmt(available)})`, 'error')
          return
        }
        if (Number(row.split.amount1) > 0 && Number(row.split.amount2) > 0 && row.split.dest1 === row.split.dest2) {
          toast(`"${itemName}": tujuan transfer 1 dan 2 tidak boleh sama`, 'error')
          return
        }
      } else if (Number(row.normal.amount) > available) {
        toast(`"${itemName}": jumlah melebihi sisa dana (${fmt(available)})`, 'error')
        return
      }
    }
    save.mutate()
  }

  const handleCommit = () => {
    if (!hasDrafts) { toast('Belum ada draft untuk disimpan permanen', 'error'); return }
    if (!window.confirm('Simpan permanen semua draft transfer PDO ini? Setelah permanen, transfer akan dihitung di semua laporan.')) return
    commit.mutate()
  }

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
          <p className="text-muted text-sm mt-1">Catat transfer per item biaya sebagai draft, review lewat Excel, lalu simpan permanen.</p>
        </div>
        {summary && (
          <Button variant="secondary" size="sm" onClick={() => { void exportToExcel(summary) }}>
            <Download className="w-4 h-4 mr-1.5" />
            Download Excel
          </Button>
        )}
      </div>

      {/* ── Cards ringkasan live ─────────────────────────────────────────────── */}
      <div className="grid grid-cols-2 md:grid-cols-6 gap-3 mb-6">
        <SummaryCard label="Total Pengajuan" value={cards.totalPengajuan} tone="neutral" />
        <SummaryCard label="Total Potongan" value={-cards.totalPotongan} tone="red" />
        <SummaryCard label="Transfer — Rek. Kebun" value={cards.dest.rek_kebun} tone="teal" />
        <SummaryCard label="Transfer — Pribadi" value={cards.dest.pribadi} tone="amber" />
        <SummaryCard label="Transfer — Vendor" value={cards.dest.vendor} tone="blue" />
        <SummaryCard label="Sisa Dana" value={cards.sisa} tone={cards.sisa < 0 ? 'red' : 'green'} />
      </div>

      <div className="overflow-auto border border-line rounded-drawer bg-white mb-4">
        <table className="w-full border-collapse" style={{ minWidth: 1400 }}>
          <thead>
            <tr>
              {['Kategori / Sub-Kategori', 'Item Biaya', 'Total Pengajuan', 'Sudah Ditransfer', 'Draft', 'Sisa Dana', 'Tujuan Transfer', 'Jumlah (Rp)', 'Tanggal', 'No. Referensi', 'Catatan'].map((h) => (
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
                  {Array.from({ length: 11 }).map((__, j) => (
                    <td key={j} className="px-3 py-3"><div className="h-4 bg-[#f0f4f0] rounded animate-pulse" /></td>
                  ))}
                </tr>
              ))
            ) : details.map((detail, idx) => {
              const row      = rows[idx]
              if (!row) return null
              const hasHistory   = detail.entries.length > 0
              const draftCount   = detail.draft_entries.length
              const isDeduction  = detail.expense_item?.is_deduction ?? false
              // Potongan dianggap sudah committed bila total transfernya negatif
              // (entri potongan otomatis sudah dibuat saat simpan permanen).
              const isDeductionCommitted = isDeduction && detail.total_transferred < 0
              const available    = Math.max(detail.amount_approved - detail.total_transferred, 0)
              const isExpanded   = expandedIds.has(detail.pdo_detail_id)
              const itemCode     = detail.expense_item?.code
              const itemName     = detail.expense_item?.name ?? '—'
              const itemLabel    = itemCode ? `[${itemCode}] ${itemName}` : itemName
              const categoryLabel = detail.category ? `${detail.category.code} — ${detail.category.name}` : '—'
              const subcategoryLabel = detail.subcategory ? `${detail.subcategory.code} — ${detail.subcategory.name}` : null

              const toggles = (
                <span className="inline-flex gap-2 ml-2">
                  {draftCount > 0 && (
                    <button type="button" onClick={() => toggleExpand(detail.pdo_detail_id)} className="inline-flex items-center gap-0.5 text-xs text-amber-600 hover:text-amber-800 font-normal">
                      Draft ({draftCount})
                      {isExpanded ? <ChevronUp className="w-3 h-3" /> : <ChevronDown className="w-3 h-3" />}
                    </button>
                  )}
                  {hasHistory && (
                    <button type="button" onClick={() => toggleExpand(detail.pdo_detail_id)} className="inline-flex items-center gap-0.5 text-xs text-blue-600 hover:text-blue-800 font-normal">
                      Riwayat ({detail.entries.length})
                    </button>
                  )}
                </span>
              )

              const metaCols = isDeduction ? (
                <>
                  {/* Item potongan: Total Pengajuan minus; kolom transfer/sisa tak berlaku */}
                  <td className="px-3 py-2 text-sm text-red-600 font-medium">-{fmt(detail.amount_approved)}</td>
                  {/* Sudah Ditransfer = potongan yang sudah diterapkan (negatif) bila sudah committed */}
                  <td className="px-3 py-2 text-sm text-red-600 font-medium">{detail.total_transferred < 0 ? fmt(detail.total_transferred) : '—'}</td>
                  <td className="px-3 py-2 text-sm text-muted">—</td>
                  <td className="px-3 py-2 text-sm text-muted">—</td>
                </>
              ) : (
                <>
                  <td className="px-3 py-2 text-sm">{fmt(detail.amount_approved)}</td>
                  <td className="px-3 py-2 text-sm text-teal-700 font-medium">{fmt(detail.total_transferred)}</td>
                  <td className="px-3 py-2 text-sm text-amber-600 font-medium">{detail.draft_total > 0 ? fmt(detail.draft_total) : '—'}</td>
                  <td className="px-3 py-2 text-sm font-bold text-green-700">{fmt(detail.remaining)}</td>
                </>
              )

              const expandedRow = isExpanded && (
                <tr key={`${detail.pdo_detail_id}-exp`}>
                  <td colSpan={11} className="px-0 py-0 bg-gray-50 border-t border-dashed border-line">
                    {draftCount > 0 && <DraftTable entries={detail.draft_entries} />}
                    {hasHistory && <HistoryTable entries={detail.entries} />}
                  </td>
                </tr>
              )

              if (row.isSplit) {
                const totalSplit  = Number(row.split.amount1) + Number(row.split.amount2)
                const overLimit   = totalSplit > available
                const sameDestErr = Number(row.split.amount1) > 0 && Number(row.split.amount2) > 0 && row.split.dest1 === row.split.dest2

                return (
                  <>
                    <tr key={`${detail.pdo_detail_id}-header`} className="border-t-2 border-line bg-[#f7faf7]">
                      <td className="px-3 py-2 text-xs text-muted">
                        <div className="font-[700] text-ink">{categoryLabel}</div>
                        {subcategoryLabel && <div className="text-[11px] mt-0.5">{subcategoryLabel}</div>}
                      </td>
                      <td className="px-3 py-2 font-bold text-sm">
                        {itemLabel}
                        <span className="ml-2 text-xs bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded font-normal">Split</span>
                        {toggles}
                      </td>
                      {metaCols}
                      <td colSpan={5} className="px-3 py-2 text-xs text-muted">
                        <div className="flex items-center gap-2 flex-wrap">
                          <div><span className="font-bold mr-1">Tgl:</span>
                            <input type="date" value={row.split.transfer_date} onChange={(e) => updateSplit(idx, 'transfer_date', e.target.value)} className="input-base w-36 text-sm" /></div>
                          <div><span className="font-bold mr-1">Ref:</span>
                            <input type="text" placeholder="No. Referensi" value={row.split.reference_number} onChange={(e) => updateSplit(idx, 'reference_number', e.target.value)} className="input-base w-32 text-sm" /></div>
                          <div><span className="font-bold mr-1">Catatan:</span>
                            <input type="text" placeholder="Catatan" value={row.split.notes} onChange={(e) => updateSplit(idx, 'notes', e.target.value)} className="input-base w-32 text-sm" /></div>
                        </div>
                        {overLimit && <p className="text-red-500 text-xs mt-1">Total split melebihi sisa dana</p>}
                        {sameDestErr && <p className="text-red-500 text-xs">Tujuan 1 dan 2 tidak boleh sama</p>}
                      </td>
                    </tr>
                    <tr key={`${detail.pdo_detail_id}-s1`} className="border-t border-dashed border-line">
                      <td className="px-3 py-2 pl-8 text-xs text-muted">↳ Tujuan 1</td>
                      <td colSpan={5} />
                      <td className="px-3 py-2">
                        <select value={row.split.dest1} onChange={(e) => updateSplit(idx, 'dest1', e.target.value as TransferDest)} className={`input-base w-32 text-sm ${sameDestErr ? 'border-red-400' : ''}`}>
                          {DEST_OPTIONS.map((d) => <option key={d} value={d}>{DEST_LABELS[d]}</option>)}
                        </select>
                      </td>
                      <td className="px-3 py-2">
                        <input type="number" min={0} value={row.split.amount1} onChange={(e) => updateSplit(idx, 'amount1', e.target.value)} className={`input-base w-36 ${overLimit ? 'border-red-400' : ''}`} />
                      </td>
                      <td colSpan={3} />
                    </tr>
                    <tr key={`${detail.pdo_detail_id}-s2`} className="border-t border-dashed border-line">
                      <td className="px-3 py-2 pl-8 text-xs text-muted">↳ Tujuan 2</td>
                      <td colSpan={5} />
                      <td className="px-3 py-2">
                        <select value={row.split.dest2} onChange={(e) => updateSplit(idx, 'dest2', e.target.value as TransferDest)} className={`input-base w-32 text-sm ${sameDestErr ? 'border-red-400' : ''}`}>
                          {DEST_OPTIONS.map((d) => <option key={d} value={d}>{DEST_LABELS[d]}</option>)}
                        </select>
                      </td>
                      <td className="px-3 py-2">
                        <input type="number" min={0} value={row.split.amount2} onChange={(e) => updateSplit(idx, 'amount2', e.target.value)} className={`input-base w-36 ${overLimit ? 'border-red-400' : ''}`} />
                      </td>
                      <td colSpan={3} />
                    </tr>
                    {expandedRow}
                  </>
                )
              }

              // Normal mode
              const overLim = Number(row.normal.amount) > available

              return (
                <>
                  <tr key={detail.pdo_detail_id} className="border-t border-line hover:bg-[#fbfdfb]">
                    <td className="px-3 py-3 text-xs text-muted">
                      <div className="font-[700] text-ink">{categoryLabel}</div>
                      {subcategoryLabel && <div className="text-[11px] mt-0.5">{subcategoryLabel}</div>}
                    </td>
                    <td className="px-3 py-3 text-sm font-medium whitespace-nowrap">
                      {itemLabel}
                      {isDeduction && <span className="ml-2 text-xs bg-red-100 text-red-700 px-1.5 py-0.5 rounded font-normal">Potongan</span>}
                      {toggles}
                    </td>
                    {metaCols}
                    {isDeduction ? (
                      isDeductionCommitted ? (
                        <td colSpan={5} className="px-3 py-2 text-xs text-muted italic">
                          Potongan sudah dikurangkan dari transfer {DEST_LABELS[row.normal.dest]} (−{fmt(detail.amount_approved)}).
                        </td>
                      ) : (
                        <>
                          {/* Belum committed: tujuan boleh dipilih; field lain disabled */}
                          <td className="px-3 py-2">
                            <select value={row.normal.dest} onChange={(e) => updateNormal(idx, 'dest', e.target.value as TransferDest)} className="input-base w-32 text-sm">
                              {DEST_OPTIONS.map((d) => <option key={d} value={d}>{DEST_LABELS[d]}</option>)}
                            </select>
                          </td>
                          <td colSpan={4} className="px-3 py-2 text-xs text-muted italic">
                            Potongan −{fmt(detail.amount_approved)} akan dikurangkan dari transfer {DEST_LABELS[row.normal.dest]} saat simpan permanen.
                          </td>
                        </>
                      )
                    ) : (
                      <>
                        <td className="px-3 py-2">
                          <select value={row.normal.dest} onChange={(e) => updateNormal(idx, 'dest', e.target.value as TransferDest)} className="input-base w-32 text-sm">
                            {DEST_OPTIONS.map((d) => <option key={d} value={d}>{DEST_LABELS[d]}</option>)}
                          </select>
                        </td>
                        <td className="px-3 py-2">
                          <input type="number" min={0} max={available} value={row.normal.amount} onChange={(e) => updateNormal(idx, 'amount', e.target.value)} className={`input-base w-36 ${overLim ? 'border-red-400' : ''}`} />
                          {overLim && <p className="text-red-500 text-xs mt-1">Melebihi sisa dana</p>}
                        </td>
                        <td className="px-3 py-2">
                          <input type="date" value={row.normal.transfer_date} onChange={(e) => updateNormal(idx, 'transfer_date', e.target.value)} className="input-base w-36" />
                        </td>
                        <td className="px-3 py-2">
                          <input type="text" placeholder="TRF/2026/001" value={row.normal.reference_number} onChange={(e) => updateNormal(idx, 'reference_number', e.target.value)} className="input-base w-36" />
                        </td>
                        <td className="px-3 py-2">
                          <input type="text" value={row.normal.notes} onChange={(e) => updateNormal(idx, 'notes', e.target.value)} className="input-base w-40" />
                        </td>
                      </>
                    )}
                  </tr>
                  {expandedRow}
                </>
              )
            })}
          </tbody>
        </table>
      </div>

      <div className="flex justify-end gap-2">
        <Button variant="secondary" onClick={() => navigate('/transfer')}>Kembali</Button>
        <Button variant="secondary" onClick={handleSave} loading={save.isPending}>
          Simpan sebagai Draft
        </Button>
        <Button onClick={handleCommit} loading={commit.isPending} disabled={!hasDrafts}>
          Simpan Permanen
        </Button>
      </div>
    </div>
  )
}

// ─── card ringkasan ─────────────────────────────────────────────────────────────

type CardTone = 'neutral' | 'teal' | 'amber' | 'blue' | 'green' | 'red'
const TONE: Record<CardTone, string> = {
  neutral: 'text-ink',
  teal:    'text-teal-700',
  amber:   'text-amber-600',
  blue:    'text-blue-700',
  green:   'text-green-700',
  red:     'text-red-600',
}

function SummaryCard({ label, value, tone }: { label: string; value: number; tone: CardTone }) {
  return (
    <div className="rounded-drawer border border-line bg-white px-4 py-3">
      <p className="text-[11px] font-bold uppercase tracking-wider text-muted">{label}</p>
      <p className={`mt-1 text-lg font-[800] ${TONE[tone]}`}>{fmt(value)}</p>
    </div>
  )
}

// ─── sub-component: draft editor ────────────────────────────────────────────────

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

// Tampilan draft read-only. Perubahan draft dilakukan lewat kolom "Jumlah" di
// form utama (single source of truth), lalu klik "Simpan sebagai Draft".
function DraftTable({ entries }: { entries: TransferEntryRecord[] }) {
  return (
    <div className="px-6 py-3">
      <p className="text-xs font-bold text-amber-600 mb-2 uppercase tracking-wider">Draft Transfer (belum permanen)</p>
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
              <td className="py-1.5 pr-4 font-medium text-amber-600">{fmtRp(e.amount)}</td>
              <td className="py-1.5 pr-4 text-muted">{e.reference_number ?? '—'}</td>
              <td className="py-1.5 text-muted">{e.notes ?? '—'}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

// ─── sub-component: riwayat committed ────────────────────────────────────────────

function HistoryTable({ entries }: { entries: TransferEntryRecord[] }) {
  return (
    <div className="px-6 py-3">
      <p className="text-xs font-bold text-muted mb-2 uppercase tracking-wider">Riwayat Transfer (permanen)</p>
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
