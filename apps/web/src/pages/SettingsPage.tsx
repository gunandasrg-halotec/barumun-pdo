import { useState, useEffect } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { Button } from '@/components/ui/Button'
import { useToastStore } from '@/store/toast.store'
import { Eye, EyeOff } from 'lucide-react'
import type { SystemSetting, NotificationTemplate, ApiResponse } from '@/types'

export function SettingsPage() {
  const qc    = useQueryClient()
  const toast = useToastStore((s) => s.push)
  const [showApiKey, setShowApiKey] = useState(false)

  // Fetch settings
  const { data: settings } = useQuery({
    queryKey: ['settings'],
    queryFn: async () => {
      const res = await api.get<ApiResponse<SystemSetting[]>>('/settings')
      return res.data.data
    },
  })

  const { data: templates } = useQuery({
    queryKey: ['notification-templates'],
    queryFn: async () => {
      const res = await api.get<ApiResponse<NotificationTemplate[]>>('/notification-templates')
      return res.data.data
    },
  })

  const settingMap = Object.fromEntries(settings?.map((s) => [s.key, s.value]) ?? [])

  const [threshold, setThreshold] = useState({ bukti: '', penjelasan: '' })
  const [gateway, setGateway]     = useState({ url: '', api_key: '' })
  const [reminder, setReminder]   = useState({ date: '1', hour: '8' })
  const [templateBodies, setTemplateBodies] = useState<Record<string, string>>({})

  useEffect(() => {
    if (settingMap) {
      setThreshold({
        bukti:       settingMap['threshold_bukti']        ?? '1000000',
        penjelasan:  settingMap['threshold_penjelasan']   ?? '500000',
      })
      setGateway({
        url:      settingMap['wa_gateway_url']   ?? '',
        api_key:  settingMap['wa_api_key']       ?? '',
      })
      setReminder({
        date: settingMap['reminder_date'] ?? '1',
        hour: settingMap['reminder_hour'] ?? '8',
      })
    }
  }, [settings])

  useEffect(() => {
    if (templates) {
      setTemplateBodies(Object.fromEntries(templates.map((t) => [t.id, t.template_body])))
    }
  }, [templates])

  const updateSettings = useMutation({
    mutationFn: async (data: Record<string, string>) => api.put('/settings', data),
    onSuccess: () => {
      toast('Pengaturan berhasil disimpan')
      qc.invalidateQueries({ queryKey: ['settings'] })
    },
    onError: () => toast('Gagal menyimpan pengaturan', 'error'),
  })

  const testWa = useMutation({
    mutationFn: () => api.post('/settings/wa-test'),
    onSuccess: () => toast('Test koneksi berhasil. Pesan terkirim.'),
    onError: (e: unknown) => toast(`Koneksi gagal: ${(e as Error).message}`, 'error'),
  })

  const updateTemplate = useMutation({
    mutationFn: ({ id, body }: { id: string; body: string }) =>
      api.put(`/notification-templates/${id}`, { template_body: body }),
    onSuccess: () => toast('Template berhasil disimpan'),
    onError: () => toast('Gagal menyimpan template', 'error'),
  })

  return (
    <div className="max-w-2xl">
      <h2 className="text-[28px] font-[950] text-ink mb-6">Pengaturan</h2>

      {/* Section 1: Threshold */}
      <div className="card mb-4">
        <h3 className="text-[17px] font-[850] mb-4">Threshold Global</h3>
        <div className="grid grid-cols-1 desk:grid-cols-2 gap-4">
          <div>
            <label className="block text-[12px] font-[850] text-muted mb-1.5">
              Threshold Wajib Bukti (Rp)
            </label>
            <input
              type="number"
              className="input-base"
              value={threshold.bukti}
              onChange={(e) => setThreshold((t) => ({ ...t, bukti: e.target.value }))}
            />
          </div>
          <div>
            <label className="block text-[12px] font-[850] text-muted mb-1.5">
              Threshold Penjelasan Selisih (Rp)
            </label>
            <input
              type="number"
              className="input-base"
              value={threshold.penjelasan}
              onChange={(e) => setThreshold((t) => ({ ...t, penjelasan: e.target.value }))}
            />
          </div>
        </div>
        <div className="mt-4">
          <Button
            loading={updateSettings.isPending}
            onClick={() => updateSettings.mutate({
              threshold_bukti: threshold.bukti,
              threshold_penjelasan: threshold.penjelasan,
            })}
          >
            Simpan Threshold
          </Button>
        </div>
      </div>

      {/* Section 2: WhatsApp Gateway */}
      <div className="card mb-4">
        <h3 className="text-[17px] font-[850] mb-4">WhatsApp Gateway</h3>
        <div className="flex flex-col gap-3">
          <div>
            <label className="block text-[12px] font-[850] text-muted mb-1.5">URL Endpoint Gateway</label>
            <input
              type="text"
              className="input-base"
              value={gateway.url}
              onChange={(e) => setGateway((g) => ({ ...g, url: e.target.value }))}
              placeholder="https://api.whatsapp-gateway.com/send"
            />
          </div>
          <div>
            <label className="block text-[12px] font-[850] text-muted mb-1.5">API Key / Token</label>
            <div className="relative">
              <input
                type={showApiKey ? 'text' : 'password'}
                className="input-base pr-10"
                value={gateway.api_key}
                onChange={(e) => setGateway((g) => ({ ...g, api_key: e.target.value }))}
                placeholder="••••••••"
              />
              <button
                className="absolute right-3 top-1/2 -translate-y-1/2 text-muted hover:text-ink"
                onClick={() => setShowApiKey((v) => !v)}
                type="button"
              >
                {showApiKey ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
              </button>
            </div>
          </div>
        </div>
        <div className="flex gap-2 mt-4">
          <Button
            loading={updateSettings.isPending}
            onClick={() => updateSettings.mutate({
              wa_gateway_url: gateway.url,
              wa_api_key: gateway.api_key,
            })}
          >
            Simpan Konfigurasi Gateway
          </Button>
          <Button variant="secondary" loading={testWa.isPending} onClick={() => testWa.mutate()}>
            Test Koneksi
          </Button>
        </div>
      </div>

      {/* Section 3: Jadwal Reminder */}
      <div className="card mb-4">
        <h3 className="text-[17px] font-[850] mb-4">Jadwal Reminder Bulanan</h3>
        <div className="grid grid-cols-1 desk:grid-cols-2 gap-4">
          <div>
            <label className="block text-[12px] font-[850] text-muted mb-1.5">Tanggal (1–28)</label>
            <input
              type="number"
              min={1} max={28}
              className="input-base"
              value={reminder.date}
              onChange={(e) => setReminder((r) => ({ ...r, date: e.target.value }))}
            />
          </div>
          <div>
            <label className="block text-[12px] font-[850] text-muted mb-1.5">Jam Pengiriman (0–23)</label>
            <input
              type="number"
              min={0} max={23}
              className="input-base"
              value={reminder.hour}
              onChange={(e) => setReminder((r) => ({ ...r, hour: e.target.value }))}
            />
          </div>
        </div>
        <div className="mt-4">
          <Button
            loading={updateSettings.isPending}
            onClick={() => updateSettings.mutate({
              reminder_date: reminder.date,
              reminder_hour: reminder.hour,
            })}
          >
            Simpan Jadwal
          </Button>
        </div>
      </div>

      {/* Section 4: Template WhatsApp */}
      <div className="card">
        <h3 className="text-[17px] font-[850] mb-4">Template Pesan WhatsApp</h3>
        <div className="flex flex-col gap-5">
          {templates?.map((tmpl) => (
            <div key={tmpl.id}>
              <div className="text-[12px] font-[850] text-muted uppercase tracking-wider mb-1.5">
                {tmpl.event_type}
              </div>
              <textarea
                className="input-base resize-none w-full"
                rows={4}
                value={templateBodies[tmpl.id] ?? tmpl.template_body}
                onChange={(e) => setTemplateBodies((b) => ({ ...b, [tmpl.id]: e.target.value }))}
              />
              <p className="text-[11px] text-muted mt-1">
                Variabel: {'{{nama_user}}'} {'{{nomor_pdo}}'} {'{{periode}}'} {'{{unit_kebun}}'} {'{{alasan_reject}}'} {'{{deadline}}'}
              </p>
              <div className="mt-2">
                <Button
                  size="sm"
                  variant="secondary"
                  loading={updateTemplate.isPending}
                  onClick={() => updateTemplate.mutate({ id: tmpl.id, body: templateBodies[tmpl.id] })}
                >
                  Simpan Template
                </Button>
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  )
}
