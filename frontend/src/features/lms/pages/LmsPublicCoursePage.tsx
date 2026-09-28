import { useQuery } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import { GraduationCap, Lock, Users } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/Spinner';
import { lmsApi } from '../api/lmsApi';
import { ItemIcon } from '../components/ItemIcon';
import { LEVEL_LABEL, TYPE_LABEL, formatDate } from '../lib/lmsLabels';
import { sanitizeHtml } from '../lib/sanitizeHtml';

/**
 * Detail course publik (§30).
 *
 * Silabus ditampilkan terbuka, tetapi isi materi hanya untuk yang bertanda
 * "pratinjau" — sisanya terkunci sampai pengguna terdaftar.
 */
export default function LmsPublicCoursePage() {
  const { slug } = useParams<{ slug: string }>();

  const course = useQuery({
    queryKey: ['lms', 'public-course', slug],
    queryFn: () => lmsApi.publicCourse(String(slug)),
    enabled: Boolean(slug),
  });

  if (course.isLoading) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-slate-50">
        <Spinner className="h-6 w-6" />
      </div>
    );
  }

  if (course.isError || !course.data) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-slate-50 p-4">
        <EmptyState
          title="Course tidak ditemukan"
          description="Course mungkin belum diterbitkan, bersifat privat, atau tautannya salah."
          action={
            <Link to="/learn/courses">
              <Button size="sm">Lihat katalog publik</Button>
            </Link>
          }
        />
      </div>
    );
  }

  const { course: detail, sections, preview_items: previewItems } = course.data;

  return (
    <div className="min-h-screen bg-slate-50">
      <header className="border-b border-slate-200 bg-white">
        <div className="mx-auto flex max-w-5xl items-center gap-3 px-4 py-3">
          <Link to="/learn" className="flex items-center gap-2 text-sm font-semibold text-slate-900">
            <span className="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-brand-600 text-white">
              <GraduationCap className="h-4 w-4" aria-hidden="true" />
            </span>
            LMS Sekolah
          </Link>
          <Link to="/learn/courses" className="ml-auto text-sm text-slate-600 hover:text-brand-700">
            ← Katalog
          </Link>
        </div>
      </header>

      <main className="mx-auto max-w-5xl space-y-6 px-4 py-8">
        <section className="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
          <div className="relative h-44 bg-gradient-to-r from-brand-600 to-emerald-800 sm:h-56">
            {detail.cover_image || detail.thumbnail ? (
              <img src={detail.cover_image ?? detail.thumbnail ?? ''} alt="" className="h-full w-full object-cover" />
            ) : null}
            <div className="absolute inset-0 bg-gradient-to-t from-slate-950/75 to-transparent" />
            <div className="absolute inset-x-0 bottom-0 p-5 text-white">
              <div className="flex flex-wrap gap-2">
                <Badge className="bg-slate-900/60 text-white">{detail.course_type ? TYPE_LABEL[detail.course_type] : 'Course'}</Badge>
                <Badge className="bg-slate-900/60 text-white">{LEVEL_LABEL[detail.level]}</Badge>
                <Badge className="bg-slate-900/60 text-white">{detail.language.toUpperCase()}</Badge>
              </div>
              <h1 className="mt-2 text-xl font-semibold sm:text-2xl">{detail.name}</h1>
              <p className="text-xs text-white/85">
                {(detail.teachers ?? []).map((teacher) => teacher.name).filter(Boolean).join(', ') || 'Pengajar LMS'}
                {detail.start_date ? ` • ${formatDate(detail.start_date)} – ${formatDate(detail.end_date)}` : ''}
              </p>
            </div>
          </div>
          <div className="flex flex-wrap items-center gap-3 px-5 py-4">
            <div className="flex flex-wrap gap-3 text-xs text-slate-600">
              <span className="inline-flex items-center gap-1">
                <Users className="h-3.5 w-3.5" aria-hidden="true" /> {detail.statistics?.students ?? 0} peserta
              </span>
              <span>{sections.length} bagian</span>
              <span>{detail.statistics?.items ?? previewItems.length} materi</span>
            </div>
            <div className="ml-auto flex gap-2">
              <Link to="/login">
                <Button size="sm">Masuk untuk mengikuti</Button>
              </Link>
            </div>
          </div>
        </section>

        <div className="grid gap-6 lg:grid-cols-3">
          <div className="space-y-6 lg:col-span-2">
            <Card>
              <CardHeader>
                <CardTitle>Tentang course</CardTitle>
              </CardHeader>
              <CardBody className="space-y-3">
                {detail.short_description && <p className="text-sm text-slate-700">{detail.short_description}</p>}
                {detail.description ? (
                  <div className="lms-rich-text text-sm text-slate-700" dangerouslySetInnerHTML={{ __html: sanitizeHtml(detail.description) }} />
                ) : (
                  <p className="text-sm text-slate-500">Deskripsi belum tersedia.</p>
                )}
                {detail.learning_objectives && (
                  <div>
                    <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Tujuan pembelajaran</p>
                    <div
                      className="lms-rich-text mt-1 text-sm text-slate-700"
                      dangerouslySetInnerHTML={{ __html: sanitizeHtml(detail.learning_objectives) }}
                    />
                  </div>
                )}
              </CardBody>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>Silabus</CardTitle>
              </CardHeader>
              <CardBody>
                {sections.length === 0 ? (
                  <EmptyState title="Silabus belum tersedia" />
                ) : (
                  <ol className="space-y-4">
                    {sections.map((section, index) => (
                      <li key={section.id}>
                        <p className="flex items-center gap-2 text-sm font-semibold text-slate-800">
                          <span className="inline-flex h-6 w-6 items-center justify-center rounded bg-brand-50 text-[11px] text-brand-700">
                            {index + 1}
                          </span>
                          {section.title}
                          <Badge tone="gray">{section.items_count} materi</Badge>
                        </p>
                        {section.summary && <p className="mt-1 text-xs text-slate-500">{section.summary}</p>}
                        <ul className="mt-2 space-y-1">
                          {previewItems
                            .filter((item) => item.section_title === section.title)
                            .map((item) => (
                              <li key={item.id} className="flex items-center gap-2 text-xs text-slate-600">
                                <ItemIcon icon={item.resource_type ?? item.activity_type ?? 'file'} className="h-6 w-6" />
                                <span className="truncate">{item.title}</span>
                                {item.locked && <Lock className="h-3 w-3 text-slate-400" aria-label="Terkunci" />}
                                {item.is_preview && <Badge tone="blue">pratinjau</Badge>}
                              </li>
                            ))}
                        </ul>
                      </li>
                    ))}
                  </ol>
                )}
              </CardBody>
            </Card>
          </div>

          <div className="space-y-4">
            <Card>
              <CardHeader>
                <CardTitle>Informasi</CardTitle>
              </CardHeader>
              <CardBody className="space-y-2 text-sm text-slate-700">
                <InfoRow label="Kode" value={detail.code} />
                <InfoRow label="Kategori" value={detail.category?.name ?? '-'} />
                <InfoRow label="Tingkat" value={LEVEL_LABEL[detail.level]} />
                <InfoRow label="Visibilitas" value={detail.visibility === 'PUBLIC' ? 'Publik' : 'Terbatas'} />
                <InfoRow label="Mulai" value={formatDate(detail.start_date)} />
                <InfoRow label="Selesai" value={formatDate(detail.end_date)} />
              </CardBody>
            </Card>

            <Card>
              <CardBody className="space-y-2 text-xs text-slate-600">
                <p className="flex items-center gap-2 font-medium text-slate-800">
                  <Lock className="h-3.5 w-3.5" aria-hidden="true" /> Materi penuh hanya untuk peserta
                </p>
                <p>
                  Silabus terbuka agar Anda dapat menilai kesesuaiannya. Isi materi, aktivitas, dan penilaian terbuka
                  setelah terdaftar sebagai peserta course.
                </p>
                <Link to="/login">
                  <Button size="sm" variant="outline" className="mt-1 w-full">
                    Masuk / daftar peserta
                  </Button>
                </Link>
              </CardBody>
            </Card>
          </div>
        </div>
      </main>
    </div>
  );
}

function InfoRow({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-center justify-between gap-2 border-b border-slate-50 pb-1 last:border-0">
      <span className="text-xs text-slate-500">{label}</span>
      <span className="text-sm font-medium text-slate-800">{value}</span>
    </div>
  );
}
