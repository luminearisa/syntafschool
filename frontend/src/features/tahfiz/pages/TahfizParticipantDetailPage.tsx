import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, RefreshCw } from 'lucide-react';
import { memorizationRecordsApi, participantsApi, reportsApi } from '../api/tahfizApi';
import { HafalanMap } from '../components/HafalanMap';
import { StatCard, ProgressBar, FieldRow, SectionTitle } from '../components/TahfizUi';
import {
  EXPERIENCE_LEVEL_LABELS,
  GENDER_LABELS,
  PARTICIPANT_STATUS_LABELS,
  PARTICIPANT_TYPE_LABELS,
  RAPOR_PREDICATE_TONE,
  READING_ABILITY_LABELS,
  TARGET_PERIOD_LABELS,
  interventionStatusTone,
  memorizationStatusTone,
  progressStatusTone,
} from '../lib/labels';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Spinner } from '@/components/ui/Spinner';
import { Pagination } from '@/components/common/Pagination';
import { cn } from '@/lib/utils';
import { getErrorMessage } from '@/lib/axios';
import type { TahfizParticipantStatus } from '@/types/tahfiz';

type DetailTab = 'profil' | 'progress' | 'setoran' | 'rapor' | 'intervention';

const TAB_LABELS: Record<DetailTab, string> = {
  profil: 'Profil',
  progress: 'Progress & Peta Hafalan',
  setoran: 'Setoran',
  rapor: 'Rapor',
  intervention: 'Intervention',
};

function participantStatusTone(status: TahfizParticipantStatus) {
  switch (status) {
    case 'active':
      return 'green' as const;
    case 'blacklisted':
      return 'red' as const;
    default:
      return 'gray' as const;
  }
}

function Loading() {
  return (
    <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
      <Spinner /> Memuat data...
    </div>
  );
}

/* ============================ TAB PROFIL ============================ */

function ProfilTab({ participantId }: { participantId: number }) {
  const query = useQuery({
    queryKey: ['tahfiz', 'participant', participantId],
    queryFn: () => participantsApi.show(participantId),
  });

  if (query.isLoading) return <Loading />;
  if (query.isError || !query.data) {
    return <EmptyState title="Gagal memuat profil" description={getErrorMessage(query.error)} />;
  }

  const p = query.data;
  const profile = p.profile;
  const fullName = p.name;

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
      <Card className="p-4">
        <SectionTitle>Identitas</SectionTitle>
        <FieldRow label="Nama">{fullName || '—'}</FieldRow>
        <FieldRow label="Tipe Peserta">
          {PARTICIPANT_TYPE_LABELS[p.participant_type] ?? p.participant_type}
        </FieldRow>
        <FieldRow label="Jenis Kelamin">{p.gender ? GENDER_LABELS[p.gender] : '—'}</FieldRow>
        <FieldRow label="Tanggal Lahir">{p.birth_date ?? '—'}</FieldRow>
        <FieldRow label="Telepon">{p.phone ?? '—'}</FieldRow>
        <FieldRow label="Email">{p.email ?? '—'}</FieldRow>
        <FieldRow label="Alamat">{p.address ?? '—'}</FieldRow>
        <FieldRow label="Status">
          <Badge tone={participantStatusTone(p.status)}>
            {PARTICIPANT_STATUS_LABELS[p.status] ?? p.status}
          </Badge>
        </FieldRow>
        <FieldRow label="Catatan">{p.notes ?? '—'}</FieldRow>
      </Card>

      <div className="space-y-4">
        <Card className="p-4">
          <SectionTitle>Profil Hafalan</SectionTitle>
          <FieldRow label="Tingkat Pengalaman">
            {profile?.experience_level
              ? EXPERIENCE_LEVEL_LABELS[profile.experience_level] ?? profile.experience_level
              : '—'}
          </FieldRow>
          <FieldRow label="Hafalan Terakhir">{profile?.last_memorized ?? '—'}</FieldRow>
          <FieldRow label="Kemampuan Membaca">
            {profile?.reading_ability
              ? READING_ABILITY_LABELS[profile.reading_ability] ?? profile.reading_ability
              : '—'}
          </FieldRow>
          <FieldRow label="Target Hafalan">{profile?.memorization_target ?? '—'}</FieldRow>
          <FieldRow label="Preferensi Jadwal">{profile?.schedule_preference ?? '—'}</FieldRow>
          <FieldRow label="Pendidikan">{profile?.education_background ?? '—'}</FieldRow>
          <FieldRow label="Pekerjaan">{profile?.occupation ?? '—'}</FieldRow>
          <FieldRow label="Kontak Darurat">{profile?.emergency_contact ?? '—'}</FieldRow>
        </Card>

        <Card className="p-4">
          <SectionTitle>Keterkaitan Data</SectionTitle>
          <FieldRow label="Siswa">
            {p.student ? `${p.student.nis} — ${p.student.full_name}` : '—'}
          </FieldRow>
          <FieldRow label="Pegawai">
            {p.employee ? `${p.employee.nip ?? '-'} — ${p.employee.full_name}` : '—'}
          </FieldRow>
          <FieldRow label="Wali Murid">{p.guardian?.full_name ?? '—'}</FieldRow>
          <FieldRow label="Jumlah Pendaftaran">
            {p.active_enrollments_count}/{p.enrollments_count}
          </FieldRow>
          <FieldRow label="Jumlah Setoran">{p.memorization_records_count}</FieldRow>
        </Card>
      </div>
    </div>
  );
}

