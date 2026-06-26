import { useRef, useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Paperclip, Upload, Trash2, FileText, FileImage, FileSpreadsheet } from 'lucide-react'
import { api } from '@/lib/api'
import { useToastStore } from '@/store/toast.store'

type Attachment = {
  id: string
  original_filename: string
  mime_type: string
  file_size: number
  uploaded_by: string
  created_at: string
  download_url: string
}

function fmtBytes(bytes: number): string {
  if (bytes < 1024)         return bytes + ' B'
  if (bytes < 1024 * 1024)  return (bytes / 1024).toFixed(1) + ' KB'
  return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
}

function FileIcon({ mime }: { mime: string }) {
  if (mime.startsWith('image/'))                               return <FileImage className="w-4 h-4 text-blue-500" />
  if (mime.includes('spreadsheet') || mime.includes('excel')) return <FileSpreadsheet className="w-4 h-4 text-green-600" />
  return <FileText className="w-4 h-4 text-muted" />
}

// ── Shared inner content ──────────────────────────────────────────────────────

type ContentProps = {
  detailId: string
  canUpload: boolean
}

export function AttachmentContent({ detailId, canUpload }: ContentProps) {
  const toast    = useToastStore((s) => s.push)
  const qc       = useQueryClient()
  const inputRef = useRef<HTMLInputElement>(null)
  const [uploading, setUploading] = useState(false)

  const { data: attachments = [], isLoading } = useQuery<Attachment[]>({
    queryKey: ['detail-attachments', detailId],
    queryFn: async () => {
      const res = await api.get(`/pdo-details/${detailId}/attachments`)
      return res.data.data
    },
  })

  const deleteMut = useMutation({
    mutationFn: (id: string) => api.delete(`/pdo-detail-attachments/${id}`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['detail-attachments', detailId] })
      toast('Lampiran dihapus.', 'success')
    },
    onError: () => toast('Gagal menghapus lampiran.', 'error'),
  })

  const handleUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    if (!file) return
    setUploading(true)
    try {
      const form = new FormData()
      form.append('file', file)
      await api.post(`/pdo-details/${detailId}/attachments`, form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      qc.invalidateQueries({ queryKey: ['detail-attachments', detailId] })
      toast('Lampiran berhasil diunggah.', 'success')
    } catch (err: any) {
      const msg = err?.response?.data?.errors?.file?.[0] ?? 'Gagal mengunggah file.'
      toast(msg, 'error')
    } finally {
      setUploading(false)
      if (inputRef.current) inputRef.current.value = ''
    }
  }

  const handleDownload = async (att: Attachment) => {
    try {
      const path = att.download_url.split('/api/v1/')[1]
      const res  = await api.get(path, { responseType: 'blob' })
      const url  = URL.createObjectURL(new Blob([res.data]))
      const a    = document.createElement('a')
      a.href     = url
      a.download = att.original_filename
      a.click()
      URL.revokeObjectURL(url)
    } catch {
      toast('Gagal mengunduh file.', 'error')
    }
  }

  return (
    <div>
      {canUpload && (
        <div className="flex items-center gap-3 mb-2">
          <button
            type="button"
            disabled={uploading}
            onClick={() => inputRef.current?.click()}
            className="inline-flex items-center gap-1.5 text-[11px] font-[700] px-2.5 py-1 rounded border border-line bg-white hover:bg-[#f0f4f0] text-ink transition-colors disabled:opacity-50"
          >
            <Upload className="w-3 h-3" />
            {uploading ? 'Mengunggah...' : 'Unggah File'}
          </button>
          <span className="text-[10px] text-muted">Excel, Word, PDF, Gambar · maks 10 MB</span>
          <input
            ref={inputRef}
            type="file"
            className="hidden"
            accept=".xlsx,.xls,.doc,.docx,.pdf,.jpg,.jpeg,.png,.webp"
            onChange={handleUpload}
          />
        </div>
      )}

      {isLoading ? (
        <p className="text-[12px] text-muted">Memuat...</p>
      ) : attachments.length === 0 ? (
        <p className="text-[12px] text-muted italic">Belum ada lampiran.</p>
      ) : (
        <ul className="space-y-1">
          {attachments.map((att) => (
            <li key={att.id} className="flex items-center gap-2 text-[12px]">
              <FileIcon mime={att.mime_type} />
              <button
                type="button"
                onClick={() => handleDownload(att)}
                className="text-blue-600 hover:underline font-[500] truncate max-w-[280px] text-left"
                title={att.original_filename}
              >
                {att.original_filename}
              </button>
              <span className="text-muted shrink-0">{fmtBytes(att.file_size)}</span>
              <span className="text-muted shrink-0">· {att.uploaded_by}</span>
              {canUpload && (
                <button
                  type="button"
                  onClick={() => deleteMut.mutate(att.id)}
                  disabled={deleteMut.isPending}
                  className="ml-auto text-muted hover:text-red-500 transition-colors"
                  title="Hapus lampiran"
                >
                  <Trash2 className="w-3.5 h-3.5" />
                </button>
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

// ── Table row version — dipakai di halaman detail PDO ────────────────────────

type PanelProps = ContentProps & { colSpan: number }

export function DetailAttachmentPanel({ detailId, canUpload, colSpan }: PanelProps) {
  return (
    <tr>
      <td colSpan={colSpan} className="px-0 py-0 border-t border-dashed border-line bg-[#f9fbf9]">
        <div className="pl-10 pr-4 py-3">
          <div className="flex items-center gap-2 mb-2">
            <Paperclip className="w-3.5 h-3.5 text-muted" />
            <span className="text-[11px] font-[850] uppercase tracking-wider text-muted">Lampiran</span>
          </div>
          <AttachmentContent detailId={detailId} canUpload={canUpload} />
        </div>
      </td>
    </tr>
  )
}

// ── Div version — dipakai di halaman edit PDO ─────────────────────────────────

export function AttachmentSection({ detailId }: { detailId: string }) {
  return (
    <div className="mt-3 pt-3 border-t border-dashed border-line">
      <div className="flex items-center gap-2 mb-2">
        <Paperclip className="w-3.5 h-3.5 text-muted" />
        <span className="text-[11px] font-[850] uppercase tracking-wider text-muted">Lampiran</span>
      </div>
      <AttachmentContent detailId={detailId} canUpload={true} />
    </div>
  )
}

// ── Badge — ikon clip dengan jumlah lampiran ──────────────────────────────────

export function AttachmentBadge({ detailId, onClick }: { detailId: string; onClick: () => void }) {
  const { data: attachments = [] } = useQuery<Attachment[]>({
    queryKey: ['detail-attachments', detailId],
    queryFn: async () => {
      const res = await api.get(`/pdo-details/${detailId}/attachments`)
      return res.data.data
    },
    staleTime: 30_000,
  })

  return (
    <button
      type="button"
      onClick={onClick}
      className="inline-flex items-center gap-1 text-muted hover:text-ink transition-colors"
      title="Lihat lampiran"
    >
      <Paperclip className="w-3.5 h-3.5" />
      {attachments.length > 0 && (
        <span className="text-[10px] font-[700] bg-blue-100 text-blue-700 rounded-full px-1.5 py-0.5 leading-none">
          {attachments.length}
        </span>
      )}
    </button>
  )
}
