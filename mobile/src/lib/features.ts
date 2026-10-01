import type React from 'react';
import type { Ionicons } from '@expo/vector-icons';
import type { RoleType } from '@/api/types';

type IconName = React.ComponentProps<typeof Ionicons>['name'];

export type Category = 'Belajar' | 'Keuangan' | 'Kesiswaan' | 'Pekerjaan' | 'Kesehatan & Konseling' | 'Sarana';

export interface Feature {
  key: string;
  title: string;
  hint: string;
  icon: IconName;
  tint: string;
  category: Category;
  roles: RoleType[];
  /** Rute aplikasi; tanpa rute = belum tersedia ("Segera"). */
  route?: string;
  /** Fase rencana (docs/23-MOBILE-COVERAGE.md) untuk fitur yang belum jadi. */
  phase?: 1 | 2 | 3 | 4;
  /** Fitur tampil bila peran aktif memiliki salah satu izin ini (kosong = selalu tampil). */
  perms?: string[];
}

const g: RoleType[] = ['guardian'];
const s: RoleType[] = ['student'];
const e: RoleType[] = ['employee'];
const gs: RoleType[] = ['guardian', 'student'];
const all: RoleType[] = ['guardian', 'student', 'employee'];

/**
 * Registry fitur: satu-satunya tempat yang menentukan menu per peran.
 * Modul baru cukup menambah satu entri di sini + rutenya di `src/app/(app)/`.
 */
export const FEATURES: Feature[] = [
  // ---- Tersedia ----
  { key: 'akademik', title: 'Akademik', hint: 'Absensi, nilai, jadwal, rapor', icon: 'book', tint: '#116149', category: 'Belajar', roles: gs, route: '/akademik' },
  { key: 'keuangan', title: 'Keuangan', hint: 'Tagihan & pembayaran', icon: 'wallet', tint: '#B8892B', category: 'Keuangan', roles: g, route: '/keuangan' },
  { key: 'presensi', title: 'Presensi', hint: 'Check-in & riwayat', icon: 'finger-print', tint: '#116149', category: 'Pekerjaan', roles: e, route: '/presensi' },

  // ---- Fase 1: karyawan ----
  { key: 'persetujuan', title: 'Persetujuan', hint: 'Approval & pengajuan', icon: 'checkmark-done-circle', tint: '#2F6FB2', category: 'Pekerjaan', roles: e, route: '/persetujuan', perms: ['workflow.approval.view', 'workflow.request.view'] },
  { key: 'jurnal', title: 'Jurnal & RPP', hint: 'Jurnal mengajar, RPP', icon: 'create', tint: '#116149', category: 'Pekerjaan', roles: e, route: '/jurnal', perms: ['academic.attendance', 'academic.approve'] },
  { key: 'absen-siswa', title: 'Absensi Siswa', hint: 'Input kehadiran kelas', icon: 'people', tint: '#B8892B', category: 'Pekerjaan', roles: e, route: '/absen-siswa', perms: ['academic.attendance'] },
  { key: 'nilai', title: 'Input Nilai', hint: 'Penilaian & rapor', icon: 'ribbon', tint: '#8E4EC6', category: 'Pekerjaan', roles: e, route: '/nilai', perms: ['academic.grade'] },
  { key: 'tugas-guru', title: 'Tugas', hint: 'Buat & nilai tugas', icon: 'document-text', tint: '#2F6FB2', category: 'Pekerjaan', roles: e, route: '/tugas-guru', perms: ['academic.grade'] },
  { key: 'aset', title: 'Aset & Sarpras', hint: 'Booking fasilitas, lapor rusak', icon: 'construct', tint: '#C8423B', category: 'Sarana', roles: e, route: '/aset', perms: ['asset.maintenance.request', 'facility.book'] },
  { key: 'supervisi', title: 'Supervisi', hint: 'Kunjungan & tindak lanjut', icon: 'eye', tint: '#116149', category: 'Pekerjaan', roles: e, phase: 1 },

  // ---- Fase 2: wali ----
  { key: 'asrama', title: 'Asrama', hint: 'Poin, izin, kepulangan', icon: 'bed', tint: '#2F6FB2', category: 'Kesiswaan', roles: g, phase: 2 },
  { key: 'tahfiz', title: 'Tahfiz', hint: 'Setoran & capaian', icon: 'moon', tint: '#116149', category: 'Belajar', roles: [...g, ...s, ...e], phase: 2 },
  { key: 'aktivitas', title: 'Ekstrakurikuler', hint: 'Kegiatan, prestasi, izin', icon: 'trophy', tint: '#B8892B', category: 'Kesiswaan', roles: all, phase: 2 },
  { key: 'kesehatan', title: 'Kesehatan', hint: 'Klinik & UKS', icon: 'medkit', tint: '#C8423B', category: 'Kesehatan & Konseling', roles: all, phase: 2 },
  { key: 'bk', title: 'Konseling', hint: 'Bimbingan & konseling', icon: 'chatbubbles', tint: '#8E4EC6', category: 'Kesehatan & Konseling', roles: all, phase: 2 },
  { key: 'dompet', title: 'Dompet', hint: 'Saldo & top-up', icon: 'card', tint: '#B8892B', category: 'Keuangan', roles: [...g, ...s], phase: 2 },

  // ---- Fase 3: siswa ----
  { key: 'lms', title: 'Kelas Digital', hint: 'Materi, kuis, forum', icon: 'laptop', tint: '#2F6FB2', category: 'Belajar', roles: [...s, ...g], phase: 3 },
  { key: 'tugas', title: 'Tugas', hint: 'Kerjakan & kumpulkan', icon: 'document-text', tint: '#2F6FB2', category: 'Belajar', roles: s, phase: 3 },
  { key: 'perpus', title: 'Perpustakaan', hint: 'Katalog, pinjaman, kartu', icon: 'library', tint: '#116149', category: 'Belajar', roles: [...s, ...e], phase: 3 },

  // ---- Fase 4 ----
  { key: 'cbt', title: 'Ujian', hint: 'Ujian berbasis komputer', icon: 'school', tint: '#C8423B', category: 'Belajar', roles: [...s, ...e], phase: 4 },
];

/**
 * Fitur untuk peran aktif. `permissions` kosong (sesi lama yang belum menyegarkan izin)
 * berarti jangan menyembunyikan apa pun — server tetap menolak yang tidak berhak.
 */
export const featuresFor = (role: RoleType | null, permissions: string[] = []): Feature[] =>
  role
    ? FEATURES.filter((f) => f.roles.includes(role) && (permissions.length === 0 || !f.perms || f.perms.some((p) => permissions.includes(p))))
    : [];

export const CATEGORY_ORDER: Category[] = ['Belajar', 'Pekerjaan', 'Keuangan', 'Kesiswaan', 'Kesehatan & Konseling', 'Sarana'];
