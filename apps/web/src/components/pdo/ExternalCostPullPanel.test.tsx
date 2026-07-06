import { useState } from 'react'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { fmt } from '@/lib/format'
import { ExternalCostPullPanel } from './ExternalCostPullPanel'

interface HarnessResult {
  amount: number
  detail: {
    id: string
    external_amount_pulled_at: string | null
    external_component: string | null
    is_auto_external_active?: boolean
    needs_pull?: boolean
    is_stale_external_snapshot?: boolean
    is_external_read_only?: boolean
    external_payload: {
      status: string
      component_label: string
      period: string
      estate_external_id: string
      generated_at: string | null
    } | null
  }
}

function Harness({
  amount = 100,
  pull,
}: {
  amount?: number
  pull: () => Promise<HarnessResult>
}) {
  const [currentAmount, setCurrentAmount] = useState(amount)
  const [snapshot, setSnapshot] = useState<HarnessResult['detail']>({
    id: 'detail-1',
    external_amount_pulled_at: null,
    external_component: 'base_payroll_total',
    is_auto_external_active: true,
    needs_pull: true,
    is_stale_external_snapshot: false,
    is_external_read_only: true,
    external_payload: null,
  })
  const [errorMessage, setErrorMessage] = useState<string>()
  const [isPulling, setIsPulling] = useState(false)

  const handlePull = async () => {
    setIsPulling(true)
    setErrorMessage(undefined)

    try {
      const result = await pull()
      setCurrentAmount(result.amount)
      setSnapshot(result.detail)
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : 'Gagal tarik data.')
    } finally {
      setIsPulling(false)
    }
  }

  return (
    <div>
      <p data-testid="amount">{fmt(currentAmount)}</p>
      <p data-testid="total">{fmt(currentAmount)}</p>
      <ExternalCostPullPanel
        errorMessage={errorMessage}
        isPulling={isPulling}
        onPull={handlePull}
        snapshot={snapshot}
      />
    </div>
  )
}

describe('ExternalCostPullPanel', () => {
  it('calls pull handler, shows loading, updates amount and total on success', async () => {
    const user = userEvent.setup()
    let resolvePull!: (value: HarnessResult) => void
    const pull = vi.fn(() => new Promise<HarnessResult>((resolve) => {
      resolvePull = resolve
    }))

    render(<Harness pull={pull} />)

    await user.click(screen.getByTestId('pull-external-button'))

    expect(pull).toHaveBeenCalledTimes(1)
    await waitFor(() => expect(screen.getByTestId('pull-external-button')).toBeDisabled())
    expect(screen.getByTestId('pull-external-button')).toHaveTextContent('Mengambil...')

    resolvePull({
      amount: 250000,
      detail: {
        id: 'detail-1',
        external_amount_pulled_at: '2026-06-23T10:15:00+07:00',
        external_component: 'base_payroll_total',
        is_auto_external_active: true,
        needs_pull: false,
        is_stale_external_snapshot: false,
        is_external_read_only: true,
        external_payload: {
          status: 'ok',
          component_label: 'Gaji Pokok',
          period: '2026-06',
          estate_external_id: 'EST-001',
          generated_at: '2026-06-23T10:00:00+07:00',
        },
      },
    })

    await waitFor(() => expect(screen.getByTestId('amount')).toHaveTextContent(fmt(250000)))
    expect(screen.getByTestId('total')).toHaveTextContent(fmt(250000))
    expect(screen.getByTestId('pull-external-button')).toHaveAttribute('title', expect.stringContaining('Gaji Pokok'))
    expect(screen.getByTestId('pull-external-button')).toHaveAttribute('title', expect.stringContaining('2026-06'))
  })

  it('shows zero amount as valid pulled data', async () => {
    const user = userEvent.setup()
    const pull = vi.fn().mockResolvedValue({
      amount: 0,
      detail: {
        id: 'detail-1',
        external_amount_pulled_at: '2026-06-23T10:15:00+07:00',
        external_component: 'base_payroll_total',
        is_auto_external_active: true,
        needs_pull: false,
        is_stale_external_snapshot: false,
        is_external_read_only: true,
        external_payload: {
          status: 'empty',
          component_label: 'Gaji Pokok',
          period: '2026-06',
          estate_external_id: 'EST-001',
          generated_at: null,
        },
      },
    } satisfies HarnessResult)

    render(<Harness pull={pull} />)

    await user.click(screen.getByTestId('pull-external-button'))

    await waitFor(() => expect(screen.getByTestId('amount')).toHaveTextContent(fmt(0)))
    expect(screen.getByTestId('pull-external-button')).toHaveAttribute('title', expect.stringContaining('empty'))
    expect(screen.queryByText('Belum ada data Payroll ditarik untuk baris ini.')).not.toBeInTheDocument()
  })

  it('shows error state when pull fails', async () => {
    const user = userEvent.setup()
    const pull = vi.fn().mockRejectedValue(new Error('Payroll Estate Mapping belum diatur untuk kebun ini.'))

    render(<Harness pull={pull} />)

    await user.click(screen.getByTestId('pull-external-button'))

    expect(await screen.findByText('Payroll Estate Mapping belum diatur untuk kebun ini.')).toBeInTheDocument()
    expect(screen.getByTestId('amount')).toHaveTextContent(fmt(100))
  })

  it('shows stale and needs-pull warnings from backend flags', () => {
    render(
      <ExternalCostPullPanel
        errorMessage={undefined}
        isPulling={false}
        onPull={vi.fn()}
        snapshot={{
          id: 'detail-1',
          external_amount_pulled_at: '2026-06-23T10:15:00+07:00',
          external_component: 'base_payroll_total',
          is_auto_external_active: true,
          needs_pull: false,
          is_stale_external_snapshot: true,
          is_external_read_only: true,
          external_payload: {
            status: 'ok',
            component_label: 'Gaji Pokok',
            period: '2026-06',
            estate_external_id: 'EST-001',
            generated_at: '2026-06-23T10:00:00+07:00',
          },
        }}
      />,
    )

    expect(screen.getByTestId('pull-external-button')).toHaveAttribute(
      'title',
      expect.stringContaining('Snapshot external sudah stale. Ambil Data ulang sebelum submit PDO.'),
    )
    expect(screen.getByTestId('pull-external-button')).toHaveAttribute(
      'title',
      expect.stringContaining('Nilai volume, satuan, dan jumlah dikunci selama item masih Auto External aktif.'),
    )
  })

  it('keeps pull button label visible while disabled before draft saved', () => {
    render(
      <ExternalCostPullPanel
        errorMessage={undefined}
        isPulling={false}
        onPull={vi.fn()}
        snapshot={{
          external_amount_pulled_at: null,
          external_component: 'base_payroll_total',
          is_auto_external_active: true,
          needs_pull: true,
          is_stale_external_snapshot: false,
          is_external_read_only: true,
          external_payload: null,
        }}
      />,
    )

    expect(screen.getByTestId('pull-external-button')).toBeDisabled()
    expect(screen.getByTestId('pull-external-button')).toHaveClass(
      'disabled:opacity-100',
      'disabled:bg-[#dbeafe]',
      'disabled:text-[#1e3a8a]',
      'disabled:border-[#93c5fd]',
    )
  })
})
