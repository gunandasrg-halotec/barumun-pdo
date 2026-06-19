import { useMutation, useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { useAuthStore } from '@/store/auth.store'
import type { AuthUser, ApiResponse } from '@/types'

export function useMe() {
  const setUser = useAuthStore((s) => s.setUser)

  return useQuery({
    queryKey: ['auth', 'me'],
    queryFn: async () => {
      const res = await api.get<ApiResponse<AuthUser>>('/auth/me')
      setUser(res.data.data)
      return res.data.data
    },
    retry: false,
    staleTime: 5 * 60 * 1000,
  })
}

export function useLogin() {
  const setUser  = useAuthStore((s) => s.setUser)
  const setToken = useAuthStore((s) => s.setToken)

  return useMutation({
    mutationFn: async (data: { email: string; password: string }) => {
      const res = await api.post<ApiResponse<{ user: AuthUser; access_token: string }>>('/auth/login', data)
      return res.data.data
    },
    onSuccess: (data) => {
      setToken(data.access_token)
      setUser(data.user)
    },
  })
}
