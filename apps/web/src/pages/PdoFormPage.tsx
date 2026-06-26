import { useEffect, useCallback, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useForm, useFieldArray } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api, getApiErrorMessage, getApiValidationDetails } from '@/lib/api'
import { Button } from '@/components/ui/Button'
import { useToastStore } from '@/store/toast.store'
import { useAuthStore } from '@/store/auth.store'
import { ExternalCostPullPanel } from '@/components/pdo/ExternalCostPullPanel'
import { useItems, useSubcategories, useCategories } from '@/hooks/useMasterData'
import { usePdo, usePullExternalCost } from '@/hooks/usePdo'
import { fmt } from '@/lib/format'
import { ArrowLeft, Plus, Trash2, LayoutList } from 'lucide-react'
import type { ApiResponse, PdoDetail, PdoHeader, PlantationUnit } from '@/types'

const detailSchema = z.object({
  id:              z.string().uuid().optional(),
  expense_item_id: z.string().uuid('Pilih item'),
  description:     z.string().min(1, 'Deskripsi wajib diisi'),
  quantity:        z.coerce.number().nullable().optional(),
  unit:            z.string().nullable().optional(),
  rate:            z.coerce.number().nullable().optional(),
  amount:          z.coerce.number().min(0).default(0),
  notes:           z.string().nullable().optional(),
  display_order:   z.coerce.number().int().default(0),
})

const createSchema = z.object({
  plantation_unit_id: z.string().uuid('Pilih unit kebun'),
  period_month:       z.coerce.number().int().min(1).max(12),
  period_year:        z.coerce.number().int().min(2020),
  notes:              z.string().nullable().optional(),
})

const editSchema = z.object({
  plantation_unit_id: z.string().uuid('Pilih unit kebun'),
  period_month:       z.coerce.number().int().min(1).max(12),
  period_year:        z.coerce.number().int().min(2020),
  notes:              z.string().nullable().optional(),
  details:            z.array(detailSchema).min(1, 'Minimal 1 item biaya'),
})

type Form = z.infer<typeof editSchema>

type RowSelection  = { categoryId: string; subcategoryId: string }
type DetailSnapshot = Partial<PdoDetail> & { id?: string; _isNew?: boolean }

const MONTHS = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember']
const YEARS  = Array.from({ length: 5 }, (_, i) => new Date().getFullYear() - i + 1)

