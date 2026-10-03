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
  GitPullRequestArrow,
  Trophy,
  HeartHandshake,
  Bus,
  MoonStar,
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
import { activityMenu } from '@/routes/domains/activity';
import { bkMenu } from '@/routes/domains/bk';
import { workflowMenu } from '@/routes/domains/workflow';
import { assetMenu } from '@/routes/domains/asset';
import { transportationMenu } from '@/routes/domains/transportation';
import { religiousMenu } from '@/routes/domains/religious';
import { competitionMenu } from '@/routes/domains/competitions';

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
  {
    label: 'Transportasi',
    icon: Bus,
    children: transportationMenu,
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
  /*
   * Workflow & Approval Engine — infrastruktur lintas modul.
   *
   * Menu ini bukan milik satu modul: ia tempat semua persetujuan berkumpul
   * (pengadaan, cuti, pengeluaran, dokumen). Ditaruh sebelum menu modul agar
   * approver menjadikannya pintu masuk harian.
   */
  {
    label: 'Workflow & Persetujuan',
    icon: GitPullRequestArrow,
    permission: 'workflow.view',
    children: workflowMenu,
  },
  { label: 'Jelajahi kompetisi', icon: Trophy, to: '/competitions/discover', permission: 'registration.create' },
  { label: 'Kompetisi Saya', icon: Trophy, to: '/my-competitions', permission: 'competition.portal.student' },
  { label: 'Kompetisi Anak', icon: Trophy, to: '/competitions/parent', permission: 'competition.portal.parent' },
  {
    label: 'Kompetisi',
    icon: Trophy,
    permission: 'competition.view',
    children: competitionMenu,
  },
  /*
   * Student Activity & Extracurricular — pengembangan diri siswa.
   *
   * Menu ini menampung seluruh kegiatan non-akademik: katalog, aktivitas yang
   * diikuti, ruang kerja pembina, portal orang tua, dan verifikasi sertifikat.
   */
  {
    label: 'Aktivitas Siswa',
    icon: Trophy,
    permission: 'activity.view',
    children: activityMenu,
  },
  /*
   * Bimbingan & Konseling — dukungan siswa.
   *
   * Menu ini untuk staf BK (dasbor, referral, kasus, janji temu, laporan) dan
   * portal siswa/orang tua. Catatan konseling tidak pernah muncul di sini bagi
   * guru/siswa/wali: izinnya tidak mereka pegang.
   */
  {
    label: 'Bimbingan & Konseling',
    icon: HeartHandshake,
    permission: 'bk.view',
    children: bkMenu,
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
    label: 'Religius & Masjid',
    icon: MoonStar,
    permission: 'religious.view',
    children: religiousMenu,
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
    label: 'Aset & Sarpras',
    icon: Package,
    permission: 'asset.view',
    children: assetMenu,
  },
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
