import axios, { AxiosError } from 'axios';
import { useAuthStore } from '@/stores/authStore';
import type { ApiError } from '@/types';

export const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL,
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
});

// Sisipkan Bearer token pada setiap request
api.interceptors.request.use((config) => {
  const token = useAuthStore.getState().token;
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// Tangani error global
api.interceptors.response.use(
  (res) => res,
  (error: AxiosError<ApiError>) => {
    const status = error.response?.status;
    if (status === 401) {
      const path = window.location.pathname;
      useAuthStore.getState().logout();
      if (path !== '/login') {
        window.location.href = '/login';
      }
    }
    return Promise.reject(error);
  },
);

/** Ambil pesan error yang ramah dari response API */
export function getErrorMessage(error: unknown): string {
  const err = error as AxiosError<ApiError>;
  if (err?.response?.data?.message) return err.response.data.message;
  if (err?.message) return err.message;
  return 'Terjadi kesalahan yang tidak diketahui';
}

/** Ambil map error validasi per-kolom */
export function getValidationErrors(
  error: unknown,
): Record<string, string[]> | undefined {
  const err = error as AxiosError<ApiError>;
  return err?.response?.data?.errors;
}
