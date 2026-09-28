import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { BookOpen, GraduationCap, Layers, Search, Sparkles, Users } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/Spinner';
import { useAuthStore } from '@/stores/authStore';
import { lmsApi } from '../api/lmsApi';
import { CourseCard } from '../components/CourseCard';

/** Landing LMS publik (§30): hanya course ber-visibility PUBLIC yang tampil. */
export default function LmsPublicHomePage() {
  const token = useAuthStore((state) => state.token);

  const home = useQuery({ queryKey: ['lms', 'public-home'], queryFn: () => lmsApi.publicHome() });
  const courses = useQuery({
    queryKey: ['lms', 'public-courses', 'home'],
    queryFn: () => lmsApi.publicCourses({ per_page: 6, sort: 'newest' }),
  });

  return (
    <div className="min-h-screen bg-slate-50">
      <header className="border-b border-slate-200 bg-white">
        <div className="mx-auto flex max-w-6xl items-center gap-3 px-4 py-3">
          <Link to="/learn" className="flex items-center gap-2 text-sm font-semibold text-slate-900">
            <span className="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-brand-600 text-white">
              <GraduationCap className="h-4 w-4" aria-hidden="true" />
            </span>
            LMS Sekolah
          </Link>
          <nav className="ml-auto flex items-center gap-1 text-sm">
            <Link to="/learn/courses" className="rounded-lg px-3 py-1.5 text-slate-600 hover:bg-slate-100">
              Course
            </Link>
            <Link to="/learn/teachers" className="rounded-lg px-3 py-1.5 text-slate-600 hover:bg-slate-100">
              Guru
            </Link>
            {token ? (
              <Link to="/lms">
                <Button size="sm">Buka LMS saya</Button>
              </Link>
            ) : (
              <Link to="/login">
                <Button size="sm" variant="outline">
                  Masuk
                </Button>
              </Link>
            )}
          </nav>
        </div>
      </header>

      <main className="mx-auto max-w-6xl space-y-10 px-4 py-10">
        <section className="lms-public-hero grid gap-6 rounded-3xl bg-gradient-to-br from-brand-600 via-brand-700 to-emerald-800 px-6 py-10 text-white md:grid-cols-2 md:items-center">
          <div className="space-y-4">
            <Badge className="bg-white/20 text-white">
              <Sparkles className="mr-1 h-3 w-3" aria-hidden="true" /> Belajar terstruktur
            </Badge>
            <h1 className="text-2xl font-semibold leading-tight sm:text-3xl">
              {home.data?.hero.title ?? 'Belajar tanpa batas bersama LMS sekolah'}
            </h1>
            <p className="text-sm text-white/85">
              {home.data?.hero.subtitle ?? 'Materi, aktivitas, dan progress belajar dalam satu tempat.'}
            </p>
            <div className="flex flex-wrap gap-2">
              <Link to="/learn/courses">
                <Button className="bg-white text-brand-700 hover:bg-white/90">
                  <BookOpen className="mr-1 h-4 w-4" aria-hidden="true" /> Jelajahi course
                </Button>
              </Link>
              <Link to="/lms/search">
                <Button variant="outline" className="border-white/40 bg-white/10 text-white hover:bg-white/20">
                  <Search className="mr-1 h-4 w-4" aria-hidden="true" /> Cari materi
                </Button>
              </Link>
            </div>
          </div>
          <div className="grid grid-cols-3 gap-3">
            <HeroStat icon={BookOpen} label="Course publik" value={home.data?.hero.stats.courses ?? 0} />
            <HeroStat icon={Layers} label="Kategori" value={home.data?.hero.stats.categories ?? 0} />
            <HeroStat icon={Users} label="Materi" value={home.data?.hero.stats.lessons ?? 0} />
          </div>
        </section>

        <section className="space-y-3">
          <div className="flex items-center justify-between">
            <h2 className="text-base font-semibold text-slate-900">Course unggulan</h2>
            <Link to="/learn/courses" className="text-xs font-medium text-brand-700 hover:underline">
              Lihat semua
            </Link>
          </div>
          {courses.isLoading || home.isLoading ? (
            <div className="flex justify-center py-10">
              <Spinner />
            </div>
          ) : (home.data?.featured_courses ?? []).length === 0 ? (
            <EmptyState title="Belum ada course publik" description="Course yang diterbitkan dengan visibilitas publik akan tampil di sini." />
          ) : (
            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
              {(home.data?.featured_courses ?? []).map((course) => (
                <CourseCard key={course.id} course={course} to={`/learn/courses/${course.slug}`} />
              ))}
            </div>
          )}
        </section>

        <section className="grid gap-6 md:grid-cols-3">
          <Card className="md:col-span-2">
            <CardHeader>
              <CardTitle>Kategori</CardTitle>
            </CardHeader>
            <CardBody>
              {(home.data?.categories ?? []).length === 0 ? (
                <EmptyState title="Belum ada kategori" />
              ) : (
                <ul className="grid gap-2 sm:grid-cols-2">
                  {(home.data?.categories ?? []).map((category) => (
                    <li key={category.id}>
                      <Link
                        to={`/learn/courses?category_id=${category.id}`}
                        className="flex items-center justify-between gap-2 rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-700 hover:border-brand-300 hover:bg-brand-50/60"
                      >
                        <span className="truncate">{category.name}</span>
                        <Badge tone="gray">{category.courses_count ?? 0}</Badge>
                      </Link>
                    </li>
                  ))}
                </ul>
              )}
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Guru pengajar</CardTitle>
            </CardHeader>
            <CardBody>
              {(home.data?.teachers ?? []).length === 0 ? (
                <EmptyState title="Belum ada guru" />
              ) : (
                <ul className="space-y-2">
                  {(home.data?.teachers ?? []).map((teacher) => (
                    <li key={teacher.id} className="flex items-center gap-3">
                      <span className="inline-flex h-8 w-8 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-700">
                        {teacher.name.slice(0, 2).toUpperCase()}
                      </span>
                      <span className="min-w-0">
                        <span className="block truncate text-sm font-medium text-slate-800">{teacher.name}</span>
                        <span className="block text-[11px] text-slate-500">{teacher.nip ?? 'pengajar LMS'}</span>
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </CardBody>
          </Card>
        </section>

        <section className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {[
            { title: 'Course terstruktur', description: 'Materi disusun per bagian dengan aktivitas yang terukur.' },
            { title: 'Progress transparan', description: 'Siswa, guru, dan orang tua melihat angka yang sama.' },
            { title: 'Terhubung SIAKAD', description: 'Peserta course akademik tersinkron dari rombel.' },
            { title: 'Portal orang tua', description: 'Wali memantau belajar anak tanpa bisa mengubah nilai.' },
          ].map((feature) => (
            <Card key={feature.title}>
              <CardBody className="space-y-1">
                <p className="text-sm font-semibold text-slate-800">{feature.title}</p>
                <p className="text-xs text-slate-600">{feature.description}</p>
              </CardBody>
            </Card>
          ))}
        </section>
      </main>

      <footer className="border-t border-slate-200 bg-white py-6 text-center text-xs text-slate-500">
        LMS Sekolah • {home.data?.integration ? String(home.data.integration.label ?? '') : ''}
      </footer>
    </div>
  );
}

function HeroStat({ icon: Icon, label, value }: { icon: typeof BookOpen; label: string; value: number }) {
  return (
    <div className="rounded-2xl bg-white/10 px-3 py-3 text-center backdrop-blur-sm">
      <Icon className="mx-auto h-4 w-4 text-white/80" aria-hidden="true" />
      <p className="mt-1 text-lg font-semibold">{value}</p>
      <p className="text-[11px] text-white/70">{label}</p>
    </div>
  );
}
