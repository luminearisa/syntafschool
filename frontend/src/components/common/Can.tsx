import type { ReactNode } from 'react';
import { usePermission } from '@/hooks/usePermission';

/**
 * Render children hanya jika pengguna punya permission tertentu.
 * Contoh: <Can perm="user.create"><Button>... </Button></Can>
 */
export function Can({
  perm,
  children,
  fallback = null,
}: {
  perm: string | string[];
  children: ReactNode;
  fallback?: ReactNode;
}) {
  const { can } = usePermission();
  return <>{can(perm) ? children : fallback}</>;
}
