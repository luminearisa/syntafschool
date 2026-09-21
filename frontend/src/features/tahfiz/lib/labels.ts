import type { SelectOption } from '@/constants/masterData';
import type {
  TahfizAchievementType,
  TahfizActivityType,
  TahfizAttendanceStatus,
  TahfizEnrollmentStatus,
  TahfizExamStatus,
  TahfizExamType,
  TahfizIndividualTarget,
  TahfizInterventionStatus,
  TahfizInterventionType,
  TahfizLevel,
  TahfizMemorizationStatus,
  TahfizMemorizationType,
  TahfizMutabaahCategory,
  TahfizMutabaahValueType,
  TahfizParticipantStatus,
  TahfizParticipantType,
  TahfizProgramStatus,
  TahfizProgressStatus,
  TahfizRegistrationStatus,
  TahfizRubricScope,
  TahfizSessionStatus,
  TahfizTargetPeriod,
  TahfizTargetUnit,
  TahfizTasmiResult,
  TahfizRevisionMethod,
  TahfizMistakeCategory,
  TahfizInstructorRole,
  TahfizSpecialization,
  TahfizDurationUnit,
} from '@/types/tahfiz';

type Tone = 'gray' | 'green' | 'red' | 'amber' | 'blue' | 'brand';

/** Ubah peta label menjadi opsi dropdown. */
export function toOptions<T extends string>(map: Record<T, string>): SelectOption[] {
  return (Object.keys(map) as T[]).map((key) => ({ value: key, label: map[key] }));
}

/* ============================ PESERTA ============================ */

export const PARTICIPANT_TYPE_LABELS: Record<TahfizParticipantType, string> = {
  student: 'Siswa',
  employee: 'Pegawai',
  guardian: 'Wali Murid',
  public: 'Umum',
  alumni: 'Alumni',
  other: 'Lainnya',
};

export const PARTICIPANT_STATUS_LABELS: Record<TahfizParticipantStatus, string> = {
  active: 'Aktif',
  inactive: 'Tidak Aktif',
  blacklisted: 'Blacklist',
};

export const GENDER_LABELS: Record<'male' | 'female', string> = {
  male: 'Laki-laki',
  female: 'Perempuan',
};

export const EXPERIENCE_LEVEL_LABELS: Record<string, string> = {
  beginner: 'Pemula',
  intermediate: 'Menengah',
  advanced: 'Mahir',
};

export const READING_ABILITY_LABELS: Record<string, string> = {
  not_yet: 'Belum Bisa',
  learning: 'Sedang Belajar',
  fluent: 'Lancar',
  tahsin_completed: 'Selesai Tahsin',
};

/* ============================ PROGRAM & LEVEL ============================ */

export const PROGRAM_STATUS_LABELS: Record<TahfizProgramStatus, string> = {
  draft: 'Draf',
  active: 'Aktif',
  archived: 'Diarsipkan',
};

export const DURATION_UNIT_LABELS: Record<TahfizDurationUnit, string> = {
  day: 'Hari',
  week: 'Minggu',
  month: 'Bulan',
  semester: 'Semester',
  year: 'Tahun',
};

export const TARGET_UNIT_LABELS: Record<TahfizTargetUnit, string> = {
  juz: 'Juz',
  surah: 'Surah',
  ayat: 'Ayat',
  page: 'Halaman',
  line: 'Baris',
};

/* ============================ RUBRIK ============================ */

export const RUBRIC_SCOPE_LABELS: Record<TahfizRubricScope, string> = {
  tahsin: 'Tahsin',
  tilawah: 'Tilawah',
  hafalan: 'Hafalan',
  placement: 'Placement',
  exam: 'Ujian',
  tasmi: "Tasmi'",
};

/* ============================ KESALAHAN ============================ */

export const MISTAKE_CATEGORY_LABELS: Record<TahfizMistakeCategory, string> = {
  memorization: 'Hafalan',
  pronunciation: 'Makhraj',
  tajwid: 'Tajwid',
  reading: 'Bacaan',
  other: 'Lainnya',
};

/* ============================ MUTABAAH ============================ */

export const MUTABAAH_CATEGORY_LABELS: Record<TahfizMutabaahCategory, string> = {
  quran: "Al-Qur'an",
  worship: 'Ibadah',
  activity: 'Aktivitas',
  other: 'Lainnya',
};

