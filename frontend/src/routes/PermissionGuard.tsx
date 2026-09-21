import type { ReactNode } from 'react';
import { usePermission } from '@/hooks/usePermission';
import ForbiddenPage from '@/features/common/pages/ForbiddenPage';

/**
 * Membatasi akses halaman berdasarkan permission.
 * Jika kosong, halaman bisa diakses semua pengguna terautentikasi.
 */
export function PermissionGuard({
  permission,
  children,
}: {
  permission?: string | string[];
  children: ReactNode;
}) {
  const { can } = usePermission();

  if (permission && !can(permission)) {
    return <ForbiddenPage />;
  }

  return <>{children}</>;
}
