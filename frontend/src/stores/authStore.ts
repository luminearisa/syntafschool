import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import type { User } from '@/types';

interface AuthState {
  token: string | null;
  user: User | null;
  setAuth: (token: string, user: User) => void;
  setUser: (user: User) => void;
  logout: () => void;
  hasRole: (...roles: string[]) => boolean;
  can: (permission: string) => boolean;
}

export const useAuthStore = create<AuthState>()(
  persist(
    (set, get) => ({
      token: null,
      user: null,
      setAuth: (token, user) => set({ token, user }),
      setUser: (user) => set({ user }),
      logout: () => set({ token: null, user: null }),
      hasRole: (...roles) => {
        const user = get().user;
        if (!user?.roles) return false;
        return roles.some((role) => user.roles!.includes(role));
      },
      can: (permission) => {
        const user = get().user;
        if (!user) return false;
        // Super-admin selalu memiliki seluruh izin — konsisten dengan
        // `usePermission().can()`. Pemeriksaan ini penting karena halaman
        // modul (mis. Laundry) membaca izin dari store: bila snapshot izin
        // di klien sudah usang (diambil saat login sebelum modul/izin baru
        // di-seed), tombol create/aksi akan hilang tanpa ini.
        if (user.roles?.includes('super-admin')) return true;
        if (!user.permissions) return false;
        return user.permissions.includes(permission);
      },
    }),
    { name: 'syntaf-auth' },
  ),
);
