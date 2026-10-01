import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { getErrorMessage } from '@/lib/axios';
import { useAuthStore } from '@/stores/authStore';
import { activityApi } from '../api/activityApi';

const LEVELS = ['INTERNAL', 'SCHOOL', 'DISTRICT', 'CITY', 'PROVINCE', 'NATIONAL', 'INTERNATIONAL'];
const ROUND_TYPES = ['QUALIFICATION', 'GROUP', 'SEMI_FINAL', 'FINAL', 'EXHIBITION'];

/**
 * Kompetisi.
 *
 * Satu layar untuk seluruh daur hidup lomba: menyiapkan kompetisi & kategori,
 * membuka pendaftaran, menyusun babak dan pertandingan, menugaskan juri, mengunci
 * skor, mengoreksi skor **beralasan**, lalu menerbitkan hasil — hasil terbit
 * otomatis menjadi prestasi siswa (dan sertifikatnya diterbitkan di layar
 * Pengakuan).
 *
 * Hak akses ditampilkan apa adanya: pengelola kompetisi tidak dapat mengoreksi
 * skor (itu wewenang terpisah) maupun menerbitkan hasil (itu wewenang kepala
 * sekolah/penanggung jawab yang berizin).
 */
export default function ActivityCompetitionsPage() {
  const can = useAuthStore((state) => state.can);
  const queryClient = useQueryClient();

  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [notice, setNotice] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);

  const [form, setForm] = useState({
    name: '',
    level: 'SCHOOL',
    participant_type: 'INDIVIDUAL',
    organizer: '',
    categories: 'Umum',
    starts_on: '',
    ends_on: '',
  });
  const [roundForm, setRoundForm] = useState({ roundId: '', type: 'FINAL', name: '', participantA: '', participantB: '', venue: '' });
  const [judgeForm, setJudgeForm] = useState({ name: '', round_id: '' });
  const [scoreForm, setScoreForm] = useState<{ matchId: number | null; participantId: string; total: string; comment: string }>({
    matchId: null,
    participantId: '',
    total: '',
    comment: '',
  });
  const [correction, setCorrection] = useState<{ scoreId: number | null; reason: string; total: string }>({ scoreId: null, reason: '', total: '' });

  const listQuery = useQuery({ queryKey: ['activity', 'competitions'], queryFn: () => activityApi.competitions({ per_page: 30 }).then((r) => r.data.data) });
  const activeId = selectedId ?? listQuery.data?.[0]?.id ?? null;

  const detailQuery = useQuery({
    queryKey: ['activity', 'competition', activeId],
    queryFn: () => activityApi.competition(activeId as number).then((r) => r.data.data),
    enabled: activeId !== null,
  });

  const judgeAssignmentsQuery = useQuery({
    queryKey: ['activity', 'judge-assignments'],
    queryFn: () => activityApi.myJudgeAssignments().then((r) => r.data.data),
    enabled: can('activity.competition.judge'),
  });

  const invalidate = () => void queryClient.invalidateQueries({ queryKey: ['activity'] });

  const createCompetition = useMutation({
    mutationFn: () =>
      activityApi
        .createCompetition({
          name: form.name,
          level: form.level,
          participant_type: form.participant_type,
          organizer: form.organizer || null,
          starts_on: form.starts_on || null,
          ends_on: form.ends_on || null,
          categories: form.categories
            .split(',')
            .map((name) => name.trim())
            .filter(Boolean)
            .map((name) => ({ name })),
        })
        .then((r) => r.data.data),
    onSuccess: (competition) => {
      setNotice({ tone: 'success', text: `Kompetisi "${competition.name}" dibuat (${competition.status}).` });
      setForm({ ...form, name: '', organizer: '' });
      invalidate();
    },
    onError: (error) => setNotice({ tone: 'danger', text: getErrorMessage(error) }),
  });

  const openRegistration = useMutation({
    mutationFn: (id: number) => activityApi.openCompetition(id).then((r) => r.data.data),
    onSuccess: () => {
      setNotice({ tone: 'success', text: 'Pendaftaran kompetisi dibuka.' });
      invalidate();
    },
    onError: (error) => setNotice({ tone: 'danger', text: getErrorMessage(error) }),
  });

  const createRound = useMutation({
    mutationFn: () =>
      activityApi
        .createRound(activeId as number, { type: roundForm.type, name: roundForm.name || roundForm.type, scheduled_date: undefined })
        .then((r) => r.data.data),
    onSuccess: () => {
      setNotice({ tone: 'success', text: 'Babak dibuat.' });
      invalidate();
    },
    onError: (error) => setNotice({ tone: 'danger', text: getErrorMessage(error) }),
  });

  const createMatch = useMutation({
    mutationFn: () =>
      activityApi
        .createMatch(Number(roundForm.roundId), {
          participant_a_id: roundForm.participantA ? Number(roundForm.participantA) : undefined,
          participant_b_id: roundForm.participantB ? Number(roundForm.participantB) : undefined,
          venue: roundForm.venue || undefined,
        })
        .then((r) => r.data.data),
    onSuccess: () => {
      setNotice({ tone: 'success', text: 'Pertandingan dibuat.' });
      invalidate();
    },
    onError: (error) => setNotice({ tone: 'danger', text: getErrorMessage(error) }),
  });

  const assignJudge = useMutation({
    mutationFn: () =>
      activityApi
        .assignJudge(activeId as number, { name: judgeForm.name, round_id: judgeForm.round_id ? Number(judgeForm.round_id) : null })
        .then((r) => r.data.data),
    onSuccess: () => {
      setNotice({ tone: 'success', text: 'Juri ditugaskan.' });
      setJudgeForm({ name: '', round_id: '' });
      invalidate();
    },
    onError: (error) => setNotice({ tone: 'danger', text: getErrorMessage(error) }),
  });

  const submitScore = useMutation({
    mutationFn: () =>
      activityApi
        .submitScore(scoreForm.matchId as number, {
          participant_id: Number(scoreForm.participantId),
          total: Number(scoreForm.total),
          comment: scoreForm.comment || null,
        })
        .then((r) => r.data),
    onSuccess: () => {
      setNotice({ tone: 'success', text: 'Skor tersimpan.' });
      setScoreForm({ matchId: null, participantId: '', total: '', comment: '' });
      invalidate();
    },
    onError: (error) => setNotice({ tone: 'danger', text: getErrorMessage(error) }),
  });

  const lockScores = useMutation({
    mutationFn: (matchId: number) => activityApi.lockMatchScores(matchId).then((r) => r.data),
    onSuccess: () => {
      setNotice({ tone: 'success', text: 'Skor pertandingan dikunci dan pemenang dihitung.' });
      invalidate();
    },
    onError: (error) => setNotice({ tone: 'danger', text: getErrorMessage(error) }),
  });

  const correctScore = useMutation({
    mutationFn: () =>
      activityApi
        .correctScore(correction.scoreId as number, {
          reason: correction.reason,
          total: correction.total ? Number(correction.total) : null,
        })
        .then((r) => r.data.data),
    onSuccess: () => {
      setNotice({ tone: 'success', text: 'Koreksi skor tersimpan beserta alasan dan nilai sebelumnya di jejak audit.' });
      setCorrection({ scoreId: null, reason: '', total: '' });
      invalidate();
    },
    onError: (error) => setNotice({ tone: 'danger', text: getErrorMessage(error) }),
  });

  const buildResults = useMutation({
    mutationFn: (id: number) => activityApi.buildResults(id).then((r) => r.data.data),
    onSuccess: (rows) => {
      setNotice({ tone: 'success', text: `${rows.length} baris hasil disusun dari skor terkunci.` });
      invalidate();
    },
    onError: (error) => setNotice({ tone: 'danger', text: getErrorMessage(error) }),
  });

  const publishResults = useMutation({
    mutationFn: (id: number) => activityApi.publishResults(id).then((r) => r.data.data),
    onSuccess: (data) => {
      setNotice({ tone: 'success', text: `Hasil diterbitkan: ${data.results} baris, ${data.achievements} prestasi siswa tercatat.` });
      invalidate();
    },
    onError: (error) => setNotice({ tone: 'danger', text: getErrorMessage(error) }),
  });

  const detail = detailQuery.data;
  const participants = useMemo(() => detail?.participants ?? [], [detail]);

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-800">Kompetisi</h1>
          <p className="mt-1 text-sm text-slate-500">
            Persiapan, pelaksanaan, penjurian, dan hasil lomba — hasil terbit otomatis menjadi prestasi siswa.
          </p>
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

      {judgeAssignmentsQuery.data && judgeAssignmentsQuery.data.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle className="text-sm">Penampilan yang saya nilai</CardTitle>
          </CardHeader>
          <CardBody className="space-y-2 text-sm">
            {judgeAssignmentsQuery.data.map((assignment) => (
              <div key={assignment.match_id} className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-2 last:border-0">
                <div>
                  <p className="font-medium text-slate-800">
                    {assignment.participant_a ?? 'peserta A'} vs {assignment.participant_b ?? 'peserta B'}
                  </p>
                  <p className="text-xs text-slate-500">
                    {assignment.competition} · {assignment.round} · {assignment.scheduled_at ?? 'jadwal menyusul'}
                  </p>
                </div>
                <div className="flex items-center gap-2">
                  {assignment.locked ? <Badge tone="neutral">terkunci</Badge> : <Badge tone="warning">menunggu nilai</Badge>}
                  <Button size="sm" variant="outline" onClick={() => setScoreForm({ ...scoreForm, matchId: assignment.match_id })}>
                    Nilai
                  </Button>
                </div>
              </div>
            ))}

            {scoreForm.matchId !== null && (
              <div className="space-y-2 rounded-md bg-slate-50 p-3">
                <p className="text-xs text-slate-600">Menilai pertandingan #{scoreForm.matchId}</p>
                <div className="grid gap-2 sm:grid-cols-3">
                  <Input placeholder="ID peserta" value={scoreForm.participantId} onChange={(event) => setScoreForm({ ...scoreForm, participantId: event.target.value })} />
                  <Input placeholder="Total nilai (0-100)" value={scoreForm.total} onChange={(event) => setScoreForm({ ...scoreForm, total: event.target.value })} />
                  <Input placeholder="Catatan penilaian" value={scoreForm.comment} onChange={(event) => setScoreForm({ ...scoreForm, comment: event.target.value })} />
                </div>
                <div className="flex gap-2">
                  <Button
                    size="sm"
                    loading={submitScore.isPending}
                    disabled={!scoreForm.participantId || scoreForm.total === ''}
                    onClick={() => submitScore.mutate()}
                  >
                    Kirim nilai
                  </Button>
                  <Button size="sm" variant="ghost" onClick={() => setScoreForm({ matchId: null, participantId: '', total: '', comment: '' })}>
                    Batal
                  </Button>
                </div>
              </div>
            )}
          </CardBody>
        </Card>
      )}

      <div className="grid gap-4 lg:grid-cols-4">
        <Card>
          <CardHeader>
            <CardTitle className="text-sm">Daftar kompetisi</CardTitle>
          </CardHeader>
          <CardBody className="space-y-2 text-sm">
            {listQuery.isLoading ? (
              <Spinner />
            ) : (listQuery.data ?? []).length === 0 ? (
              <p className="text-slate-500">Belum ada kompetisi.</p>
            ) : (
              (listQuery.data ?? []).map((competition) => (
                <button
                  key={competition.id}
                  type="button"
                  onClick={() => setSelectedId(competition.id)}
                  className={`w-full rounded-lg border px-3 py-2 text-left transition-colors ${
                    competition.id === activeId ? 'border-brand-500 bg-brand-50' : 'border-slate-200 hover:bg-slate-50'
                  }`}
                >
                  <p className="font-medium text-slate-800">{competition.name}</p>
                  <p className="text-xs text-slate-500">
                    {competition.level} · {competition.participants_count ?? 0} peserta · {competition.matches_count ?? 0} pertandingan
                  </p>
                  <Badge tone={competition.status === 'REGISTRATION_OPEN' ? 'success' : competition.status === 'PUBLISHED' ? 'info' : 'neutral'}>
                    {competition.status_label ?? competition.status}
                  </Badge>
                </button>
              ))
            )}
          </CardBody>
        </Card>

        <div className="space-y-4 lg:col-span-3">
          {can('activity.competition.manage') && (
            <Card>
              <CardHeader>
                <CardTitle className="text-sm">Kompetisi baru</CardTitle>
              </CardHeader>
              <CardBody className="space-y-2">
                <div className="grid gap-2 sm:grid-cols-3">
                  <Input placeholder="Nama kompetisi" value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} />
                  <Select value={form.level} onChange={(event) => setForm({ ...form, level: event.target.value })} aria-label="Tingkat">
                    {LEVELS.map((level) => (
                      <option key={level} value={level}>
                        {level}
                      </option>
                    ))}
                  </Select>
                  <Select value={form.participant_type} onChange={(event) => setForm({ ...form, participant_type: event.target.value })} aria-label="Jenis peserta">
                    <option value="INDIVIDUAL">Individu</option>
                    <option value="TEAM">Tim</option>
                    <option value="MIXED">Campuran</option>
                  </Select>
                  <Input placeholder="Penyelenggara" value={form.organizer} onChange={(event) => setForm({ ...form, organizer: event.target.value })} />
                  <Input placeholder="Kategori (pisahkan koma)" value={form.categories} onChange={(event) => setForm({ ...form, categories: event.target.value })} />
                  <Input type="date" value={form.starts_on} onChange={(event) => setForm({ ...form, starts_on: event.target.value })} aria-label="Tanggal mulai" />
                </div>
                <Button loading={createCompetition.isPending} disabled={form.name.trim().length < 3} onClick={() => createCompetition.mutate()}>
                  Buat kompetisi
                </Button>
              </CardBody>
            </Card>
          )}

          {detail && (
            <>
              <Card>
                <CardHeader>
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <CardTitle className="text-sm">{detail.name}</CardTitle>
                    <div className="flex flex-wrap items-center gap-2">
                      <Badge tone="info">{detail.level}</Badge>
                      <Badge tone={detail.status === 'REGISTRATION_OPEN' ? 'success' : 'neutral'}>{detail.status_label ?? detail.status}</Badge>
                      {can('activity.competition.manage') && detail.status !== 'REGISTRATION_OPEN' && (
                        <Button size="sm" variant="outline" onClick={() => openRegistration.mutate(detail.id)}>
                          Buka pendaftaran
                        </Button>
                      )}
                    </div>
                  </div>
                </CardHeader>
                <CardBody className="space-y-3">
                  <p className="text-xs text-slate-500">
                    Kategori: {detail.categories.map((category) => category.name).join(', ') || '-'} · penyelenggara {detail.organizer ?? '-'}
                  </p>

                  <div>
                    <p className="text-xs font-medium uppercase tracking-wide text-slate-600">Peserta & tim</p>
                    <div className="mt-1 flex flex-wrap gap-2 text-sm">
                      {participants.length === 0 && (detail.teams ?? []).length === 0 ? (
                        <span className="text-slate-500">Belum ada peserta terdaftar. Pendaftaran peserta dilakukan lewat API portal/panitia.</span>
                      ) : null}
                      {participants.map((participant) => (
                        <Badge key={participant.id} tone="brand">
                          {participant.student?.full_name ?? `Peserta #${participant.id}`} · {participant.status_label ?? participant.status}
                        </Badge>
                      ))}
                      {(detail.teams ?? []).map((team) => (
                        <Badge key={team.id} tone="info">
                          {team.name} ({team.members?.length ?? 0} anggota)
                        </Badge>
                      ))}
                    </div>
                  </div>
                </CardBody>
              </Card>

              <div className="grid gap-4 lg:grid-cols-2">
                <Card>
                  <CardHeader>
                    <CardTitle className="text-sm">Babak & pertandingan</CardTitle>
                  </CardHeader>
                  <CardBody className="space-y-3 text-sm">
                    {can('activity.competition.manage') && (
                      <div className="space-y-2 rounded-md bg-slate-50 p-3">
                        <div className="grid gap-2 sm:grid-cols-2">
                          <Select value={roundForm.type} onChange={(event) => setRoundForm({ ...roundForm, type: event.target.value })} aria-label="Jenis babak">
                            {ROUND_TYPES.map((type) => (
                              <option key={type} value={type}>
                                {type}
                              </option>
                            ))}
                          </Select>
                          <Input placeholder="Nama babak" value={roundForm.name} onChange={(event) => setRoundForm({ ...roundForm, name: event.target.value })} />
                        </div>
                        <Button size="sm" variant="outline" onClick={() => createRound.mutate()} loading={createRound.isPending}>
                          Tambah babak
                        </Button>

                        <div className="grid gap-2 sm:grid-cols-4">
                          <Select value={roundForm.roundId} onChange={(event) => setRoundForm({ ...roundForm, roundId: event.target.value })} aria-label="Pilih babak">
                            <option value="">Pilih babak…</option>
                            {(detail.rounds ?? []).map((round) => (
                              <option key={round.id} value={round.id}>
                                {round.name ?? round.type}
                              </option>
                            ))}
                          </Select>
                          <Select value={roundForm.participantA} onChange={(event) => setRoundForm({ ...roundForm, participantA: event.target.value })} aria-label="Peserta A">
                            <option value="">Peserta A…</option>
                            {participants.map((participant) => (
                              <option key={participant.id} value={participant.id}>
                                {participant.student?.full_name ?? `#${participant.id}`}
                              </option>
                            ))}
                          </Select>
                          <Select value={roundForm.participantB} onChange={(event) => setRoundForm({ ...roundForm, participantB: event.target.value })} aria-label="Peserta B">
                            <option value="">Peserta B…</option>
                            {participants.map((participant) => (
                              <option key={participant.id} value={participant.id}>
                                {participant.student?.full_name ?? `#${participant.id}`}
                              </option>
                            ))}
                          </Select>
                          <Button size="sm" onClick={() => createMatch.mutate()} loading={createMatch.isPending} disabled={!roundForm.roundId}>
                            Tambah pertandingan
                          </Button>
                        </div>
                      </div>
                    )}

                    {(detail.rounds ?? []).length === 0 ? (
                      <p className="text-slate-500">Belum ada babak.</p>
                    ) : (
                      (detail.rounds ?? []).map((round) => (
                        <div key={round.id} className="rounded-lg border border-slate-200 p-3">
                          <p className="font-medium text-slate-800">
                            {round.name ?? round.type} <span className="text-xs text-slate-500">({round.type_label ?? round.type})</span>
                          </p>
                          {(round.matches ?? []).length === 0 ? (
                            <p className="mt-1 text-xs text-slate-500">Belum ada pertandingan.</p>
                          ) : (
                            (round.matches ?? []).map((match) => (
                              <div key={match.id} className="mt-2 rounded-md bg-slate-50 p-2">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                  <p className="text-sm text-slate-700">
                                    {match.participant_a?.student?.full_name ?? 'peserta A'} ({match.score_a ?? '-'}) vs{' '}
                                    {match.participant_b?.student?.full_name ?? 'peserta B'} ({match.score_b ?? '-'})
                                  </p>
                                  <div className="flex items-center gap-2">
                                    <Badge tone={match.status === 'COMPLETED' ? 'success' : 'neutral'}>{match.status_label ?? match.status}</Badge>
                                    {can('activity.competition.manage') && (
                                      <Button size="sm" variant="outline" onClick={() => lockScores.mutate(match.id)}>
                                        Kunci skor
                                      </Button>
                                    )}
                                  </div>
                                </div>

                                {(match.scores ?? []).length > 0 && (
                                  <div className="mt-1 space-y-1">
                                    {(match.scores ?? []).map((score) => (
                                      <div key={score.id} className="flex flex-wrap items-center gap-2 text-xs text-slate-600">
                                        <span>
                                          juri #{score.judge_id} → peserta #{score.participant_id}: {score.total ?? '-'} ({score.status})
                                        </span>
                                        {score.correction_reason && <span className="text-amber-700">dikoreksi: {score.correction_reason}</span>}
                                        {can('activity.competition.score.correct') && (
                                          <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => setCorrection({ scoreId: score.id, reason: '', total: String(score.total ?? '') })}
                                          >
                                            Koreksi
                                          </Button>
                                        )}
                                      </div>
                                    ))}
                                  </div>
                                )}

                                {correction.scoreId !== null && (match.scores ?? []).some((score) => score.id === correction.scoreId) && (
                                  <div className="mt-2 space-y-2 rounded-md bg-white p-2">
                                    <p className="text-xs text-slate-600">Koreksi skor #{correction.scoreId} — nilai sebelum & alasan tersimpan di audit.</p>
                                    <Textarea
                                      rows={2}
                                      placeholder="Alasan koreksi (wajib)"
                                      value={correction.reason}
                                      onChange={(event) => setCorrection({ ...correction, reason: event.target.value })}
                                    />
                                    <div className="flex gap-2">
                                      <Input
                                        className="w-28"
                                        placeholder="Nilai baru"
                                        value={correction.total}
                                        onChange={(event) => setCorrection({ ...correction, total: event.target.value })}
                                      />
                                      <Button size="sm" loading={correctScore.isPending} disabled={correction.reason.trim().length < 3} onClick={() => correctScore.mutate()}>
                                        Simpan koreksi
                                      </Button>
                                      <Button size="sm" variant="ghost" onClick={() => setCorrection({ scoreId: null, reason: '', total: '' })}>
                                        Batal
                                      </Button>
                                    </div>
                                  </div>
                                )}
                              </div>
                            ))
                          )}
                        </div>
                      ))
                    )}
                  </CardBody>
                </Card>

                <Card>
                  <CardHeader>
                    <CardTitle className="text-sm">Juri & hasil</CardTitle>
                  </CardHeader>
                  <CardBody className="space-y-3 text-sm">
                    {can('activity.competition.manage') && (
                      <div className="grid gap-2 sm:grid-cols-3">
                        <Input placeholder="Nama juri" value={judgeForm.name} onChange={(event) => setJudgeForm({ ...judgeForm, name: event.target.value })} />
                        <Select value={judgeForm.round_id} onChange={(event) => setJudgeForm({ ...judgeForm, round_id: event.target.value })} aria-label="Babak juri">
                          <option value="">Semua babak</option>
                          {(detail.rounds ?? []).map((round) => (
                            <option key={round.id} value={round.id}>
                              {round.name ?? round.type}
                            </option>
                          ))}
                        </Select>
                        <Button size="sm" variant="outline" onClick={() => assignJudge.mutate()} loading={assignJudge.isPending} disabled={judgeForm.name.trim().length < 3}>
                          Tugaskan juri
                        </Button>
                      </div>
                    )}

                    <div className="flex flex-wrap gap-2">
                      {(detail.judges ?? []).length === 0 ? (
                        <span className="text-slate-500">Belum ada juri ditugaskan.</span>
                      ) : (
                        (detail.judges ?? []).map((judge) => (
                          <Badge key={judge.id} tone="neutral">
                            {judge.name ?? judge.user?.name ?? `Juri #${judge.id}`}
                            {judge.round_id ? ` (babak #${judge.round_id})` : ''}
                          </Badge>
                        ))
                      )}
                    </div>

                    <div className="flex flex-wrap gap-2">
                      {can('activity.competition.manage') && (
                        <Button size="sm" variant="outline" onClick={() => buildResults.mutate(detail.id)} loading={buildResults.isPending}>
                          Susun hasil
                        </Button>
                      )}
                      {can('activity.competition.result.publish') && (
                        <Button size="sm" onClick={() => publishResults.mutate(detail.id)} loading={publishResults.isPending}>
                          Terbitkan hasil & prestasi
                        </Button>
                      )}
                      {!can('activity.competition.result.publish') && (
                        <p className="text-xs text-slate-500">Penerbitan hasil dilakukan kepala sekolah/penanggung jawab yang berizin.</p>
                      )}
                    </div>

                    <div className="space-y-1">
                      {(detail.results ?? []).length === 0 ? (
                        <p className="text-slate-500">Hasil belum disusun.</p>
                      ) : (
                        (detail.results ?? []).map((result) => (
                          <div key={result.id} className="flex items-center justify-between border-b border-slate-100 pb-1 last:border-0">
                            <span className="text-slate-700">
                              #{result.rank} {result.participant?.student?.full_name ?? result.team?.name ?? '-'}
                            </span>
                            <span className="text-xs text-slate-500">
                              {result.total_score ?? '-'} · {result.status_label ?? result.status}
                            </span>
                          </div>
                        ))
                      )}
                    </div>

                    <p className="text-xs text-slate-500">Tingkat prestasi mengikuti tingkat kompetisi: {LEVELS.join(' → ')}.</p>
                  </CardBody>
                </Card>
              </div>
            </>
          )}

          {!detail && !listQuery.isLoading && (listQuery.data ?? []).length === 0 && (
            <EmptyState title="Belum ada kompetisi" description="Buat kompetisi pertama, lalu buka pendaftarannya." />
          )}
        </div>
      </div>
    </div>
  );
}
