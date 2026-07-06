import { render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { PdoFormPage } from './PdoFormPage'
import { api } from '@/lib/api'
import { useAuthStore } from '@/store/auth.store'
import { useToastStore } from '@/store/toast.store'

function renderPdoForm(initialEntry = '/pdo/pdo-1') {
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
      <MemoryRouter initialEntries={[initialEntry]}>
        <Routes>
          <Route path="/pdo/:id" element={<PdoFormPage />} />
          <Route path="/pdo/:id/edit" element={<PdoFormPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const basePdo = {
  id: 'pdo-1',
  plantation_unit_id: 'unit-1',
  period_month: 7,
  period_year: 2026,
  notes: null,
  grand_total_amount: 0,
}

const categories = [{
  id: 'cat-1',
  code: 'CAT',
  name: 'Kategori',
  display_order: 1,
  is_active: true,
}, {
  id: 'cat-2',
  code: 'OPS',
  name: 'Operasional',
  display_order: 2,
  is_active: true,
}]

const subcategories = [{
  id: 'sub-1',
  category_id: 'cat-1',
  code: 'SUB',
  name: 'Subkategori',
  display_order: 1,
  is_active: true,
}, {
  id: 'sub-1b',
  category_id: 'cat-1',
  code: 'SUB-B',
  name: 'Subkategori B',
  display_order: 2,
  is_active: true,
}, {
  id: 'sub-2',
  category_id: 'cat-2',
  code: 'OPS-SUB',
  name: 'Sub Operasional',
  display_order: 1,
  is_active: true,
}]

const items = [{
  id: 'item-eligible',
  subcategory_id: 'missing-sub',
  code: 'AUTO-1',
  name: 'Auto Eligible',
  mode_input: 'auto_external',
  default_unit: null,
  default_rate: null,
}, {
  id: 'item-failed',
  subcategory_id: 'missing-sub',
  code: 'AUTO-2',
  name: 'Auto Failed',
  mode_input: 'auto_external',
  default_unit: null,
  default_rate: null,
}, {
  id: 'item-fresh',
  subcategory_id: 'missing-sub',
  code: 'AUTO-3',
  name: 'Auto Fresh',
  mode_input: 'auto_external',
  default_unit: null,
  default_rate: null,
}, {
  id: 'item-cat-1',
  subcategory_id: 'sub-1',
  code: 'CAT-1',
  name: 'First Group Item',
  mode_input: 'manual',
  default_unit: null,
  default_rate: null,
}, {
  id: 'item-cat-2',
  subcategory_id: 'sub-2',
  code: 'OPS-1',
  name: 'Second Group Item',
  mode_input: 'manual',
  default_unit: null,
  default_rate: null,
}, {
  id: 'item-cat-1b',
  subcategory_id: 'sub-1b',
  code: 'CAT-2',
  name: 'First Group Item B',
  mode_input: 'manual',
  default_unit: null,
  default_rate: null,
}, {
  id: 'item-new-auto',
  subcategory_id: 'sub-1',
  code: 'AUTO-NEW',
  name: 'Auto New',
  mode_input: 'auto_external',
  default_unit: null,
  default_rate: null,
}]

function makeDetail(overrides: Record<string, unknown>) {
  return {
    id: 'detail-1',
    expense_item_id: 'item-eligible',
    description: 'Detail Auto',
    quantity: null,
    unit: null,
    rate: null,
    amount: 0,
    notes: null,
    display_order: 1,
    is_auto_external_active: true,
    is_external_read_only: true,
    needs_pull: true,
    is_stale_external_snapshot: false,
    external_payload: null,
    ...overrides,
  }
}

describe('PdoFormPage bulk external pull', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
    window.scrollTo = vi.fn()
    useToastStore.setState({ toasts: [] })
    useAuthStore.setState({
      user: {
        id: 'kerani-1',
        full_name: 'Kerani',
        email: 'kerani@example.test',
        whatsapp_number: '',
        is_active: true,
        role: { id: 'role-1', code: 'KERANI', name: 'Kerani' },
        plantation_unit: { id: 'unit-1', code: 'KP', name: 'Kebun Pusat', is_active: true },
        company_id: 'company-1',
      },
      token: 'token',
      expiresAt: Date.now() + 60_000,
    })
  })

  it('shows fresh-state copy and disables bulk button when no eligible persisted rows', async () => {
    vi.spyOn(api, 'get').mockImplementation((url: string) => {
      if (url === '/plantation-units') {
        return Promise.resolve({ data: { success: true, data: [{ id: 'unit-1', code: 'KP', name: 'Kebun Pusat', is_active: true }] } })
      }

      if (url === '/expense-items') {
        return Promise.resolve({ data: { success: true, data: items } })
      }

      if (url === '/expense-subcategories') {
        return Promise.resolve({ data: { success: true, data: subcategories } })
      }

      if (url === '/expense-categories') {
        return Promise.resolve({ data: { success: true, data: categories } })
      }

      if (url === '/pdo/pdo-1') {
        return Promise.resolve({ data: { success: true, data: { pdo: basePdo } } })
      }

      if (url === '/pdo/pdo-1/details') {
        return Promise.resolve({
          data: {
            success: true,
            data: [
              makeDetail({
                id: 'detail-fresh',
                expense_item_id: 'item-fresh',
                amount: 750000,
                needs_pull: false,
                external_amount_pulled_at: '2026-07-07T10:00:00+07:00',
                external_payload: {
                  status: 'ok',
                  component_label: 'Gaji Pokok',
                  period: '2026-06',
                },
              }),
            ],
          },
        })
      }

      return Promise.resolve({ data: { success: true, data: [] } })
    })

    renderPdoForm('/pdo/pdo-1/edit')

    expect(await screen.findByRole('button', { name: 'Semua Data Sudah Fresh' })).toBeDisabled()
  })

  it('opens first category, auto-opens its subcategories, locks existing taxonomy fields', async () => {
    vi.spyOn(api, 'get').mockImplementation((url: string) => {
      if (url === '/plantation-units') {
        return Promise.resolve({ data: { success: true, data: [{ id: 'unit-1', code: 'KP', name: 'Kebun Pusat', is_active: true }] } })
      }

      if (url === '/expense-items') {
        return Promise.resolve({ data: { success: true, data: items } })
      }

      if (url === '/expense-subcategories') {
        return Promise.resolve({ data: { success: true, data: subcategories } })
      }

      if (url === '/expense-categories') {
        return Promise.resolve({ data: { success: true, data: categories } })
      }

      if (url === '/pdo/pdo-1') {
        return Promise.resolve({ data: { success: true, data: { pdo: basePdo } } })
      }

      if (url === '/pdo/pdo-1/details') {
        return Promise.resolve({
          data: {
            success: true,
            data: [
              makeDetail({ id: 'detail-second', expense_item_id: 'item-cat-2', description: 'Second Group Detail', amount: 200, needs_pull: false }),
              makeDetail({ id: 'detail-first-b', expense_item_id: 'item-cat-1b', description: 'First Group Detail B', amount: 150, needs_pull: false }),
              makeDetail({ id: 'detail-first', expense_item_id: 'item-cat-1', description: 'First Group Detail', amount: 100, needs_pull: false }),
            ],
          },
        })
      }

      return Promise.resolve({ data: { success: true, data: [] } })
    })

    renderPdoForm('/pdo/pdo-1/edit')

    expect(await screen.findByTestId('pdo-form-page')).toHaveClass('w-full')
    expect(await screen.findByTestId('pdo-detail-table')).toHaveClass('min-w-[1200px]')
    const firstDetail = await screen.findByDisplayValue('First Group Detail')
    const firstDetailB = await screen.findByDisplayValue('First Group Detail B')
    expect(firstDetail).toBeInTheDocument()
    expect(firstDetailB).toBeInTheDocument()
    expect(screen.queryByDisplayValue('Second Group Detail')).not.toBeInTheDocument()

    const firstRow = firstDetail.closest('tr')
    const firstRowSelects = within(firstRow as HTMLElement).getAllByRole('combobox')
    expect(firstRowSelects[0]).toBeDisabled()
    expect(firstRowSelects[1]).toBeDisabled()
    expect(firstRowSelects[2]).toBeDisabled()
  })

  it('re-opens all subcategories when category group expands again', async () => {
    const user = userEvent.setup()

    vi.spyOn(api, 'get').mockImplementation((url: string) => {
      if (url === '/plantation-units') {
        return Promise.resolve({ data: { success: true, data: [{ id: 'unit-1', code: 'KP', name: 'Kebun Pusat', is_active: true }] } })
      }

      if (url === '/expense-items') {
        return Promise.resolve({ data: { success: true, data: items } })
      }

      if (url === '/expense-subcategories') {
        return Promise.resolve({ data: { success: true, data: subcategories } })
      }

      if (url === '/expense-categories') {
        return Promise.resolve({ data: { success: true, data: categories } })
      }

      if (url === '/pdo/pdo-1') {
        return Promise.resolve({ data: { success: true, data: { pdo: basePdo } } })
      }

      if (url === '/pdo/pdo-1/details') {
        return Promise.resolve({
          data: {
            success: true,
            data: [
              makeDetail({ id: 'detail-second', expense_item_id: 'item-cat-2', description: 'Second Group Detail', amount: 200, needs_pull: false }),
              makeDetail({ id: 'detail-first-b', expense_item_id: 'item-cat-1b', description: 'First Group Detail B', amount: 150, needs_pull: false }),
              makeDetail({ id: 'detail-first', expense_item_id: 'item-cat-1', description: 'First Group Detail', amount: 100, needs_pull: false }),
            ],
          },
        })
      }

      return Promise.resolve({ data: { success: true, data: [] } })
    })

    renderPdoForm('/pdo/pdo-1/edit')

    expect(await screen.findByDisplayValue('First Group Detail B')).toBeInTheDocument()

    await user.click(screen.getByText('SUB-B — Subkategori B', { selector: 'span' }))
    await waitFor(() => expect(screen.queryByDisplayValue('First Group Detail B')).not.toBeInTheDocument())

    await user.click(screen.getByText('CAT — Kategori', { selector: 'span' }))
    await waitFor(() => expect(screen.queryByDisplayValue('First Group Detail')).not.toBeInTheDocument())

    await user.click(screen.getByText('CAT — Kategori', { selector: 'span' }))
    expect(await screen.findByDisplayValue('First Group Detail')).toBeInTheDocument()
    expect(await screen.findByDisplayValue('First Group Detail B')).toBeInTheDocument()
  })

  it('runs bulk pull, refetches details, shows summary toast, row error, unsaved warning', async () => {
    const user = userEvent.setup()
    let resolveBulk!: (value: unknown) => void
    let detailCallCount = 0

    vi.spyOn(api, 'get').mockImplementation((url: string) => {
      if (url === '/plantation-units') {
        return Promise.resolve({ data: { success: true, data: [{ id: 'unit-1', code: 'KP', name: 'Kebun Pusat', is_active: true }] } })
      }

      if (url === '/expense-items') {
        return Promise.resolve({ data: { success: true, data: items } })
      }

      if (url === '/expense-subcategories') {
        return Promise.resolve({ data: { success: true, data: subcategories } })
      }

      if (url === '/expense-categories') {
        return Promise.resolve({ data: { success: true, data: categories } })
      }

      if (url === '/pdo/pdo-1') {
        return Promise.resolve({ data: { success: true, data: { pdo: basePdo } } })
      }

      if (url === '/pdo/pdo-1/details') {
        detailCallCount += 1

        return Promise.resolve({
          data: {
            success: true,
            data: detailCallCount === 1
              ? [
                makeDetail({ id: 'detail-eligible', expense_item_id: 'item-eligible', amount: 0, needs_pull: true }),
                makeDetail({ id: 'detail-failed', expense_item_id: 'item-failed', amount: 0, needs_pull: true, display_order: 2 }),
              ]
              : [
                makeDetail({
                  id: 'detail-eligible',
                  expense_item_id: 'item-eligible',
                  amount: 1250000,
                  needs_pull: false,
                  external_amount_pulled_at: '2026-07-07T10:15:00+07:00',
                  external_payload: {
                    status: 'ok',
                    component_label: 'Gaji Pokok',
                    period: '2026-06',
                  },
                }),
                makeDetail({ id: 'detail-failed', expense_item_id: 'item-failed', amount: 0, needs_pull: true, display_order: 2 }),
              ],
          },
        })
      }

      return Promise.resolve({ data: { success: true, data: [] } })
    })

    vi.spyOn(api, 'post').mockImplementation((url: string) => {
      if (url === '/pdo/pdo-1/pull-external-costs') {
        return new Promise((resolve) => {
          resolveBulk = resolve
        })
      }

      return Promise.resolve({ data: { success: true, data: {} } })
    })

    renderPdoForm('/pdo/pdo-1/edit')

    expect(await screen.findByRole('button', { name: 'Ambil Semua Data' })).toBeEnabled()

    await user.click(screen.getByRole('button', { name: /Tambah Item/i }))

    const rows = document.querySelectorAll('[data-detail-row]')
    const newRow = rows[0] as HTMLElement
    const selects = within(newRow).getAllByRole('combobox')

    await user.selectOptions(selects[0], 'cat-1')
    await user.selectOptions(selects[1], 'sub-1')
    await user.selectOptions(selects[2], 'item-new-auto')

    expect(screen.getByText('Ada item Auto External baru belum disimpan. Simpan draft dulu untuk ikut Ambil Semua Data.')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Ambil Semua Data' }))

    expect(screen.getByRole('button', { name: 'Mengambil...' })).toBeDisabled()

    resolveBulk({
      data: {
        success: true,
        data: {
          total: 2,
          succeeded_count: 1,
          failed_count: 1,
          succeeded: [{ detail_id: 'detail-eligible' }],
          failed: [{ detail_id: 'detail-failed', message: 'Komponen Payroll tidak valid untuk item ini.' }],
          grand_total: 1250000,
        },
      },
    })

    await waitFor(() => expect(detailCallCount).toBe(2))
    await waitFor(() => expect(screen.getByTestId('detail-amount-1')).toHaveValue(1250000))
    expect(screen.getByText('Komponen Payroll tidak valid untuk item ini.')).toBeInTheDocument()
    expect(useToastStore.getState().toasts.some((toast) => toast.message === '1 berhasil, 1 gagal')).toBe(true)
  })
})
