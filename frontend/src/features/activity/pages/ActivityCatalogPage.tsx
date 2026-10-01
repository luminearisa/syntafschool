import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { getErrorMessage } from '@/lib/axios';
import { useAuthStore } from '@/stores/authStore';
import { activityApi } from '../api/activityApi';
import type { ActivityProgramRow } from '../types';

/**
 * Katalog Aktivitas — pintu masuk siswa dan orang tua.
 *
 * Bukan tabel CRUD: yang ditampilkan adalah hal-hal yang menentukan keputusan
 * ikut atau tidak — jadwal, pembina, sisa kursi, syarat, dan biaya — lalu
 * pendaftaran dilakukan langsung dari kartu, dengan pemeriksaan kelayakan
 * lebih dahulu sehingga siswa tahu apa yang belum dipenuhi sebelum menekan
 * tombol.
 */
export default function ActivityCatalogPage() {
  const can = useAuthStore((state) => state.can);
  const queryClient = useQueryClient();

  const [search, setSearch] = useState('');
  const [categoryId, setCategoryId] = useState<string>('');
  const [studentId, setStudentId] = useState<string>('');
  const [notice, setNotice] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);

  const metaQuery = useQuery({ queryKey: ['activity', 'me'], queryFn: () => activityApi.me().then((r) => r.data.data) });
  const categoriesQuery = useQuery({ queryKey: ['activity', 'categories'], queryFn: () => activityApi.categories().then((r) => r.data.data) });

  const programsQuery = useQuery({
    queryKey: ['activity', 'discover', search, categoryId],
    queryFn: () =>
      activityApi
        .discover({ search: search || undefined, category_id: categoryId || undefined, per_page: 24 })
        .then((r) => r.data),
  });

  const students = useMemo(() => {
    const meta = metaQuery.data;
    if (!meta) return [] as { id: number; label: string }[];

    const options: { id: number; label: string }[] = [];
    if (meta.student_id) options.push({ id: meta.student_id, label: 'Saya' });
    meta.child_student_ids.forEach((id) => options.push({ id, label: `Anak #${id}` }));

    return options;
  }, [metaQuery.data]);

  const activeStudentId = Number(studentId || students[0]?.id || 0);

  const eligibility = useMutation({
    mutationFn: (program: ActivityProgramRow) => activityApi.checkEligibility(program.id, activeStudentId).then((r) => r.data.data),
    onSuccess: (data) => {
      setNotice({
        tone: data.passed ? 'success' : 'danger',
        text: data.passed
          ? `Memenuhi syarat. ${data.warnings.map((warning) => warning.message).join(' ') || 'Sisa kursi: ' + (data.capacity.seats_left ?? 'tidak dibatasi') + '.'}`
          : data.failures.map((failure) => failure.message).join(' '),
      });
    },
    onError: (error) => setNotice({ tone: 'danger', text: getErrorMessage(error) }),
  });

  const register = useMutation({
    mutationFn: (program: ActivityProgramRow) =>
      activityApi
        .register(program.id, {
          student_id: activeStudentId,
          motivation: 'Mendaftar melalui katalog aktivitas.',
          idempotency_key: `ui|${program.id}|${activeStudentId}`,
        })
        .then((r) => r.data.data),
    onSuccess: (registration) => {
      setNotice({ tone: 'success', text: `Pendaftaran ${registration.code} tercatat: ${registration.status_label ?? registration.status}.` });
      void queryClient.invalidateQueries({ queryKey: ['activity'] });
    },
    onError: (error) => setNotice({ tone: 'danger', text: getErrorMessage(error) }),
  });

  const programs = programsQuery.data?.data ?? [];

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-800">Katalog Aktivitas</h1>
          <p className="mt-1 text-sm text-slate-500">
            Ekstrakurikuler, klub, organisasi, dan kompetisi yang sedang dibuka. Periksa syarat dan sisa kursi sebelum mendaftar.
          </p>
        </div>

        <div className="flex flex-wrap items-end gap-2">
          {students.length > 1 && (
            <div className="w-44">
              <Select value={studentId || String(students[0].id)} onChange={(event) => setStudentId(event.target.value)} aria-label="Pilih anak">
                {students.map((option) => (
                  <option key={option.id} value={option.id}>
                    {option.label}
                  </option>
                ))}
              </Select>
            </div>
          )}
          <div className="w-56">
            <Select value={categoryId} onChange={(event) => setCategoryId(event.target.value)} aria-label="Kategori">
              <option value="">Semua kategori</option>
              {(categoriesQuery.data ?? []).map((category) => (
                <option key={category.id} value={category.id}>
                  {category.name}
                </option>
              ))}
            </Select>
          </div>
          <div className="w-56">
            <Input placeholder="Cari aktivitas…" value={search} onChange={(event) => setSearch(event.target.value)} />
          </div>
        </div>
      </div>

      {notice && (
        <div
          role="status"
          className={
            notice.tone === 'success'
              ? 'rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800'
              : 'rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800'
          }
        >
          {notice.text}
        </div>
      )}

      {programsQuery.isLoading ? (
        <div className="flex justify-center py-12">
          <Spinner />
        </div>
      ) : programs.length === 0 ? (
        <EmptyState title="Belum ada aktivitas dibuka" description="Pendaftaran mungkin belum dibuka untuk periode ini." />
      ) : (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {programs.map((program) => (
            <Card key={program.id} className="flex h-full flex-col">
              <CardBody className="flex flex-1 flex-col gap-3">
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <h2 className="text-base font-semibold text-slate-800">{program.name}</h2>
                    <p className="mt-1 text-xs text-slate-500">
                      {program.category?.name ?? '-'} · {program.period?.name ?? 'periode belum ditentukan'}
                    </p>
                  </div>
                  <Badge tone={program.requires_payment ? 'warning' : 'success'}>{program.requires_payment ? 'Berbiaya' : 'Gratis'}</Badge>
                </div>

                <dl className="grid grid-cols-2 gap-2 text-xs text-slate-600">
                  <div>
                    <dt className="text-slate-500">Pembina</dt>
                    <dd>{program.coach?.full_name ?? program.advisor?.full_name ?? '-'}</dd>
                  </div>
                  <div>
                    <dt className="text-slate-500">Seleksi</dt>
                    <dd>{program.selection_method ?? 'OPEN'}</dd>
                  </div>
                  <div>
                    <dt className="text-slate-500">Kapasitas</dt>
                    <dd>{program.maximum_capacity ?? 'tidak dibatasi'}</dd>
                  </div>
                  <div>
                    <dt className="text-slate-500">Pendaftaran</dt>
                    <dd>
                      {program.registration_closes ? `s.d. ${program.registration_closes}` : 'selama kuota tersedia'}
                    </dd>
                  </div>
                </dl>

                {program.requires_parent_consent && (
                  <p className="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800">
                    Memerlukan persetujuan orang tua sebelum keanggotaan aktif.
                  </p>
                )}

                <div className="mt-auto flex flex-wrap gap-2">
                  <Link to={`/activities/${program.id}`} className="text-sm font-medium text-brand-700 hover:underline">
                    Lihat detail
                  </Link>
                  <div className="ml-auto flex gap-2">
                    <Button
                      size="sm"
                      variant="outline"
                      onClick={() => eligibility.mutate(program)}
                      loading={eligibility.isPending}
                      disabled={activeStudentId === 0}
                    >
                      Uji syarat
                    </Button>
                    {can('activity.registration.create') && (
                      <Button size="sm" onClick={() => register.mutate(program)} loading={register.isPending} disabled={activeStudentId === 0}>
                        Daftar
                      </Button>
                    )}
                  </div>
                </div>
              </CardBody>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
