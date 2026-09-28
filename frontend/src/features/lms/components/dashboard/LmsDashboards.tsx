import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import {
  Activity,
  AlertTriangle,
  BarChart3,
  BookOpenCheck,
  CalendarClock,
  CheckCircle2,
  ClipboardCheck,
  GraduationCap,
  Layers,
  LineChart,
  Sparkles,
  TrendingUp,
  UserCheck,
  Users,
} from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/Spinner';
import { CourseCard } from '../CourseCard';
import { ProgressBar } from '../ProgressBar';
import { StatCard } from '../StatCard';
import { AnnouncementList } from '../AnnouncementList';
import { CalendarAgendaList } from '../CalendarAgendaList';
import { ItemIcon } from '../ItemIcon';
import { dueLabel, formatDate, relativeTime } from '../../lib/lmsLabels';
import { lmsApi } from '../../api/lmsApi';
import { useAuthStore } from '@/stores/authStore';
import type {
  AdminDashboard,
  ContinueLearningCard,
  ParentDashboard,
  StudentDashboard,
  TeacherDashboard,
} from '../../types';

function DashboardSkeleton() {
  return (
    <div className="flex flex-col items-center justify-center gap-3 py-16 text-sm text-slate-500">
      <Spinner className="h-6 w-6" />
      Memuat dashboard LMS…
    </div>
  );
}

/** Pembungkus: mengambil dashboard sesuai peran pemanggil (§26–§29). */
export function LmsDashboardView() {
  const roles = useAuthStore((state) => state.user?.roles ?? []);

  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['lms', 'dashboard'],
    queryFn: () => lmsApi.dashboard(),
  });

  if (isLoading) return <DashboardSkeleton />;

  if (isError || !data) {
    return (
      <EmptyState
        title="Dashboard LMS belum dapat dimuat"
        description={
          (error as { response?: { data?: { message?: string } } })?.response?.data?.message ??
          'Pastikan akun Anda memiliki akses ke modul LMS.'
        }
      />
    );
  }

  if (data.role === 'STUDENT') return <StudentDashboardView data={data} />;
  if (data.role === 'TEACHER') return <TeacherDashboardView data={data} />;
  if (data.role === 'PARENT') return <ParentDashboardView data={data} />;
  if (data.role === 'ADMIN') return <AdminDashboardView data={data} />;

  return <EmptyState title="Selamat datang di LMS" description={`Peran terdeteksi: ${roles.join(', ') || 'guest'}`} />;
}

