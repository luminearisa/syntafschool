import axios, { AxiosError } from 'axios';
import { useAuthStore } from '@/stores/authStore';
import type { ApiError } from '@/types';

/**
 * Instance HTTP khusus PPDB.
 *
 * Memakai token Sanctum yang sama dengan aplikasi admin (`authStore`), tetapi
 * TIDAK ikut melempar pengguna ke `/login` admin saat sesi berakhir: portal
 * orang tua punya halaman login sendiri di `/ppdb/login`.
 */
export const ppdbHttp = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL,
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
});

ppdbHttp.interceptors.request.use((config) => {
  const token = useAuthStore.getState().token;
  if (token) config.headers.Authorization = `Bearer ${token}`;
  return config;
});

ppdbHttp.interceptors.response.use(
  (res) => res,
  (error: AxiosError<ApiError>) => {
    if (error.response?.status === 401 && window.location.pathname.startsWith('/ppdb/')) {
      useAuthStore.getState().logout();
      const target = window.location.pathname;
      if (target !== '/ppdb/login' && target !== '/ppdb/register') {
        window.location.href = `/ppdb/login?next=${encodeURIComponent(target)}`;
      }
    }
    return Promise.reject(error);
  },
);

/** Buang parameter kosong agar aturan `nullable` backend tidak ditipu `''`. */
export function ppdbParams<T extends object>(
  params?: T,
): Record<string, string | number> | undefined {
  if (!params) return undefined;

  const out: Record<string, string | number> = {};
  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === null || value === '') continue;
    out[key] = typeof value === 'boolean' ? (value ? 1 : 0) : (value as string | number);
  }
  return out;
}

/** Ambil nama berkas dari `Content-Disposition`, dengan fallback. */
export function filenameFromResponse(
  disposition: string | undefined,
  fallback: string,
): string {
  const match = /filename="?([^";]+)"?/i.exec(disposition ?? '');
  return match?.[1] ?? fallback;
}

export function downloadBlob(data: Blob, filename: string): void {
  const url = window.URL.createObjectURL(data);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  window.URL.revokeObjectURL(url);
}

export { getErrorMessage, getValidationErrors } from '@/lib/axios';
