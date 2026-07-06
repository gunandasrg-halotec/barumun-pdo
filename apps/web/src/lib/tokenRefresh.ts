import axios from 'axios'
import { useAuthStore } from '@/store/auth.store'

// Refresh proaktif 5 menit sebelum access token (1 jam) kedaluwarsa,
// supaya user tidak pernah melihat 401 di tengah sesi aktif.
const REFRESH_BUFFER_MS = 5 * 60 * 1000

let refreshTimer: ReturnType<typeof setTimeout> | null = null
let refreshPromise: Promise<string | null> | null = null

function scheduleTokenRefresh(expiresInSeconds: number) {
  if (refreshTimer) clearTimeout(refreshTimer)
  const delay = Math.max(expiresInSeconds * 1000 - REFRESH_BUFFER_MS, 0)
  refreshTimer = setTimeout(() => {
    void refreshAccessToken()
  }, delay)
}

export function clearTokenRefreshTimer() {
  if (refreshTimer) clearTimeout(refreshTimer)
  refreshTimer = null
}

// Dipanggil sekali saat app mount (AppLayout) untuk melanjutkan jadwal
// refresh dari sesi yang sudah tersimpan (mis. setelah reload halaman).
export function resumeTokenRefreshFromStoredSession() {
  const { token, expiresAt } = useAuthStore.getState()
  if (!token || !expiresAt) return

  const remainingMs = expiresAt - Date.now()
  if (remainingMs <= REFRESH_BUFFER_MS) {
    void refreshAccessToken()
  } else {
    scheduleTokenRefresh(remainingMs / 1000)
  }
}

// Menukar token yang masih berlaku dengan token baru. Dipakai baik oleh
// timer proaktif maupun sebagai fallback reaktif saat 401 (mis. laptop
// sleep membuat setTimeout meleset dari jadwal).
export function refreshAccessToken(): Promise<string | null> {
  if (refreshPromise) return refreshPromise

  const currentToken = useAuthStore.getState().token
  if (!currentToken) return Promise.resolve(null)

  refreshPromise = axios
    .post('/api/v1/auth/refresh-token', { refresh_token: currentToken })
    .then((res) => {
      const { access_token, expires_in } = res.data.data
      useAuthStore.getState().setSession(access_token, expires_in)
      scheduleTokenRefresh(expires_in)
      return access_token as string
    })
    .catch(() => {
      clearTokenRefreshTimer()
      useAuthStore.getState().logout()
      return null
    })
    .finally(() => {
      refreshPromise = null
    })

  return refreshPromise
}
