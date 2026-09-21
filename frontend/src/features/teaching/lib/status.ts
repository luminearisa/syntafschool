import type { JournalStatus, LessonPlanStatus } from '@/types/teaching';

type Tone = 'gray' | 'green' | 'red' | 'amber' | 'blue' | 'brand';

/**
 * Warna badge per status — dijaga di satu tempat supaya daftar jurnal,
 * halaman "hari ini", dan daftar RPP tidak memakai warna yang berbeda
 * untuk status yang sama.
 */
const LESSON_PLAN_TONES: Record<LessonPlanStatus, Tone> = {
  draft: 'gray',
  submitted: 'amber',
  approved: 'green',
  rejected: 'red',
};

const JOURNAL_TONES: Record<JournalStatus, Tone> = {
  planned: 'blue',
  ongoing: 'amber',
  completed: 'green',
  cancelled: 'red',
};

export function lessonPlanTone(status: LessonPlanStatus): Tone {
  return LESSON_PLAN_TONES[status] ?? 'gray';
}

export function journalTone(status: JournalStatus): Tone {
  return JOURNAL_TONES[status] ?? 'gray';
}

/** RPP boleh diubah hanya saat draft atau setelah ditolak (revisi). */
export function isLessonPlanEditable(status: LessonPlanStatus): boolean {
  return status === 'draft' || status === 'rejected';
}