export const MUTABAAH_VALUE_TYPE_LABELS: Record<TahfizMutabaahValueType, string> = {
  boolean: 'Selesai / Belum',
  count: 'Jumlah',
};

/* ============================ INSTRUKTUR ============================ */

export const INSTRUCTOR_ROLE_LABELS: Record<TahfizInstructorRole, string> = {
  instructor: 'Instruktur',
  assistant: 'Asisten',
  musyrif: 'Musyrif',
  musyrifah: 'Musyrifah',
};

export const SPECIALIZATION_LABELS: Record<TahfizSpecialization, string> = {
  tahsin: 'Tahsin',
  tahfiz: 'Tahfiz',
  tilawah: 'Tilawah',
  'tasmi': "Tasmi'",
  murojaah: 'Murojaah',
};

/* ============================ ENROLLMENT ============================ */

export const ENROLLMENT_STATUS_LABELS: Record<TahfizEnrollmentStatus, string> = {
  draft: 'Draf',
  registered: 'Terdaftar',
  assessment: 'Penilaian',
  approved: 'Disetujui',
  active: 'Aktif',
  suspended: 'Ditangguhkan',
  completed: 'Selesai',
  withdrawn: 'Mengundurkan Diri',
  rejected: 'Ditolak',
};

/** Status yang butuh alasan saat transisi (aturan bisnis backend). */
export const ENROLLMENT_REASON_REQUIRED: TahfizEnrollmentStatus[] = [
  'suspended',
  'withdrawn',
  'rejected',
  'completed',
];

export function enrollmentStatusTone(status: TahfizEnrollmentStatus): Tone {
  switch (status) {
    case 'active':
      return 'green';
    case 'approved':
      return 'blue';
    case 'completed':
      return 'brand';
    case 'rejected':
    case 'withdrawn':
      return 'red';
    case 'suspended':
      return 'amber';
    default:
      return 'gray';
  }
}

/* ============================ HALAQAH / JADWAL / SESI ============================ */

export const HALAQAH_STATUS_LABELS: Record<string, string> = {
  active: 'Aktif',
  inactive: 'Tidak Aktif',
  merged: 'Digabung',
};

export const DAY_LABELS: Record<number, string> = {
  0: 'Ahad',
  1: 'Senin',
  2: 'Selasa',
  3: 'Rabu',
  4: 'Kamis',
  5: 'Jumat',
  6: 'Sabtu',
};

export const ACTIVITY_TYPE_LABELS: Record<TahfizActivityType, string> = {
  setoran: 'Setoran',
  murojaah: 'Murojaah',
  tahsin: 'Tahsin',
  tilawah: 'Tilawah',
  ujian: 'Ujian',
  mentoring: 'Mentoring',
  halaqah: 'Halaqah',
};

export const SESSION_STATUS_LABELS: Record<TahfizSessionStatus, string> = {
  planned: 'Direncanakan',
  ongoing: 'Berlangsung',
  done: 'Selesai',
  cancelled: 'Dibatalkan',
};

export function sessionStatusTone(status: TahfizSessionStatus): Tone {
  switch (status) {
    case 'done':
      return 'green';
    case 'ongoing':
      return 'blue';
    case 'cancelled':
      return 'red';
    default:
      return 'gray';
  }
}

/* ============================ KEHADIRAN ============================ */

export const ATTENDANCE_STATUS_LABELS: Record<TahfizAttendanceStatus, string> = {
  hadir: 'Hadir',
  izin: 'Izin',
  sakit: 'Sakit',
  alpa: 'Alpa',
  terlambat: 'Terlambat',
};

export function attendanceTone(status: TahfizAttendanceStatus): Tone {
  switch (status) {
    case 'hadir':
      return 'green';
    case 'terlambat':
      return 'amber';
    case 'izin':
    case 'sakit':
      return 'blue';
    default:
      return 'red';
  }
}

/* ============================ SETORAN ============================ */

export const MEMORIZATION_TYPE_LABELS: Record<TahfizMemorizationType, string> = {
  hafalan_baru: 'Hafalan Baru',
  ziyadah: 'Ziyadah',
  setoran: 'Setoran',
  tasmi: "Tasmi'",
};

