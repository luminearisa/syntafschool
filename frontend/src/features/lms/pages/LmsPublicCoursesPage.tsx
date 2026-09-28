import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useSearchParams } from 'react-router-dom';
import { GraduationCap, Search } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Card, CardBody } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Pagination } from '@/components/common/Pagination';
import { lmsApi } from '../api/lmsApi';
import { CourseCard } from '../components/CourseCard';

/** Katalog publik dengan filter kategori/tingkat/jenis (§30, §31). */
export default function LmsPublicCoursesPage() {
  const [params] = useSearchParams();
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState(params.get('search') ?? '');
  const [categoryId, setCategoryId] = useState(params.get('category_id') ?? '');
  const [level, setLevel] = useState('');
  const [sort, setSort] = useState('newest');

  const categories = useQuery({ queryKey: ['lms', 'public-categories'], queryFn: () => lmsApi.publicCategories() });
  const courses = useQuery({
    queryKey: ['lms', 'public-courses', { page, search, categoryId, level, sort }],
    queryFn: () =>
      lmsApi.publicCourses({
        page,
        per_page: 12,
        search,
        category_id: categoryId ? Number(categoryId) : '',
        level,
        sort,
      }),
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
            <Link to="/learn/courses" className="rounded-lg bg-slate-100 px-3 py-1.5 font-medium text-slate-800">
              Course
            </Link>
            <Link to="/learn/teachers" className="rounded-lg px-3 py-1.5 text-slate-600 hover:bg-slate-100">
              Guru
            </Link>
            <Link to="/login" className="ml-1">
              <Button size="sm" variant="outline">
                Masuk
              </Button>
            </Link>
          </nav>
        </div>
      </header>

      <main className="mx-auto max-w-6xl space-y-6 px-4 py-8">
        <header className="space-y-1">
          <h1 className="text-xl font-semibold text-slate-900">Katalog course</h1>
          <p className="text-sm text-slate-500">Course publik dan internal yang dapat Anda lihat tanpa login.</p>
        </header>

        <Card>
          <CardBody className="grid gap-3 md:grid-cols-5">
            <div className="md:col-span-2">
              <div className="relative">
                <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden="true" />
                <Input
                  value={search}
                  onChange={(event) => setSearch(event.target.value)}
                  placeholder="Cari course…"
                  className="pl-9"
                  aria-label="Cari course"
                />
              </div>
            </div>
            <Select value={categoryId} onChange={(event) => setCategoryId(event.target.value)} aria-label="Kategori">
              <option value="">Semua kategori</option>
              {(categories.data ?? []).map((category) => (
                <option key={category.id} value={category.id}>
                  {category.name}
                </option>
              ))}
            </Select>
            <Select value={level} onChange={(event) => setLevel(event.target.value)} aria-label="Tingkat">
              <option value="">Semua tingkat</option>
              <option value="BEGINNER">Pemula</option>
              <option value="INTERMEDIATE">Menengah</option>
              <option value="ADVANCED">Mahir</option>
              <option value="ALL_LEVELS">Semua tingkat</option>
            </Select>
            <Select value={sort} onChange={(event) => setSort(event.target.value)} aria-label="Urutkan">
              <option value="newest">Terbaru</option>
              <option value="name">Nama A-Z</option>
              <option value="popular">Terpopuler</option>
            </Select>
          </CardBody>
        </Card>

        {courses.isLoading ? (
          <div className="flex justify-center py-16">
            <Spinner />
          </div>
        ) : (courses.data?.data ?? []).length === 0 ? (
          <EmptyState title="Tidak ada course ditemukan" description="Coba ubah filter atau kata kunci." />
        ) : (
          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            {(courses.data?.data ?? []).map((course) => (
              <CourseCard key={course.id} course={course} to={`/learn/courses/${course.slug}`} />
            ))}
          </div>
        )}

        <Pagination meta={courses.data?.meta as never} onPageChange={setPage} />
      </main>
    </div>
  );
}
