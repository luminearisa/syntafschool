import { create } from 'zustand';
import { createJSONStorage, persist } from 'zustand/middleware';
import { secureStorage } from '@/lib/storage';
import type { AuthPayload, RoleContext, RoleType, SessionUser } from '@/api/types';

interface AuthState {
  token: string | null;
  user: SessionUser | null;
  contexts: RoleContext[];
  active: RoleType | null;
  hydrated: boolean;
  selectedChildId: number | null;
  setSession: (p: AuthPayload) => void;
  selectChild: (id: number | null) => void;
  clear: () => void;
}

export const useAuth = create<AuthState>()(
  persist(
    (set) => ({
      token: null,
      user: null,
      contexts: [],
      active: null,
      hydrated: false,
      selectedChildId: null,
      setSession: (p) =>
        set({ token: p.token, user: p.user, contexts: p.contexts, active: p.active_context, selectedChildId: null }),
      selectChild: (id) => set({ selectedChildId: id }),
      clear: () => set({ token: null, user: null, contexts: [], active: null, selectedChildId: null }),
    }),
    {
      name: 'syntaf-session',
      storage: createJSONStorage(() => secureStorage),
      partialize: (s) => ({ token: s.token, user: s.user, contexts: s.contexts, active: s.active }),
      onRehydrateStorage: () => () => useAuth.setState({ hydrated: true }),
    },
  ),
);
