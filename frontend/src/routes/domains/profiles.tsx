import type { RouteObject } from 'react-router-dom';
import { PermissionGuard } from '@/routes/PermissionGuard';
import { StudentProfilePage } from '@/features/profile/pages/StudentProfilePage';
import { GuardianProfilePage } from '@/features/profile/pages/GuardianProfilePage';
import { EmployeeProfilePage } from '@/features/profile/pages/EmployeeProfilePage';

/**
 * Profil 360° (siswa, wali, pegawai). Izin dasar sama dengan daftar masing-masing;
 * izin per bagian ditegakkan server dan menentukan tab yang tampil.
 */
export const domainRoutes: RouteObject[] = [
  {
    path: 'students/:studentId',
    element: (
      <PermissionGuard permission="student.view">
        <StudentProfilePage />
      </PermissionGuard>
    ),
  },
  {
    path: 'students/guardians/:guardianId',
    element: (
      <PermissionGuard permission="student.view">
        <GuardianProfilePage />
      </PermissionGuard>
    ),
  },
  {
    path: 'employees/:employeeId',
    element: (
      <PermissionGuard permission="employee.view">
        <EmployeeProfilePage />
      </PermissionGuard>
    ),
  },
];