export const MEMORIZATION_STATUS_LABELS: Record<TahfizMemorizationStatus, string> = {
  submitted: 'Menunggu Penilaian',
  reviewed: 'Sedang Dinilai',
  accepted: 'Diterima',
  accepted_with_revision: 'Diterima dengan Revisi',
  repeated: 'Diulang',
  rejected: 'Ditolak',
};

/** Status penilaian yang boleh dipilih guru (tanpa `submitted`). */
export const REVIEW_STATUS_OPTIONS: SelectOption[] = toOptions({
  reviewed: MEMORIZATION_STATUS_LABELS.reviewed,
  accepted: MEMORIZATION_STATUS_LABELS.accepted,
  accepted_with_revision: MEMORIZATION_STATUS_LABELS.accepted_with_revision,
  repeated: MEMORIZATION_STATUS_LABELS.repeated,
  rejected: MEMORIZATION_STATUS_LABELS.rejected,
});

export function memorizationStatusTone(status: TahfizMemorizationStatus): Tone {
  switch (status) {
    case 'accepted':
      return 'green';
    case 'accepted_with_revision':
      return 'amber';
    case 'rejected':
      return 'red';
    case 'reviewed':
      return 'blue';
    case 'repeated':
      return 'brand';
    default:
      return 'gray';
  }
}

/* ============================ MUROJAAH / TILAWAH / TAHSIN / TASMI ============================ */

export const REVISION_METHOD_LABELS: Record<TahfizRevisionMethod, string> = {
  individual: 'Individual',
  paired: 'Berpasangan',
  group: 'Kelompok',
  'tasmi': "Tasmi'",
  teacher_listening: 'Dibacakan kepada Guru',
};

export const TASMI_RESULT_LABELS: Record<TahfizTasmiResult, string> = {
  lulus: 'Lulus',
  lulus_dengan_catatan: 'Lulus dengan Catatan',
  ulang: 'Ulang',
};

export function tasmiResultTone(result: TahfizTasmiResult | null): Tone {
  switch (result) {
    case 'lulus':
      return 'green';
    case 'lulus_dengan_catatan':
      return 'amber';
    case 'ulang':
      return 'red';
    default:
      return 'gray';
  }
}

/* ============================ TARGET ============================ */

export const TARGET_PERIOD_LABELS: Record<TahfizTargetPeriod, string> = {
  daily: 'Harian',
  weekly: 'Mingguan',
  monthly: 'Bulanan',
  semester: 'Semester',
  program: 'Selama Program',
};

/* ============================ UJIAN ============================ */

export const EXAM_TYPE_LABELS: Record<TahfizExamType, string> = {
  ujian_hafalan: 'Ujian Hafalan',
  sambung_ayat: 'Sambung Ayat',
  acak_ayat: 'Ayat Acak',
  'tasmi': "Tasmi'",
  ujian_tahsin: 'Ujian Tahsin',
  kenaikan_level: 'Kenaikan Level',
};

export const EXAM_STATUS_LABELS: Record<TahfizExamStatus, string> = {
  draft: 'Draf',
  scheduled: 'Terjadwal',
  ongoing: 'Berlangsung',
  done: 'Selesai',
  cancelled: 'Dibatalkan',
};

export const EXAM_ITEM_TYPE_LABELS: Record<string, string> = {
  sambung_ayat: 'Sambung Ayat',
  acak_ayat: 'Ayat Acak',
  hafalan: 'Hafalan',
  tahsin: 'Tahsin',
};

export function examStatusTone(status: TahfizExamStatus): Tone {
  switch (status) {
    case 'done':
      return 'green';
    case 'ongoing':
      return 'blue';
    case 'scheduled':
      return 'brand';
    case 'cancelled':
      return 'red';
    default:
      return 'gray';
  }
}

export const EXAM_PARTICIPANT_STATUS_LABELS: Record<string, string> = {
  terdaftar: 'Terdaftar',
  hadir: 'Hadir',
  tidak_hadir: 'Tidak Hadir',
  menunggu_nilai: 'Menunggu Nilai',
  dinilai: 'Dinilai',
};

export function examResultTone(result: string | null): Tone {
  switch (result) {
    case 'lulus':
      return 'green';
    case 'remedial':
      return 'amber';
    case 'tidak_lulus':
      return 'red';
    default:
      return 'gray';
  }
}

/* ============================ SERTIFIKAT ============================ */

