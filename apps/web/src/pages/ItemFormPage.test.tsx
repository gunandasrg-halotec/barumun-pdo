import { render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ItemFormPage } from './ItemFormPage'
import { api } from '@/lib/api'

type PayrollOption = { component_key: string; label: string }

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
    expenseItem?: Record<string, unknown> | null
    optionsError?: Record<string, Error>
  } = {}) => vi.spyOn(api, 'get').mockImplementation((url: string, config?: { params?: { component?: string } }) => {
    if (url === '/expense-subcategories') {
      return Promise.resolve({ data: { success: true, data: testSubcategories } })
    }

    if (url === '/plantation-units') {
      return Promise.resolve({ data: { success: true, data: [] } })
    }

    if (url === '/payroll-cost-component-options') {
      const component = config?.params?.component
      if (responses.optionsError && component && responses.optionsError[component]) {
        return Promise.reject(responses.optionsError[component])
      }

      return Promise.resolve({
        data: {
          success: true,
          data: {
            component,
            options: responses.componentOptions?.[component ?? ''] ?? [],
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

  it('memuat option payroll dengan opsi kosong untuk component yang mengizinkan all', async () => {
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

    const keySelect = await screen.findByRole('combobox', { name: /Component Key/i })
    expect(keySelect).toHaveDisplayValue('Semua')
    expect(screen.getByRole('option', { name: 'Pemanen' })).toBeInTheDocument()
    expect(screen.getByRole('option', { name: 'BHL' })).toBeInTheDocument()

    expect(get).toHaveBeenCalledWith('/payroll-cost-component-options', expect.objectContaining({
      params: { component: 'base_payroll_total' },
    }))
  })

  it('tidak menampilkan opsi kosong untuk additional wage type', async () => {
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

    const keySelect = await screen.findByRole('combobox', { name: /Component Key/i })
    expect(screen.queryByRole('option', { name: 'Semua' })).not.toBeInTheDocument()
    expect(keySelect).toHaveDisplayValue('Bonus Pemanen')
  })

  it('menyembunyikan Component Key jika component tidak punya opsi', async () => {
    const user = userEvent.setup()
    mockGet({
      componentOptions: {
        base_payroll_total: [{ component_key: 'pemanen', label: 'Pemanen' }],
      },
    })

    renderItemForm('/master/item/buat')

    await user.selectOptions(screen.getByRole('combobox', { name: /Mode Input/i }), ['auto_external'])
    await user.selectOptions(screen.getByRole('combobox', { name: /Component Payroll/i }), ['harvest_tbs_total'])

    expect(screen.queryByLabelText('Component Key')).not.toBeInTheDocument()
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
    const keySelect = await screen.findByRole('combobox', { name: /Component Key/i })
    expect(keySelect).toHaveDisplayValue('Semua')
    expect(screen.queryByRole('option', { name: /invalid/i })).not.toBeInTheDocument()
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
    const keySelect = await screen.findByRole('combobox', { name: /Component Key/i })
    await user.selectOptions(keySelect, ['pemanen'])

    await user.selectOptions(screen.getByRole('combobox', { name: /Component Payroll/i }), ['harvest_tbs_total'])
    expect(screen.queryByLabelText('Component Key')).not.toBeInTheDocument()

    await user.selectOptions(screen.getByRole('combobox', { name: /Component Payroll/i }), ['base_payroll_total'])
    const clearedKeySelect = await screen.findByRole('combobox', { name: /Component Key/i })
    expect(clearedKeySelect).toHaveDisplayValue('Semua')
  })
})
