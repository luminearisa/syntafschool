import type { ReactNode } from 'react';
import { useAuthStore } from '@/stores/authStore';
import ForbiddenPage from '@/features/common/pages/ForbiddenPage';

export function RoleGuard({
  roles,
  children,
}: {
  roles: string[];
  children: ReactNode;
}) {
  const user = useAuthStore((s) => s.user);
  if (!user || !roles.some((r) => user.roles?.includes(r))) {
    return <ForbiddenPage />;
  }
  return <>{children}</>;
}