// --------------------------------------------------------------- SISWA (§26)
export function StudentDashboardView({ data }: { data: StudentDashboard }) {
  return (
    <div className="space-y-6">
      <header className="lms-hero rounded-2xl bg-gradient-to-r from-brand-600 via-brand-700 to-emerald-800 px-5 py-6 text-white shadow-sm">
        <p className="text-sm text-white/80">{data.greeting}</p>
        <h1 className="mt-1 text-xl font-semibold sm:text-2xl">Lanjutkan belajar hari ini</h1>
        <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
          <HeroStat label="Course aktif" value={data.stats.courses} />
          <HeroStat label="Selesai" value={data.stats.completed_courses} />
          <HeroStat label="Materi tuntas" value={data.stats.completed_items} />
          <HeroStat label="Rata-rata progress" value={`${Math.round(data.stats.average_progress)}%`} />
        </div>
      </header>

      <section className="space-y-3">
        <h2 className="flex items-center gap-2 text-sm font-semibold text-slate-800">
          <Sparkles className="h-4 w-4 text-brand-600" aria-hidden="true" /> Continue Learning
        </h2>
        {data.continue_learning.length === 0 ? (
          <EmptyState
            title="Belum ada course yang diikuti"
            description="Jelajahi katalog LMS dan daftar pada course yang tersedia."
            action={
              <Link to="/lms/courses">
                <Button size="sm">Jelajahi course</Button>
              </Link>
            }
          />
        ) : (
          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            {data.continue_learning.map((card) => (
              <ContinueCard key={card.course_id} card={card} />
            ))}
          </div>
        )}
      </section>

      <div className="grid gap-6 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader className="flex items-center justify-between">
            <CardTitle>Upcoming</CardTitle>
            <Link to="/lms/calendar" className="text-xs font-medium text-brand-700 hover:underline">
              Lihat kalender
            </Link>
          </CardHeader>
          <CardBody className="space-y-4">
            {data.upcoming.assignments.length > 0 && (
              <ul className="space-y-2">
                {data.upcoming.assignments.map((assignment) => {
                  const due = dueLabel(assignment.days_left);
                  return (
                    <li key={assignment.id} className="flex items-center gap-3 rounded-xl border border-slate-200 px-3 py-2.5">
                      <ItemIcon icon="clipboard-list" tone="amber" />
                      <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-medium text-slate-800">{assignment.title}</p>
                        <p className="text-[11px] text-slate-500">
                          {assignment.subject ?? 'Tugas'} • {assignment.due_date ? formatDate(assignment.due_date) : 'tanpa tenggat'}
                        </p>
                      </div>
                      <Badge tone={due.tone}>{due.text}</Badge>
                    </li>
                  );
                })}
              </ul>
            )}
            <CalendarAgendaList agenda={data.calendar} compact />
          </CardBody>
        </Card>

        <div className="space-y-6">
          <Card>
            <CardHeader>
              <CardTitle>Pengumuman</CardTitle>
            </CardHeader>
            <CardBody>
              <AnnouncementList announcements={data.announcements} showCourse emptyText="Belum ada pengumuman." />
            </CardBody>
          </Card>

          <Card>
            <CardHeader className="flex items-center justify-between">
              <CardTitle>Aktivitas terbaru</CardTitle>
              <Link to="/lms/bookmarks" className="text-xs font-medium text-brand-700 hover:underline">
                Bookmark ({data.bookmarks})
              </Link>
            </CardHeader>
            <CardBody>
              {data.recent_activity.length === 0 ? (
                <EmptyState title="Belum ada aktivitas" description="Materi yang Anda buka akan tercatat di sini." />
              ) : (
                <ul className="space-y-2">
                  {data.recent_activity.map((activity, index) => (
                    <li key={`${activity.item_id}-${index}`} className="flex items-start gap-3">
                      <ItemIcon icon={activity.type === 'completed' ? 'check' : 'play-circle'} tone={activity.type === 'completed' ? 'brand' : 'slate'} />
                      <div className="min-w-0">
                        <p className="truncate text-sm font-medium text-slate-800">{activity.title ?? 'Materi'}</p>
                        <p className="text-[11px] text-slate-500">
                          {activity.type === 'completed' ? 'Selesai' : 'Dibuka'} • {relativeTime(activity.at)}
                        </p>
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </CardBody>
          </Card>
        </div>
      </div>

      <section className="space-y-3">
        <h2 className="flex items-center gap-2 text-sm font-semibold text-slate-800">
          <BookOpenCheck className="h-4 w-4 text-brand-600" aria-hidden="true" /> Course saya
        </h2>
        {data.my_courses.length === 0 ? (
          <EmptyState title="Belum ada course" />
        ) : (
          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            {data.my_courses.map((course) => (
              <div key={course.course_id} className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <Link to={`/lms/courses/${course.course_id}`} className="text-sm font-semibold text-slate-900 hover:text-brand-700">
                  {course.name}
                </Link>
                <p className="text-xs text-slate-500">{course.code}</p>
                <div className="mt-3">
                  <ProgressBar value={course.progress_percent} showLabel />
                </div>
                <div className="mt-3 flex items-center justify-between">
                  <Badge tone={course.status === 'COMPLETED' ? 'green' : 'brand'}>
                    {course.completed_items}/{course.required_items} materi
                  </Badge>
                  <Link to={`/lms/courses/${course.course_id}/learn`}>
                    <Button size="sm" variant="outline">
                      Lanjutkan
                    </Button>
                  </Link>
                </div>
              </div>
            ))}
          </div>
        )}
      </section>
    </div>
  );
}

function HeroStat({ label, value }: { label: string; value: string | number }) {
  return (
    <div className="rounded-xl bg-white/10 px-3 py-2 backdrop-blur-sm">
      <p className="text-[11px] uppercase tracking-wide text-white/70">{label}</p>
      <p className="text-lg font-semibold">{value}</p>
    </div>
  );
}

function ContinueCard({ card }: { card: ContinueLearningCard }) {
  return (
    <div className="lms-continue-card flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <Link to={`/lms/courses/${card.course_id}`} className="truncate text-sm font-semibold text-slate-900 hover:text-brand-700">
            {card.name}
          </Link>
          <p className="text-xs text-slate-500">{card.code}</p>
        </div>
        <Badge tone="brand">{Math.round(card.progress_percent)}%</Badge>
      </div>
      <ProgressBar value={card.progress_percent} />
      {card.next_item ? (
        <p className="line-clamp-1 text-xs text-slate-600">
          Selanjutnya: <span className="font-medium text-slate-800">{card.next_item.title}</span>
        </p>
      ) : (
        <p className="text-xs text-emerald-600">Semua materi telah selesai 🎉</p>
      )}
      <Link to={`/lms/courses/${card.course_id}/learn`} className="mt-auto">
        <Button size="sm" className="w-full">
          Continue →
        </Button>
      </Link>
    </div>
  );
}

// -------------------------------------------------------------- GURU (§27)
export function TeacherDashboardView({ data }: { data: TeacherDashboard }) {
  return (
    <div className="space-y-6">
      <header className="lms-hero rounded-2xl bg-gradient-to-r from-slate-900 via-brand-800 to-emerald-900 px-5 py-6 text-white shadow-sm">
        <p className="text-sm text-white/80">{data.greeting}</p>
        <h1 className="mt-1 text-xl font-semibold sm:text-2xl">Ruang kerja guru</h1>
        <div className="mt-4 flex flex-wrap gap-2">
          <Link to="/lms/courses/new">
            <Button size="sm" className="bg-white text-brand-700 hover:bg-white/90">
              Buat course baru
            </Button>
          </Link>
          <Link to="/lms/courses?scope=MANAGED">
            <Button size="sm" variant="outline" className="border-white/40 bg-white/10 text-white hover:bg-white/20">
              Kelola course saya
            </Button>
          </Link>
        </div>
      </header>

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Course saya" value={data.stats.courses} icon={Layers} hint={`${data.stats.published_courses} terbit • ${data.stats.draft_courses} draf`} />
        <StatCard label="Siswa aktif" value={data.stats.active_students} icon={Users} tone="sky" />
        <StatCard label="Menunggu penilaian" value={data.stats.pending_grading} icon={ClipboardCheck} tone="amber" hint={`${data.stats.graded} sudah dinilai`} />
        <StatCard label="Aktivitas hari ini" value={data.stats.completions_today} icon={Activity} tone="violet" hint="materi dituntaskan" />
      </div>

      {data.stats.items_needing_setup > 0 && (
        <div className="flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
          <AlertTriangle className="mt-0.5 h-4 w-4" aria-hidden="true" />
          <p>
            {data.stats.items_needing_setup} materi belum memiliki aturan penyelesaian, sehingga tidak dihitung dalam progress siswa.
          </p>
        </div>
      )}

      <div className="grid gap-6 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader>
            <CardTitle>Course saya</CardTitle>
          </CardHeader>
          <CardBody className="space-y-3">
            {data.my_courses.length === 0 ? (
              <EmptyState title="Belum ada course" description="Mulai dengan membuat course pertama Anda." />
            ) : (
              data.my_courses.map((course) => (
                <div key={course.id} className="flex flex-wrap items-center gap-3 rounded-xl border border-slate-200 px-3 py-3">
                  <div className="min-w-0 flex-1">
                    <Link to={`/lms/courses/${course.id}`} className="truncate text-sm font-semibold text-slate-900 hover:text-brand-700">
                      {course.name}
                    </Link>
                    <p className="text-[11px] text-slate-500">
                      {course.code} • {course.students_count} siswa • {course.items_count} materi
                    </p>
                  </div>
                  <div className="w-32">
                    <ProgressBar value={course.average_progress} size="sm" showLabel />
                  </div>
                  <Badge tone={course.status === 'PUBLISHED' ? 'green' : course.status === 'DRAFT' ? 'amber' : 'gray'}>
                    {course.status === 'PUBLISHED' ? 'Terbit' : course.status === 'DRAFT' ? 'Draf' : 'Arsip'}
                  </Badge>
                  <Link to={`/lms/courses/${course.id}/edit`}>
                    <Button size="sm" variant="outline">
                      Kelola
                    </Button>
                  </Link>
                </div>
              ))
            )}
          </CardBody>
        </Card>

        <div className="space-y-6">
          <Card>
            <CardHeader>
              <CardTitle>Pending grading</CardTitle>
            </CardHeader>
            <CardBody>
              {data.pending_grading.length === 0 ? (
                <EmptyState title="Tidak ada yang menunggu" description="Semua pengumpulan sudah dinilai." />
              ) : (
                <ul className="space-y-2">
                  {data.pending_grading.map((submission) => (
                    <li key={submission.id} className="rounded-xl border border-slate-200 px-3 py-2">
                      <p className="truncate text-sm font-medium text-slate-800">{submission.student_name}</p>
                      <p className="truncate text-[11px] text-slate-500">{submission.title}</p>
                      <p className="text-[11px] text-amber-700">
                        Dikumpulkan {relativeTime(submission.submitted_at)}
                      </p>
                    </li>
                  ))}
                </ul>
              )}
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Tenggat terdekat</CardTitle>
            </CardHeader>
            <CardBody>
              {data.upcoming_deadlines.length === 0 ? (
                <EmptyState title="Tidak ada tenggat" />
              ) : (
                <ul className="space-y-2">
                  {data.upcoming_deadlines.map((assignment) => (
                    <li key={assignment.id} className="rounded-xl border border-slate-200 px-3 py-2">
                      <p className="truncate text-sm font-medium text-slate-800">{assignment.title}</p>
                      <p className="text-[11px] text-slate-500">
                        {assignment.class_name} • {assignment.due_date ? formatDate(assignment.due_date) : '-'}
                      </p>
                    </li>
                  ))}
                </ul>
              )}
            </CardBody>
          </Card>
        </div>
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Konversi SIAKAD → Course LMS</CardTitle>
          </CardHeader>
          <CardBody>
            {data.academic_coverage.length === 0 ? (
              <EmptyState title="Tidak ada mapel terjadwal" description="Mapel yang Anda ampu akan muncul di sini." />
            ) : (
              <ul className="space-y-2">
                {data.academic_coverage.map((row) => (
                  <li key={`${row.academic_course_id}-${row.class_id}`} className="flex items-center gap-3 rounded-xl border border-slate-200 px-3 py-2">
                    <ItemIcon icon="book-open" tone={row.has_lms_course ? 'brand' : 'amber'} />
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-medium text-slate-800">{row.academic_course_name}</p>
                      <p className="text-[11px] text-slate-500">
                        {row.academic_course_code} • {row.class_name ?? 'lintas kelas'}
                      </p>
                    </div>
                    {row.has_lms_course ? (
                      <Link to={`/lms/courses/${row.lms_course_id}`}>
                        <Badge tone="green">Course siap</Badge>
                      </Link>
                    ) : (
                      <Link to={`/lms/courses/new?subject=${row.academic_course_id}&class=${row.class_id ?? ''}`}>
                        <Button size="sm" variant="outline">
                          Buat course
                        </Button>
                      </Link>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Aktivitas course 7 hari</CardTitle>
          </CardHeader>
          <CardBody>
            {data.course_activity.length === 0 ? (
              <EmptyState title="Belum ada aktivitas" />
            ) : (
              <ul className="space-y-2">
                {data.course_activity.map((activity, index) => (
                  <li key={`${activity.at}-${index}`} className="flex items-center gap-3">
                    <CheckCircle2 className="h-4 w-4 text-emerald-500" aria-hidden="true" />
                    <div className="min-w-0">
                      <p className="truncate text-sm text-slate-800">{activity.item_title}</p>
                      <p className="text-[11px] text-slate-500">
                        {activity.course_name} • {relativeTime(activity.at)}
                      </p>
                    </div>
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

// ------------------------------------------------------------ ORANG TUA (§28)
export function ParentDashboardView({ data }: { data: ParentDashboard }) {
  return (
    <div className="space-y-6">
      <header className="lms-hero rounded-2xl bg-gradient-to-r from-sky-700 via-brand-700 to-emerald-700 px-5 py-6 text-white shadow-sm">
        <p className="text-sm text-white/80">{data.greeting}</p>
        <h1 className="mt-1 text-xl font-semibold sm:text-2xl">Pantau belajar anak</h1>
        <p className="mt-2 max-w-2xl text-xs text-white/80">
          Anda melihat progress, tenggat, dan nilai anak sesuai kebijakan sekolah. Wali tidak dapat mengubah nilai,
          mengumpulkan tugas, atau mengerjakan kuis atas nama anak.
        </p>
      </header>

      {data.children.length === 0 ? (
        <EmptyState title="Belum ada anak yang tertaut" description="Hubungi admin sekolah untuk menautkan akun wali dengan data siswa." />
      ) : (
        <div className="grid gap-5 md:grid-cols-2">
          {data.children.map((child) => (
            <Card key={child.student.id}>
              <CardHeader className="flex items-center gap-3">
                <span className="inline-flex h-10 w-10 items-center justify-center rounded-full bg-brand-100 text-sm font-semibold text-brand-700">
                  {child.student.name.slice(0, 2).toUpperCase()}
                </span>
                <div className="min-w-0">
                  <CardTitle className="truncate">{child.student.name}</CardTitle>
                  <p className="text-xs text-slate-500">
                    {child.student.code ?? '-'} • {child.student.class_name ?? 'tanpa kelas'}
                  </p>
                </div>
                <Link to={`/lms/parent/${child.student.id}`} className="ml-auto">
                  <Button size="sm" variant="outline">
                    Detail
                  </Button>
                </Link>
              </CardHeader>
              <CardBody className="space-y-3">
                <div className="grid grid-cols-3 gap-2 text-center">
                  <div className="rounded-xl bg-slate-50 px-2 py-2">
                    <p className="text-[11px] text-slate-500">Course</p>
                    <p className="text-base font-semibold text-slate-900">{child.courses}</p>
                  </div>
                  <div className="rounded-xl bg-slate-50 px-2 py-2">
                    <p className="text-[11px] text-slate-500">Selesai</p>
                    <p className="text-base font-semibold text-slate-900">{child.completed_courses}</p>
                  </div>
                  <div className="rounded-xl bg-slate-50 px-2 py-2">
                    <p className="text-[11px] text-slate-500">Progress</p>
                    <p className="text-base font-semibold text-slate-900">{Math.round(child.average_progress)}%</p>
                  </div>
                </div>
                <ProgressBar value={child.average_progress} />
                {child.upcoming_assignments.length > 0 && (
                  <ul className="space-y-1 text-xs text-slate-600">
                    {child.upcoming_assignments.slice(0, 2).map((assignment) => (
                      <li key={assignment.id} className="flex items-center justify-between gap-2">
                        <span className="truncate">{assignment.title}</span>
                        <Badge tone={dueLabel(assignment.days_left).tone}>{dueLabel(assignment.days_left).text}</Badge>
                      </li>
                    ))}
                  </ul>
                )}
              </CardBody>
            </Card>
          ))}
        </div>
      )}

      <p className="text-xs text-slate-500">
        Kebijakan akses: nilai {data.policy.can_view_grades ? 'dapat dilihat' : 'disembunyikan'} • umpan balik guru{' '}
        {data.policy.can_view_feedback ? 'dapat dilihat' : 'disembunyikan'}.
      </p>
    </div>
  );
}

// --------------------------------------------------------------- ADMIN (§29)
export function AdminDashboardView({ data }: { data: AdminDashboard }) {
  const maxEnrollment = Math.max(1, ...data.enrollment_trend.map((row) => row.total));
  const maxActivity = Math.max(1, ...data.activity_trend.map((row) => row.completions));

  return (
    <div className="space-y-6">
      <header className="lms-hero rounded-2xl bg-gradient-to-r from-slate-900 to-brand-900 px-5 py-6 text-white shadow-sm">
        <p className="text-sm text-white/80">{data.greeting}</p>
        <h1 className="mt-1 text-xl font-semibold sm:text-2xl">Pusat kendali LMS</h1>
        <div className="mt-4 flex flex-wrap gap-2 text-xs">
          <Badge className="bg-slate-900/45 text-white">{data.integration.label}</Badge>
          <Badge className="bg-slate-900/45 text-white">Sumber akademik: {data.integration.academic_source}</Badge>
          <Badge className="bg-slate-900/45 text-white">
            {data.integration.self_enrollment ? 'Pendaftaran mandiri aktif' : 'Pendaftaran mandiri nonaktif'}
          </Badge>
        </div>
      </header>

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Total course" value={data.stats.total_courses} icon={Layers} hint={`${data.stats.active_courses} terbit`} />
        <StatCard label="Enrollment" value={data.stats.enrollments} icon={UserCheck} tone="sky" hint={`${data.stats.students} siswa • ${data.stats.teachers} guru`} />
        <StatCard label="Tingkat penyelesaian" value={`${Math.round(data.stats.completion_rate)}%`} icon={TrendingUp} tone="violet" />
        <StatCard label="Belajar aktif 7 hari" value={data.stats.active_learning_7d} icon={Activity} tone="amber" hint={`${data.stats.pending_content} course tanpa materi`} />
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader className="flex items-center gap-2">
            <BarChart3 className="h-4 w-4 text-brand-600" aria-hidden="true" />
            <CardTitle>Tren enrollment (6 bulan)</CardTitle>
          </CardHeader>
          <CardBody className="space-y-2">
            {data.enrollment_trend.map((row) => (
              <div key={row.month} className="flex items-center gap-3">
                <span className="w-20 shrink-0 text-xs text-slate-500">{row.label}</span>
                <div className="h-3 flex-1 overflow-hidden rounded-full bg-slate-100">
                  <div className="h-full rounded-full bg-brand-500" style={{ width: `${(row.total / maxEnrollment) * 100}%` }} />
                </div>
                <span className="w-8 text-right text-xs font-medium text-slate-700">{row.total}</span>
              </div>
            ))}
          </CardBody>
        </Card>

        <Card>
          <CardHeader className="flex items-center gap-2">
            <LineChart className="h-4 w-4 text-brand-600" aria-hidden="true" />
            <CardTitle>Aktivitas belajar 14 hari</CardTitle>
          </CardHeader>
          <CardBody>
            <div className="flex h-32 items-end gap-1">
              {data.activity_trend.map((row) => (
                <div key={row.date} className="flex flex-1 flex-col items-center gap-1" title={`${row.date}: ${row.completions} materi`}>
                  <div
                    className={`w-full rounded-t ${
                      row.completions > 0 ? 'bg-gradient-to-t from-brand-500 to-emerald-400' : 'bg-slate-200'
                    }`}
                    style={{ height: `${Math.max(4, (row.completions / maxActivity) * 100)}%` }}
                  />
                </div>
              ))}
            </div>
            <p className="mt-2 text-[11px] text-slate-500">Tinggi batang = jumlah materi yang dituntaskan siswa.</p>
          </CardBody>
        </Card>
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        <Card>
          <CardHeader>
            <CardTitle>Course paling aktif</CardTitle>
          </CardHeader>
          <CardBody>
            {data.most_active_courses.length === 0 ? (
              <EmptyState title="Belum ada aktivitas" />
            ) : (
              <ul className="space-y-3">
                {data.most_active_courses.map((course) => (
                  <li key={course.course_id}>
                    <div className="flex items-center justify-between gap-2 text-sm">
                      <Link to={`/lms/courses/${course.course_id}`} className="truncate font-medium text-slate-800 hover:text-brand-700">
                        {course.name}
                      </Link>
                      <span className="text-xs text-slate-500">{course.learners} siswa</span>
                    </div>
                    <ProgressBar value={course.average_progress} size="sm" />
                  </li>
                ))}
              </ul>
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Course tidak aktif (30 hari)</CardTitle>
          </CardHeader>
          <CardBody>
            {data.inactive_courses.length === 0 ? (
              <EmptyState title="Semua course aktif" />
            ) : (
              <ul className="space-y-2">
                {data.inactive_courses.map((course) => (
                  <li key={course.id} className="flex items-center justify-between gap-2 rounded-xl border border-slate-200 px-3 py-2">
                    <span className="truncate text-sm text-slate-800">{course.name}</span>
                    <Badge tone="amber">{course.students_count} siswa</Badge>
                  </li>
                ))}
              </ul>
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Kesiapan integrasi</CardTitle>
          </CardHeader>
          <CardBody className="space-y-2 text-sm text-slate-700">
            <IntegrationRow label="Course tertaut SIAKAD" value={data.integration.courses_linked ?? 0} icon={GraduationCap} />
            <IntegrationRow label="Course tanpa tautan" value={data.integration.courses_unlinked ?? 0} icon={AlertTriangle} />
            <IntegrationRow label="Course masih draf" value={data.integration.courses_draft ?? 0} icon={CalendarClock} />
            <IntegrationRow label="Rombel tersedia" value={data.integration.classes_available ?? 0} icon={Users} />
            <p className="pt-2 text-[11px] text-slate-500">
              Guest preview {data.integration.guest_preview ? 'aktif' : 'nonaktif'} • pendaftaran mandiri{' '}
              {data.integration.self_enrollment ? 'aktif' : 'nonaktif'}
            </p>
          </CardBody>
        </Card>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Course terbaru</CardTitle>
        </CardHeader>
        <CardBody>
          {data.recent_courses.length === 0 ? (
            <EmptyState title="Belum ada course" />
          ) : (
            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
              {data.recent_courses.map((course) => (
                <CourseCard
                  key={course.id}
                  course={{
                    ...course,
                    short_description: null,
                    level: null,
                    course_type: null,
                    category: course.category ? { name: course.category } : null,
                  }}
                  to={`/lms/courses/${course.id}`}
                  compact
                />
              ))}
            </div>
          )}
        </CardBody>
      </Card>
    </div>
  );
}

function IntegrationRow({ label, value, icon: Icon }: { label: string; value: number; icon: typeof Users }) {
  return (
    <div className="flex items-center gap-2">
      <Icon className="h-4 w-4 text-slate-400" aria-hidden="true" />
      <span className="flex-1 text-xs text-slate-600">{label}</span>
      <span className="text-sm font-semibold text-slate-800">{value}</span>
    </div>
  );
}