export const ACHIEVEMENT_TYPE_LABELS: Record<TahfizAchievementType, string> = {
  selesai_program: 'Selesai Program',
  hafal_1_juz: 'Hafal 1 Juz',
  hafal_5_juz: 'Hafal 5 Juz',
  hafal_10_juz: 'Hafal 10 Juz',
  hafal_30_juz: 'Hafal 30 Juz',
  lulus_tasmi: "Lulus Tasmi'",
  lulus_tahsin: 'Lulus Tahsin',
};

/* ============================ INTERVENTION ============================ */

export const INTERVENTION_TYPE_LABELS: Record<TahfizInterventionType, string> = {
  tambahan_murojaah: 'Tambahan Murojaah',
  pengurangan_target: 'Pengurangan Target',
  sesi_khusus: 'Sesi Khusus',
  bimbingan_individual: 'Bimbingan Individual',
  remedial_hafalan: 'Remedial Hafalan',
  naik_level: 'Naik Level',
  turun_level: 'Turun Level',
};

export const INTERVENTION_STATUS_LABELS: Record<TahfizInterventionStatus, string> = {
  proposed: 'Diajukan',
  in_progress: 'Berlangsung',
  done: 'Selesai',
  cancelled: 'Dibatalkan',
};

/** Transisi status intervention (cermin `TahfizIntervention::TRANSITIONS`). */
export const INTERVENTION_TRANSITIONS: Record<TahfizInterventionStatus, TahfizInterventionStatus[]> = {
  proposed: ['in_progress', 'cancelled'],
  in_progress: ['done', 'cancelled'],
  done: [],
  cancelled: [],
};

export function interventionStatusTone(status: TahfizInterventionStatus): Tone {
  switch (status) {
    case 'done':
      return 'green';
    case 'in_progress':
      return 'blue';
    case 'cancelled':
      return 'red';
    default:
      return 'amber';
  }
}

/* ============================ NOTIFIKASI ============================ */

export const NOTIFICATION_AUDIENCE_LABELS: Record<string, string> = {
  participant: 'Peserta',
  instructor: 'Guru/Instruktur',
  musyrif: 'Musyrif',
  parent: 'Wali',
  admin: 'Admin',
};

/* ============================ PENDAFTARAN ============================ */

export const REGISTRATION_STATUS_LABELS: Record<TahfizRegistrationStatus, string> = {
  pending: 'Menunggu',
  approved: 'Disetujui',
  rejected: 'Ditolak',
};

export function registrationStatusTone(status: TahfizRegistrationStatus): Tone {
  switch (status) {
    case 'approved':
      return 'green';
    case 'rejected':
      return 'red';
    default:
      return 'amber';
  }
}

/* ============================ PROGRESS ============================ */

export const PROGRESS_STATUS_LABELS: Record<TahfizProgressStatus, string> = {
  belum: 'Belum',
  hafal: 'Hafal',
  lancar: 'Lancar',
  mutqin: 'Mutqin',
  belum_mutqin: 'Perlu Murojaah',
};

export function progressStatusTone(status: TahfizProgressStatus): Tone {
  switch (status) {
    case 'mutqin':
      return 'green';
    case 'lancar':
      return 'brand';
    case 'belum_mutqin':
      return 'amber';
    case 'hafal':
      return 'blue';
    default:
      return 'gray';
  }
}

export const RAPOR_PREDICATE_TONE: Record<string, Tone> = {
  sangat_baik: 'green',
  baik: 'brand',
  cukup: 'amber',
  perlu_bimbingan: 'red',
};

/** Rentang juz dari sebuah level, mis. "Juz 1–5". */
export function levelJuzRange(level: Pick<TahfizLevel, 'juz_start' | 'juz_end'>): string {
  if (level.juz_start === null || level.juz_end === null) return '—';
  return level.juz_start === level.juz_end
    ? `Juz ${level.juz_start}`
    : `Juz ${level.juz_start}–${level.juz_end}`;
}

/** Label ringkas untuk target individual. */
export function individualTargetLabel(target: TahfizIndividualTarget): string {
  const unit = TARGET_UNIT_LABELS[target.target_type] ?? target.target_type;
  const value = target.value ?? '—';
  const surah = target.surah?.name ? ` ${target.surah.name}` : '';
  return `${value} ${unit}${surah}`;
}

export { type Tone as TahfizTone };