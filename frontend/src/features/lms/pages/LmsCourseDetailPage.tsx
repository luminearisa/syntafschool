import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useParams } from 'react-router-dom';
import {
  ArrowLeft,
  BarChart3,
  BookOpen,
  Check,
  ClipboardList,
  Layers,
  MessagesSquare,
  PlayCircle,
  Plus,
  Rocket,
  Settings2,
  Users,
} from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/Spinner';
import { cn } from '@/lib/utils';
import { lmsApi } from '../api/lmsApi';
import { AnnouncementList } from '../components/AnnouncementList';
import { AnnouncementPanel, EnrollmentPanel, GroupPanel } from '../components/CourseManagePanels';
import { ActivityWizard } from '../components/activities/ActivityWizard';
import { activityApi } from '../api/activityApi';
import type { ActivityTypeValue } from '../types';
import { ContentBuilder } from '../components/ContentBuilderPanels';
import { CurriculumAccordion } from '../components/CurriculumAccordion';
import { ProgressBar } from '../components/ProgressBar';
import { StatCard } from '../components/StatCard';
import { formatDate, STATUS_TONE, TYPE_LABEL, VISIBILITY_LABEL } from '../lib/lmsLabels';
import type { LmsLearningItem } from '../types';
import { sanitizeHtml } from '../lib/sanitizeHtml';

type TabKey = 'overview' | 'content' | 'assignments' | 'quizzes' | 'discussions' | 'grades' | 'progress' | 'manage';

