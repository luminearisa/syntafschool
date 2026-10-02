import { create } from 'zustand';
import { createJSONStorage, persist } from 'zustand/middleware';
import { secureStorage } from '@/lib/storage';
import type { AuthPayload, RoleContext, RoleType, SessionUser } from '@/api/types';

interface AuthState {
  token: string | null;
  user: SessionUser | null;
  contexts: RoleContext[];
  active: RoleType | null;
  permissions: string[];
  hydrated: boolean;
  selectedChildId: number | null;
  setSession: (p: AuthPayload) => void;
  selectChild: (id: number | null) => void;
  setPermissions: (p: string[]) => void;
  clear: () => void;
  can: (...perms: string[]) => boolean;
}

export const useAuth = create<AuthState>()(
  persist(
    (set, get) => ({
      token: null,
      user: null,
      contexts: [],
      active: null,
      permissions: [],
      hydrated: false,
      selectedChildId: null,
      setSession: (p) =>
        set({ token: p.token, user: p.user, contexts: p.contexts, active: p.active_context, permissions: p.permissions ?? [], selectedChildId: null }),
      selectChild: (id) => set({ selectedChildId: id }),
      setPermissions: (p) => set({ permissions: p }),
      clear: () => set({ token: null, user: null, contexts: [], active: null, permissions: [], selectedChildId: null }),
      can: (...perms) => perms.some((p) => get().permissions.includes(p)),
    }),
    {
      name: 'syntaf-session',
      storage: createJSONStorage(() => secureStorage),
      partialize: (s) => ({ token: s.token, user: s.user, contexts: s.contexts, active: s.active, permissions: s.permissions }),
      onRehydrateStorage: () => () => useAuth.setState({ hydrated: true }),
    },
  ),
);
