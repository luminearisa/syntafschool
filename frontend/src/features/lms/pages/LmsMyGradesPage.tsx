import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Badge } from '@/components/ui/Badge';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { ProgressBar } from '../components/ProgressBar';
import { GradeBreakdownCard } from '../components/grades/GradeBreakdownCard';
import { GradeReviewRequestForm } from '../components/grades/GradeReviewPanel';
import { gradebookApi } from '../api/gradebookApi';
import { formatDate } from '../lib/lmsLabels';
import type { MyGradesResponse, StudentCourseGrade } from '../types.phase3';

/**
 * "Nilai saya" untuk siswa (§32, §56).
 *
 * Bukan sekadar tabel: setiap course menampilkan nilai saat ini, progress,
 * rincian komponen, umpan balik terbaru, dan form banding bila nilai sudah
 * dirilis.
 */
export default function LmsMyGradesPage() {
  const [payload, setPayload] = useState<MyGradesResponse | null>(null);
  const [expanded, setExpanded] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);

  const load = async () => {
    const data = await gradebookApi.myGrades().catch(() => null);
    setPayload(data);
    setLoading(false);
  };

  useEffect(() => {
    void load();
  }, []);

  if (loading) {
    return <p className="p-6 text-sm text-slate-500">Memuat nilai…</p>;
  }

  if (payload === null) {
    return <p className="p-6 text-sm text-slate-500">Nilai belum dapat dimuat.</p>;
  }

  return (
    <div className="space-y-4 p-4 lg:p-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Nilai saya</h1>
          <p className="text-xs text-slate-500">
            {payload.summary.graded_course_count} dari {payload.summary.course_count} course sudah memiliki nilai
            {payload.summary.average === null ? '' : ` · rata-rata ${payload.summary.average.toFixed(2)}`}
          </p>
        </div>
        <Link to="/lms/performance" className="text-xs font-medium text-brand-700 hover:underline">
          Lihat ringkasan performa belajar →
        </Link>
      </div>

      {payload.courses.length === 0 && (
        <EmptyState title="Belum ada course" description="Nilai akan muncul setelah Anda terdaftar pada course yang dinilai." />
      )}

      <div className="grid gap-4 lg:grid-cols-2">
        {payload.courses.map((course) => (
          <CourseGradeCard
            key={course.course_id}
            course={course}
            expanded={expanded === course.course_id}
            onToggle={() => setExpanded(expanded === course.course_id ? null : course.course_id)}
            onReviewed={load}
          />
        ))}
      </div>
    </div>
  );
}

function CourseGradeCard({
  course,
  expanded,
  onToggle,
  onReviewed,
}: {
  course: StudentCourseGrade;
  expanded: boolean;
  onToggle: () => void;
  onReviewed: () => void | Promise<void>;
}) {
  const releaseProgress = course.is_released ? 100 : 0;
  const reviewableItem = course.items.find((item) => !item.is_missing && !item.is_exempt && item.feedback !== null) ?? course.items[0];

  return (
    <Card>
      <CardHeader className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <CardTitle>{course.course_title}</CardTitle>
          <p className="mt-1 text-xs text-slate-500">
            {course.is_released
              ? course.release?.released_at
                ? `Dirilis ${formatDate(course.release.released_at, true)}`
                : 'Nilai sudah dirilis'
              : 'Menunggu rilis guru'}
          </p>
        </div>
        <div className="text-right">
          <p className="text-2xl font-semibold text-slate-900">
            {course.current_grade === null ? '—' : course.current_grade.toFixed(1)}
          </p>
          <div className="flex items-center justify-end gap-1">
            {course.letter && <Badge tone="brand">{course.letter}</Badge>}
            {course.predicate && <span className="text-xs text-slate-500">{course.predicate}</span>}
          </div>
        </div>
      </CardHeader>
      <CardBody className="space-y-3">
        <div>
          <div className="flex items-center justify-between text-xs text-slate-500">
            <span>Progres penilaian</span>
            <span>{releaseProgress}%</span>
          </div>
          <ProgressBar value={releaseProgress} tone={course.is_released ? 'emerald' : 'amber'} size="sm" />
        </div>

        {course.is_incomplete && (
          <p className="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
            {course.missing_count} komponen belum memiliki nilai — nilai akhir masih bersifat sementara.
          </p>
        )}

        {course.categories.length > 0 && (
          <div className="grid gap-2 sm:grid-cols-2">
            {course.categories.map((category) => (
              <div key={category.name} className="rounded-lg border border-slate-200 px-3 py-2">
                <p className="text-xs font-medium text-slate-600">{category.name}</p>
                <p className="text-sm text-slate-900">{category.value === null ? '—' : category.value.toFixed(1)}</p>
                <p className="text-[11px] text-slate-400">
                  {category.weight > 0 ? `bobot ${category.weight}%` : 'tanpa bobot'}
                  {category.missing_count > 0 ? ` · ${category.missing_count} kosong` : ''}
                </p>
              </div>
            ))}
          </div>
        )}

        {course.feedback.length > 0 && (
          <div className="rounded-lg bg-slate-50 px-3 py-2">
            <p className="text-xs font-medium text-slate-600">Umpan balik terbaru</p>
            {course.feedback.slice(0, 2).map((entry) => (
              <p key={entry.grade_item_id} className="mt-1 text-xs text-slate-700">
                <strong>{entry.name}</strong>: {entry.feedback}
              </p>
            ))}
          </div>
        )}

        <button type="button" onClick={onToggle} className="text-xs font-medium text-brand-700 hover:underline">
          {expanded ? 'Sembunyikan rincian' : 'Lihat rincian penilaian'}
        </button>

        {expanded && <GradeBreakdownCard items={course.items} components={course.breakdown} />}

        {course.is_released && reviewableItem !== undefined && (
          <GradeReviewRequestForm
            gradeItemId={reviewableItem.grade_item_id}
            gradeItemName={reviewableItem.name}
            currentScore={reviewableItem.score}
            onSubmitted={onReviewed}
          />
        )}

        {course.reviews.length > 0 && (
          <div className="space-y-1">
            <p className="text-xs font-medium text-slate-600">Banding nilai</p>
            {course.reviews.map((review) => (
              <p key={review.id} className="text-xs text-slate-600">
                {review.grade_item_name ?? 'Nilai'} · <Badge tone={review.status_tone as 'gray' | 'green' | 'amber' | 'red' | 'blue' | 'brand'}>{review.status_label}</Badge>
                {review.decided_score === null ? '' : ` · nilai baru ${review.decided_score.toFixed(2)}`}
              </p>
            ))}
          </div>
        )}
      </CardBody>
    </Card>
  );
}