/** Halaman course (§8, §9) dengan navigasi tab dan panel kelola guru/admin. */
export default function LmsCourseDetailPage() {
  const { courseId } = useParams<{ courseId: string }>();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [tab, setTab] = useState<TabKey>('overview');
  const [activityWizardSection, setActivityWizardSection] = useState<number | null>(null);

  const detail = useQuery({
    queryKey: ['lms', 'course', courseId],
    queryFn: () => lmsApi.course(Number(courseId)),
    enabled: Boolean(courseId),
  });

  const lookups = useQuery({ queryKey: ['lms', 'lookups'], queryFn: () => lmsApi.lookups(), enabled: detail.data?.capabilities.manage });

  const publish = useMutation({
    mutationFn: (force: boolean) => lmsApi.publishCourse(Number(courseId), force),
    onSuccess: () => {
      toast.success('Course diterbitkan');
      queryClient.invalidateQueries({ queryKey: ['lms', 'course', courseId] });
    },
    onError: (error: { response?: { data?: { message?: string } } }) => {
      const message = error.response?.data?.message ?? 'Course belum siap diterbitkan';
      toast.error(message);
      setTab('manage');
    },
  });

  const archive = useMutation({
    mutationFn: () => lmsApi.archiveCourse(Number(courseId)),
    onSuccess: () => {
      toast.success('Course diarsipkan');
      queryClient.invalidateQueries({ queryKey: ['lms', 'course', courseId] });
    },
  });

  const enrollSelf = useMutation({
    mutationFn: () => lmsApi.selfEnroll(Number(courseId)),
    onSuccess: () => {
      toast.success('Berhasil mendaftar course');
      queryClient.invalidateQueries({ queryKey: ['lms', 'course', courseId] });
    },
    onError: (error: { response?: { data?: { message?: string } } }) =>
      toast.error(error.response?.data?.message ?? 'Pendaftaran gagal'),
  });

  const bookmark = useMutation({
    mutationFn: () => lmsApi.toggleBookmark(Number(courseId)),
    onSuccess: (result) => toast.success(result.bookmarked ? 'Ditambahkan ke bookmark' : 'Bookmark dihapus'),
  });

  if (detail.isLoading) {
    return (
      <div className="flex flex-col items-center gap-3 py-16 text-sm text-slate-500">
        <Spinner className="h-6 w-6" /> Memuat course…
      </div>
    );
  }

  if (detail.isError || !detail.data) {
    return (
      <EmptyState
        title="Course tidak dapat dibuka"
        description="Anda mungkin belum terdaftar, course belum terbit, atau sudah diarsipkan."
        action={
          <Link to="/lms/courses">
            <Button size="sm">Kembali ke daftar course</Button>
          </Link>
        }
      />
    );
  }

  const { course, structure, progress, capabilities, statistics, access, announcements, academic } = detail.data;
  const itemsBySection = Object.fromEntries(
    structure.map((section) => [
      section.id,
      section.items.map((item) => ({
        ...item,
        resource_type_label: item.resource_type ?? undefined,
        activity_type_label: item.activity_type ?? undefined,
        completion_type_label: item.completion_type,
      })),
    ]),
  );

  const tabs: Array<{ key: TabKey; label: string; icon: typeof BookOpen }> = [
    { key: 'overview', label: 'Overview', icon: BookOpen },
    { key: 'content', label: 'Content', icon: Layers },
    { key: 'assignments', label: 'Assignments', icon: ClipboardList },
    { key: 'quizzes', label: 'Quizzes', icon: Check },
    { key: 'discussions', label: 'Discussions', icon: MessagesSquare },
    { key: 'grades', label: 'Grades', icon: BarChart3 },
    { key: 'progress', label: 'Progress', icon: PlayCircle },
    ...(capabilities.manage ? [{ key: 'manage' as TabKey, label: 'Kelola', icon: Settings2 }] : []),
  ];

  return (
    <div className="space-y-5">
      <section className="lms-course-hero overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div className="relative h-40 bg-gradient-to-r from-brand-600 via-brand-700 to-emerald-800 sm:h-48">
          {course.cover_image || course.thumbnail ? (
            <img src={course.cover_image ?? course.thumbnail ?? ''} alt="" className="h-full w-full object-cover" />
          ) : null}
          <div className="absolute inset-0 bg-gradient-to-t from-slate-950/75 via-slate-950/25 to-transparent" />
          <div className="absolute inset-x-0 bottom-0 px-5 pb-4 text-white">
            <div className="flex flex-wrap items-center gap-2">
              <Badge className="bg-slate-900/60 text-white">{course.course_type ? TYPE_LABEL[course.course_type] : 'Course'}</Badge>
              <Badge tone={STATUS_TONE[course.status]}>{course.status_label ?? course.status}</Badge>
              <Badge className="bg-slate-900/60 text-white">{course.visibility ? VISIBILITY_LABEL[course.visibility] : '-'}</Badge>
              {course.class_id && <Badge className="bg-slate-900/60 text-white">Rombel tertaut SIAKAD</Badge>}
            </div>
            <h1 className="mt-2 text-xl font-semibold sm:text-2xl">{course.name}</h1>
            <p className="text-xs text-white/80">
              {course.code}
              {course.teachers && course.teachers.length > 0
                ? ` • ${course.teachers.map((teacher) => teacher.name).filter(Boolean).join(', ')}`
                : ''}
              {course.start_date ? ` • ${formatDate(course.start_date)} – ${formatDate(course.end_date)}` : ''}
            </p>
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-4 px-5 py-4">
          <div className="min-w-[200px] flex-1">
            <ProgressBar value={progress.progress_percent} size="lg" showLabel />
            <p className="mt-1 text-xs text-slate-500">
              {progress.completed_items}/{progress.required_items} materi wajib tuntas
              {statistics ? ` • rata-rata kelas ${Math.round(statistics.average_progress)}%` : ''}
            </p>
          </div>
          <div className="flex flex-wrap gap-2">
            <Button variant="ghost" size="sm" onClick={() => navigate(-1)}>
              <ArrowLeft className="h-4 w-4" aria-hidden="true" /> Kembali
            </Button>
            <Button variant="outline" size="sm" onClick={() => bookmark.mutate()}>
              Simpan
            </Button>
            {capabilities.learn ? (
              <Link to={`/lms/courses/${course.id}/learn`}>
                <Button size="sm">
                  <PlayCircle className="h-4 w-4" aria-hidden="true" /> Continue Learning
                </Button>
              </Link>
            ) : capabilityCanSelfEnroll(course, access.allowed) ? (
              <Button size="sm" loading={enrollSelf.isPending} onClick={() => enrollSelf.mutate()}>
                Daftar course ini
              </Button>
            ) : !access.allowed ? (
              <Badge tone="amber">{access.message ?? 'Akses terbatas'}</Badge>
            ) : null}
            {capabilities.manage && (
              <>
                <Link to={`/lms/courses/${course.id}/gradebook`}>
                  <Button size="sm" variant="outline">
                    Gradebook
                  </Button>
                </Link>
                <Link to={`/lms/courses/${course.id}/edit`}>
                  <Button size="sm" variant="outline">
                    Sunting
                  </Button>
                </Link>
                {course.status !== 'PUBLISHED' ? (
                  <Button size="sm" loading={publish.isPending} onClick={() => publish.mutate(false)}>
                    <Rocket className="h-4 w-4" aria-hidden="true" /> Terbitkan
                  </Button>
                ) : (
                  <Button size="sm" variant="outline" loading={archive.isPending} onClick={() => archive.mutate()}>
                    Arsipkan
                  </Button>
                )}
              </>
            )}
          </div>
        </div>
      </section>

      <nav className="lms-course-nav sticky top-0 z-10 -mx-1 overflow-x-auto px-1 py-1">
        <ul className="flex min-w-max gap-1">
          {tabs.map((item) => {
            const Icon = item.icon;
            return (
              <li key={item.key}>
                <button
                  type="button"
                  onClick={() => setTab(item.key)}
                  className={cn(
                    'flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-medium transition',
                    tab === item.key
                      ? 'bg-brand-600 text-white shadow-sm'
                      : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50',
                  )}
                >
                  <Icon className="h-3.5 w-3.5" aria-hidden="true" /> {item.label}
                </button>
              </li>
            );
          })}
        </ul>
      </nav>

      {tab === 'overview' && (
        <div className="grid gap-6 lg:grid-cols-3">
          <div className="space-y-6 lg:col-span-2">
            <Card>
              <CardHeader>
                <CardTitle>Tentang course</CardTitle>
              </CardHeader>
              <CardBody className="space-y-4">
                {course.short_description && <p className="text-sm text-slate-700">{course.short_description}</p>}
                {course.description ? (
                  <div
                    className="lms-rich-text text-sm text-slate-700"
                    dangerouslySetInnerHTML={{ __html: sanitizeHtml(course.description) }}
                  />
                ) : (
                  <p className="text-sm text-slate-500">Deskripsi lengkap belum diisi.</p>
                )}
                {course.learning_objectives && (
                  <div>
                    <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Tujuan pembelajaran</p>
                    <div
                      className="lms-rich-text mt-1 text-sm text-slate-700"
                      dangerouslySetInnerHTML={{ __html: sanitizeHtml(course.learning_objectives) }}
                    />
                  </div>
                )}
                {academic && (
                  <div className="rounded-xl border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-900">
                    Tautan SIAKAD: <strong>{String(academic.name ?? '-')}</strong> ({String(academic.code ?? '-')})
                    {academic.class_name ? ` • ${String(academic.class_name)}` : ''}
                    {academic.teacher_name ? ` • pengampu ${String(academic.teacher_name)}` : ''}
                  </div>
                )}
              </CardBody>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>Silabus</CardTitle>
              </CardHeader>
              <CardBody>
                <CurriculumAccordion sections={structure} courseId={course.id} linkMode="course" />
              </CardBody>
            </Card>
          </div>

          <div className="space-y-6">
            <Card>
              <CardHeader>
                <CardTitle>Ringkasan</CardTitle>
              </CardHeader>
              <CardBody className="space-y-2 text-sm text-slate-700">
                <Row label="Bagian" value={statistics?.sections ?? structure.length} />
                <Row label="Materi" value={statistics?.resources ?? 0} />
                <Row label="Aktivitas" value={statistics?.activities ?? 0} />
                <Row label="Peserta" value={statistics?.students ?? progress.required_items} />
                <Row label="Penyelesaian" value={`${Math.round(statistics?.completion_rate ?? 0)}%`} />
                <Row label="Bahasa" value={course.language.toUpperCase()} />
                <Row label="Tingkat" value={course.level_label ?? course.level} />
              </CardBody>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>Pengumuman</CardTitle>
              </CardHeader>
              <CardBody>
                <AnnouncementList announcements={announcements} emptyText="Belum ada pengumuman." />
              </CardBody>
            </Card>
          </div>
        </div>
      )}

      {tab === 'content' && (
        <Card>
          <CardHeader>
            <CardTitle>Struktur pembelajaran</CardTitle>
          </CardHeader>
          <CardBody>
            <CurriculumAccordion sections={structure} courseId={course.id} linkMode={capabilities.learn ? 'learn' : 'course'} />
          </CardBody>
        </Card>
      )}

      {tab === 'assignments' && (
        <CourseActivityPanel
          courseId={course.id}
          type="ASSIGNMENT"
          title="Tugas"
          canManage={capabilities.manage}
          onAdd={(sectionId) => setActivityWizardSection(sectionId)}
        />
      )}

      {tab === 'quizzes' && (
        <CourseActivityPanel
          courseId={course.id}
          type="QUIZ"
          title="Kuis"
          canManage={capabilities.manage}
          onAdd={(sectionId) => setActivityWizardSection(sectionId)}
        />
      )}

      {tab === 'discussions' && (
        <CourseActivityPanel
          courseId={course.id}
          type="FORUM"
          title="Forum diskusi"
          canManage={capabilities.manage}
          onAdd={(sectionId) => setActivityWizardSection(sectionId)}
        />
      )}

      {tab === 'grades' && (
        <Card>
          <CardHeader>
            <CardTitle>Nilai & umpan balik</CardTitle>
          </CardHeader>
          <CardBody>
            <EmptyState
              title="Gradebook LMS menyusul pada phase berikutnya"
              description="Penilaian tugas & kuis LMS akan hadir bersama Question Bank dan Gradebook. Nilai akademik saat ini tetap dikelola modul Penilaian ERP."
            />
          </CardBody>
        </Card>
      )}

      {tab === 'progress' && (
        <div className="space-y-6">
          {statistics && (
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
              <StatCard label="Peserta" value={statistics.students} icon={Users} />
              <StatCard label="Rata-rata progress" value={`${Math.round(statistics.average_progress)}%`} icon={BarChart3} tone="sky" />
              <StatCard label="Penyelesaian" value={`${Math.round(statistics.completion_rate)}%`} icon={Check} tone="violet" />
              <StatCard label="Aktivitas" value={statistics.activities} icon={ClipboardList} tone="amber" />
            </div>
          )}
          <Card>
            <CardHeader>
              <CardTitle>Progress per bagian</CardTitle>
            </CardHeader>
            <CardBody className="space-y-3">
              {progress.sections.length === 0 ? (
                <EmptyState title="Belum ada data progress" />
              ) : (
                progress.sections.map((section) => (
                  <div key={section.section_id}>
                    <div className="flex items-center justify-between text-sm">
                      <span className="text-slate-700">{section.title}</span>
                      <span className="text-xs text-slate-500">
                        {section.completed_items}/{section.required_items}
                      </span>
                    </div>
                    <ProgressBar value={section.progress_percent} size="sm" />
                  </div>
                ))
              )}
            </CardBody>
          </Card>
        </div>
      )}

      {activityWizardSection !== null && (
        <ActivityWizard
          open
          sectionId={activityWizardSection}
          onClose={() => setActivityWizardSection(null)}
          onCreated={(activityId) => {
            toast.success('Aktivitas dibuat sebagai draf');
            void queryClient.invalidateQueries({ queryKey: ['lms', 'activities', course.id] });
            navigate(`/lms/activities/${activityId}/manage`);
          }}
        />
      )}

      {tab === 'manage' && capabilities.manage && (
        <div className="space-y-6">
          {lookups.data && (
            <ContentBuilder
              courseId={course.id}
              sections={detail.data.structure.map((section) => ({
                id: section.id,
                course_id: course.id,
                title: section.title,
                description: section.description,
                summary: section.summary,
                sort_order: section.sort_order,
                visibility: section.visibility,
                available_from: section.available_from,
                available_until: section.available_until,
                availability: null,
                completion_rule: 'ALL_ITEMS',
              }))}
              itemsBySection={itemsBySection as unknown as Record<number, LmsLearningItem[]>}
              lookups={lookups.data}
              canEdit={capabilities.edit_content}
              onChanged={() => queryClient.invalidateQueries({ queryKey: ['lms', 'course', courseId] })}
            />
          )}
          <EnrollmentPanel
            courseId={course.id}
            classId={course.class_id}
            academicYearId={course.academic_year_id}
          />
          <div className="grid gap-6 lg:grid-cols-2">
            <AnnouncementPanel courseId={course.id} />
            <GroupPanel courseId={course.id} />
          </div>
        </div>
      )}
    </div>
  );
}

