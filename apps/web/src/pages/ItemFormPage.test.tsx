import { useEffect, useState } from 'react'
import { render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ItemFormPage } from './ItemFormPage'
import { MasterDataPage } from './MasterDataPage'
import { api } from '@/lib/api'
import { useAuthStore } from '@/store/auth.store'

type PayrollOption = { component_key: string; label: string }
type SelectOption = { value: string; label: string }

vi.mock('react-select/async', () => ({
  default: function AsyncSelectMock({
    'aria-label': ariaLabel,
    defaultOptions,
    isClearable,
    isMulti,
    loadOptions,
    onChange,
    value,
    placeholder,
  }: {
    'aria-label'?: string
    defaultOptions?: boolean | SelectOption[]
    isClearable?: boolean
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
        {isClearable && currentValue.length > 0 && (
          <button type="button" aria-label={`Clear ${ariaLabel ?? placeholder ?? 'async-select'}`} onClick={() => onChange?.(null)}>
            ×
          </button>
        )}
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
    useAuthStore.setState({
      user: {
        id: 'admin-1',
        full_name: 'Admin',
        email: 'admin@example.test',
        whatsapp_number: '',
        is_active: true,
        role: { id: 'role-1', code: 'ADMIN', name: 'Admin' },
        plantation_unit: null,
        company_id: 'company-1',
      },
      token: 'token',
      expiresAt: Date.now() + 60_000,
    })
  })

  const mockGet = (responses: {
    componentOptions?: Record<string, PayrollOption[]>
    roleOptions?: Record<string, PayrollOption[]>
    blockOptions?: Record<string, PayrollOption[]>
    expenseItem?: Record<string, unknown> | null
    expenseItems?: Record<string, unknown>[]
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
      if (filter !== 'roles' && responses.optionsError && component && responses.optionsError[component]) {
        return Promise.reject(responses.optionsError[component])
      }

      return Promise.resolve({
        data: {
          success: true,
          data: {
            component,
            options: filter === 'blocks'
              ? responses.blockOptions?.[component ?? ''] ?? []
              : (filter === 'roles'
                ? responses.roleOptions?.[component ?? ''] ?? []
                : responses.componentOptions?.[component ?? ''] ?? []),
          },
        },
      })
    }

    if (url === '/expense-items') {
      return Promise.resolve({ data: { success: true, data: responses.expenseItems ?? [] } })
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
        const filter = (config?.params as { filter?: string } | undefined)?.filter
        if (filter === 'roles') {
          return Promise.resolve({ data: { success: true, data: { component, options: [] } } })
        }
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

  it('tetap bisa submit saat opsi role payroll gagal dan role kosong', async () => {
    const user = userEvent.setup()
    const post = vi.spyOn(api, 'post').mockResolvedValue({ data: { success: true, data: {} } })
    const subcategoryId = '11111111-1111-4111-8111-111111111111'

    vi.spyOn(api, 'get').mockImplementation((url: string, config?: { params?: { component?: string; filter?: string } }) => {
      if (url === '/expense-subcategories') {
        return Promise.resolve({
          data: {
            success: true,
            data: testSubcategories.map((subcategory) => ({ ...subcategory, id: subcategoryId })),
          },
        })
      }

      if (url === '/plantation-units') {
        return Promise.resolve({ data: { success: true, data: [] } })
      }

      if (url === '/payroll-cost-component-options') {
        const component = config?.params?.component
        const filter = config?.params?.filter

        if (filter === 'roles') {
          return Promise.reject(new Error('Payroll roles unreachable'))
        }

        return Promise.resolve({
          data: {
            success: true,
            data: {
              component,
              options: [
                { component_key: 'pemanen', label: 'Pemanen' },
              ],
            },
          },
        })
      }

      return Promise.resolve({ data: { success: true, data: [] } })
    })

    renderItemForm('/master/item/buat')

    await screen.findByRole('option', { name: /SUB-1/i })
    await user.selectOptions(screen.getByRole('combobox', { name: /Sub-Kategori Induk/i }), [subcategoryId])
    await user.type(screen.getByPlaceholderText('A1.001'), 'EXT-ROLE-EMPTY')
    await user.type(screen.getByPlaceholderText('Pupuk Urea'), 'Gaji semua role')
    await user.selectOptions(screen.getByRole('combobox', { name: /Mode Input/i }), ['auto_external'])
    await user.selectOptions(screen.getByRole('combobox', { name: /Component Payroll/i }), ['base_payroll_total'])

    const submit = await screen.findByRole('button', { name: /Simpan/i })
    await waitFor(() => expect(submit).not.toBeDisabled())
    expect(await screen.findByText(/Gagal memuat role Payroll:/i)).toBeInTheDocument()

    await user.click(submit)

    await waitFor(() => expect(post).toHaveBeenCalledWith('/expense-items', expect.objectContaining({
      external_component: 'base_payroll_total',
      external_role: null,
    })))
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

  it('mengisi role payroll dari external role saat edit', async () => {
    mockGet({
      roleOptions: {
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

    await userEvent.click(screen.getByRole('button', { name: 'Clear Role Payroll' }))
    await waitFor(() => expect(screen.getByRole('button', { name: 'Pemanen' })).toHaveAttribute('aria-pressed', 'false'))
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

  it('mengisi default item biaya saat klik edit dari master data tanpa refresh', async () => {
    const user = userEvent.setup()
    const expenseItem = {
      ...baseItemPayload,
      id: 'clicked-item-id',
      subcategory_id: 'subcat-1',
      code: 'DEF-001',
      name: 'Default dari master',
      default_account_number: '6-1001',
      default_unit: 'kg',
      default_rate: 12500,
      mode_input: 'auto_external',
      external_source_system: 'payroll',
      external_component: 'base_payroll_total',
      external_component_keys: ['pemanen'],
      external_role: 'bhl',
      is_routine: false,
      is_active: true,
      is_deduction: true,
    }

    mockGet({
      componentOptions: {
        base_payroll_total: [
          { component_key: 'pemanen', label: 'Pemanen' },
        ],
      },
      roleOptions: {
        base_payroll_total: [
          { component_key: 'bhl', label: 'BHL' },
        ],
      },
      expenseItem,
      expenseItems: [expenseItem],
    })

    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <MemoryRouter initialEntries={['/master']}>
          <Routes>
            <Route path="/master" element={<MasterDataPage />} />
            <Route path="/master/item/:id/edit" element={<ItemFormPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    )

    await user.click(await screen.findByRole('button', { name: 'Item Biaya' }))
    await user.click(await screen.findByRole('button', { name: 'Edit' }))

    await waitFor(() => expect(screen.getByPlaceholderText('A1.001')).toHaveValue('DEF-001'))
    expect(screen.getByPlaceholderText('Pupuk Urea')).toHaveValue('Default dari master')
    expect(screen.getByPlaceholderText('6-1001')).toHaveValue('6-1001')
    expect(screen.getByPlaceholderText('kg')).toHaveValue('kg')
    expect(screen.getByPlaceholderText('0')).toHaveValue(12500)
    expect(screen.getByRole('combobox', { name: /Mode Input/i })).toHaveValue('auto_external')
    expect(screen.getByRole('combobox', { name: /Component Payroll/i })).toHaveValue('base_payroll_total')
    await waitFor(() => expect(screen.getByRole('button', { name: 'Pemanen' })).toHaveAttribute('aria-pressed', 'true'))
    await waitFor(() => expect(screen.getByRole('button', { name: 'BHL' })).toHaveAttribute('aria-pressed', 'true'))
  })

  it('mengisi default item biaya saat pindah dari tambah ke edit tanpa refresh', async () => {
    const user = userEvent.setup()
    const expenseItem = {
      ...baseItemPayload,
      id: 'route-reuse-item-id',
      subcategory_id: 'subcat-1',
      code: 'EDIT-001',
      name: 'Edit route reuse',
      default_account_number: '6-2002',
      default_unit: 'ltr',
      default_rate: 7700,
      is_routine: false,
      is_active: false,
      is_deduction: true,
    }

    mockGet({
      expenseItem,
      expenseItems: [expenseItem],
    })

    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <MemoryRouter initialEntries={['/master/item/buat']}>
          <Routes>
            <Route path="/master/item/buat" element={<ItemFormPage />} />
            <Route path="/master" element={<MasterDataPage />} />
            <Route path="/master/item/:id/edit" element={<ItemFormPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    )

    await user.type(screen.getByPlaceholderText('A1.001'), 'DRAFT')
    await user.click(screen.getByRole('button', { name: 'Batal' }))
    await user.click(await screen.findByRole('button', { name: 'Item Biaya' }))
    await user.click(await screen.findByRole('button', { name: 'Edit' }))

    await waitFor(() => expect(screen.getByPlaceholderText('A1.001')).toHaveValue('EDIT-001'))
    expect(screen.getByPlaceholderText('Pupuk Urea')).toHaveValue('Edit route reuse')
    expect(screen.getByPlaceholderText('6-1001')).toHaveValue('6-2002')
    expect(screen.getByPlaceholderText('kg')).toHaveValue('ltr')
    expect(screen.getByPlaceholderText('0')).toHaveValue(7700)
  })

  it('refetch detail item biaya saat cache edit masih stale dari klik sebelumnya', async () => {
    const freshItem = {
      ...baseItemPayload,
      id: 'stale-cache-item-id',
      subcategory_id: 'subcat-1',
      code: 'FRESH-001',
      name: 'Fresh detail',
      default_account_number: '6-3003',
      default_unit: 'ha',
      default_rate: 9900,
    }
    const staleItem = {
      ...freshItem,
      code: '',
      name: '',
      default_account_number: null,
      default_unit: null,
      default_rate: null,
    }
    const get = mockGet({ expenseItem: freshItem })
    const client = new QueryClient({
      defaultOptions: {
        queries: {
          retry: false,
          staleTime: 30_000,
        },
      },
    })
    client.setQueryData(['item', 'stale-cache-item-id'], staleItem)

    render(
      <QueryClientProvider client={client}>
        <MemoryRouter initialEntries={['/master/item/stale-cache-item-id/edit']}>
          <Routes>
            <Route path="/master/item/:id/edit" element={<ItemFormPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    )

    await waitFor(() => expect(get).toHaveBeenCalledWith('/expense-items/stale-cache-item-id'))
    await waitFor(() => expect(screen.getByPlaceholderText('A1.001')).toHaveValue('FRESH-001'))
    expect(screen.getByPlaceholderText('Pupuk Urea')).toHaveValue('Fresh detail')
    expect(screen.getByPlaceholderText('6-1001')).toHaveValue('6-3003')
    expect(screen.getByPlaceholderText('kg')).toHaveValue('ha')
    expect(screen.getByPlaceholderText('0')).toHaveValue(9900)
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
