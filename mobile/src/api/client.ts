import axios, { AxiosError } from 'axios';
import { useAuth } from '@/store/auth';

const baseURL = process.env.EXPO_PUBLIC_API_URL ?? 'http://localhost:8001/api/v1';

export const api = axios.create({
  baseURL,
  timeout: 20000,
  headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
});

api.interceptors.request.use((config) => {
  const token = useAuth.getState().token;
  if (token) config.headers.Authorization = `Bearer ${token}`;
  return config;
});

api.interceptors.response.use(
  (res) => res,
  (error: AxiosError<{ message?: string }>) => {
    if (error.response?.status === 401 && useAuth.getState().token) {
      useAuth.getState().clear();
    }
    return Promise.reject(error);
  },
);

export function errorMessage(error: unknown): string {
  const e = error as AxiosError<{ message?: string; errors?: Record<string, string[]> }>;
  if (!e.response) return 'Tidak dapat terhubung ke server. Periksa koneksi internet Anda.';
  const first = e.response.data?.errors ? Object.values(e.response.data.errors)[0]?.[0] : undefined;
  return first ?? e.response.data?.message ?? 'Terjadi kesalahan. Coba lagi.';
}

/** Ambil `data` dari amplop `{ success, message, data }`. */
export async function get<T>(url: string, params?: Record<string, unknown>): Promise<T> {
  const res = await api.get<{ data: T }>(url, { params });
  return res.data.data;
}

export async function post<T>(url: string, body?: unknown): Promise<T> {
  const res = await api.post<{ data: T }>(url, body);
  return res.data.data;
}
