import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { BookOpenCheck, GraduationCap } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/Spinner';
import { lmsApi } from '../api/lmsApi';
import { ProgressBar } from '../components/ProgressBar';
import { StatCard } from '../components/StatCard';
import { relativeTime } from '../lib/lmsLabels';

/** Daftar course yang diikuti siswa beserta progress dan lanjutannya (§23). */
export default function LmsMyCoursesPage() {
  const myCourses = useQuery({ queryKey: ['lms', 'my-courses'], queryFn: () => lmsApi.myCourses() });
  const myProgress = useQuery({ queryKey: ['lms', 'my-progress'], queryFn: () => lmsApi.myProgress() });

  if (myCourses.isLoading) {
    return (
      <div className="flex flex-col items-center gap-3 py-16 text-sm text-slate-500">
        <Spinner className="h-6 w-6" /> Memuat course saya…
      </div>
    );
  }

  const summary = myCourses.data?.summary;

  return (
    <div className="space-y-6">
      <header className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2 text-lg font-semibold text-slate-900">
            <BookOpenCheck className="h-5 w-5 text-brand-600" aria-hidden="true" /> Course saya
          </h1>
          <p className="text-sm text-slate-500">Lanjutkan belajar dari tempat Anda berhenti.</p>
        </div>
        <Link to="/lms/courses">
          <Button variant="outline" size="sm">
            Jelajahi katalog
          </Button>
        </Link>
      </header>

      {summary && (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <StatCard label="Course diikuti" value={summary.total} icon={BookOpenCheck} />
          <StatCard label="Aktif" value={summary.active} icon={GraduationCap} tone="sky" />
          <StatCard label="Selesai" value={summary.completed} icon={GraduationCap} tone="violet" />
          <StatCard label="Rata-rata progress" value={`${Math.round(summary.average_progress)}%`} icon={GraduationCap} tone="amber" />
        </div>
      )}

      {(myCourses.data?.courses ?? []).length === 0 ? (
        <EmptyState
          title="Belum ada course yang diikuti"
          description="Pilih course dari katalog atau tunggu guru mendaftarkan Anda."
          action={
            <Link to="/lms/courses">
              <Button size="sm">Lihat katalog</Button>
            </Link>
          }
        />
      ) : (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {(myCourses.data?.courses ?? []).map((row) => (
            <Card key={row.enrollment_id}>
              <CardHeader className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                  <CardTitle className="truncate">{row.course.name}</CardTitle>
                  <p className="text-xs text-slate-500">{row.course.code}</p>
                </div>
                <Badge tone={row.status === 'COMPLETED' ? 'green' : row.status === 'SUSPENDED' ? 'red' : 'brand'}>
                  {row.status}
                </Badge>
              </CardHeader>
              <CardBody className="space-y-3">
                <ProgressBar value={row.progress_percent} showLabel />
                <p className="text-xs text-slate-500">
                  {row.completed_items}/{row.required_items} materi • terakhir dibuka {relativeTime(row.last_accessed_at)}
                </p>
                <div className="flex gap-2">
                  <Link to={`/lms/courses/${row.course.id}/learn${row.next_item_id ? `/${row.next_item_id}` : ''}`} className="flex-1">
                    <Button size="sm" className="w-full">
                      {row.progress_percent >= 100 ? 'Ulangi materi' : 'Lanjutkan'}
                    </Button>
                  </Link>
                  <Link to={`/lms/courses/${row.course.id}`}>
                    <Button size="sm" variant="outline">
                      Detail
                    </Button>
                  </Link>
                </div>
              </CardBody>
            </Card>
          ))}
        </div>
      )}

      {myProgress.data && (
        <Card>
          <CardHeader>
            <CardTitle>Ringkasan progress</CardTitle>
          </CardHeader>
          <CardBody className="grid gap-4 sm:grid-cols-4">
            <div>
              <p className="text-xs text-slate-500">Total materi tuntas</p>
              <p className="text-lg font-semibold text-slate-900">{myProgress.data.totals.completed_items}</p>
            </div>
            <div>
              <p className="text-xs text-slate-500">Course selesai</p>
              <p className="text-lg font-semibold text-slate-900">{myProgress.data.totals.completed_courses}</p>
            </div>
            <div>
              <p className="text-xs text-slate-500">Rata-rata progress</p>
              <p className="text-lg font-semibold text-slate-900">{Math.round(myProgress.data.totals.average_progress)}%</p>
            </div>
            <div>
              <p className="text-xs text-slate-500">Course diikuti</p>
              <p className="text-lg font-semibold text-slate-900">{myProgress.data.totals.courses}</p>
            </div>
          </CardBody>
        </Card>
      )}
    </div>
  );
}
