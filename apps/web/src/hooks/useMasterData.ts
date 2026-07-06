import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type {
  ExpenseCategory, ExpenseSubcategory, ExpenseItem,
  ApiResponse, PaginatedResponse, AuthUser, Role,
} from '@/types'

type PayrollComponentOption = {
  component_key: string
  label: string
}

type PayrollComponentOptionsResponse = {
  component: string
  options: PayrollComponentOption[]
}

// ─── Categories ──────────────────────────────────────────────────────────────

export function useCategories(params?: Record<string, unknown>) {
  return useQuery({
    queryKey: ['categories', params],
    queryFn: async () => {
      const res = await api.get<PaginatedResponse<ExpenseCategory>>('/expense-categories', { params })
      return res.data.data
    },
    staleTime: 5 * 60 * 1000,
  })
}

export function useCreateCategory() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (data: Record<string, unknown>) => {
      const res = await api.post<ApiResponse<ExpenseCategory>>('/expense-categories', data)
      return res.data.data
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['categories'] }),
  })
}

export function useUpdateCategory(id: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (data: Record<string, unknown>) => {
      const res = await api.put<ApiResponse<ExpenseCategory>>(`/expense-categories/${id}`, data)
      return res.data.data
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['categories'] }),
  })
}

export function useDeleteCategory(id: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: () => api.delete(`/expense-categories/${id}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['categories'] }),
  })
}

// ─── Subcategories ────────────────────────────────────────────────────────────

export function useSubcategories(params?: Record<string, unknown>) {
  return useQuery({
    queryKey: ['subcategories', params],
    queryFn: async () => {
      const res = await api.get<PaginatedResponse<ExpenseSubcategory>>('/expense-subcategories', { params })
      return res.data.data
    },
    staleTime: 5 * 60 * 1000,
  })
}

export function useCreateSubcategory() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (data: Record<string, unknown>) => {
      const res = await api.post<ApiResponse<ExpenseSubcategory>>('/expense-subcategories', data)
      return res.data.data
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['subcategories'] }),
  })
}

export function useUpdateSubcategory(id: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (data: Record<string, unknown>) => {
      const res = await api.put<ApiResponse<ExpenseSubcategory>>(`/expense-subcategories/${id}`, data)
      return res.data.data
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['subcategories'] }),
  })
}

// ─── Items ───────────────────────────────────────────────────────────────────

export function useItems(params?: Record<string, unknown>) {
  return useQuery({
    queryKey: ['items', params],
    queryFn: async () => {
      const res = await api.get<PaginatedResponse<ExpenseItem>>('/expense-items', { params })
      return res.data.data
    },
    staleTime: 5 * 60 * 1000,
  })
}

export function useRoutineItems() {
  return useQuery({
    queryKey: ['items', 'routine'],
    queryFn: async () => {
      const res = await api.get<ApiResponse<ExpenseItem[]>>('/expense-items/routine')
      return res.data.data
    },
    staleTime: 10 * 60 * 1000,
  })
}

export function useCreateItem() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (data: Record<string, unknown>) => {
      const res = await api.post<ApiResponse<ExpenseItem>>('/expense-items', data)
      return res.data.data
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['items'] }),
  })
}

export function usePayrollComponentOptions(
  component?: string | null,
  options?: { filter?: 'blocks'; estateExternalId?: string | null },
) {
  return useQuery({
    queryKey: ['payroll-component-options', component, options?.filter ?? null, options?.estateExternalId ?? null],
    queryFn: async () => {
      const res = await api.get<ApiResponse<PayrollComponentOptionsResponse>>('/payroll-cost-component-options', {
        params: {
          component,
          filter: options?.filter,
          estate_external_id: options?.estateExternalId ?? undefined,
        },
      })
      return res.data.data
    },
    enabled: Boolean(component) && (options?.filter !== 'blocks' || Boolean(options?.estateExternalId)),
    staleTime: 5 * 60 * 1000,
  })
}

export function useUpdateItem(id: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (data: Record<string, unknown>) => {
      const res = await api.put<ApiResponse<ExpenseItem>>(`/expense-items/${id}`, data)
      return res.data.data
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['items'] }),
  })
}

// ─── Users ───────────────────────────────────────────────────────────────────

export function useUsers(params?: Record<string, unknown>) {
  return useQuery({
    queryKey: ['users', params],
    queryFn: async () => {
      const res = await api.get<PaginatedResponse<AuthUser>>('/users', { params })
      return res.data.data
    },
  })
}

export function useRoles() {
  return useQuery({
    queryKey: ['roles'],
    queryFn: async () => {
      const res = await api.get<ApiResponse<Role[]>>('/roles')
      return res.data.data
    },
    staleTime: Infinity,
  })
}

export function useCreateUser() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (data: Record<string, unknown>) => {
      const res = await api.post<ApiResponse<AuthUser>>('/users', data)
      return res.data.data
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['users'] }),
  })
}

export function useUpdateUser(id: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (data: Record<string, unknown>) => {
      const res = await api.put<ApiResponse<AuthUser>>(`/users/${id}`, data)
      return res.data.data
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['users'] }),
  })
}

export function useDeleteUser(id: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: () => api.delete(`/users/${id}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['users'] }),
  })
}
