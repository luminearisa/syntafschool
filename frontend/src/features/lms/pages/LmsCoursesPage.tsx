import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useSearchParams } from 'react-router-dom';
import { BookOpen, Filter, Plus, Search } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Card, CardBody } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Pagination } from '@/components/common/Pagination';
import { usePermission } from '@/hooks/usePermission';
import { lmsApi } from '../api/lmsApi';
import { CourseCard } from '../components/CourseCard';
import type { CourseScopeValue } from '../types';

/**
 * Katalog & daftar course LMS.
 *
 * Tab pertama untuk course yang diikuti/dikelola, tab kedua untuk menjelajah
 * course lain sesuai cakupan peran (§8, §30, §31).
 */
export default function LmsCoursesPage() {
  const { can } = usePermission();
  const [params, setParams] = useSearchParams();
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState(params.get('search') ?? '');
  const [categoryId, setCategoryId] = useState(params.get('category_id') ?? '');
  const [level, setLevel] = useState(params.get('level') ?? '');
  const [type, setType] = useState(params.get('course_type') ?? '');
  const [status, setStatus] = useState(params.get('status') ?? '');

  const scope = (params.get('scope') as CourseScopeValue | null) ?? (can('lms.course.create') ? 'MANAGED' : 'ENROLLED');

  const lookups = useQuery({ queryKey: ['lms', 'lookups'], queryFn: () => lmsApi.lookups() });

  const courses = useQuery({
    queryKey: ['lms', 'courses', { scope, page, search, categoryId, level, type, status }],
    queryFn: () =>
      lmsApi.courses({
        scope,
        page,
        search,
        category_id: categoryId ? Number(categoryId) : '',
        level,
        course_type: type,
        status,
        per_page: 12,
      }),
  });

  const categories = useMemo(() => lookups.data?.categories ?? [], [lookups.data]);

  const setScope = (next: CourseScopeValue) => {
    const updated = new URLSearchParams(params);
    updated.set('scope', next);
    setParams(updated);
    setPage(1);
  };

  const tabs: Array<{ key: CourseScopeValue; label: string; permission?: string }> = [
    ...(can('lms.course.create') ? [{ key: 'MANAGED' as CourseScopeValue, label: 'Course saya' }] : []),
    { key: 'ENROLLED', label: 'Diikuti' },
    { key: 'PUBLIC', label: 'Katalog publik' },
    ...(can('lms.manage') ? [{ key: 'ALL' as CourseScopeValue, label: 'Semua (admin)' }] : []),
  ];

  return (
    <div className="space-y-5">
      <header className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2 text-lg font-semibold text-slate-900">
            <BookOpen className="h-5 w-5 text-brand-600" aria-hidden="true" /> Course LMS
          </h1>
          <p className="text-sm text-slate-500">
            Cari, ikuti, dan kelola course. Progress belajar tampil langsung pada tiap kartu.
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          {can('lms.manage') && (
            <Link to="/lms/categories">
              <Button variant="outline" size="sm">
                Kelola kategori
              </Button>
            </Link>
          )}
          {can('lms.course.create') && (
            <Link to="/lms/courses/new">
              <Button size="sm">
                <Plus className="h-4 w-4" aria-hidden="true" /> Course baru
              </Button>
            </Link>
          )}
        </div>
      </header>

      <div className="flex flex-wrap gap-2">
        {tabs.map((tab) => (
          <button
            key={tab.key}
            type="button"
            onClick={() => setScope(tab.key)}
            className={`rounded-full px-3 py-1.5 text-xs font-medium transition ${
              scope === tab.key ? 'bg-brand-600 text-white shadow-sm' : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50'
            }`}
          >
            {tab.label}
          </button>
        ))}
      </div>

      <Card>
        <CardBody className="grid gap-3 md:grid-cols-5">
          <div className="md:col-span-2">
            <div className="relative">
              <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden="true" />
              <Input
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                onKeyDown={(event) => event.key === 'Enter' && setPage(1)}
                placeholder="Cari course, kode…"
                className="pl-9"
                aria-label="Cari course"
              />
            </div>
          </div>
          <Select value={categoryId} onChange={(event) => setCategoryId(event.target.value)} aria-label="Filter kategori">
            <option value="">Semua kategori</option>
            {categories.map((category) => (
              <option key={category.id} value={category.id}>
                {category.label}
              </option>
            ))}
          </Select>
          <Select value={level} onChange={(event) => setLevel(event.target.value)} aria-label="Filter tingkat">
            <option value="">Semua tingkat</option>
            {(lookups.data?.enums.level ?? []).map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </Select>
          {scope === 'MANAGED' || scope === 'ALL' ? (
            <Select value={status} onChange={(event) => setStatus(event.target.value)} aria-label="Filter status">
              <option value="">Semua status</option>
              <option value="DRAFT">Draf</option>
              <option value="PUBLISHED">Terbit</option>
              <option value="ARCHIVED">Arsip</option>
            </Select>
          ) : (
            <Select value={type} onChange={(event) => setType(event.target.value)} aria-label="Filter jenis">
              <option value="">Semua jenis</option>
              {(lookups.data?.enums.course_type ?? []).map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </Select>
          )}
        </CardBody>
      </Card>

      {courses.isLoading ? (
        <div className="flex flex-col items-center gap-3 py-16 text-sm text-slate-500">
          <Spinner className="h-6 w-6" /> Memuat course…
        </div>
      ) : courses.isError ? (
        <EmptyState title="Course gagal dimuat" description="Coba muat ulang halaman atau periksa hak akses Anda." />
      ) : (courses.data?.data ?? []).length === 0 ? (
        <EmptyState
          title="Tidak ada course ditemukan"
          description="Ubah filter atau kata kunci pencarian."
          action={
            can('lms.course.create') ? (
              <Link to="/lms/courses/new">
                <Button size="sm">Buat course pertama</Button>
              </Link>
            ) : undefined
          }
        />
      ) : (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {(courses.data?.data ?? []).map((course) => (
            <CourseCard key={course.id} course={course} to={`/lms/courses/${course.id}`} />
          ))}
        </div>
      )}

      {(courses.data?.meta?.last_page ?? 1) > 1 && (
        <div className="flex items-center justify-between">
          <p className="text-xs text-slate-500">
            <Filter className="mr-1 inline h-3 w-3" aria-hidden="true" />
            {courses.data?.meta.total} course • halaman {courses.data?.meta.current_page} dari {courses.data?.meta.last_page}
          </p>
          <Pagination meta={courses.data?.meta as never} onPageChange={setPage} />
        </div>
      )}

      {scope === 'PUBLIC' && (
        <p className="text-center text-xs text-slate-500">
          Ingin melihat tampilan publik?{' '}
          <Link to="/learn" className="font-medium text-brand-700 hover:underline">
            Buka halaman LMS publik
          </Link>
        </p>
      )}
    </div>
  );
}
