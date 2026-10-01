import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { getErrorMessage } from '@/lib/axios';
import { activityApi } from '../api/activityApi';

const ATTENDANCE_OPTIONS = [
  { value: 'PRESENT', label: 'Hadir' },
  { value: 'LATE', label: 'Terlambat' },
  { value: 'EXCUSED', label: 'Izin' },
  { value: 'SICK', label: 'Sakit' },
  { value: 'ABSENT', label: 'Alpa' },
];

/**
 * Ruang Kerja Pembina.
 *
 * Satu layar untuk alur kerja nyata pembina: pilih program → buat/lihat sesi →
 * isi daftar hadir → beri penilaian → lihat rekap kehadiran. Semua aksi memakai
 * API yang sama dengan siswa, sehingga pembina hanya melihat program yang
 * ditugaskan kepadanya (ditegakkan backend).
 */
export default function ActivityWorkspacePage() {
  const queryClient = useQueryClient();
  const [programId, setProgramId] = useState<number | null>(null);
  const [sessionId, setSessionId] = useState<number | null>(null);
  const [rows, setRows] = useState<Record<number, string>>({});
  const [notice, setNotice] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);
  const [assessmentTitle, setAssessmentTitle] = useState('');
  const [assessmentId, setAssessmentId] = useState<number | null>(null);
  const [scores, setScores] = useState<Record<number, string>>({});

  const programsQuery = useQuery({ queryKey: ['activity', 'my-programs'], queryFn: () => activityApi.myPrograms().then((r) => r.data.data) });
  const activeProgramId = programId ?? programsQuery.data?.[0]?.id ?? null;

  const sessionsQuery = useQuery({
    queryKey: ['activity', 'sessions', activeProgramId],
    queryFn: () => activityApi.sessions({ program_id: activeProgramId, status: 'SCHEDULED' }).then((r) => r.data.data),
    enabled: activeProgramId !== null,
  });

  const activeSessionId = sessionId ?? sessionsQuery.data?.[0]?.id ?? null;

  const sheetQuery = useQuery({
    queryKey: ['activity', 'sheet', activeSessionId],
    queryFn: () => activityApi.attendanceSheet(activeSessionId as number).then((r) => r.data.data),
    enabled: activeSessionId !== null,
  });

  const assessmentsQuery = useQuery({
    queryKey: ['activity', 'assessments', activeProgramId],
    queryFn: () => activityApi.assessments(activeProgramId as number).then((r) => r.data.data),
    enabled: activeProgramId !== null,
  });

  const summaryQuery = useQuery({
    queryKey: ['activity', 'program-attendance', activeProgramId],
    queryFn: () => activityApi.programAttendance(activeProgramId as number).then((r) => r.data.data),
    enabled: activeProgramId !== null,
  });

  const generate = useMutation({
    mutationFn: () => activityApi.generateSessions(activeProgramId as number).then((r) => r.data.data),
    onSuccess: (data) => {
      setNotice({ tone: 'success', text: `${data.created} sesi baru dibuat, ${data.skipped} sudah ada.` });
      void queryClient.invalidateQueries({ queryKey: ['activity', 'sessions'] });
    },
    onError: (error) => setNotice({ tone: 'danger', text: getErrorMessage(error) }),
  });

  const saveAttendance = useMutation({
    mutationFn: () =>
      activityApi
        .recordAttendanceBulk(
          activeSessionId as number,
          Object.entries(rows).map(([studentId, status]) => ({ student_id: Number(studentId), status })),
          `ui|session|${activeSessionId}`,
        )
        .then((r) => r.data.data),
    onSuccess: (data) => {
      setNotice({ tone: 'success', text: `${data.recorded} kehadiran tersimpan.` });
      void queryClient.invalidateQueries({ queryKey: ['activity'] });
    },
    onError: (error) => setNotice({ tone: 'danger', text: getErrorMessage(error) }),
  });

  const createAssessment = useMutation({
    mutationFn: () =>
      activityApi
        .createAssessment(activeProgramId as number, { title: assessmentTitle, type: 'PARTICIPATION', max_score: 100 })
        .then((r) => r.data.data),
    onSuccess: (assessment) => {
      setNotice({ tone: 'success', text: `Penilaian "${assessment.title}" dibuat (draf).` });
      setAssessmentTitle('');
      setAssessmentId(assessment.id);
      void queryClient.invalidateQueries({ queryKey: ['activity', 'assessments'] });
    },
    onError: (error) => setNotice({ tone: 'danger', text: getErrorMessage(error) }),
  });

  const publishAssessment = useMutation({
    mutationFn: (id: number) => activityApi.publishAssessment(id).then((r) => r.data.data),
    onSuccess: () => {
      setNotice({ tone: 'success', text: 'Penilaian diterbitkan dan siap diberi nilai.' });
      void queryClient.invalidateQueries({ queryKey: ['activity', 'assessments'] });
    },
    onError: (error) => setNotice({ tone: 'danger', text: getErrorMessage(error) }),
  });

  const submitScores = useMutation({
    mutationFn: async () => {
      const entries = Object.entries(scores).filter(([, value]) => value !== '');

      for (const [studentId, value] of entries) {
        await activityApi.submitResult(assessmentId as number, { student_id: Number(studentId), score: Number(value) });
      }

      return entries.length;
    },
    onSuccess: (count) => {
      setNotice({ tone: 'success', text: `${count} nilai tersimpan; progres siswa dihitung ulang.` });
      setScores({});
      void queryClient.invalidateQueries({ queryKey: ['activity'] });
    },
    onError: (error) => setNotice({ tone: 'danger', text: getErrorMessage(error) }),
  });

  if (programsQuery.isLoading) {
    return (
      <div className="flex justify-center py-12">
        <Spinner />
      </div>
    );
  }

  const programs = programsQuery.data ?? [];

  if (programs.length === 0) {
    return (
      <EmptyState
        title="Belum ada program yang ditugaskan"
        description="Ruang kerja ini menampilkan program yang Anda bina. Minta pengelola aktivitas menugaskan Anda pada program terlebih dahulu."
      />
    );
  }

  const sheet = sheetQuery.data;
  const assessments = assessmentsQuery.data ?? [];
  const activeAssessment = assessments.find((assessment) => assessment.id === assessmentId) ?? assessments[0] ?? null;

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-800">Ruang Kerja Pembina</h1>
          <p className="mt-1 text-sm text-slate-500">Sesi, daftar hadir, penilaian, dan rekap kehadiran program yang Anda bina.</p>
        </div>
        <div className="flex items-end gap-2">
          <div className="w-64">
            <Select value={String(activeProgramId ?? '')} onChange={(event) => { setProgramId(Number(event.target.value)); setSessionId(null); setAssessmentId(null); }} aria-label="Pilih program">
              {programs.map((program) => (
                <option key={program.id} value={program.id}>
                  {program.name}
                </option>
              ))}
            </Select>
          </div>
          <Button variant="outline" onClick={() => generate.mutate()} loading={generate.isPending}>
            Buat sesi
          </Button>
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
        <Card>
          <CardHeader>
            <CardTitle className="text-sm">Sesi terjadwal</CardTitle>
          </CardHeader>
          <CardBody className="space-y-2 text-sm">
            {(sessionsQuery.data ?? []).length === 0 ? (
              <p className="text-slate-500">Belum ada sesi terjadwal. Tekan “Buat sesi”.</p>
            ) : (
              (sessionsQuery.data ?? []).map((session) => (
                <button
                  key={session.id}
                  type="button"
                  onClick={() => setSessionId(session.id)}
                  className={`w-full rounded-lg border px-3 py-2 text-left transition-colors ${
                    session.id === activeSessionId ? 'border-brand-500 bg-brand-50' : 'border-slate-200 hover:bg-slate-50'
                  }`}
                >
                  <p className="font-medium text-slate-800">
                    #{session.session_number} · {session.date}
                  </p>
                  <p className="text-xs text-slate-500">
                    {session.start_time}–{session.end_time}
                    {session.room ? ` · ${session.room}` : ''}
                  </p>
                  <p className="mt-1 text-xs text-slate-500">
                    {session.topic ?? 'Tema belum diisi'} · kehadiran tercatat: {session.attendance_count ?? 0}
                  </p>
                </button>
              ))
            )}
          </CardBody>
        </Card>

        <Card className="lg:col-span-2">
          <CardHeader>
            <CardTitle className="text-sm">Daftar hadir</CardTitle>
          </CardHeader>
          <CardBody className="space-y-2">
            {activeSessionId === null ? (
              <p className="text-sm text-slate-500">Pilih sesi untuk mengisi kehadiran.</p>
            ) : sheetQuery.isLoading ? (
              <Spinner />
            ) : (
              <>
                <p className="text-xs text-slate-500">
                  Sesi {sheet?.meta.date} {sheet?.meta.locked ? '· kehadiran terkunci' : ''}
                </p>
                {(sheet?.session ?? []).map((row) => (
                  <div key={row.student_id} className="flex items-center justify-between gap-3 border-b border-slate-100 pb-2 last:border-0">
                    <div className="text-sm">
                      <p className="font-medium text-slate-800">{row.name}</p>
                      <p className="text-xs text-slate-500">
                        {row.nis ?? '-'} · peran {row.membership_id ? 'anggota' : '-'}
                        {row.status_label ? ` · tercatat: ${row.status_label}` : ''}
                      </p>
                    </div>
                    <div className="w-40">
                      <Select
                        value={rows[row.student_id] ?? row.status ?? 'PRESENT'}
                        disabled={sheet?.meta.locked}
                        onChange={(event) => setRows((current) => ({ ...current, [row.student_id]: event.target.value }))}
                        aria-label={`Kehadiran ${row.name}`}
                      >
                        {ATTENDANCE_OPTIONS.map((option) => (
                          <option key={option.value} value={option.value}>
                            {option.label}
                          </option>
                        ))}
                      </Select>
                    </div>
                  </div>
                ))}

                {(sheet?.session ?? []).length > 0 && (
                  <div className="flex gap-2 pt-2">
                    <Button loading={saveAttendance.isPending} onClick={() => saveAttendance.mutate()} disabled={Object.keys(rows).length === 0}>
                      Simpan kehadiran
                    </Button>
                    <Button
                      variant="outline"
                      onClick={() => activityApi.completeSession(activeSessionId).then(() => queryClient.invalidateQueries({ queryKey: ['activity'] }))}
                    >
                      Selesaikan sesi
                    </Button>
                  </div>
                )}
              </>
            )}
          </CardBody>
        </Card>
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle className="text-sm">Penilaian perkembangan</CardTitle>
          </CardHeader>
          <CardBody className="space-y-3">
            <div className="flex gap-2">
              <Input placeholder="Nama penilaian (mis. Penilaian Tengah Periode)" value={assessmentTitle} onChange={(event) => setAssessmentTitle(event.target.value)} />
              <Button onClick={() => createAssessment.mutate()} loading={createAssessment.isPending} disabled={assessmentTitle.trim().length < 3}>
                Tambah
              </Button>
            </div>

            <div className="flex flex-wrap gap-2">
              {assessments.length === 0 ? (
                <p className="text-sm text-slate-500">Belum ada penilaian.</p>
              ) : (
                assessments.map((assessment) => (
                  <button
                    key={assessment.id}
                    type="button"
                    onClick={() => setAssessmentId(assessment.id)}
                    className={`rounded-lg border px-3 py-2 text-left text-xs ${
                      assessment.id === activeAssessment?.id ? 'border-brand-500 bg-brand-50' : 'border-slate-200 hover:bg-slate-50'
                    }`}
                  >
                    <p className="font-medium text-slate-800">{assessment.title}</p>
                    <p className="text-slate-500">
                      {assessment.type} · maks {Number(assessment.max_score)}
                      {assessment.is_final ? ' · akhir' : ''}
                    </p>
                    <Badge tone={assessment.status === 'PUBLISHED' ? 'success' : 'neutral'}>{assessment.status}</Badge>
                  </button>
                ))
              )}
            </div>

            {activeAssessment && (
              <div className="space-y-2">
                <div className="flex items-center gap-2">
                  <Button size="sm" variant="outline" onClick={() => publishAssessment.mutate(activeAssessment.id)} disabled={activeAssessment.status === 'PUBLISHED'}>
                    Terbitkan
                  </Button>
                  <p className="text-xs text-slate-500">Nilai hanya dapat diisi pada penilaian yang sudah terbit.</p>
                </div>

                {(sheet?.session ?? []).map((row) => (
                  <div key={row.student_id} className="flex items-center justify-between gap-3">
                    <span className="text-sm text-slate-700">{row.name}</span>
                    <div className="w-28">
                      <Input
                        type="number"
                        min={0}
                        max={100}
                        placeholder="Nilai"
                        value={scores[row.student_id] ?? ''}
                        onChange={(event) => setScores((current) => ({ ...current, [row.student_id]: event.target.value }))}
                        aria-label={`Nilai ${row.name}`}
                      />
                    </div>
                  </div>
                ))}

                <Button
                  onClick={() => submitScores.mutate()}
                  loading={submitScores.isPending}
                  disabled={activeAssessment.status !== 'PUBLISHED' || Object.keys(scores).length === 0}
                >
                  Simpan nilai
                </Button>
              </div>
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="text-sm">Rekap kehadiran program</CardTitle>
          </CardHeader>
          <CardBody className="space-y-2 text-sm">
            {summaryQuery.data ? (
              <>
                <p className="text-xs text-slate-500">
                  {summaryQuery.data.sessions} sesi · kelengkapan catatan {summaryQuery.data.coverage ?? '-'}%
                </p>
                {summaryQuery.data.students.map((student) => (
                  <div key={student.student_id} className="flex items-center justify-between border-b border-slate-100 pb-2 last:border-0">
                    <span className="text-slate-700">{student.name}</span>
                    <span className="text-xs text-slate-600">
                      {student.summary.percentage === null ? 'belum ada data' : `${student.summary.percentage}% hadir`}
                      {' · '}
                      {student.summary.sessions_recorded}/{student.summary.sessions_held} pertemuan tercatat
                    </span>
                  </div>
                ))}
              </>
            ) : (
              <Spinner />
            )}
          </CardBody>
        </Card>
      </div>
    </div>
  );
}
