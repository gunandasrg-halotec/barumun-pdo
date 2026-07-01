import { useEffect, useMemo, useRef } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useForm, useWatch } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api, getApiErrorMessage } from '@/lib/api'
import { Button } from '@/components/ui/Button'
import { useToastStore } from '@/store/toast.store'
import { usePayrollComponentOptions, useSubcategories } from '@/hooks/useMasterData'
import { ArrowLeft } from 'lucide-react'
import type { ApiResponse, ExpenseItem, PlantationUnit } from '@/types'

const schema = z.object({
  subcategory_id:               z.string().uuid('Pilih sub-kategori'),
  code:                         z.string().min(1, 'Kode wajib diisi'),
  name:                         z.string().min(1, 'Nama wajib diisi'),
  default_account_number:       z.string().nullable().optional(),
  default_unit:                 z.string().nullable().optional(),
  default_rate:                 z.preprocess(
    (v) => (v === '' || v === null || v === undefined ? null : Number(v)),
    z.number().nullable().optional(),
  ),
  mode_input:                   z.enum(['manual', 'auto_external']),
  external_source_system:       z.enum(['payroll']).nullable().optional(),
  external_component:           z.enum([
    'harvest_tbs_total',
    'relayed_tbs_total',
    'harvest_bonus_total',
    'loose_fruit_total',
    'maintenance_total',
    'base_payroll_total',
    'additional_wages_total',
    'additional_wage_type_total',
  ]).nullable().optional(),
  external_component_key:       z.string().nullable().optional(),
  split_transfer:                      z.boolean(),
  split_transfer_plantation_unit_ids:  z.array(z.string().uuid()).nullable().optional(),
  is_routine:                          z.boolean(),
  routine_plantation_unit_ids:  z.array(z.string().uuid()).nullable().optional(),
  is_active:                    z.boolean(),
  is_deduction:                 z.boolean(),
  notes:                        z.string().nullable().optional(),
}).superRefine((values, ctx) => {
  if (values.mode_input === 'auto_external' && values.external_component === 'additional_wage_type_total' && !values.external_component_key) {
    ctx.addIssue({
      path: ['external_component_key'],
      code: z.ZodIssueCode.custom,
      message: 'external_component_key wajib diisi untuk component additional_wage_type_total.',
    })
  }
})

type Form = z.infer<typeof schema>

const payrollComponents = [
  { value: 'harvest_tbs_total', label: 'Harvest TBS Total' },
  { value: 'relayed_tbs_total', label: 'Relayed TBS Total' },
  { value: 'harvest_bonus_total', label: 'Harvest Bonus Total' },
  { value: 'loose_fruit_total', label: 'Loose Fruit Total' },
  { value: 'maintenance_total', label: 'Maintenance Total' },
  { value: 'base_payroll_total', label: 'Base Payroll Total' },
  { value: 'additional_wages_total', label: 'Additional Wages Total' },
  { value: 'additional_wage_type_total', label: 'Additional Wage Type Total' },
] as const

const payrollComponentOptionsComponents = [
  'base_payroll_total',
  'maintenance_total',
  'additional_wage_type_total',
] as const

type PayrollComponent = typeof payrollComponents[number]['value']
type PayrollComponentWithOptions = typeof payrollComponentOptionsComponents[number]

const payrollComponentsWithOptions = new Set<PayrollComponentWithOptions>(payrollComponentOptionsComponents)

function isPayrollComponent(value: unknown): value is PayrollComponent {
  return payrollComponents.some((component) => component.value === value)
}

