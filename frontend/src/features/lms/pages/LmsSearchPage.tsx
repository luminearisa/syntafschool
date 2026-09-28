import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { Search } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { lmsApi } from '../api/lmsApi';
import { CourseCard } from '../components/CourseCard';
import { ItemIcon } from '../components/ItemIcon';

/** Pencarian global LMS: course, materi, guru, kategori (§31). */
export default function LmsSearchPage() {
  const [term, setTerm] = useState('');
  const [categoryId, setCategoryId] = useState('');
  const [level, setLevel] = useState('');

  const search = useQuery({
    queryKey: ['lms', 'search', term, categoryId, level],
    queryFn: () =>
      lmsApi.search({
        q: term,
        category_id: categoryId ? Number(categoryId) : '',
        level,
        per_page: 12,
      }),
    staleTime: 30_000,
  });

  const results = search.data;

  return (
    <div className="space-y-5">
      <header>
        <h1 className="flex items-center gap-2 text-lg font-semibold text-slate-900">
          <Search className="h-5 w-5 text-brand-600" aria-hidden="true" /> Pencarian LMS
        </h1>
        <p className="text-sm text-slate-500">Cari course, materi, guru, dan kategori dalam satu tempat.</p>
      </header>

      <Card>
        <CardBody className="grid gap-3 md:grid-cols-4">
          <div className="md:col-span-2">
            <Input
              value={term}
              onChange={(event) => setTerm(event.target.value)}
              placeholder="Cari course, materi, guru…"
              aria-label="Kata kunci"
            />
          </div>
          <Select value={categoryId} onChange={(event) => setCategoryId(event.target.value)} aria-label="Kategori">
            <option value="">Semua kategori</option>
            {(results?.filters.categories ?? []).map((category) => (
              <option key={category.id} value={category.id}>
                {category.label}
              </option>
            ))}
          </Select>
          <Select value={level} onChange={(event) => setLevel(event.target.value)} aria-label="Tingkat">
            <option value="">Semua tingkat</option>
            {(results?.filters.levels ?? []).map((value) => (
              <option key={value} value={value}>
                {value}
              </option>
            ))}
          </Select>
        </CardBody>
      </Card>

      {search.isLoading ? (
        <div className="flex justify-center py-10">
          <Spinner />
        </div>
      ) : (
        <div className="space-y-6">
          <section className="space-y-3">
            <h2 className="text-sm font-semibold text-slate-800">Course ({results?.courses.length ?? 0})</h2>
            {(results?.courses ?? []).length === 0 ? (
              <EmptyState title="Tidak ada course cocok" description="Coba kata kunci lain." />
            ) : (
              <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                {(results?.courses ?? []).map((course) => (
                  <CourseCard
                    key={course.id}
                    course={{
                      id: course.id,
                      name: course.name,
                      code: course.code,
                      thumbnail: course.thumbnail,
                      short_description: course.short_description,
                      level: course.level,
                      course_type: course.course_type,
                      status: course.status,
                      visibility: course.visibility,
                      teachers: course.teachers.map((name) => ({ name })),
                      counts: {
                        students: course.students_count,
                        items: course.items_count,
                        sections: course.sections_count,
                      },
                    }}
                    to={`/lms/courses/${course.id}`}
                    studentsCount={course.students_count}
                    itemsCount={course.items_count}
                    compact
                  />
                ))}
              </div>
            )}
          </section>

          {(results?.items ?? []).length > 0 && (
            <Card>
              <CardHeader>
                <CardTitle>Materi</CardTitle>
              </CardHeader>
              <CardBody>
                <ul className="divide-y divide-slate-100">
                  {(results?.items ?? []).map((item) => (
                    <li key={item.id} className="flex items-center gap-3 py-2">
                      <ItemIcon icon={item.resource_type ?? item.activity_type ?? 'file'} />
                      <span className="min-w-0 flex-1">
                        <span className="block truncate text-sm font-medium text-slate-800">{item.title}</span>
                        <span className="block text-[11px] text-slate-500">
                          {item.course_name} • {item.section_name}
                        </span>
                      </span>
                      <Link to={`/lms/courses/${item.course_id}/learn/${item.id}`}>
                        <Button size="sm" variant="outline">
                          Buka
                        </Button>
                      </Link>
                    </li>
                  ))}
                </ul>
              </CardBody>
            </Card>
          )}

          {(results?.teachers ?? []).length > 0 && (
            <Card>
              <CardHeader>
                <CardTitle>Guru</CardTitle>
              </CardHeader>
              <CardBody className="flex flex-wrap gap-2">
                {(results?.teachers ?? []).map((teacher) => (
                  <Badge key={teacher.id} tone="gray">
                    {teacher.name}
                    {teacher.nip ? ` • ${teacher.nip}` : ''}
                  </Badge>
                ))}
              </CardBody>
            </Card>
          )}

          {(results?.categories ?? []).length > 0 && (
            <Card>
              <CardHeader>
                <CardTitle>Kategori</CardTitle>
              </CardHeader>
              <CardBody className="flex flex-wrap gap-2">
                {(results?.categories ?? []).map((category) => (
                  <Badge key={category.id} tone="brand">
                    {category.name} ({category.courses_count})
                  </Badge>
                ))}
              </CardBody>
            </Card>
          )}
        </div>
      )}
    </div>
  );
}
