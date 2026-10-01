import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/Spinner';
import { getErrorMessage } from '@/lib/axios';
import { useAuthStore } from '@/stores/authStore';
import { activityApi } from '../api/activityApi';

const dayName = (day: number) => ['', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'][day] ?? 'Hari';

/**
 * Detail Aktivitas.
 *
 * Menampilkan apa yang dibutuhkan untuk memutuskan: jadwal, tujuan, keterampilan
 * yang dibina, syarat penerimaan, kapasitas, dan biaya — beserta uji kelayakan
 * yang menyebutkan syarat mana yang belum dipenuhi.
 */
export default function ActivityDetailPage() {
  const { programId } = useParams();
  const id = Number(programId);
  const can = useAuthStore((state) => state.can);
  const queryClient = useQueryClient();
  const [notice, setNotice] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);

  const metaQuery = useQuery({ queryKey: ['activity', 'me'], queryFn: () => activityApi.me().then((r) => r.data.data) });

  const programQuery = useQuery({
    queryKey: ['activity', 'program', id],
    queryFn: () => activityApi.program(id).then((r) => r.data.data),
    enabled: Number.isFinite(id),
  });

  const capacityQuery = useQuery({
    queryKey: ['activity', 'capacity', id],
    queryFn: () => activityApi.capacity(id).then((r) => r.data.data),
    enabled: Number.isFinite(id),
  });

  const studentId = metaQuery.data?.student_id ?? metaQuery.data?.child_student_ids[0] ?? 0;

  const eligibility = useMutation({
    mutationFn: () => activityApi.checkEligibility(id, studentId).then((r) => r.data.data),
    onSuccess: (data) =>
      setNotice({
        tone: data.passed ? 'success' : 'danger',
        text: data.passed
          ? ['Memenuhi syarat.', ...data.warnings.map((warning) => warning.message)].join(' ')
          : data.failures.map((failure) => failure.message).join(' '),
      }),
    onError: (error) => setNotice({ tone: 'danger', text: getErrorMessage(error) }),
  });

  const register = useMutation({
    mutationFn: () =>
      activityApi
        .register(id, { student_id: studentId, idempotency_key: `ui|${id}|${studentId}` })
        .then((r) => r.data.data),
    onSuccess: (registration) => {
      setNotice({ tone: 'success', text: `Pendaftaran ${registration.code}: ${registration.status_label ?? registration.status}.` });
      void queryClient.invalidateQueries({ queryKey: ['activity'] });
    },
    onError: (error) => setNotice({ tone: 'danger', text: getErrorMessage(error) }),
  });

  if (programQuery.isLoading) {
    return (
      <div className="flex justify-center py-12">
        <Spinner />
      </div>
    );
  }

  const program = programQuery.data;

  if (!program) {
    return <EmptyState title="Aktivitas tidak ditemukan" description="Program mungkin sudah diarsipkan." />;
  }

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <Link to="/activities" className="text-xs text-slate-500 hover:underline">
            ← Katalog aktivitas
          </Link>
          <h1 className="mt-1 text-xl font-semibold text-slate-800">{program.name}</h1>
          <p className="mt-1 text-sm text-slate-500">
            {program.category?.name ?? '-'} · {program.delivery_type ?? 'RECURRING'} · {program.period?.name ?? '-'}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Badge tone={program.status === 'PUBLISHED' || program.status === 'ACTIVE' ? 'success' : 'neutral'}>{program.status}</Badge>
          {capacityQuery.data && (
            <Badge tone={capacityQuery.data.is_full ? 'danger' : 'info'}>
              {capacityQuery.data.active}/{capacityQuery.data.maximum ?? '∞'} anggota
              {capacityQuery.data.waitlisted > 0 ? ` · ${capacityQuery.data.waitlisted} daftar tunggu` : ''}
            </Badge>
          )}
          {program.requires_payment && (
            <Badge tone="warning">
              Biaya {Number(program.fee_amount ?? 0).toLocaleString('id-ID')} {program.fee_amount ? '' : ''}
            </Badge>
          )}
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

      <div className="grid gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader>
            <CardTitle className="text-sm">Tentang aktivitas</CardTitle>
          </CardHeader>
          <CardBody className="space-y-4 text-sm text-slate-700">
            <p>{program.description ?? 'Belum ada deskripsi.'}</p>

            <div className="grid gap-3 sm:grid-cols-2">
              <div>
                <p className="text-xs font-medium uppercase tracking-wide text-slate-600">Tujuan perkembangan</p>
                <ul className="mt-1 list-disc space-y-1 pl-4 text-sm">
                  {(program.objectives ?? []).length === 0 ? <li className="text-slate-500">Belum ditentukan</li> : null}
                  {(program.objectives ?? []).map((objective) => (
                    <li key={objective.id}>{objective.name}</li>
                  ))}
                </ul>
              </div>
              <div>
                <p className="text-xs font-medium uppercase tracking-wide text-slate-600">Keterampilan yang dibina</p>
                <div className="mt-1 flex flex-wrap gap-1">
                  {(program.skills ?? []).length === 0 ? <span className="text-sm text-slate-500">Belum ditentukan</span> : null}
                  {(program.skills ?? []).map((skill) => (
                    <Badge key={skill.id} tone="brand">
                      {skill.name}
                    </Badge>
                  ))}
                </div>
              </div>
            </div>

            <div>
              <p className="text-xs font-medium uppercase tracking-wide text-slate-600">Jadwal</p>
              <div className="mt-1 space-y-1">
                {(program.schedules ?? []).length === 0 ? (
                  <p className="text-slate-500">Belum ada jadwal tetap.</p>
                ) : (
                  (program.schedules ?? []).map((schedule) => (
                    <p key={schedule.id}>
                      {dayName(schedule.day_of_week)} {schedule.start_time.slice(0, 5)}–{schedule.end_time.slice(0, 5)}
                      {schedule.room?.name ? ` · ${schedule.room.name}` : schedule.location ? ` · ${schedule.location}` : ''}
                    </p>
                  ))
                )}
              </div>
            </div>

            {(program.requirements ?? []).length > 0 && (
              <div>
                <p className="text-xs font-medium uppercase tracking-wide text-slate-600">Syarat penerimaan</p>
                <ul className="mt-1 list-disc space-y-1 pl-4 text-sm">
                  {(program.requirements ?? []).map((requirement) => (
                    <li key={requirement.id}>
                      {requirement.message ?? `${requirement.type} ${requirement.operator}`}
                      {requirement.is_mandatory ? '' : ' (disarankan)'}
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="text-sm">Pendaftaran</CardTitle>
          </CardHeader>
          <CardBody className="space-y-3 text-sm">
            <dl className="space-y-2 text-slate-700">
              <div className="flex justify-between gap-2">
                <dt className="text-slate-500">Pembina</dt>
                <dd>{program.coach?.full_name ?? program.advisor?.full_name ?? '-'}</dd>
              </div>
              <div className="flex justify-between gap-2">
                <dt className="text-slate-500">Metode seleksi</dt>
                <dd>{program.selection_method ?? 'OPEN'}</dd>
              </div>
              <div className="flex justify-between gap-2">
                <dt className="text-slate-500">Persetujuan orang tua</dt>
                <dd>{program.requires_parent_consent ? 'Diperlukan' : 'Tidak diperlukan'}</dd>
              </div>
              <div className="flex justify-between gap-2">
                <dt className="text-slate-500">Biaya</dt>
                <dd>{program.requires_payment ? Number(program.fee_amount ?? 0).toLocaleString('id-ID') : 'Gratis'}</dd>
              </div>
            </dl>

            <div className="flex flex-col gap-2">
              <Button variant="outline" onClick={() => eligibility.mutate()} loading={eligibility.isPending} disabled={studentId === 0}>
                Uji kelayakan saya
              </Button>
              {can('activity.registration.create') && (
                <Button onClick={() => register.mutate()} loading={register.isPending} disabled={studentId === 0}>
                  Daftar sekarang
                </Button>
              )}
              {studentId === 0 && (
                <p className="text-xs text-amber-700">
                  Akun ini belum tertaut ke data siswa. Minta tata usaha menautkan akun terlebih dahulu.
                </p>
              )}
            </div>
          </CardBody>
        </Card>
      </div>
    </div>
  );
}
