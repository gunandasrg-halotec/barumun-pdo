import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type {
  ExpenseCategory, ExpenseSubcategory, ExpenseItem, Vehicle, VehicleTripLog,
  ApiResponse, PaginatedResponse, AuthUser, Role,
} from '@/types'

type PayrollComponentOption = {
  component_key: string
  label: string
}

export type PayrollComponentOptionsQuery = {
  filter?: 'blocks' | 'roles'
  estateExternalId?: string | null
  q?: string
  limit?: number
}

type PayrollComponentOptionsResponse = {
  component: string
  options: PayrollComponentOption[]
}

export async function fetchPayrollComponentOptions(
  component: string,
  options?: PayrollComponentOptionsQuery,
) {
  const res = await api.get<ApiResponse<PayrollComponentOptionsResponse>>('/payroll-cost-component-options', {
    params: {
      component,
      filter: options?.filter,
      estate_external_id: options?.estateExternalId ?? undefined,
      q: options?.q ?? undefined,
      limit: options?.limit ?? undefined,
    },
  })
  return res.data.data
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
  options?: PayrollComponentOptionsQuery,
) {
  return useQuery({
    queryKey: ['payroll-component-options', component, options?.filter ?? null, options?.estateExternalId ?? null],
    queryFn: async () => fetchPayrollComponentOptions(component!, options),
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

// ─── Vehicles ─────────────────────────────────────────────────────────────────

export function useVehicles(params?: Record<string, unknown>) {
  return useQuery({
    queryKey: ['vehicles', params],
    queryFn: async () => {
      const res = await api.get<ApiResponse<Vehicle[]>>('/vehicles', { params })
      return res.data.data
    },
    staleTime: 5 * 60 * 1000,
  })
}

export function useVehicle(id: string) {
  return useQuery({
    queryKey: ['vehicles', id],
    queryFn: async () => {
      const res = await api.get<ApiResponse<Vehicle>>(`/vehicles/${id}`)
      return res.data.data
    },
    enabled: !!id,
  })
}

export function useCreateVehicle() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (data: Record<string, unknown>) => {
      const res = await api.post<ApiResponse<Vehicle>>('/vehicles', data)
      return res.data.data
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['vehicles'] }),
  })
}

export function useUpdateVehicle(id: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (data: Record<string, unknown>) => {
      const res = await api.put<ApiResponse<Vehicle>>(`/vehicles/${id}`, data)
      return res.data.data
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['vehicles'] }),
  })
}

export function useDeleteVehicle(id: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: () => api.delete(`/vehicles/${id}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['vehicles'] }),
  })
}

// ─── Vehicle Trip Logs ────────────────────────────────────────────────────────

export function useVehicleTripLogs(pdoHeaderId?: string) {
  return useQuery({
    queryKey: ['vehicle-trip-logs', pdoHeaderId],
    queryFn: async () => {
      const res = await api.get<ApiResponse<VehicleTripLog[]>>('/vehicle-trip-logs', {
        params: { pdo_header_id: pdoHeaderId },
      })
      return res.data.data
    },
    enabled: !!pdoHeaderId,
  })
}

export function useCreateVehicleTripLog() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (data: Record<string, unknown>) => {
      const res = await api.post<ApiResponse<VehicleTripLog>>('/vehicle-trip-logs', data)
      return res.data.data
    },
    onSuccess: (_data, variables) => {
      qc.invalidateQueries({ queryKey: ['vehicle-trip-logs', variables.pdo_header_id] })
    },
  })
}

export function useDeleteVehicleTripLog() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, pdoHeaderId }: { id: string; pdoHeaderId: string }) => {
      await api.delete(`/vehicle-trip-logs/${id}`)
      return pdoHeaderId
    },
    onSuccess: (pdoHeaderId) => {
      qc.invalidateQueries({ queryKey: ['vehicle-trip-logs', pdoHeaderId] })
    },
  })
}
