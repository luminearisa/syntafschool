import type { RouteObject } from 'react-router-dom';
import type { MenuChild } from '@/constants/menu';
import { PermissionGuard } from '@/routes/PermissionGuard';
import { StudentAttendancePage } from '@/features/attendance/pages/StudentAttendancePage';
import { AttendanceRecapPage } from '@/features/attendance/pages/AttendanceRecapPage';
import { AttendanceCorrectionsPage } from '@/features/attendance/pages/AttendanceCorrectionsPage';
import { EmployeeAttendancePage } from '@/features/attendance/pages/EmployeeAttendancePage';

/**
 * A6 — Presensi siswa, rekap presensi, presensi pegawai, dan koreksi.
 *
 * File ini di-glob otomatis oleh `src/routes/domains/index.ts`, jadi tidak
 * perlu menyunting `routes/index.tsx` maupun `constants/menu.ts`.
 */
export const domainRoutes: RouteObject[] = [
  {
    path: 'academics/attendance/students',
    element: (
      <PermissionGuard permission="academic.view">
        <StudentAttendancePage />
      </PermissionGuard>
    ),
  },
  {
    path: 'academics/attendance/recap',
    element: (
      <PermissionGuard permission="academic.view">
        <AttendanceRecapPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'academics/attendance/corrections',
    element: (
      <PermissionGuard permission="academic.view">
        <AttendanceCorrectionsPage />
      </PermissionGuard>
    ),
  },
  {
    path: 'academics/attendance/employees',
    element: (
      <PermissionGuard permission="academic.view">
        <EmployeeAttendancePage />
      </PermissionGuard>
    ),
  },
  {
    // Alias untuk menu Kepegawaian; memakai layar dan data yang sama.
    path: 'employees/attendance',
    element: (
      <PermissionGuard permission="employee.view">
        <EmployeeAttendancePage />
      </PermissionGuard>
    ),
  },
];

export const domainMenu: MenuChild[] = [
  {
    label: 'Presensi Siswa',
    to: '/academics/attendance/students',
    permission: 'academic.view',
  },
  {
    label: 'Rekap Presensi',
    to: '/academics/attendance/recap',
    permission: 'academic.view',
  },
  {
    label: 'Koreksi Presensi',
    to: '/academics/attendance/corrections',
    permission: 'academic.view',
  },
  {
    label: 'Presensi Pegawai',
    to: '/academics/attendance/employees',
    permission: 'academic.view',
  },
];
