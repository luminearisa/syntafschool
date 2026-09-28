import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { GraduationCap, Search, Users } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Card, CardBody } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Spinner } from '@/components/ui/Spinner';
import { lmsApi } from '../api/lmsApi';

/** Direktori guru pengajar course publik (§30). */
export default function LmsPublicTeachersPage() {
  const [search, setSearch] = useState('');
  const teachers = useQuery({
    queryKey: ['lms', 'public-teachers', search],
    queryFn: () => lmsApi.publicTeachers(search),
  });

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
          <nav className="ml-auto flex items-center gap-1 text-sm">
            <Link to="/learn/courses" className="rounded-lg px-3 py-1.5 text-slate-600 hover:bg-slate-100">
              Course
            </Link>
            <Link to="/learn/teachers" className="rounded-lg bg-slate-100 px-3 py-1.5 font-medium text-slate-800">
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

      <main className="mx-auto max-w-5xl space-y-6 px-4 py-8">
        <header className="space-y-1">
          <h1 className="text-xl font-semibold text-slate-900">Guru pengajar</h1>
          <p className="text-sm text-slate-500">Guru yang membuka course publiknya untuk dijelajahi.</p>
        </header>

        <div className="relative max-w-md">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden="true" />
          <Input
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder="Cari nama guru…"
            className="pl-9"
            aria-label="Cari guru"
          />
        </div>

        {teachers.isLoading ? (
          <div className="flex justify-center py-16">
            <Spinner />
          </div>
        ) : (teachers.data ?? []).length === 0 ? (
          <EmptyState title="Belum ada guru" description="Guru akan tampil setelah memiliki course publik." />
        ) : (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {(teachers.data ?? []).map((teacher) => (
              <Card key={teacher.id}>
                <CardBody className="flex items-center gap-3">
                  <span className="inline-flex h-11 w-11 items-center justify-center rounded-full bg-brand-100 text-sm font-semibold text-brand-700">
                    {teacher.name.slice(0, 2).toUpperCase()}
                  </span>
                  <div className="min-w-0">
                    <p className="truncate text-sm font-semibold text-slate-800">{teacher.name}</p>
                    <p className="text-[11px] text-slate-500">{teacher.nip ?? 'Pengajar LMS'}</p>
                    <p className="mt-1 inline-flex items-center gap-1 text-[11px] text-brand-700">
                      <Users className="h-3 w-3" aria-hidden="true" /> {teacher.public_courses} course publik
                    </p>
                  </div>
                </CardBody>
              </Card>
            ))}
          </div>
        )}
      </main>
    </div>
  );
}
