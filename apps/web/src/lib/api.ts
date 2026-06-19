import axios, { AxiosError } from 'axios'
import type { ApiError } from '@/types'

export const api = axios.create({
  baseURL: '/api/v1',
  withCredentials: true, // Sanctum cookie
  headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
})

// Request interceptor — CSRF token dari meta tag jika ada
api.interceptors.request.use((config) => {
  const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
  if (token) config.headers['X-CSRF-TOKEN'] = token
  return config
})

// Response interceptor — handle 401 (redirect ke login)
api.interceptors.response.use(
  (res) => res,
  (error: AxiosError<ApiError>) => {
    if (error.response?.status === 401) {
      window.location.href = '/login'
    }
    return Promise.reject(error)
  }
)

// Helper: ekstrak pesan error dari response API
export function getApiErrorMessage(error: unknown): string {
  if (axios.isAxiosError(error)) {
    const data = error.response?.data as ApiError | undefined
    return data?.error?.message ?? error.message
  }
  return 'Terjadi kesalahan. Silakan coba lagi.'
}

// Helper: ekstrak field errors dari response validasi (422)
export function getFieldErrors(error: unknown): Record<string, string> {
  if (axios.isAxiosError(error)) {
    const data = error.response?.data as ApiError | undefined
    const errors = data?.error?.errors ?? {}
    return Object.fromEntries(
      Object.entries(errors).map(([k, v]) => [k, Array.isArray(v) ? v[0] : v])
    )
  }
  return {}
}
