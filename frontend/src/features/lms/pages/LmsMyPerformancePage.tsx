import { useEffect, useState } from 'react';
import { Badge } from '@/components/ui/Badge';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { ProgressBar } from '../components/ProgressBar';
import { gradebookApi } from '../api/gradebookApi';
import type { StudentPerformanceResponse } from '../types.phase3';

const DIRECTION_LABEL: Record<string, string> = {
  improving: 'Meningkat',
  declining: 'Menurun',
  stable: 'Stabil',
  insufficient_data: 'Data belum cukup',
};

/**
 * Ringkasan performa belajar seorang siswa (§47).
 *
 * Menampilkan capaian, bukan kesimpulan otomatis tentang kemampuan anak:
 * angka, penyelesaian, outcome, dan kompetensi disajikan berdampingan.
 */
export default function LmsMyPerformancePage() {
  const [payload, setPayload] = useState<StudentPerformanceResponse | null>(null);

  useEffect(() => {
    void (async () => {
      const data = await gradebookApi.myPerformance().catch(() => null);
      setPayload(data);
    })();
  }, []);

  if (payload === null) {
    return <p className="p-6 text-sm text-slate-500">Memuat ringkasan performa…</p>;
  }

  return (
    <div className="space-y-4 p-4 lg:p-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Performa belajar</h1>
          <p className="text-xs text-slate-500">
            Rata-rata {payload.average === null ? '—' : payload.average.toFixed(2)} · tren{' '}
            {DIRECTION_LABEL[payload.trend.direction] ?? '—'}
            {payload.trend.delta === null ? '' : ` (${payload.trend.delta > 0 ? '+' : ''}${payload.trend.delta.toFixed(2)})`}
          </p>
        </div>
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Course</CardTitle>
          </CardHeader>
          <CardBody className="space-y-3">
            {payload.courses.map((course) => (
              <div key={course.course_id} className="rounded-xl border border-slate-200 px-3 py-2">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <p className="text-sm font-medium text-slate-800">{course.course_title}</p>
                  <div className="flex items-center gap-2">
                    <span className="text-sm font-semibold text-slate-900">
                      {course.current_grade === null ? '—' : course.current_grade.toFixed(1)}
                    </span>
                    {course.letter && <Badge tone="brand">{course.letter}</Badge>}
                    {!course.is_released && <Badge tone="gray">belum dirilis</Badge>}
                  </div>
                </div>
                <div className="mt-2">
                  <div className="flex items-center justify-between text-[11px] text-slate-500">
                    <span>
                      Penyelesaian {course.completed_items ?? 0}/{course.required_items ?? 0}
                    </span>
                    <span>{course.completion === null ? '—' : `${course.completion.toFixed(0)}%`}</span>
                  </div>
                  <ProgressBar value={course.completion ?? 0} size="sm" tone="brand" />
                </div>
                <p className="mt-1 text-[11px] text-slate-500">
                  Tugas {course.assignments.graded}/{course.assignments.total}
                  {course.assignments.average === null ? '' : ` (rata ${course.assignments.average.toFixed(1)})`} · Kuis {course.quizzes.graded}/
                  {course.quizzes.total}
                  {course.missing_count > 0 ? ` · ${course.missing_count} nilai kosong` : ''}
                </p>
              </div>
            ))}
            {payload.courses.length === 0 && <p className="text-sm text-slate-500">Belum ada course yang diikuti.</p>}
          </CardBody>
        </Card>

        <div className="space-y-4">
          <Card>
            <CardHeader>
              <CardTitle>Capaian learning outcome</CardTitle>
            </CardHeader>
            <CardBody className="space-y-2">
              {payload.outcomes.map((outcome) => (
                <div key={`${outcome.course_id}-${outcome.learning_outcome_id}`} className="rounded-xl border border-slate-200 px-3 py-2">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="text-sm text-slate-800">
                      {outcome.code ? `${outcome.code} · ` : ''}
                      {outcome.statement}
                    </p>
                    <Badge tone={outcome.status_tone as 'gray' | 'green' | 'amber' | 'red' | 'blue' | 'brand'}>{outcome.status_label}</Badge>
                  </div>
                  <p className="mt-1 text-[11px] text-slate-500">
                    {outcome.course_title}
                    {outcome.score === null ? '' : ` · ${outcome.score.toFixed(1)}`} · {outcome.evidence_count} bukti
                  </p>
                </div>
              ))}
              {payload.outcomes.length === 0 && <p className="text-sm text-slate-500">Belum ada learning outcome yang dipetakan.</p>}
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Kompetensi</CardTitle>
            </CardHeader>
            <CardBody className="space-y-2">
              {payload.competencies.map((competency) => (
                <div key={competency.competency_id} className="rounded-xl border border-slate-200 px-3 py-2">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="text-sm text-slate-800">{competency.name}</p>
                    <Badge tone={(competency.status_tone ?? 'gray') as 'gray' | 'green' | 'amber' | 'red' | 'blue' | 'brand'}>
                      {competency.status_label ?? 'Belum dimulai'}
                    </Badge>
                  </div>
                  <p className="mt-1 text-[11px] text-slate-500">{competency.course_title}</p>
                </div>
              ))}
              {payload.competencies.length === 0 && <p className="text-sm text-slate-500">Belum ada kompetensi yang dinilai.</p>}
            </CardBody>
          </Card>
        </div>
      </div>
    </div>
  );
}
