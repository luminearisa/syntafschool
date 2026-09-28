import { useQuery } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, GraduationCap, TrendingUp, Users } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/Spinner';
import { lmsApi } from '../api/lmsApi';
import { AnnouncementList } from '../components/AnnouncementList';
import { ProgressBar } from '../components/ProgressBar';
import { StatCard } from '../components/StatCard';
import { formatDate, relativeTime } from '../lib/lmsLabels';

/**
 * Detail belajar satu anak untuk wali (§28).
 *
 * Halaman ini baca-saja: tidak ada aksi pengumpulan, pengerjaan kuis, atau
 * perubahan nilai — backend menolak tindakan itu walaupun UI-nya direkayasa.
 */
export default function LmsParentChildPage() {
  const { studentId } = useParams<{ studentId: string }>();

  const overview = useQuery({
    queryKey: ['lms', 'child', studentId],
    queryFn: () => lmsApi.child(Number(studentId)),
    enabled: Boolean(studentId),
  });

  if (overview.isLoading) {
    return (
      <div className="flex flex-col items-center gap-3 py-16 text-sm text-slate-500">
        <Spinner className="h-6 w-6" /> Memuat data belajar anak…
      </div>
    );
  }

  if (overview.isError || !overview.data) {
    return (
      <EmptyState
        title="Data anak tidak dapat dibuka"
        description="Pastikan akun Anda tertaut sebagai wali siswa tersebut."
        action={
          <Link to="/lms">
            <Button size="sm">Kembali ke dashboard</Button>
          </Link>
        }
      />
    );
  }

  const data = overview.data;

  return (
    <div className="space-y-6">
      <header className="flex items-start gap-3">
        <Link to="/lms">
          <Button variant="ghost" size="icon" aria-label="Kembali">
            <ArrowLeft className="h-4 w-4" aria-hidden="true" />
          </Button>
        </Link>
        <div>
          <h1 className="text-lg font-semibold text-slate-900">{data.child.name}</h1>
          <p className="text-sm text-slate-500">
            {data.child.code ?? '-'} • {data.child.class_name ?? 'tanpa kelas'} • {data.child.program_name ?? 'umum'}
          </p>
        </div>
        <Badge tone="blue" className="ml-auto">
          Mode pantau wali
        </Badge>
      </header>

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Course diikuti" value={data.stats.courses} icon={GraduationCap} />
        <StatCard label="Course selesai" value={data.stats.completed_courses} icon={GraduationCap} tone="violet" />
        <StatCard label="Rata-rata progress" value={`${Math.round(data.stats.average_progress)}%`} icon={TrendingUp} tone="sky" />
        <StatCard label="Tenggat dekat" value={data.stats.upcoming_deadlines} icon={Users} tone="amber" />
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader>
            <CardTitle>Progress per course</CardTitle>
          </CardHeader>
          <CardBody className="space-y-4">
            {data.courses.length === 0 ? (
              <EmptyState title="Belum ada course" />
            ) : (
              data.courses.map((course) => (
                <div key={course.enrollment_id}>
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <Link to={`/lms/courses/${course.course_id}`} className="text-sm font-medium text-slate-800 hover:text-brand-700">
                      {course.name}
                    </Link>
                    <span className="text-xs text-slate-500">
                      {course.completed_items}/{course.required_items} materi • terakhir {relativeTime(course.last_accessed_at)}
                    </span>
                  </div>
                  <ProgressBar value={course.progress_percent} size="sm" />
                </div>
              ))
            )}
          </CardBody>
        </Card>

        <div className="space-y-6">
          <Card>
            <CardHeader>
              <CardTitle>Tenggat terdekat</CardTitle>
            </CardHeader>
            <CardBody>
              {data.upcoming.length === 0 ? (
                <EmptyState title="Tidak ada tenggat" />
              ) : (
                <ul className="space-y-2 text-sm">
                  {data.upcoming.map((assignment) => (
                    <li key={assignment.id} className="rounded-xl border border-slate-200 px-3 py-2">
                      <p className="truncate font-medium text-slate-800">{assignment.title}</p>
                      <p className="text-[11px] text-slate-500">
                        {assignment.subject ?? 'Tugas'} • {assignment.due_date ? formatDate(assignment.due_date) : '-'}
                      </p>
                    </li>
                  ))}
                </ul>
              )}
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Nilai</CardTitle>
            </CardHeader>
            <CardBody>
              {data.grades.hidden ? (
                <p className="text-xs text-slate-500">Sekolah menyembunyikan nilai pada portal wali.</p>
              ) : data.grades.count === 0 ? (
                <EmptyState title="Belum ada nilai" />
              ) : (
                <div className="space-y-2">
                  <p className="text-sm text-slate-600">
                    Rata-rata: <strong>{data.grades.average ?? '-'}</strong> dari {data.grades.count} penilaian
                  </p>
                  <ul className="space-y-1 text-xs text-slate-600">
                    {data.grades.recent.slice(0, 5).map((score) => (
                      <li key={score.id} className="flex items-center justify-between gap-2">
                        <span className="truncate">
                          {score.subject ?? 'Mapel'} — {score.assessment ?? 'Penilaian'}
                        </span>
                        <span className="font-medium text-slate-800">
                          {score.score}/{score.max_score}
                        </span>
                      </li>
                    ))}
                  </ul>
                </div>
              )}
            </CardBody>
          </Card>
        </div>
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Pengumuman</CardTitle>
          </CardHeader>
          <CardBody>
            <AnnouncementList announcements={data.announcements} showCourse emptyText="Belum ada pengumuman." />
          </CardBody>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Aktivitas terakhir</CardTitle>
          </CardHeader>
          <CardBody>
            {data.recent_activity.length === 0 ? (
              <EmptyState title="Belum ada aktivitas" />
            ) : (
              <ul className="space-y-2 text-sm">
                {data.recent_activity.map((activity, index) => (
                  <li key={`${activity.item_id}-${index}`} className="flex items-center justify-between gap-2">
                    <span className="min-w-0 truncate text-slate-700">{activity.title}</span>
                    <span className="shrink-0 text-[11px] text-slate-500">{relativeTime(activity.at)}</span>
                  </li>
                ))}
              </ul>
            )}
          </CardBody>
        </Card>
      </div>
    </div>
  );
}