export function ItemFormPage() {
  const { id }   = useParams<{ id: string }>()
  const navigate = useNavigate()
  const toast    = useToastStore((s) => s.push)
  const qc       = useQueryClient()
  const isEdit   = !!id

  const { data: subcategories } = useSubcategories({ is_active: true })

  const { data: units } = useQuery({
    queryKey: ['plantation-units'],
    queryFn: async () => {
      const res = await api.get<ApiResponse<PlantationUnit[]>>('/plantation-units')
      return res.data.data
    },
  })

  const { data: existing } = useQuery({
    queryKey: ['item', id],
    queryFn: async () => {
      const res = await api.get<ApiResponse<ExpenseItem>>(`/expense-items/${id}`)
      return res.data.data
    },
    enabled: isEdit,
  })

  const { register, handleSubmit, reset, setError, control, getValues, setValue, formState: { errors } } = useForm<Form>({
    resolver: zodResolver(schema),
    defaultValues: {
      mode_input: 'manual',
      external_source_system: null,
      external_component: null,
      external_component_key: null,
      split_transfer: false,
      split_transfer_plantation_unit_ids: null,
      is_routine: true,
      is_active: true,
      is_deduction: false,
      routine_plantation_unit_ids: null,
    },
  })

  const isRoutine      = useWatch({ control, name: 'is_routine' })
  const isSplit        = useWatch({ control, name: 'split_transfer' })
  const modeInput      = useWatch({ control, name: 'mode_input' })
  const extComponent   = useWatch({ control, name: 'external_component' })
  const routineUnitIds = useWatch({ control, name: 'routine_plantation_unit_ids' })
  const splitUnitIds   = useWatch({ control, name: 'split_transfer_plantation_unit_ids' })
  const isAutoExternal = modeInput === 'auto_external'
  const componentNeedsOptions = extComponent ? payrollComponentsWithOptions.has(extComponent as PayrollComponentWithOptions) : false
  const externalComponentOptionsQuery = usePayrollComponentOptions(extComponent && isAutoExternal ? extComponent : null)
  const componentOptions = externalComponentOptionsQuery.data?.options ?? []
  const optionsLoaded = componentNeedsOptions
    ? componentOptions.length > 0 || externalComponentOptionsQuery.isSuccess
    : true
  const componentAllowsEmptyOption = extComponent === 'base_payroll_total' || extComponent === 'maintenance_total'

  const componentWatchInitialized = useRef(false)
  const previousComponent = useRef<string | null>(null)

  useEffect(() => {
    if (!componentWatchInitialized.current) {
      componentWatchInitialized.current = true
      previousComponent.current = extComponent ?? null
      return
    }

    if (isEdit && previousComponent.current === null) {
      previousComponent.current = extComponent ?? null
      return
    }

    if (previousComponent.current !== extComponent) {
      setValue('external_component_key', null)
    }

    previousComponent.current = extComponent ?? null
  }, [extComponent, isEdit, setValue])

  const availableComponentKeys = useMemo(() => new Set(
    componentOptions.map((option) => option.component_key),
  ), [componentOptions])

  useEffect(() => {
    if (!componentNeedsOptions || !optionsLoaded) return
    const currentKey = getValues('external_component_key')
    if (currentKey && !availableComponentKeys.has(currentKey)) {
      setValue('external_component_key', null)
    }
  }, [componentNeedsOptions, optionsLoaded, availableComponentKeys, getValues, setValue])

  useEffect(() => {
    if (existing) {
      const ext = existing as unknown as {
        external_source_system?: string | null
        external_component?: string | null
        external_component_key?: string | null
        external_role?: string | null
        split_transfer?: boolean
        split_transfer_plantation_unit_ids?: string[] | null
        routine_plantation_unit_ids?: string[] | null
      }
      const legacyRole = ext.external_component === 'base_payroll_total' && !ext.external_component_key ? ext.external_role : null
      const normalizedExternalRole = legacyRole ? legacyRole : null
      reset({
        ...existing,
        notes: existing.notes ?? '',
        external_source_system: ext.external_source_system === 'payroll' ? 'payroll' : null,
        external_component: isPayrollComponent(ext.external_component) ? ext.external_component : null,
        external_component_key: ext.external_component_key ?? normalizedExternalRole ?? null,
        split_transfer:                     ext.split_transfer ?? false,
        split_transfer_plantation_unit_ids: ext.split_transfer_plantation_unit_ids ?? null,
        routine_plantation_unit_ids:        ext.routine_plantation_unit_ids ?? null,
      })

      if (normalizedExternalRole && ext.external_component === 'base_payroll_total' && !ext.external_component_key) {
        setValue('external_component_key', normalizedExternalRole)
      }
    }
  }, [existing, reset, setValue])

  useEffect(() => {
    if (isAutoExternal) {
      setValue('external_source_system', 'payroll')
      return
    }

    setValue('external_source_system', null)
    setValue('external_component', null)
    setValue('external_component_key', null)
  }, [isAutoExternal, setValue])

  const toggleSplitUnit = (id: string) => {
    const current = splitUnitIds ?? []
    const next = current.includes(id)
      ? current.filter((x) => x !== id)
      : [...current, id]
    setValue('split_transfer_plantation_unit_ids', next.length > 0 ? next : null)
  }

  const toggleRoutineUnit = (id: string) => {
    const current = routineUnitIds ?? []
    const next = current.includes(id)
      ? current.filter((x) => x !== id)
      : [...current, id]
    setValue('routine_plantation_unit_ids', next.length > 0 ? next : null)
  }

  const save = useMutation({
    mutationFn: (data: Form) => {
      const payload: Form = data.mode_input === 'manual'
        ? {
          ...data,
          external_source_system: undefined,
          external_component: undefined,
          external_component_key: undefined,
        }
        : {
          ...data,
          external_source_system: data.external_source_system ?? 'payroll',
          external_component_key: payrollComponentsWithOptions.has(data.external_component as PayrollComponentWithOptions)
            ? data.external_component_key ?? null
            : null,
        }

      return isEdit
        ? api.put(`/expense-items/${id}`, payload)
        : api.post('/expense-items', payload)
    },
    onSuccess: () => {
      toast(isEdit ? 'Item berhasil diperbarui' : 'Item berhasil dibuat')
      qc.invalidateQueries({ queryKey: ['items'] })
      navigate('/master')
    },
    onError: (err: unknown) => {
      type ApiErr = { response?: { data?: { error?: { details?: { field: string; message: string }[] } } } }
      const details = (err as ApiErr)?.response?.data?.error?.details
      if (details?.length) {
        const validFields = new Set<string>([
          'code',
          'name',
          'subcategory_id',
          'default_account_number',
          'default_unit',
          'default_rate',
          'mode_input',
          'external_source_system',
          'external_component',
          'external_component_key',
          'split_transfer',
          'is_routine',
          'is_active',
          'notes',
        ])
        let handled = false
        for (const { field, message } of details) {
          if (validFields.has(field)) {
            setError(field as keyof Form, { message })
            handled = true
          }
        }
        if (handled) return
      }
      toast('Gagal menyimpan item', 'error')
    },
  })

  const isSubmitDisabled = save.isPending || (componentNeedsOptions && (
    externalComponentOptionsQuery.isLoading
    || externalComponentOptionsQuery.isError
    || !optionsLoaded
  ))
  const componentOptionsErrorMessage = componentNeedsOptions && externalComponentOptionsQuery.isError
    ? `Gagal memuat opsi Payroll: ${getApiErrorMessage(externalComponentOptionsQuery.error)}`
    : ''

  return (
    <div className="form-container-narrow">
      <div className="flex items-center gap-3 mb-6">
        <Button variant="secondary" size="sm" onClick={() => navigate('/master')}>
          <ArrowLeft className="w-4 h-4" />
        </Button>
        <h2 className="text-[28px] font-[950] text-ink">
          {isEdit ? 'Edit Item Biaya' : 'Tambah Item Biaya'}
        </h2>
      </div>

      <form onSubmit={handleSubmit((d) => save.mutate(d))} className="card flex flex-col gap-4">
        <div>
          <label className="label" htmlFor="item-subcategory">Sub-Kategori Induk</label>
          <select id="item-subcategory" {...register('subcategory_id')} className="input-base">
            <option value="">Pilih sub-kategori...</option>
            {subcategories?.map((s) => (
              <option key={s.id} value={s.id}>{s.code} — {s.name}</option>
            ))}
          </select>
          {errors.subcategory_id && <p className="field-error">{errors.subcategory_id.message}</p>}
        </div>

        <div className="grid grid-cols-1 desk:grid-cols-2 gap-4">
          <div>
            <label className="label">Kode</label>
            <input {...register('code')} className="input-base" placeholder="A1.001" />
            {errors.code && <p className="field-error">{errors.code.message}</p>}
          </div>
          <div>
            <label className="label">No. Akun (opsional)</label>
            <input {...register('default_account_number')} className="input-base" placeholder="6-1001" />
          </div>
        </div>

        <div>
          <label className="label">Nama Item</label>
          <input {...register('name')} className="input-base" placeholder="Pupuk Urea" />
          {errors.name && <p className="field-error">{errors.name.message}</p>}
        </div>

        <div className="grid grid-cols-1 desk:grid-cols-2 gap-4">
          <div>
            <label className="label">Satuan (opsional)</label>
            <input {...register('default_unit')} className="input-base" placeholder="kg" />
          </div>
          <div>
            <label className="label">Tarif (opsional)</label>
            <input type="number" {...register('default_rate')} className="input-base" placeholder="0" />
          </div>
        </div>

        <div>
          <label className="label" htmlFor="item-mode-input">Mode Input</label>
          <select id="item-mode-input" aria-label="Mode Input" {...register('mode_input')} className="input-base">
            <option value="manual">Manual</option>
            <option value="auto_external">Auto External</option>
          </select>
          {errors.mode_input && <p className="field-error">{errors.mode_input.message}</p>}
        </div>

        {isAutoExternal && (
          <div className="border border-blue-200 rounded-card p-4 bg-blue-50 space-y-4">
            <div>
              <label className="label" htmlFor="item-external-source">Sumber External</label>
              <select id="item-external-source" aria-label="Sumber External" {...register('external_source_system')} className="input-base">
                <option value="payroll">Payroll</option>
              </select>
              {errors.external_source_system && <p className="field-error">{errors.external_source_system.message}</p>}
            </div>

            <div>
              <label className="label" htmlFor="item-external-component">Component Payroll</label>
              <select id="item-external-component" aria-label="Component Payroll" {...register('external_component')} className="input-base">
                <option value="">Pilih component...</option>
                {payrollComponents.map((component) => (
                  <option key={component.value} value={component.value}>{component.label}</option>
                ))}
              </select>
              {errors.external_component && <p className="field-error">{errors.external_component.message}</p>}
            </div>

            {componentNeedsOptions && (
              <div>
                <label className="label" htmlFor="item-external-component-key">Component Key</label>
                <select
                  id="item-external-component-key"
                  aria-label="Component Key"
                  {...register('external_component_key')}
                  className="input-base"
                  disabled={componentNeedsOptions && externalComponentOptionsQuery.isLoading}
                >
                  {componentAllowsEmptyOption && <option value="">Semua</option>}
                  {!optionsLoaded ? (
                    <option value="" disabled>Memuat opsi payroll...</option>
                  ) : (
                    componentOptions.map((option) => (
                      <option key={option.component_key} value={option.component_key}>
                        {option.label}
                      </option>
                    ))
                  )}
                </select>
                {componentNeedsOptions && componentOptionsErrorMessage && (
                  <p className="field-error">{componentOptionsErrorMessage}</p>
                )}
                {errors.external_component_key && <p className="field-error">{errors.external_component_key.message}</p>}
              </div>
            )}
          </div>
        )}

        <div>
          <label className="label">Catatan</label>
          <textarea {...register('notes')} className="input-base resize-none" rows={3} />
        </div>

        {/* Checkboxes */}
        <div className="flex flex-wrap gap-6">
          <label className="flex items-center gap-2 text-sm font-[700] cursor-pointer">
            <input type="checkbox" {...register('is_routine')} className="checkbox" />
            Item Rutin
          </label>
          <label className="flex items-center gap-2 text-sm font-[700] cursor-pointer">
            <input type="checkbox" {...register('split_transfer')} className="checkbox" />
            Split Transfer
            <span className="text-xs text-muted font-normal">(transfer ke 2 tujuan berbeda)</span>
          </label>
          <label className="flex items-center gap-2 text-sm font-[700] cursor-pointer">
            <input type="checkbox" {...register('is_active')} className="checkbox" />
            Aktif
          </label>
          <label className="flex items-center gap-2 text-sm font-[700] cursor-pointer">
            <input type="checkbox" {...register('is_deduction')} className="checkbox" />
            Potongan
            <span className="text-xs text-muted font-normal">(mengurangi total PDO)</span>
          </label>
        </div>

        {/* Split transfer scope — hanya muncul jika split_transfer = true */}
        {isSplit && units && units.length > 1 && (
          <div className="border border-line rounded-card p-4 bg-[#f0f7ff]">
            <p className="text-sm font-[850] mb-2">Split Transfer Berlaku Untuk:</p>
            <label className="flex items-center gap-2 text-sm cursor-pointer mb-2">
              <input
                type="checkbox"
                checked={!splitUnitIds || splitUnitIds.length === 0}
                onChange={() => setValue('split_transfer_plantation_unit_ids', null)}
                className="checkbox"
              />
              <span className="font-bold">Semua Kebun</span>
            </label>
            <div className="border-t border-line my-2" />
            <div className="flex flex-col gap-1.5">
              {units.map((unit) => (
                <label key={unit.id} className="flex items-center gap-2 text-sm cursor-pointer">
                  <input
                    type="checkbox"
                    checked={(splitUnitIds ?? []).includes(unit.id)}
                    onChange={() => toggleSplitUnit(unit.id)}
                    className="checkbox"
                  />
                  <span>{unit.code} — {unit.name}</span>
                </label>
              ))}
            </div>
            {splitUnitIds && splitUnitIds.length > 0 && (
              <p className="text-xs text-muted mt-2">
                Mode split hanya akan tampil di halaman transfer untuk {splitUnitIds.length} kebun yang dipilih.
              </p>
            )}
          </div>
        )}

        {/* Routine scope — hanya muncul jika is_routine = true */}
        {isRoutine && units && units.length > 1 && (
          <div className="border border-line rounded-card p-4 bg-[#f7faf7]">
            <p className="text-sm font-[850] mb-2">Item Rutin Berlaku Untuk:</p>
            <label className="flex items-center gap-2 text-sm cursor-pointer mb-2">
              <input
                type="checkbox"
                checked={!routineUnitIds || routineUnitIds.length === 0}
                onChange={() => setValue('routine_plantation_unit_ids', null)}
                className="checkbox"
              />
              <span className="font-bold">Semua Kebun</span>
            </label>
            <div className="border-t border-line my-2" />
            <div className="flex flex-col gap-1.5">
              {units.map((unit) => (
                <label key={unit.id} className="flex items-center gap-2 text-sm cursor-pointer">
                  <input
                    type="checkbox"
                    checked={(routineUnitIds ?? []).includes(unit.id)}
                    onChange={() => toggleRoutineUnit(unit.id)}
                    className="checkbox"
                  />
                  <span>{unit.code} — {unit.name}</span>
                </label>
              ))}
            </div>
            {routineUnitIds && routineUnitIds.length > 0 && (
              <p className="text-xs text-muted mt-2">
                Item ini hanya akan auto-muncul di PDO untuk {routineUnitIds.length} kebun yang dipilih.
              </p>
            )}
          </div>
        )}

        <div className="flex gap-2 pt-2">
          <Button type="submit" loading={save.isPending} disabled={isSubmitDisabled}>Simpan</Button>
          <Button type="button" variant="secondary" onClick={() => navigate('/master')}>Batal</Button>
        </div>
      </form>
    </div>
  )
}
