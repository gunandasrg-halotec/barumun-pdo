import { create } from 'zustand'
import { persist } from 'zustand/middleware'
import type { AuthUser } from '@/types'

interface AuthState {
  user: AuthUser | null
  token: string | null
  expiresAt: number | null
  setUser: (user: AuthUser | null) => void
  setToken: (token: string | null) => void
  setSession: (token: string, expiresInSeconds: number) => void
  logout: () => void
}

export const useAuthStore = create<AuthState>()(
  persist(
    (set) => ({
      user: null,
      token: null,
      expiresAt: null,
      setUser: (user) => set({ user }),
      setToken: (token) => set({ token }),
      setSession: (token, expiresInSeconds) =>
        set({ token, expiresAt: Date.now() + expiresInSeconds * 1000 }),
      logout: () => set({ user: null, token: null, expiresAt: null }),
    }),
    { name: 'pdo-auth' }
  )
)
