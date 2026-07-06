import { useEffect, useState } from 'react'
import { render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ItemFormPage } from './ItemFormPage'
import { api } from '@/lib/api'

type PayrollOption = { component_key: string; label: string }
type SelectOption = { value: string; label: string }

vi.mock('react-select/async', () => ({
  default: function AsyncSelectMock({
    'aria-label': ariaLabel,
    defaultOptions,
    isMulti,
    loadOptions,
    onChange,
    value,
    placeholder,
  }: {
    'aria-label'?: string
    defaultOptions?: boolean | SelectOption[]
    isMulti?: boolean
    loadOptions?: (inputValue: string) => Promise<SelectOption[]>
    onChange?: (value: SelectOption[] | SelectOption | null) => void
    value?: SelectOption[] | SelectOption | null
    placeholder?: string
  }) {
    const [options, setOptions] = useState<SelectOption[]>(Array.isArray(defaultOptions) ? defaultOptions : [])

    useEffect(() => {
      if (defaultOptions === true && loadOptions) {
        loadOptions('').then(setOptions)
      }
    }, [defaultOptions, loadOptions])

    const currentValue = Array.isArray(value) ? value : (value ? [value] : [])

    return (
      <div>
        <input
          aria-label={ariaLabel ?? placeholder ?? 'async-select'}
          onChange={async (event) => {
            const nextOptions = await loadOptions?.(event.target.value)
            setOptions(nextOptions ?? [])
          }}
        />
        <div>
          {options.map((option) => {
            const exists = currentValue.some((selected) => selected.value === option.value)

            return (
            <button
              key={option.value}
              type="button"
              aria-pressed={exists ? 'true' : 'false'}
              onClick={() => {
                if (!onChange) return
                if (!isMulti) {
                  onChange(option)
                  return
                }

                onChange(exists
                  ? currentValue.filter((selected) => selected.value !== option.value)
                  : [...currentValue, option])
              }}
            >
              {option.label}
            </button>
            )
          })}
        </div>
      </div>
    )
  },
}))

const baseItemPayload = {
  id: 'item-1',
  subcategory_id: 'subcat-1',
  code: 'EX-001',
  name: 'Old item',
  default_account_number: null,
  default_unit: null,
  default_rate: null,
  mode_input: 'manual',
  is_routine: true,
  is_active: true,
  notes: null,
  split_transfer: false,
  split_transfer_plantation_unit_ids: null,
  routine_plantation_unit_ids: null,
}

const testSubcategories = [{
  id: 'subcat-1',
  category_id: 'cat-1',
  code: 'SUB-1',
  name: 'Sub Satu',
  is_active: true,
  display_order: 1,
  notes: null,
}]