function Row({ label, value }: { label: string; value: string | number | undefined }) {
  return (
    <div className="flex items-center justify-between gap-2 border-b border-slate-50 pb-1 last:border-0">
      <span className="text-xs text-slate-500">{label}</span>
      <span className="text-sm font-medium text-slate-800">{value ?? '-'}</span>
    </div>
  );
}


function capabilityCanSelfEnroll(course: { course_type: string; visibility: string; status: string }, allowed: boolean): boolean {
  return !allowed && course.course_type !== 'ACADEMIC' && course.visibility === 'PUBLIC' && course.status === 'PUBLISHED';
}


/**
 * Panel aktivitas Phase 2 pada halaman course (§43).
 *
 * Menampilkan aktivitas per tipe beserta status, tenggat, dan tautan ke halaman
 * pengerjaan/kelola; guru dapat menambah aktivitas lewat wizard.
 */
function CourseActivityPanel({
  courseId,
  type,
  title,
  canManage,
  onAdd,
}: {
  courseId: number;
  type: ActivityTypeValue;
  title: string;
  canManage: boolean;
  onAdd: (sectionId: number) => void;
}) {
  const structure = useQuery({
    queryKey: ['lms', 'course', courseId],
    queryFn: () => lmsApi.course(courseId),
  });

  const activities = useQuery({
    queryKey: ['lms', 'activities', courseId, type],
    queryFn: () => activityApi.listForCourse(courseId, { type }),
  });

  const sections = structure.data?.structure ?? [];
  const firstSection = sections[0]?.id ?? null;

  return (
    <Card>
      <CardHeader className="flex flex-wrap items-center justify-between gap-2">
        <CardTitle>{title}</CardTitle>
        {canManage && firstSection !== null && (
          <Button size="sm" onClick={() => onAdd(firstSection)}>
            <Plus className="mr-1 h-4 w-4" /> Aktivitas baru
          </Button>
        )}
      </CardHeader>
      <CardBody className="space-y-3">
        {activities.isLoading && <Spinner className="h-5 w-5" />}

        {!activities.isLoading && (activities.data?.data ?? []).length === 0 && (
          <EmptyState
            title={`Belum ada ${title.toLowerCase()}`}
            description={canManage ? 'Tambahkan aktivitas agar siswa dapat mengerjakannya.' : 'Guru belum menambahkan aktivitas ini.'}
          />
        )}

        {(activities.data?.data ?? []).map((activity) => (
          <div key={activity.id} className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 p-3">
            <div className="min-w-0">
              <div className="flex flex-wrap items-center gap-2">
                <Link to={`/lms/activities/${activity.id}`} className="truncate text-sm font-semibold text-slate-900 hover:text-brand-700">
                  {activity.title}
                </Link>
                <Badge tone={activity.status === 'PUBLISHED' ? 'green' : activity.status === 'DRAFT' ? 'gray' : 'amber'}>
                  {activity.status_label}
                </Badge>
                {activity.is_graded && <Badge tone="brand">Dinilai</Badge>}
              </div>
              <p className="mt-1 text-xs text-slate-500">
                {activity.due_at ? `Tenggat ${formatDate(activity.due_at, true)}` : 'Tanpa tenggat'}
                {activity.learning_item ? ` · ${activity.learning_item.title}` : ''}
              </p>
            </div>

            <div className="flex items-center gap-2">
              <Link to={`/lms/activities/${activity.id}`}>
                <Button size="sm" variant="outline">
                  {canManage ? 'Pratinjau' : 'Kerjakan'}
                </Button>
              </Link>
              {canManage && (
                <Link to={`/lms/activities/${activity.id}/manage`}>
                  <Button size="sm">Kelola</Button>
                </Link>
              )}
            </div>
          </div>
        ))}
      </CardBody>
    </Card>
  );
}
