import { useCallback } from 'react';
import { useAuthStore } from '@/stores/authStore';

/**
 * Hook untuk memeriksa role & permission pengguna yang sedang login.
 * Super-admin otomatis memiliki seluruh permission dari backend.
 */
export function usePermission() {
  const user = useAuthStore((s) => s.user);

  const permissions = user?.permissions ?? [];
  const roles = user?.roles ?? [];
  const isSuperAdmin = roles.includes('super-admin');

  const can = useCallback(
    (permission?: string | string[]) => {
      if (!permission) return true;
      if (isSuperAdmin) return true;
      const list = Array.isArray(permission) ? permission : [permission];
      return list.some((p) => permissions.includes(p));
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [permissions, isSuperAdmin],
  );

  const hasRole = useCallback(
    (...wanted: string[]) => wanted.some((r) => roles.includes(r)),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [roles],
  );

  return { can, hasRole, isSuperAdmin, permissions, roles };
}
