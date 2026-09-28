import { Link } from 'react-router-dom';
import { Clock, GraduationCap, Layers, Users } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { ProgressBar } from './ProgressBar';
import { LEVEL_LABEL, STATUS_TONE, TYPE_LABEL } from '../lib/lmsLabels';
import type { CourseLevelValue, CourseStatusValue, CourseTypeValue, CourseVisibilityValue } from '../types';

/**
 * Bentuk minimum yang dibutuhkan kartu course. Sengaja lebih longgar dari
 * `LmsCourse` agar kartu yang sama dapat dipakai dashboard, hasil pencarian,
 * dan katalog publik tanpa memaksa setiap respons mengirim seluruh field.
 */
export interface CourseCardData {
  id: number;
  name: string;
  code: string;
  thumbnail?: string | null;
  short_description?: string | null;
  level?: CourseLevelValue | null;
  course_type?: CourseTypeValue | null;
  status?: CourseStatusValue | null;
  visibility?: CourseVisibilityValue | null;
  teachers?: Array<{ name: string | null }> | null;
  counts?: { students?: number; sections?: number; items?: number } | null;
  category?: { name: string } | null;
}

/**
 * Kartu course untuk katalog, daftar "Course Saya", dan halaman publik.
 * Sengaja bukan tabel CRUD: judul, pengajar, tingkat, dan progress tampil
 * sebagai satu kesatuan yang dapat dipindai cepat (§8, §30).
 */
export function CourseCard({
  course,
  to,
  progressPercent,
  studentsCount,
  itemsCount,
  footer,
  compact = false,
}: {
  course: CourseCardData;
  to: string;
  progressPercent?: number;
  studentsCount?: number;
  itemsCount?: number;
  footer?: React.ReactNode;
  compact?: boolean;
}) {
  const teachers = (course.teachers ?? []).map((teacher) => teacher.name).filter(Boolean);
  const students = studentsCount ?? course.counts?.students ?? 0;
  const items = itemsCount ?? course.counts?.items ?? 0;

  return (
    <article className="lms-course-card group flex h-full flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
      <Link to={to} className="block focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">
        <div className="lms-course-cover relative h-36 w-full overflow-hidden bg-gradient-to-br from-brand-500 via-brand-600 to-emerald-700">
          {course.thumbnail ? (
            <img src={course.thumbnail} alt="" className="h-full w-full object-cover" loading="lazy" />
          ) : (
            <div className="flex h-full w-full items-center justify-center text-white/85">
              <GraduationCap className="h-10 w-10" aria-hidden="true" />
            </div>
          )}
          <div className="absolute inset-x-0 bottom-0 flex items-center gap-1.5 bg-gradient-to-t from-slate-950/70 to-transparent px-3 pb-2 pt-6">
            <Badge tone="brand" className="bg-white/90 text-brand-700">
              {course.course_type ? TYPE_LABEL[course.course_type] : 'Course'}
            </Badge>
            {course.level && (
              <Badge tone="gray" className="bg-white/85 text-slate-700">
                {LEVEL_LABEL[course.level]}
              </Badge>
            )}
            <Badge tone={course.status ? STATUS_TONE[course.status] : 'gray'} className="ml-auto">
              {course.status === 'PUBLISHED' ? 'Terbit' : course.status === 'DRAFT' ? 'Draf' : 'Arsip'}
            </Badge>
          </div>
        </div>
      </Link>

      <div className="flex flex-1 flex-col gap-2 p-4">
        <div className="flex items-start justify-between gap-2">
          <Link to={to} className="text-sm font-semibold leading-snug text-slate-900 hover:text-brand-700">
            {course.name}
          </Link>
        </div>

        <p className="text-xs font-medium text-slate-500">
          {course.code}
          {course.category?.name ? ` • ${course.category.name}` : ''}
        </p>

        {!compact && course.short_description && (
          <p className="line-clamp-2 text-xs text-slate-600">{course.short_description}</p>
        )}

        {teachers.length > 0 && (
          <p className="text-xs text-slate-600">
            <span className="text-slate-500">Pengajar:</span> {teachers.join(', ')}
          </p>
        )}

        <div className="mt-auto flex flex-wrap items-center gap-3 pt-2 text-[11px] text-slate-500">
          <span className="inline-flex items-center gap-1">
            <Users className="h-3.5 w-3.5" aria-hidden="true" /> {students} siswa
          </span>
          <span className="inline-flex items-center gap-1">
            <Layers className="h-3.5 w-3.5" aria-hidden="true" /> {items} materi
          </span>
          {course.counts?.sections !== undefined && (
            <span className="inline-flex items-center gap-1">
              <Clock className="h-3.5 w-3.5" aria-hidden="true" /> {course.counts.sections} bagian
            </span>
          )}
        </div>

        {progressPercent !== undefined && (
          <div className="space-y-1 pt-1">
            <ProgressBar value={progressPercent} showLabel />
            <p className="text-[11px] text-slate-500">Progress belajar Anda</p>
          </div>
        )}

        {footer && <div className="pt-2">{footer}</div>}
      </div>
    </article>
  );
}