/* ============================ TAB PROGRESS ============================ */

function ProgressTab({ participantId }: { participantId: number }) {
  const query = useQuery({
    queryKey: ['tahfiz', 'participant-map', participantId],
    queryFn: () => participantsApi.map(participantId),
  });

  if (query.isLoading) return <Loading />;
  if (query.isError || !query.data) {
    return <EmptyState title="Gagal memuat peta hafalan" description={getErrorMessage(query.error)} />;
  }

  const map = query.data;

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <StatCard label="Ayat Hafal" value={map.ayahs_memorized} tone="brand" />
        <StatCard label="Ayat Mutqin" value={map.ayahs_mutqin} tone="green" />
        <StatCard label="% Hafal" value={`${map.memorized_percent}%`} />
        <StatCard label="% Mutqin" value={`${map.mutqin_percent}%`} />
        <StatCard label="Surah Hafal" value={map.surahs_memorized} />
        <StatCard label="Juz Hafal" value={map.juz_memorized} />
      </div>

      <HafalanMap map={map} />

      <Card className="p-4">
        <SectionTitle>Rincian per Surah</SectionTitle>
        {map.surahs.length === 0 ? (
          <p className="py-4 text-center text-sm text-slate-400">Belum ada data hafalan.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-3 py-2 font-medium">No</th>
                  <th className="px-3 py-2 font-medium">Surah</th>
                  <th className="px-3 py-2 font-medium">Juz</th>
                  <th className="px-3 py-2 text-center font-medium">Ayat</th>
                  <th className="px-3 py-2 text-center font-medium">Hafal</th>
                  <th className="px-3 py-2 text-center font-medium">Mutqin</th>
                  <th className="px-3 py-2 text-center font-medium">Nilai</th>
                  <th className="px-3 py-2 font-medium">Status</th>
                </tr>
              </thead>
              <tbody>
                {map.surahs.map((s) => (
                  <tr key={s.surah_id} className="border-b border-slate-50 last:border-0">
                    <td className="px-3 py-2 text-xs text-slate-400">{s.number ?? '—'}</td>
                    <td className="px-3 py-2 font-medium text-slate-800">{s.name ?? '—'}</td>
                    <td className="px-3 py-2 text-xs text-slate-500">{s.juz ?? '—'}</td>
                    <td className="px-3 py-2 text-center text-slate-600">{s.ayah_count ?? '—'}</td>
                    <td className="px-3 py-2 text-center text-slate-600">{s.ayahs_memorized}</td>
                    <td className="px-3 py-2 text-center text-slate-600">{s.ayahs_mutqin}</td>
                    <td className="px-3 py-2 text-center text-slate-600">{s.avg_score ?? '—'}</td>
                    <td className="px-3 py-2">
                      <Badge tone={progressStatusTone(s.status)}>{s.status_label}</Badge>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  );
}

/* ============================ TAB SETORAN ============================ */

function SetoranTab({ participantId }: { participantId: number }) {
  const [page, setPage] = useState(1);

  const query = useQuery({
    queryKey: ['tahfiz', 'participant-setoran', participantId, page],
    queryFn: () =>
      memorizationRecordsApi.list({
        page,
        per_page: 15,
        filter: { participant_id: participantId },
      }),
  });

  if (query.isLoading) return <Loading />;
  if (query.isError) {
    return <EmptyState title="Gagal memuat setoran" description={getErrorMessage(query.error)} />;
  }

  const rows = query.data?.data ?? [];
  const meta = query.data?.meta;

  if (rows.length === 0) {
    return <EmptyState title="Belum ada setoran" description="Peserta ini belum memiliki setoran." />;
  }

  return (
    <Card>
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
              <th className="px-4 py-2 font-medium">Tanggal</th>
              <th className="px-4 py-2 font-medium">Surah</th>
              <th className="px-4 py-2 font-medium">Ayat</th>
              <th className="px-4 py-2 font-medium">Tipe</th>
              <th className="px-3 py-2 text-center font-medium">Nilai</th>
              <th className="px-4 py-2 font-medium">Status</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.id} className="border-b border-slate-50 last:border-0">
                <td className="px-4 py-2 text-xs text-slate-600">{r.date ?? '—'}</td>
                <td className="px-4 py-2 text-slate-700">{r.surah?.name ?? '—'}</td>
                <td className="px-4 py-2 text-xs text-slate-600">
                  {r.ayah_start}–{r.ayah_end}
                </td>
                <td className="px-4 py-2 text-xs text-slate-600">{r.type_label}</td>
                <td className="px-3 py-2 text-center text-slate-700">{r.score ?? '—'}</td>
                <td className="px-4 py-2">
                  <Badge tone={memorizationStatusTone(r.status)}>
                    {r.status_label || r.status}
                  </Badge>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {meta && <Pagination meta={meta} onPageChange={setPage} />}
    </Card>
  );
}

/* ============================ TAB RAPOR ============================ */

function RaporTab({ participantId }: { participantId: number }) {
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');

  const query = useQuery({
    queryKey: ['tahfiz', 'rapor', participantId, from, to],
    queryFn: () => reportsApi.rapor(participantId, { from: from || undefined, to: to || undefined }),
  });

  return (
    <div className="space-y-4">
      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Dari Tanggal</label>
            <Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Sampai Tanggal</label>
            <Input type="date" value={to} onChange={(e) => setTo(e.target.value)} />
          </div>
        </div>
      </Card>

      {query.isLoading ? (
        <Loading />
      ) : query.isError || !query.data ? (
        <EmptyState title="Gagal memuat rapor" description={getErrorMessage(query.error)} />
      ) : (
        (() => {
          const rapor = query.data;
          return (
            <div className="space-y-4">
              <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <StatCard label="Ayat Hafal" value={rapor.hafalan.ayahs_memorized} tone="brand" />
                <StatCard label="Ayat Mutqin" value={rapor.hafalan.ayahs_mutqin} tone="green" />
                <StatCard label="% Hafal" value={`${rapor.hafalan.memorized_percent}%`} />
                <StatCard label="% Mutqin" value={`${rapor.hafalan.mutqin_percent}%`} />
                <StatCard label="Juz Selesai" value={rapor.hafalan.juz_completed} />
                <StatCard label="Surah Hafal" value={rapor.hafalan.surahs_memorized} />
              </div>

              <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <Card className="p-4">
                  <SectionTitle>Statistik Setoran</SectionTitle>
                  <FieldRow label="Total">{rapor.setoran.total}</FieldRow>
                  <FieldRow label="Diterima">{rapor.setoran.accepted}</FieldRow>
                  <FieldRow label="Rata-rata Nilai">{rapor.setoran.avg_score ?? '—'}</FieldRow>
                  <FieldRow label="Kehadiran">
                    {rapor.attendance.present}/{rapor.attendance.total_sessions} (
                    {rapor.attendance.percent}%)
                  </FieldRow>
                  <FieldRow label="Hari Mutabaah">{rapor.mutabaah_days}</FieldRow>
                </Card>

                <Card className="p-4">
                  <SectionTitle>Pencapaian Target</SectionTitle>
                  <FieldRow label="Nilai Target">{rapor.target.value ?? '—'}</FieldRow>
                  <FieldRow label="Periode">
                    {rapor.target.period
                      ? TARGET_PERIOD_LABELS[rapor.target.period] ?? rapor.target.period
                      : '—'}
                  </FieldRow>
                  <FieldRow label="Status">
                    {rapor.target.is_achieved ? (
                      <Badge tone="green">Tercapai</Badge>
                    ) : (
                      <Badge tone="amber">Belum tercapai</Badge>
                    )}
                  </FieldRow>
                  <div className="pt-2">
                    <div className="mb-1 flex items-center justify-between text-xs text-slate-500">
                      <span>Pencapaian</span>
                      <span>{rapor.target.achievement_percent ?? 0}%</span>
                    </div>
                    <ProgressBar
                      percent={rapor.target.achievement_percent ?? 0}
                      tone={(rapor.target.achievement_percent ?? 0) >= 100 ? 'green' : 'brand'}
                    />
                  </div>
                </Card>
              </div>

              <Card className="p-4">
                <SectionTitle>Predikat & Catatan</SectionTitle>
                <div className="mb-3">
                  <Badge tone={RAPOR_PREDICATE_TONE[rapor.notes.predicate] ?? 'gray'}>
                    {rapor.notes.predicate_label}
                  </Badge>
                </div>
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <div>
                    <p className="mb-1 text-xs font-medium text-slate-600">Kekuatan</p>
                    {rapor.notes.strengths.length === 0 ? (
                      <p className="text-xs text-slate-400">—</p>
                    ) : (
                      <ul className="list-inside list-disc space-y-0.5 text-sm text-slate-700">
                        {rapor.notes.strengths.map((item, i) => (
                          <li key={i}>{item}</li>
                        ))}
                      </ul>
                    )}
                  </div>
                  <div>
                    <p className="mb-1 text-xs font-medium text-slate-600">Perlu Ditingkatkan</p>
                    {rapor.notes.improvements.length === 0 ? (
                      <p className="text-xs text-slate-400">—</p>
                    ) : (
                      <ul className="list-inside list-disc space-y-0.5 text-sm text-slate-700">
                        {rapor.notes.improvements.map((item, i) => (
                          <li key={i}>{item}</li>
                        ))}
                      </ul>
                    )}
                  </div>
                </div>
              </Card>
            </div>
          );
        })()
      )}
    </div>
  );
}

/* ============================ TAB INTERVENTION ============================ */

function InterventionTab({ participantId }: { participantId: number }) {
  const query = useQuery({
    queryKey: ['tahfiz', 'participant-interventions', participantId],
    queryFn: () => participantsApi.interventions(participantId),
  });

  if (query.isLoading) return <Loading />;
  if (query.isError) {
    return (
      <EmptyState title="Gagal memuat intervensi" description={getErrorMessage(query.error)} />
    );
  }

  const rows = query.data ?? [];
  if (rows.length === 0) {
    return <EmptyState title="Belum ada intervensi" description="Tidak ada intervensi tercatat." />;
  }

  return (
    <Card className="p-4">
      <div className="space-y-2">
        {rows.map((itv) => (
          <div
            key={itv.id}
            className={cn(
              'rounded-lg border p-3',
              itv.is_overdue ? 'border-red-200 bg-red-50' : 'border-slate-200',
            )}
          >
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div className="flex items-center gap-2">
                <span className="text-sm font-medium text-slate-800">{itv.type_label}</span>
                <Badge tone={interventionStatusTone(itv.status)}>
                  {itv.status_label || itv.status}
                </Badge>
                {itv.is_overdue && <Badge tone="red">Terlambat</Badge>}
              </div>
              <span className="text-xs text-slate-500">
                Tenggat: {itv.due_date ?? '—'}
              </span>
            </div>
            <p className="mt-1 text-sm text-slate-600">{itv.reason}</p>
            {itv.action && <p className="mt-0.5 text-xs text-slate-500">Tindakan: {itv.action}</p>}
          </div>
        ))}
      </div>
    </Card>
  );
}

/* ============================ HALAMAN ============================ */

/** Detail peserta Tahfiz dengan tab profil, progress, setoran, rapor, intervensi. */
export function TahfizParticipantDetailPage() {
  const params = useParams();
  const participantId = Number(params.id);

  const [tab, setTab] = useState<DetailTab>('profil');

  const headerQuery = useQuery({
    queryKey: ['tahfiz', 'participant', participantId],
    enabled: Number.isFinite(participantId),
    queryFn: () => participantsApi.show(participantId),
  });

  const participant = headerQuery.data;

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <Link
            to="/tahfiz/participants"
            className="mb-1 inline-flex items-center gap-1 text-xs text-slate-500 hover:text-slate-700"
          >
            <ArrowLeft className="h-3.5 w-3.5" /> Kembali ke daftar peserta
          </Link>
          <h1 className="text-lg font-semibold text-slate-900">
            {participant?.name ?? 'Detail Peserta'}
          </h1>
          {participant && (
            <p className="text-sm text-slate-500">
              {PARTICIPANT_TYPE_LABELS[participant.participant_type] ?? participant.participant_type} ·{' '}
              {PARTICIPANT_STATUS_LABELS[participant.status] ?? participant.status}
            </p>
          )}
        </div>
        <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => headerQuery.refetch()}>
          <RefreshCw className={headerQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
        </Button>
      </div>

      <div className="flex flex-wrap gap-1 border-b border-slate-200">
        {(Object.keys(TAB_LABELS) as DetailTab[]).map((key) => (
          <button
            key={key}
            type="button"
            onClick={() => setTab(key)}
            className={cn(
              'border-b-2 px-3 py-2 text-sm font-medium transition-colors',
              tab === key
                ? 'border-brand-600 text-brand-700'
                : 'border-transparent text-slate-500 hover:text-slate-700',
            )}
          >
            {TAB_LABELS[key]}
          </button>
        ))}
      </div>

      {tab === 'profil' && <ProfilTab participantId={participantId} />}
      {tab === 'progress' && <ProgressTab participantId={participantId} />}
      {tab === 'setoran' && <SetoranTab participantId={participantId} />}
      {tab === 'rapor' && <RaporTab participantId={participantId} />}
      {tab === 'intervention' && <InterventionTab participantId={participantId} />}
    </div>
  );
}