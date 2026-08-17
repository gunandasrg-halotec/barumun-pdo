import { useRef, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Upload } from 'lucide-react'
import { api, getApiErrorMessage } from '@/lib/api'
import { Button } from '@/components/ui/Button'
import { Modal } from '@/components/ui/Modal'
import { useToastStore } from '@/store/toast.store'
import type { PettyCashVoucher } from '@/types/pettycash'

interface VoucherScanUploadModalProps {
  voucher: PettyCashVoucher | null
  onClose: () => void
  onUploaded: () => void
}

export function VoucherScanUploadModal({ voucher, onClose, onUploaded }: VoucherScanUploadModalProps) {
  const toast   = useToastStore((s) => s.push)
  const [file, setFile] = useState<File | null>(null)
  const inputRef = useRef<HTMLInputElement>(null)

  const upload = useMutation({
    mutationFn: () => {
      if (!file || !voucher) throw new Error('File atau voucher tidak tersedia')
      const fd = new FormData()
      fd.append('file', file)
      return api.post(`/petty-cash-vouchers/${voucher.id}/scan`, fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
    },
    onSuccess: (res) => {
      const count = res.data?.data?.realization_entries?.length ?? 0
      toast(`Scan berhasil diunggah. ${count} realisasi dibuat.`)
      onUploaded()
      handleClose()
    },
    onError: (error) => toast(getApiErrorMessage(error), 'error'),
    onSettled: () => {
      if (inputRef.current) inputRef.current.value = ''
    },
  })

  const handleClose = () => {
    setFile(null)
    onClose()
  }

  return (
    <Modal open={!!voucher} onClose={handleClose} title={`Unggah Scan — ${voucher?.voucher_number ?? ''}`}>
      <p className="text-sm text-muted mb-4">
        Unggah scan voucher yang <span className="font-bold">SUDAH ditandatangani</span> oleh keempat pihak.
        Setelah ini, {voucher?.lines.length ?? 0} realisasi dibuat otomatis dan voucher terkunci — tidak bisa
        diedit atau dihapus lagi. Format: JPG, PNG, PDF. Maks 10 MB.
      </p>
      <div
        className="border-2 border-dashed border-line rounded-drawer p-8 text-center cursor-pointer hover:border-green transition-colors"
        onClick={() => inputRef.current?.click()}
      >
        <Upload className="w-8 h-8 text-muted mx-auto mb-2" />
        <p className="text-sm text-muted">{file ? file.name : 'Klik atau drag file ke sini'}</p>
        <input
          ref={inputRef}
          type="file"
          accept=".jpg,.jpeg,.png,.pdf"
          className="hidden"
          onChange={(e) => setFile(e.target.files?.[0] ?? null)}
        />
      </div>
      <div className="flex justify-end gap-2 mt-5">
        <Button variant="secondary" onClick={handleClose}>Batal</Button>
        <Button loading={upload.isPending} disabled={!file} onClick={() => upload.mutate()}>
          Unggah &amp; Buat Realisasi
        </Button>
      </div>
    </Modal>
  )
}
