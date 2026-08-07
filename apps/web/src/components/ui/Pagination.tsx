import { ChevronLeft, ChevronRight } from 'lucide-react'

interface PaginationProps {
  page: number
  lastPage: number
  total: number
  perPage: number
  onPageChange: (page: number) => void
  onPerPageChange: (perPage: number) => void
  perPageOptions?: number[]
}

export function Pagination({
  page,
  lastPage,
  total,
  perPage,
  onPageChange,
  onPerPageChange,
  perPageOptions = [10, 25, 50],
}: PaginationProps) {
  const from = total === 0 ? 0 : (page - 1) * perPage + 1
  const to   = Math.min(page * perPage, total)

  return (
    <div className="flex items-center justify-between px-4 py-3 border-t border-line text-sm">
      <div className="flex items-center gap-2 text-muted">
        <span>
          {total === 0 ? 'Tidak ada data' : `Menampilkan ${from}–${to} dari ${total}`}
        </span>
        <select
          className="input-base py-1 px-2 text-sm w-auto"
          value={perPage}
          onChange={(e) => onPerPageChange(Number(e.target.value))}
        >
          {perPageOptions.map((opt) => (
            <option key={opt} value={opt}>{opt} / halaman</option>
          ))}
        </select>
      </div>

      <div className="flex items-center gap-2">
        <button
          type="button"
          className="p-1.5 rounded-btn border border-line disabled:opacity-40 disabled:cursor-not-allowed hover:bg-mint"
          disabled={page <= 1}
          onClick={() => onPageChange(page - 1)}
        >
          <ChevronLeft className="w-4 h-4" />
        </button>
        <span className="text-muted">
          Halaman {lastPage === 0 ? 0 : page} / {lastPage}
        </span>
        <button
          type="button"
          className="p-1.5 rounded-btn border border-line disabled:opacity-40 disabled:cursor-not-allowed hover:bg-mint"
          disabled={page >= lastPage}
          onClick={() => onPageChange(page + 1)}
        >
          <ChevronRight className="w-4 h-4" />
        </button>
      </div>
    </div>
  )
}
