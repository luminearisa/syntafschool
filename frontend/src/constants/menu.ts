import type { LucideIcon } from 'lucide-react';
import {
  LayoutDashboard,
  Database,
  Users,
  GraduationCap,
  Wallet,
  BookOpen,
  Package,
  FileBarChart,
  ClipboardList,
  BookOpenCheck,
  MonitorCheck,
  Home,
  Settings,
  Stethoscope,
} from 'lucide-react';
import { MASTER_GROUPS, getResourcesByGroup } from './masterData';
import { domainMenu } from '@/routes/domains';
import { ppdbMenu } from '@/routes/domains/ppdb';
import { tahfizMenu } from '@/routes/domains/tahfiz';
import { libraryMenu } from '@/routes/domains/library';
import { boardingMenu } from '@/routes/domains/boarding';
import { financeMenu } from '@/routes/domains/finance';
import { assessmentsMenu } from '@/routes/domains/assessments';
import { lmsMenu } from '@/routes/domains/lms';
import { cbtMenu } from '@/routes/domains/cbt';
import { clinicMenu } from '@/routes/domains/clinic';

export interface MenuChild {
  label: string;
  to: string;
  permission?: string | string[];
}

export interface MenuItem {
  label: string;
  icon: LucideIcon;
  to?: string;
  permission?: string | string[];
  children?: MenuChild[];
}

/** Menu master data dikelompokkan (butuh permission master-data.view) */
const masterChildren: MenuChild[] = MASTER_GROUPS.flatMap((g) =>
  getResourcesByGroup(g.key).map((r) => ({
    label: r.labelPlural,
    to: `/master/${r.key}`,
    permission: 'master-data.view',
  })),
);

export const MENU_ITEMS: MenuItem[] = [
  { label: 'Dashboard', icon: LayoutDashboard, to: '/' },
  {
    label: 'Master Data',
    icon: Database,
    permission: 'master-data.view',
    children: masterChildren,
  },
  {
    label: 'Akademik',
    icon: GraduationCap,
    permission: 'academic.view',
    children: [
      { label: 'Periode Akademik', to: '/academics/periods', permission: 'academic.view' },
      { label: 'Kelas & Rombel', to: '/academics/classes', permission: 'academic.view' },
      { label: 'Jadwal Pelajaran', to: '/academics/schedules', permission: 'academic.view' },
      ...domainMenu,
    ],
  },
  ...ppdbMenu,
  {
    label: 'Kesiswaan',
    icon: Users,
    permission: 'student.view',
    children: [
      { label: 'Data Siswa', to: '/students', permission: 'student.view' },
      { label: 'Wali Murid', to: '/students/guardians', permission: 'student.view' },
    ],
  },
  {
    label: 'Kepegawaian',
    icon: ClipboardList,
    permission: 'employee.view',
    children: [
      { label: 'Data Pegawai', to: '/employees', permission: 'employee.view' },
      { label: 'Absensi Pegawai', to: '/employees/attendance', permission: 'employee.view' },
    ],
  },
  {
    label: 'Keuangan',
    icon: Wallet,
    permission: ['finance.view', 'wallet.view'],
    children: financeMenu,
  },
  {
    label: 'Penilaian',
    icon: FileBarChart,
    permission: 'assessment.view',
    children: assessmentsMenu,
  },
  {
    label: 'CBT / Ujian',
    icon: MonitorCheck,
    permission: 'cbt.view',
    children: cbtMenu,
  },
  {
    // Modul Clinic / UKS: ruang kerja klinis (antrean, triase, resep, pemulangan)
    // dengan Inventaris sebagai satu-satunya sumber stok obat. Entri portal
    // dipakai orang tua/siswa; cakupan barisnya dibatasi di server.
    label: 'Clinic / UKS',
    icon: Stethoscope,
    permission: 'clinic.view',
    children: clinicMenu,
  },
  {
    // LMS punya permukaan publik (/learn) dan ruang belajar terautentikasi.
    // Entri "LMS Publik" sengaja tanpa permission agar terlihat oleh semua peran.
    label: 'LMS / Ruang Belajar',
    icon: BookOpenCheck,
    permission: 'lms.view',
    children: lmsMenu,
  },
  {
    label: "Tahfiz / Al-Qur'an",
    icon: BookOpenCheck,
    permission: 'tahfiz.view',
    children: tahfizMenu,
  },
  {
    label: 'Boarding / Pesantren',
    icon: Home,
    permission: 'boarding.view',
    children: boardingMenu,
  },
  {
    label: 'Perpustakaan',
    icon: BookOpen,
    permission: 'library.view',
    children: libraryMenu,
  },
  { label: 'Inventaris', icon: Package, to: '/inventory', permission: 'inventory.view' },
  {
    label: 'Pengaturan',
    icon: Settings,
    permission: 'setting.view',
    children: [
      { label: 'Pengguna', to: '/settings/users', permission: 'user.view' },
      { label: 'Role & Hak Akses', to: '/settings/roles', permission: 'user.view' },
      { label: 'Pengaturan Umum', to: '/settings/general', permission: 'setting.view' },
    ],
  },
];
