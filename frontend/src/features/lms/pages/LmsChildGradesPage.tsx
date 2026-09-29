import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { Badge } from '@/components/ui/Badge';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { gradebookApi } from '../api/gradebookApi';
import { formatDate } from '../lib/lmsLabels';
import type { ChildGradesResponse } from '../types.phase3';

/**
 * Nilai anak untuk wali (§33, §57).
 *
 * Sengaja sederhana: hanya nilai yang sudah dirilis, tanpa catatan privat guru
 * maupun tabel rumit seperti gradebook pengajar.
 */
export default function LmsChildGradesPage() {
  const { childId } = useParams();
  const id = Number(childId);
  const [payload, setPayload] = useState<ChildGradesResponse | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    void (async () => {
      try {
        setPayload(await gradebookApi.childGrades(id));
      } catch {
        setError('Anda tidak berhak membuka nilai siswa ini.');
      }
    })();
  }, [id]);

  if (error !== null) {
    return <p className="p-6 text-sm text-red-600">{error}</p>;
  }

  if (payload === null) {
    return <p className="p-6 text-sm text-slate-500">Memuat nilai anak…</p>;
  }

  return (
    <div className="space-y-4 p-4 lg:p-6">
      <div>
        <h1 className="text-lg font-semibold text-slate-900">Nilai anak</h1>
        <p className="text-xs text-slate-500">
          Rata-rata {payload.average === null ? '—' : payload.average.toFixed(2)} · hanya nilai yang sudah dirilis guru yang ditampilkan
        </p>
      </div>

      {payload.courses.length === 0 && (
        <Card>
          <CardBody>
            <p className="text-sm text-slate-500">Belum ada nilai yang dirilis untuk anak Anda.</p>
          </CardBody>
        </Card>
      )}

      <div className="grid gap-4 lg:grid-cols-2">
        {payload.courses.map((course) => (
          <Card key={course.course_id}>
            <CardHeader className="flex flex-wrap items-start justify-between gap-2">
              <div>
                <CardTitle>{course.course_title}</CardTitle>
                {course.released_at && <p className="mt-1 text-xs text-slate-500">Dirilis {formatDate(course.released_at, true)}</p>}
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
              {course.components.length > 0 && (
                <div className="space-y-1">
                  {course.components.map((component) => (
                    <p key={component.name} className="text-xs text-slate-600">
                      {component.name}: <strong>{component.score === null ? '—' : component.score.toFixed(1)}</strong>
                      {component.weight ? ` (bobot ${component.weight}%)` : ''}
                    </p>
                  ))}
                </div>
              )}

              <div>
                <p className="text-xs font-medium text-slate-600">Hasil terbaru</p>
                <div className="mt-1 space-y-1">
                  {course.recent_results.slice(0, 5).map((result) => (
                    <div key={result.grade_item_id} className="rounded-lg border border-slate-200 px-3 py-1.5">
                      <p className="text-xs text-slate-700">
                        {result.name} — {result.score === null ? '—' : result.score.toFixed(1)}/{result.maximum.toFixed(0)}
                        {result.percent === null ? '' : ` (${result.percent.toFixed(0)}%)`}
                      </p>
                      {result.feedback && payload.can_view_feedback && (
                        <p className="text-[11px] text-slate-500">Umpan balik: {result.feedback}</p>
                      )}
                    </div>
                  ))}
                  {course.recent_results.length === 0 && <p className="text-xs text-slate-500">Belum ada hasil yang dirilis.</p>}
                </div>
              </div>
            </CardBody>
          </Card>
        ))}
      </div>

      {payload.upcoming.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>Agenda mendatang</CardTitle>
          </CardHeader>
          <CardBody className="space-y-1">
            {payload.upcoming.map((entry, index) => (
              <p key={index} className="text-xs text-slate-600">
                {String(entry.title ?? 'Aktivitas')} · {String(entry.course_title ?? '')} · {formatDate(String(entry.date ?? ''))}
              </p>
            ))}
          </CardBody>
        </Card>
      )}

      {payload.reviews.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>Status banding nilai</CardTitle>
          </CardHeader>
          <CardBody className="space-y-1">
            {payload.reviews.map((review) => (
              <p key={review.id} className="text-xs text-slate-600">
                {review.grade_item_name ?? 'Nilai'} · <Badge tone={review.status_tone as 'gray' | 'green' | 'amber' | 'red' | 'blue' | 'brand'}>{review.status_label}</Badge>
              </p>
            ))}
          </CardBody>
        </Card>
      )}
    </div>
  );
}