function renderItemForm(initialEntries: string) {
  const client = new QueryClient({
    defaultOptions: {
      queries: {
        retry: false,
        staleTime: 0,
      },
    },
  })

  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[initialEntries]}>
        <Routes>
          <Route path="/master/item/buat" element={<ItemFormPage />} />
          <Route path="/master/item/:id/edit" element={<ItemFormPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('ItemFormPage Payroll component options', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
  })

  const mockGet = (responses: {
    componentOptions?: Record<string, PayrollOption[]>
    blockOptions?: Record<string, PayrollOption[]>
    expenseItem?: Record<string, unknown> | null
    optionsError?: Record<string, Error>
  } = {}) => vi.spyOn(api, 'get').mockImplementation((url: string, config?: { params?: { component?: string } }) => {
    if (url === '/expense-subcategories') {
      return Promise.resolve({ data: { success: true, data: testSubcategories } })
    }

    if (url === '/plantation-units') {
      return Promise.resolve({ data: { success: true, data: [{
        id: 'unit-1',
        code: 'KP',
        name: 'Kebun Pusat',
        is_active: true,
        payroll_estate_external_id: 'EST-001',
      }, {
        id: 'unit-2',
        code: 'BN',
        name: 'Bukit Nusa',
        is_active: true,
        payroll_estate_external_id: 'EST-002',
      }] } })
    }

    if (url === '/payroll-cost-component-options') {
      const component = config?.params?.component
      const filter = (config?.params as { filter?: string } | undefined)?.filter
      if (responses.optionsError && component && responses.optionsError[component]) {
        return Promise.reject(responses.optionsError[component])
      }

      return Promise.resolve({
        data: {
          success: true,
          data: {
            component,
            options: filter === 'blocks'
              ? responses.blockOptions?.[component ?? ''] ?? []
              : responses.componentOptions?.[component ?? ''] ?? [],
          },
        },
      })
    }

    if (url.startsWith('/expense-items/')) {
      const id = url.split('/').pop() ?? 'item-1'
      const payload = responses.expenseItem ?? { ...baseItemPayload, id }
      return Promise.resolve({ data: { success: true, data: payload } })
    }

    return Promise.resolve({ data: { success: true, data: [] } })
  })

  it('memuat option payroll via react select', async () => {
    const user = userEvent.setup()
    const get = mockGet({
      componentOptions: {
        base_payroll_total: [
          { component_key: 'pemanen', label: 'Pemanen' },
          { component_key: 'bhl', label: 'BHL' },
        ],
      },
    })

    renderItemForm('/master/item/buat')

    await user.selectOptions(screen.getByRole('combobox', { name: /Mode Input/i }), ['auto_external'])
    await user.selectOptions(screen.getByRole('combobox', { name: /Component Payroll/i }), ['base_payroll_total'])

    expect(await screen.findByText('Component Keys')).toBeInTheDocument()
    expect(screen.getByLabelText('Component Keys Payroll')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Pemanen' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'BHL' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Semua' })).toBeInTheDocument()

    expect(get).toHaveBeenCalledWith('/payroll-cost-component-options', expect.objectContaining({
      params: { component: 'base_payroll_total' },
    }))
  })

  it('tidak menampilkan tombol Semua untuk additional wage type', async () => {
    const user = userEvent.setup()
    mockGet({
      componentOptions: {
        additional_wage_type_total: [
          { component_key: 'bonus-1', label: 'Bonus Pemanen' },
        ],
      },
    })

    renderItemForm('/master/item/buat')

    await user.selectOptions(screen.getByRole('combobox', { name: /Mode Input/i }), ['auto_external'])
    await user.selectOptions(screen.getByRole('combobox', { name: /Component Payroll/i }), ['additional_wage_type_total'])

    expect(await screen.findByRole('button', { name: 'Bonus Pemanen' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Semua' })).not.toBeInTheDocument()
  })

  it('menyembunyikan Component Keys jika component tidak punya opsi', async () => {
    const user = userEvent.setup()
    mockGet({
      componentOptions: {
        base_payroll_total: [{ component_key: 'pemanen', label: 'Pemanen' }],
      },
    })

    renderItemForm('/master/item/buat')

    await user.selectOptions(screen.getByRole('combobox', { name: /Mode Input/i }), ['auto_external'])
    await user.selectOptions(screen.getByRole('combobox', { name: /Component Payroll/i }), ['harvest_tbs_total'])

    expect(screen.queryByText('Component Keys')).not.toBeInTheDocument()
  })

  it('men-disable submit saat opsi option component masih loading atau gagal', async () => {
    const user = userEvent.setup()
    let resolveOptions!: (value: unknown) => void

    vi.spyOn(api, 'get').mockImplementation((url: string, config?: { params?: { component?: string } }) => {
      if (url === '/expense-subcategories') {
        return Promise.resolve({ data: { success: true, data: testSubcategories } })
      }

      if (url === '/plantation-units') {
        return Promise.resolve({ data: { success: true, data: [] } })
      }

      if (url === '/payroll-cost-component-options') {
        const component = config?.params?.component
        if (component === 'base_payroll_total') {
          return new Promise((resolve) => { resolveOptions = resolve })
        }

        return Promise.resolve({ data: { success: true, data: { component, options: [] } } })
      }

      return Promise.resolve({ data: { success: true, data: [] } })
    })

    renderItemForm('/master/item/buat')

    await user.selectOptions(screen.getByRole('combobox', { name: /Mode Input/i }), ['auto_external'])
    await user.selectOptions(screen.getByRole('combobox', { name: /Component Payroll/i }), ['base_payroll_total'])

    expect(screen.getByRole('button', { name: /Simpan/i })).toBeDisabled()

    resolveOptions!({
      data: {
        success: true,
        data: {
          component: 'base_payroll_total',
          options: [{ component_key: 'pemanen', label: 'Pemanen' }],
        },
      },
    })

    await waitFor(() => expect(screen.getByRole('button', { name: /Simpan/i })).not.toBeDisabled())
  })

  it('men-disable submit dan menampilkan error jika opsi payroll gagal dimuat', async () => {
    const user = userEvent.setup()
    mockGet({
      optionsError: {
        base_payroll_total: new Error('Payroll unreachable'),
      },
    })

    renderItemForm('/master/item/buat')

    await user.selectOptions(screen.getByRole('combobox', { name: /Mode Input/i }), ['auto_external'])
    await user.selectOptions(screen.getByRole('combobox', { name: /Component Payroll/i }), ['base_payroll_total'])

    const submit = await screen.findByRole('button', { name: /Simpan/i })
    expect(submit).toBeDisabled()
    expect(await screen.findByText(/Gagal memuat opsi Payroll:/i)).toBeInTheDocument()
  })

  it('menghapus key lama yang tidak valid dari opsi Payroll pada edit', async () => {
    mockGet({
      componentOptions: {
        base_payroll_total: [
          { component_key: 'pemanen', label: 'Pemanen' },
          { component_key: 'bhl', label: 'BHL' },
        ],
      },
      expenseItem: {
        ...baseItemPayload,
        id: 'existing-id',
        mode_input: 'auto_external',
        external_source_system: 'payroll',
        external_component: 'base_payroll_total',
        external_component_key: 'invalid-role',
      },
    })

    renderItemForm('/master/item/existing-id/edit')

    await waitFor(() => expect(screen.getByRole('combobox', { name: /Component Payroll/i })).toHaveValue('base_payroll_total'))
    const pemanen = await screen.findByRole('button', { name: 'Pemanen' })
    const bhl = screen.getByRole('button', { name: 'BHL' })
    expect(pemanen).toHaveAttribute('aria-pressed', 'false')
    expect(bhl).toHaveAttribute('aria-pressed', 'false')
  })

  it('mengisi component key dari legacy external role saat edit base payroll lama', async () => {
    mockGet({
      componentOptions: {
        base_payroll_total: [
          { component_key: 'pemanen', label: 'Pemanen' },
          { component_key: 'bhl', label: 'BHL' },
        ],
      },
      expenseItem: {
        ...baseItemPayload,
        id: 'legacy-role-id',
        mode_input: 'auto_external',
        external_source_system: 'payroll',
        external_component: 'base_payroll_total',
        external_component_key: null,
        external_role: 'pemanen',
      },
    })

    renderItemForm('/master/item/legacy-role-id/edit')

    await waitFor(() => expect((screen.getByRole('combobox', { name: /Mode Input/i }) as HTMLSelectElement).value).toBe('auto_external'))
    await waitFor(() => expect(screen.getByRole('combobox', { name: /Component Payroll/i })).toHaveValue('base_payroll_total'))
    await waitFor(() => expect(screen.getByRole('button', { name: 'Pemanen' })).toHaveAttribute('aria-pressed', 'true'))
  })

  it('mengisi component key saat edit jika selector set kosong tapi legacy component key ada', async () => {
    mockGet({
      componentOptions: {
        base_payroll_total: [
          { component_key: 'pemanen', label: 'Pemanen' },
          { component_key: 'bhl', label: 'BHL' },
        ],
      },
      expenseItem: {
        ...baseItemPayload,
        id: 'legacy-component-key-id',
        mode_input: 'auto_external',
        external_source_system: 'payroll',
        external_component: 'base_payroll_total',
        external_component_key: 'pemanen',
        external_component_keys: [],
      },
    })

    renderItemForm('/master/item/legacy-component-key-id/edit')

    await waitFor(() => expect((screen.getByRole('combobox', { name: /Mode Input/i }) as HTMLSelectElement).value).toBe('auto_external'))
    await waitFor(() => expect(screen.getByRole('combobox', { name: /Component Payroll/i })).toHaveValue('base_payroll_total'))
    await waitFor(() => expect(screen.getByRole('button', { name: 'Pemanen' })).toHaveAttribute('aria-pressed', 'true'))
  })

  it('menghapus component key saat component berubah ke non-option', async () => {
    const user = userEvent.setup()
    mockGet({
      componentOptions: {
        base_payroll_total: [
          { component_key: 'pemanen', label: 'Pemanen' },
          { component_key: 'bhl', label: 'BHL' },
        ],
      },
    })

    renderItemForm('/master/item/buat')

    await user.selectOptions(screen.getByRole('combobox', { name: /Mode Input/i }), ['auto_external'])
    await user.selectOptions(screen.getByRole('combobox', { name: /Component Payroll/i }), ['base_payroll_total'])
    await user.click(await screen.findByRole('button', { name: 'Pemanen' }))
    expect(screen.getByRole('button', { name: 'Pemanen' })).toHaveAttribute('aria-pressed', 'true')

    await user.selectOptions(screen.getByRole('combobox', { name: /Component Payroll/i }), ['harvest_tbs_total'])
    expect(screen.queryByText('Component Keys')).not.toBeInTheDocument()

    await user.selectOptions(screen.getByRole('combobox', { name: /Component Payroll/i }), ['base_payroll_total'])
    expect(await screen.findByRole('button', { name: 'Pemanen' })).toHaveAttribute('aria-pressed', 'false')
  })

  it('menampilkan block mapping maintenance independen dari item rutin', async () => {
    const user = userEvent.setup()
    mockGet({
      componentOptions: {
        maintenance_total: [{ component_key: 'PT-001', label: 'Zebra Work' }],
      },
      blockOptions: {
        maintenance_total: [{ component_key: 'BLK-001', label: 'Alpha' }],
      },
    })

    renderItemForm('/master/item/buat')

    await user.selectOptions(screen.getByRole('combobox', { name: /Mode Input/i }), ['auto_external'])
    await user.selectOptions(screen.getByRole('combobox', { name: /Component Payroll/i }), ['maintenance_total'])

    expect(await screen.findByText('Block Mapping Kebun')).toBeInTheDocument()

    await user.type(screen.getByLabelText('Kebun Block Mapping'), 'KP')
    await user.click(screen.getByRole('button', { name: 'KP — Kebun Pusat' }))

    expect(await screen.findByLabelText('Block KP')).toBeInTheDocument()

    await user.type(screen.getByLabelText('Block KP'), 'Alpha')
    expect(await screen.findByRole('button', { name: 'Alpha' })).toBeInTheDocument()
  })
})