export function PdoFormPage() {
  const { id }   = useParams<{ id: string }>()
  const navigate = useNavigate()
  const toast    = useToastStore((s) => s.push)
  const qc       = useQueryClient()
  const user     = useAuthStore((s) => s.user)
  const isEdit   = !!id

  const [rowSelections,    setRowSelections]   = useState<RowSelection[]>([])
  const [detailSnapshots,  setDetailSnapshots] = useState<DetailSnapshot[]>([])
  const [pullErrors,       setPullErrors]      = useState<Record<number, string>>({})
  const [pullingDetailId,  setPullingDetailId] = useState<string | null>(null)
  const [collapsedGroups,  setCollapsedGroups] = useState<Set<string>>(new Set())

  const { data: units } = useQuery({
    queryKey: ['plantation-units'],
    queryFn:  async () => {
      const res = await api.get<ApiResponse<PlantationUnit[]>>('/plantation-units')
      return res.data.data
    },
  })

  const { data: items }         = useItems({ is_active: true })
  const { data: subcategories } = useSubcategories({ is_active: true })
  const { data: categories }    = useCategories({ is_active: true })

  const { data: existing } = usePdo(id)
  const pullExternalCost   = usePullExternalCost(id ?? '')

  const now = new Date()
  const {
    register, control, handleSubmit, watch, reset, setValue, getValues,
    formState: { errors },
  } = useForm<Form>({
    resolver: zodResolver(isEdit ? editSchema : createSchema) as any,
    defaultValues: {
      plantation_unit_id: user?.plantation_unit?.id ?? '',
      period_month: now.getMonth() + 1,
      period_year:  now.getFullYear(),
      details: [],
    },
  })

  const { fields, append, prepend, remove, replace } = useFieldArray({ control, name: 'details' })

  const detailValues = watch('details')
  const totalAmount  = detailValues?.reduce((sum, d) => sum + (Number(d.amount) || 0), 0) ?? 0

  const resolveRowSelection = (itemId: string): RowSelection => {
    const item = items?.find((i) => i.id === itemId)
    const sub  = subcategories?.find((s) => s.id === item?.subcategory_id)
    return {
      categoryId:    sub?.category_id    ?? '',
      subcategoryId: item?.subcategory_id ?? '',
    }
  }

  // Load existing PDO details when editing
  useEffect(() => {
    if (!existing) return
    reset({
      plantation_unit_id: existing.plantation_unit_id,
      period_month:       existing.period_month,
      period_year:        existing.period_year,
      notes:              existing.notes ?? '',
      details: [],
    })
    api.get<ApiResponse<PdoDetail[]>>(`/pdo/${id}/details`).then((res) => {
      const details = res.data.data
      // Use append loop (not replace) — replace + remove has inconsistent behaviour in RHF
      details.forEach((d) => append({
        id:              d.id,
        expense_item_id: d.expense_item_id,
        description:     d.description,
        quantity:        d.quantity,
        unit:            d.unit,
        rate:            d.rate,
        amount:          d.amount,
        notes:           d.notes,
        display_order:   d.display_order,
      }))
      setDetailSnapshots(details)
      setPullErrors({})

      const selections = details.map((d) => resolveRowSelection(d.expense_item_id))
      setRowSelections(selections)

      // Default all groups collapsed
      const keys = new Set<string>()
      selections.forEach((s) => {
        if (s.categoryId) {
          keys.add(`cat_${s.categoryId}`)
          if (s.subcategoryId) keys.add(`sub_${s.categoryId}_${s.subcategoryId}`)
        }
      })
      setCollapsedGroups(keys)

      // Auto-scroll to top after items load
      setTimeout(() => window.scrollTo({ top: 0, behavior: 'smooth' }), 200)
    })
  }, [existing])

  const save = useMutation({
    mutationFn: (data: Form) => {
      if (isEdit) return api.put(`/pdo/${id}`, data)
      const { plantation_unit_id, period_month, period_year, notes } = data
      return api.post('/pdo', { plantation_unit_id, period_month, period_year, notes })
    },
    onSuccess: (res) => {
      const created = (res.data as ApiResponse<PdoHeader>).data
      toast(isEdit ? 'PDO berhasil diperbarui' : 'PDO berhasil dibuat — item rutin telah disisipkan otomatis')
      qc.invalidateQueries({ queryKey: ['pdo'] })
      navigate(`/pdo/${created.id}`)
    },
    onError: () => toast('Gagal menyimpan PDO', 'error'),
  })

  const submit = useMutation({
    mutationFn: async (data: Form) => {
      const res = isEdit
        ? await api.put(`/pdo/${id}`, data)
        : await api.post('/pdo', {
            plantation_unit_id: data.plantation_unit_id,
            period_month: data.period_month,
            period_year:  data.period_year,
            notes:        data.notes,
          })
      const header = (res.data as ApiResponse<PdoHeader>).data
      await api.post(`/pdo/${header.id}/submit`, {
        submission_date: new Date().toISOString().split('T')[0],
      })
      return header
    },
    onSuccess: (header) => {
      toast('PDO berhasil diajukan ke Asisten')
      qc.invalidateQueries({ queryKey: ['pdo'] })
      navigate(`/pdo/${header.id}/approval`)
    },
    onError: () => toast('Gagal mengajukan PDO', 'error'),
  })

  const setRowSel = (idx: number, patch: Partial<RowSelection>) => {
    setRowSelections((prev) => {
      const next = [...prev]
      next[idx]  = { ...next[idx], ...patch }
      return next
    })
  }

  const handleCategoryChange = (idx: number, categoryId: string) => {
    setRowSel(idx, { categoryId, subcategoryId: '' })
    setValue(`details.${idx}.expense_item_id`, '')
    setValue(`details.${idx}.description`, '')
    setValue(`details.${idx}.unit`, null)
    setValue(`details.${idx}.rate`, null)
  }

  const handleSubcategoryChange = (idx: number, subcategoryId: string) => {
    setRowSel(idx, { subcategoryId })
    setValue(`details.${idx}.expense_item_id`, '')
    setValue(`details.${idx}.description`, '')
    setValue(`details.${idx}.unit`, null)
    setValue(`details.${idx}.rate`, null)
  }

  const handleItemChange = (idx: number, itemId: string) => {
    const item = items?.find((i) => i.id === itemId)
    if (!item) return
    setValue(`details.${idx}.description`, item.name)
    if (item.default_unit) setValue(`details.${idx}.unit`, item.default_unit)
    if (item.default_rate) setValue(`details.${idx}.rate`, item.default_rate)
    setValue(`details.${idx}.quantity`, null)
    setValue(`details.${idx}.amount`, 0)
    const sub = subcategories?.find((s) => s.id === item.subcategory_id)
    setRowSel(idx, {
      categoryId:    sub?.category_id    ?? '',
      subcategoryId: item.subcategory_id ?? '',
    })
  }

  const handlePullExternalCost = async (idx: number) => {
    const detailId = detailSnapshots[idx]?.id
    if (!id || !detailId) {
      const message = 'Simpan draft dulu sebelum Ambil Data.'
      setPullErrors((prev) => ({ ...prev, [idx]: message }))
      toast(message, 'error')
      return
    }
    setPullingDetailId(detailId)
    setPullErrors((prev) => { const next = { ...prev }; delete next[idx]; return next })
    try {
      const result = await pullExternalCost.mutateAsync(detailId)
      setValue(`details.${idx}.amount`,   Number(result.detail.amount ?? 0))
      setValue(`details.${idx}.quantity`, result.detail.quantity ?? null)
      setValue(`details.${idx}.unit`,     result.detail.unit     ?? null)
      setDetailSnapshots((prev) => {
        const next = [...prev]; next[idx] = result.detail; return next
      })
      toast(
        result.detail.external_payload?.status === 'empty'
          ? 'Data Payroll kosong berhasil diambil.'
          : 'Data Payroll berhasil diambil.'
      )
    } catch (error) {
      const validationMessage = getApiValidationDetails(error)[0]?.message
      const message = validationMessage ?? getApiErrorMessage(error)
      setPullErrors((prev) => ({ ...prev, [idx]: message }))
      toast(message, 'error')
    } finally {
      setPullingDetailId(null)
    }
  }

  // amount = quantity × rate, reads form store synchronously via getValues
  const calculateAmount = (idx: number) => {
    const detail = getValues(`details.${idx}`)
    if (!detail) return
    const selectedItem = items?.find((entry) => entry.id === detail.expense_item_id)
    if (selectedItem?.mode_input === 'auto_external') return
    setValue(`details.${idx}.amount`, (Number(detail.quantity) || 0) * (Number(detail.rate) || 0))
  }

  useEffect(() => {
    detailValues?.forEach((_, idx) => calculateAmount(idx))
  }, [detailValues?.map((d) => `${d.quantity}-${d.rate}`).join(',')])

  const handleRemove = (idx: number) => {
    remove(idx)
    setRowSelections((prev)  => { const n = [...prev];  n.splice(idx, 1); return n })
    setDetailSnapshots((prev) => { const n = [...prev]; n.splice(idx, 1); return n })
    setPullErrors((prev) => {
      const next: Record<number, string> = {}
      Object.entries(prev).forEach(([key, value]) => {
        const k = Number(key)
        if (k === idx) return
        next[k > idx ? k - 1 : k] = value
      })
      return next
    })
  }

  // Prepend new row to the top so the user doesn't need to scroll
  const handleAppend = () => {
    prepend({
      expense_item_id: '', description: '', quantity: null,
      unit: null, rate: null, amount: 0, notes: null, display_order: 0,
    })
    setRowSelections((prev)  => [{ categoryId: '', subcategoryId: '' }, ...prev])
    setDetailSnapshots((prev) => [{ _isNew: true }, ...prev])
    // Shift all pullError indices down by +1 (existing rows moved down)
    setPullErrors((prev) => {
      const next: Record<number, string> = {}
      Object.entries(prev).forEach(([k, v]) => { next[Number(k) + 1] = v })
      return next
    })
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }

  // Re-sort all items by category → sub-kategori display_order, then collapse all groups
  const handleRegroup = useCallback(() => {
    const indexed = fields.map((_, idx) => ({
      values:   getValues(`details.${idx}`),
      sel:      rowSelections[idx]   ?? { categoryId: '', subcategoryId: '' },
      snapshot: detailSnapshots[idx] ?? {},
    }))

    indexed.sort((a, b) => {
      if (!a.sel.categoryId && !b.sel.categoryId) return 0
      if (!a.sel.categoryId) return 1
      if (!b.sel.categoryId) return -1
      const catA = categories?.find((c) => c.id === a.sel.categoryId)
      const catB = categories?.find((c) => c.id === b.sel.categoryId)
      const catCmp = (catA?.display_order ?? 999) - (catB?.display_order ?? 999)
      if (catCmp !== 0) return catCmp
      const subA = subcategories?.find((s) => s.id === a.sel.subcategoryId)
      const subB = subcategories?.find((s) => s.id === b.sel.subcategoryId)
      return (subA?.display_order ?? 999) - (subB?.display_order ?? 999)
    })

    replace(indexed.map((i) => i.values))
    setRowSelections(indexed.map((i) => i.sel))
    // Clear _isNew so regrouped items render inside their category groups
    setDetailSnapshots(indexed.map((i) => ({ ...i.snapshot, _isNew: undefined })))
    setPullErrors({})

    const keys = new Set<string>()
    indexed.forEach(({ sel }) => {
      if (sel.categoryId) {
        keys.add(`cat_${sel.categoryId}`)
        if (sel.subcategoryId) keys.add(`sub_${sel.categoryId}_${sel.subcategoryId}`)
      }
    })
    setCollapsedGroups(keys)
    setTimeout(() => window.scrollTo({ top: 0, behavior: 'smooth' }), 100)
  }, [fields, rowSelections, detailSnapshots, categories, subcategories, getValues, replace])

  const toggleGroup = useCallback((key: string) => {
    setCollapsedGroups((prev) => {
      const next = new Set(prev)
      next.has(key) ? next.delete(key) : next.add(key)
      return next
    })
  }, [])

  // Build grouped structure preserving original field indices
  type GroupItem = { fieldId: string; idx: number }
  type SubGroup  = { subKey: string; subLabel: string; subOrder: number; items: GroupItem[] }
  type CatGroup  = { catKey: string; catLabel: string; catOrder: number; subMap: Map<string, SubGroup> }

  const ungrouped: GroupItem[] = []
  const catMap = new Map<string, CatGroup>()
  fields.forEach((field, idx) => {
    const sel = rowSelections[idx] ?? { categoryId: '', subcategoryId: '' }
    // New rows (_isNew flag) stay ungrouped until user clicks "Atur Grup Item"
    // This prevents the form from disappearing into a collapsed group mid-edit
    const isNew = detailSnapshots[idx]?._isNew === true
    if (isNew || !sel.categoryId) {
      ungrouped.push({ fieldId: field.id, idx })
      return
    }
    const cat    = categories?.find((c) => c.id === sel.categoryId)
    const sub    = subcategories?.find((s) => s.id === sel.subcategoryId)
    const catKey = sel.categoryId
    const subKey = sel.subcategoryId || '__no_sub'
    if (!catMap.has(catKey)) {
      catMap.set(catKey, {
        catKey,
        catLabel: cat ? `${cat.code} — ${cat.name}` : 'Kategori Tidak Dikenal',
        catOrder: cat?.display_order ?? 999,
        subMap:   new Map(),
      })
    }
    const cg = catMap.get(catKey)!
    if (!cg.subMap.has(subKey)) {
      cg.subMap.set(subKey, {
        subKey,
        subLabel: sub ? `${sub.code} — ${sub.name}` : 'Tanpa Sub-Kategori',
        subOrder: sub?.display_order ?? 999,
        items:    [],
      })
    }
    cg.subMap.get(subKey)!.items.push({ fieldId: field.id, idx })
  })
  const sortedCats = [...catMap.values()].sort((a, b) => a.catOrder - b.catOrder)

  const renderDetailRow = (fieldId: string, idx: number) => {
    const sel                = rowSelections[idx] ?? { categoryId: '', subcategoryId: '' }
    const filteredSubs       = subcategories?.filter((s) => s.category_id === sel.categoryId) ?? []
    const filteredItems      = items?.filter((i) => i.subcategory_id === sel.subcategoryId) ?? []
    const itemId             = detailValues?.[idx]?.expense_item_id ?? ''
    const item               = items?.find((entry) => entry.id === itemId)
    const snapshot           = detailSnapshots[idx]
    const isAutoExternal     = snapshot?.is_auto_external_active ?? item?.mode_input === 'auto_external'
    const isExternalReadOnly = snapshot?.is_external_read_only   ?? item?.mode_input === 'auto_external'
    const pullError          = pullErrors[idx]
    const isPulling          = pullingDetailId === snapshot?.id && pullExternalCost.isPending

    return (
      <div key={fieldId} data-detail-row className="border border-line rounded-card p-4 relative">
        <button
          type="button"
          className="absolute top-3 right-3 text-muted hover:text-red"
          onClick={() => handleRemove(idx)}
        >
          <Trash2 className="w-4 h-4" />
        </button>

        {/* Cascading 3-level dropdown */}
        <div className="grid grid-cols-1 desk:grid-cols-3 gap-3 mb-3">
          <div>
            <label className="label">Kategori</label>
            <select
              className="input-base"
              value={sel.categoryId}
              onChange={(e) => handleCategoryChange(idx, e.target.value)}
            >
              <option value="">Pilih kategori...</option>
              {categories?.map((c) => (
                <option key={c.id} value={c.id}>{c.code} — {c.name}</option>
              ))}
            </select>
          </div>
          <div>
            <label className="label">Sub-Kategori</label>
            <select
              className="input-base"
              value={sel.subcategoryId}
              disabled={!sel.categoryId}
              onChange={(e) => handleSubcategoryChange(idx, e.target.value)}
            >
              <option value="">
                {sel.categoryId ? 'Pilih sub-kategori...' : '— Pilih kategori dulu —'}
              </option>
              {filteredSubs.map((s) => (
                <option key={s.id} value={s.id}>{s.code} — {s.name}</option>
              ))}
            </select>
          </div>
          <div>
            <label className="label">Item Biaya</label>
            <select
              {...register(`details.${idx}.expense_item_id`)}
              className="input-base"
              disabled={!sel.subcategoryId}
              onChange={(e) => {
                register(`details.${idx}.expense_item_id`).onChange(e)
                handleItemChange(idx, e.target.value)
              }}
            >
              <option value="">
                {sel.subcategoryId ? 'Pilih item...' : '— Pilih sub-kategori dulu —'}
              </option>
              {filteredItems.map((fi) => (
                <option key={fi.id} value={fi.id}>{fi.code} — {fi.name}</option>
              ))}
            </select>
            {errors.details?.[idx]?.expense_item_id && (
              <p className="field-error">{errors.details[idx]?.expense_item_id?.message}</p>
            )}
          </div>
        </div>

        <div className="grid grid-cols-1 desk:grid-cols-2 gap-3 mb-3">
          <div>
            <label className="label">Deskripsi</label>
            <input {...register(`details.${idx}.description`)} className="input-base" />
            {errors.details?.[idx]?.description && (
              <p className="field-error">{errors.details[idx]?.description?.message}</p>
            )}
          </div>
          <div>
            <label className="label">Catatan Item</label>
            <input {...register(`details.${idx}.notes`)} className="input-base" />
          </div>
        </div>

        <div className="grid grid-cols-2 desk:grid-cols-4 gap-3">
          <div>
            <label className="label">Volume</label>
            <input
              type="number"
              {...register(`details.${idx}.quantity`, { onChange: () => calculateAmount(idx) })}
              className="input-base"
              step="0.01"
              disabled={isExternalReadOnly}
            />
          </div>
          <div>
            <label className="label">Satuan</label>
            <input
              {...register(`details.${idx}.unit`)}
              className="input-base"
              disabled={isExternalReadOnly}
            />
          </div>
          <div>
            <label className="label">Harga Satuan</label>
            <input
              type="number"
              {...register(`details.${idx}.rate`, { onChange: () => calculateAmount(idx) })}
              className="input-base"
              disabled={isExternalReadOnly}
            />
          </div>
          <div>
            <label className="label">Jumlah (Rp)</label>
            <input
              type="number"
              {...register(`details.${idx}.amount`)}
              data-testid={`detail-amount-${idx}`}
              className="input-base font-bold bg-[#f7faf7] cursor-not-allowed"
              readOnly
            />
            {errors.details?.[idx]?.amount && (
              <p className="field-error">{errors.details[idx]?.amount?.message}</p>
            )}
          </div>
        </div>

        {isAutoExternal && (
          <ExternalCostPullPanel
            errorMessage={pullError}
            isPulling={isPulling}
            onPull={() => handlePullExternalCost(idx)}
            snapshot={snapshot}
          />
        )}
      </div>
    )
  }

  return (
    <div className="max-w-4xl">
      <div className="flex items-center gap-3 mb-6">
        <Button variant="secondary" size="sm" onClick={() => navigate('/pdo')}>
          <ArrowLeft className="w-4 h-4" />
        </Button>
        <h2 className="text-[28px] font-[950] text-ink">
          {isEdit ? 'Edit PDO' : 'Buat PDO Baru'}
        </h2>
      </div>

      <form onSubmit={handleSubmit(
        (d) => save.mutate(d),
        () => toast('Harap lengkapi semua field yang wajib diisi pada item biaya', 'error')
      )}>
        {/* Header */}
        <div className="card mb-4">
          <h3 className="text-[17px] font-[850] mb-4">Informasi Umum</h3>
          <div className="grid grid-cols-1 desk:grid-cols-3 gap-4">
            <div>
              <label className="label">Unit Kebun</label>
              <select {...register('plantation_unit_id')} className="input-base">
                <option value="">Pilih unit...</option>
                {units?.map((u) => (
                  <option key={u.id} value={u.id}>{u.code} — {u.name}</option>
                ))}
              </select>
              {errors.plantation_unit_id && (
                <p className="field-error">{errors.plantation_unit_id.message}</p>
              )}
            </div>
            <div>
              <label className="label">Bulan</label>
              <select {...register('period_month')} className="input-base">
                {MONTHS.map((m, i) => <option key={i + 1} value={i + 1}>{m}</option>)}
              </select>
            </div>
            <div>
              <label className="label">Tahun</label>
              <select {...register('period_year')} className="input-base">
                {YEARS.map((y) => <option key={y} value={y}>{y}</option>)}
              </select>
            </div>
          </div>
          <div className="mt-4">
            <label className="label">Catatan (opsional)</label>
            <textarea {...register('notes')} className="input-base resize-none" rows={2} />
          </div>
        </div>

        {!isEdit && (
          <div className="card mb-4 bg-[#f7faf7] border border-dashed border-[#b8d4b8]">
            <p className="text-sm text-muted text-center py-3">
              Item biaya akan disisipkan otomatis dari daftar item rutin setelah PDO disimpan.
            </p>
          </div>
        )}

        {isEdit && (
          <div className="card mb-4">
            {/* Card header */}
            <div className="flex items-center justify-between mb-4">
              <h3 className="text-[17px] font-[850]">Rencana Biaya</h3>
              <div className="flex gap-2">
                {fields.length > 0 && (
                  <Button type="button" size="sm" variant="secondary" onClick={handleRegroup}>
                    <LayoutList className="w-4 h-4" /> Atur Grup Item
                  </Button>
                )}
                <Button type="button" size="sm" variant="secondary" onClick={handleAppend}>
                  <Plus className="w-4 h-4" /> Tambah Item
                </Button>
              </div>
            </div>

            {fields.length === 0 ? (
              <p className="text-muted text-sm text-center py-6">
                Klik "Tambah Item" untuk memulai input rencana biaya.
              </p>
            ) : (
              <div className="flex flex-col gap-2">
                {/* Ungrouped rows (newly added, no category selected yet) */}
                {ungrouped.length > 0 && (
                  <div className="flex flex-col gap-3">
                    {ungrouped.map(({ fieldId, idx }) => renderDetailRow(fieldId, idx))}
                  </div>
                )}

                {/* Grouped rows: collapsible by category → sub-kategori */}
                {sortedCats.map((cg) => {
                  const catCollapsed = collapsedGroups.has(`cat_${cg.catKey}`)
                  const sortedSubs   = [...cg.subMap.values()].sort((a, b) => a.subOrder - b.subOrder)
                  const catTotal     = sortedSubs.reduce(
                    (s, sg) => s + sg.items.reduce(
                      (ss, { idx }) => ss + (Number(detailValues?.[idx]?.amount) || 0),
                      0
                    ),
                    0
                  )

                  return (
                    <div key={`cat_${cg.catKey}`} className="border border-line rounded-card overflow-hidden">
                      {/* Category toggle header */}
                      <div
                        className="flex items-center gap-2 px-3 py-2.5 bg-[#f0f4f0] cursor-pointer select-none"
                        onClick={() => toggleGroup(`cat_${cg.catKey}`)}
                      >
                        <span
                          className="text-muted text-[10px] transition-transform duration-150"
                          style={{ display: 'inline-block', transform: catCollapsed ? 'rotate(0deg)' : 'rotate(90deg)' }}
                        >▶</span>
                        <span className="text-sm font-[850] text-ink">{cg.catLabel}</span>
                        <span className="ml-auto text-xs font-[700] text-muted">{fmt(catTotal)}</span>
                      </div>

                      {!catCollapsed && sortedSubs.map((sg) => {
                        const subCollapsed = collapsedGroups.has(`sub_${cg.catKey}_${sg.subKey}`)
                        const subTotal     = sg.items.reduce(
                          (s, { idx }) => s + (Number(detailValues?.[idx]?.amount) || 0),
                          0
                        )

                        return (
                          <div key={`sub_${sg.subKey}`}>
                            {/* Subcategory toggle header */}
                            <div
                              className="flex items-center gap-2 pl-7 pr-3 py-2 bg-[#f7faf7] border-t border-line cursor-pointer select-none"
                              onClick={() => toggleGroup(`sub_${cg.catKey}_${sg.subKey}`)}
                            >
                              <span
                                className="text-muted transition-transform duration-150"
                                style={{
                                  display: 'inline-block',
                                  fontSize: 9,
                                  transform: subCollapsed ? 'rotate(0deg)' : 'rotate(90deg)',
                                }}
                              >▶</span>
                              <span className="text-[11px] font-[850] uppercase tracking-wider text-muted">
                                {sg.subLabel}
                              </span>
                              <span className="ml-auto text-xs font-[700] text-muted">{fmt(subTotal)}</span>
                            </div>

                            {!subCollapsed && (
                              <div className="flex flex-col gap-3 p-3 border-t border-line bg-white">
                                {sg.items.map(({ fieldId, idx }) => renderDetailRow(fieldId, idx))}
                              </div>
                            )}
                          </div>
                        )
                      })}
                    </div>
                  )
                })}
              </div>
            )}

            {errors.details && typeof errors.details.message === 'string' && (
              <p className="field-error mt-2">{errors.details.message}</p>
            )}

            {fields.length > 0 && (
              <div className="flex items-center justify-end gap-2 mt-4 pt-4 border-t border-line">
                <span className="text-sm text-muted">Total Pengajuan:</span>
                <span className="text-[20px] font-[950] text-green">{fmt(totalAmount)}</span>
              </div>
            )}
          </div>
        )}

        {/* Actions */}
        <div className="flex gap-2">
          <Button type="submit" loading={save.isPending}>Simpan Draft</Button>
          {isEdit && (
            <Button
              type="button"
              variant="secondary"
              loading={submit.isPending}
              onClick={handleSubmit(
                (d) => submit.mutate(d),
                () => toast('Harap lengkapi semua field yang wajib diisi pada item biaya', 'error')
              )}
            >
              Simpan & Ajukan
            </Button>
          )}
          <Button type="button" variant="secondary" onClick={() => navigate('/pdo')}>Batal</Button>
        </div>
      </form>
    </div>
  )
}
