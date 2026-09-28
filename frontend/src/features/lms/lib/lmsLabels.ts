import type {
  CourseLevelValue,
  CourseStatusValue,
  CourseTypeValue,
  CourseVisibilityValue,
  EnrollmentStatusValue,
  ItemTypeValue,
} from '../types';

type Tone = 'gray' | 'green' | 'red' | 'amber' | 'blue' | 'brand';

export const STATUS_TONE: Record<CourseStatusValue, Tone> = {
  DRAFT: 'amber',
  PUBLISHED: 'green',
  ARCHIVED: 'gray',
};

export const ENROLLMENT_TONE: Record<EnrollmentStatusValue, Tone> = {
  PENDING: 'amber',
  ACTIVE: 'green',
  SUSPENDED: 'red',
  COMPLETED: 'blue',
  CANCELLED: 'gray',
};

export const VISIBILITY_LABEL: Record<CourseVisibilityValue, string> = {
  PUBLIC: 'Publik',
  PRIVATE: 'Privat',
  INSTITUTION: 'Internal',
  ENROLLED_ONLY: 'Peserta',
};

export const TYPE_LABEL: Record<CourseTypeValue, string> = {
  ACADEMIC: 'Akademik',
  NON_ACADEMIC: 'Non-Akademik',
  TRAINING: 'Pelatihan',
  WORKSHOP: 'Workshop',
  GENERAL: 'Umum',
};

export const LEVEL_LABEL: Record<CourseLevelValue, string> = {
  BEGINNER: 'Pemula',
  INTERMEDIATE: 'Menengah',
  ADVANCED: 'Mahir',
  ALL_LEVELS: 'Semua Tingkat',
};

export const ITEM_TYPE_LABEL: Record<ItemTypeValue, string> = {
  RESOURCE: 'Materi',
  ACTIVITY: 'Aktivitas',
};

export function formatDate(value: string | null | undefined, withTime = false): string {
  if (!value) return '-';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '-';
  return date.toLocaleDateString('id-ID', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    ...(withTime ? { hour: '2-digit', minute: '2-digit' } : {}),
  });
}

export function relativeTime(value: string | null | undefined): string {
  if (!value) return '-';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '-';

  const diffMs = date.getTime() - Date.now();
  const diffMinutes = Math.round(diffMs / 60000);
  const formatter = new Intl.RelativeTimeFormat('id-ID', { numeric: 'auto' });

  if (Math.abs(diffMinutes) < 60) return formatter.format(diffMinutes, 'minute');
  const diffHours = Math.round(diffMinutes / 60);
  if (Math.abs(diffHours) < 24) return formatter.format(diffHours, 'hour');
  const diffDays = Math.round(diffHours / 24);
  if (Math.abs(diffDays) < 30) return formatter.format(diffDays, 'day');
  return formatDate(value);
}

export function dueLabel(daysLeft: number | null): { text: string; tone: Tone } {
  if (daysLeft === null) return { text: 'Tanpa tenggat', tone: 'gray' };
  if (daysLeft < 0) return { text: `Terlambat ${Math.abs(daysLeft)} hari`, tone: 'red' };
  if (daysLeft === 0) return { text: 'Jatuh tempo hari ini', tone: 'amber' };
  if (daysLeft === 1) return { text: 'Jatuh tempo besok', tone: 'amber' };
  return { text: `${daysLeft} hari lagi`, tone: 'blue' };
}

export function initials(name: string | null | undefined): string {
  if (!name) return '?';
  return name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .join('');
}
