import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useParams } from 'react-router-dom';
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
import { bkApi } from '../api/bkApi';
import type { BkCaseRow, BkFollowUpRow, BkGoalRow, BkInterventionRow, BkNoteRow, BkPlanRow, BkSessionRow, BkTimelineEvent } from '../types';

type Tab = 'overview' | 'timeline' | 'sessions' | 'notes' | 'plans' | 'interventions' | 'followups' | 'assessments' | 'documents';

const TABS: { key: Tab; label: string; permission?: string }[] = [
  { key: 'overview', label: 'Ringkasan' },
  { key: 'timeline', label: 'Timeline' },
  { key: 'sessions', label: 'Sesi', permission: 'bk.session.view' },
  { key: 'notes', label: 'Catatan', permission: 'bk.note.view' },
  { key: 'assessments', label: 'Asesmen', permission: 'bk.assessment.view' },
  { key: 'plans', label: 'Rencana', permission: 'bk.plan.view' },
  { key: 'interventions', label: 'Intervensi', permission: 'bk.intervention.view' },
  { key: 'followups', label: 'Tindak lanjut', permission: 'bk.follow-up.view' },
  { key: 'documents', label: 'Dokumen', permission: 'bk.document.view' },
];

/**
 * Ruang kerja satu kasus.
 *
 * Tab yang tampil mengikuti izin; tab konseling (sesi, catatan) tidak muncul
 * bagi wali kelas/guru, dan servernya pun menolak. Catatan konselor hanya
 * dikirim server kepada pemegang akses konseling — kolomnya kosong bagi yang lain.
 */
export default function BkCaseDetailPage() {
  const { id } = useParams();
  const caseId = Number(id);
  const can = useAuthStore((state) => state.can);
  const queryClient = useQueryClient();

  const [tab, setTab] = useState<Tab>('overview');
  const [notice, setNotice] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);
  const [session, setSession] = useState({ scheduled_at: '', objective: '' });
  const [note, setNote] = useState({ body: '', note_type: 'GENERAL', is_sensitive: false });
  const [followUp, setFollowUp] = useState({ title: '', due_date: '', type: 'CHECK_IN' });
  const [intervention, setIntervention] = useState({ type: 'ACADEMIC_SUPPORT', title: '', target_date: '' });
  const [closure, setClosure] = useState({ closure_reason: 'GOAL_ACHIEVED', summary: '' });

  const detail = useQuery({ queryKey: ['bk', 'case', caseId], queryFn: () => bkApi.case(caseId).then((r) => r.data.data) });
  const timeline = useQuery({ queryKey: ['bk', 'case', caseId, 'timeline'], queryFn: () => bkApi.timeline(caseId).then((r) => r.data.data), enabled: tab === 'timeline' });
  const sessions = useQuery({ queryKey: ['bk', 'case', caseId, 'sessions'], queryFn: () => bkApi.sessions(caseId).then((r) => r.data.data), enabled: tab === 'sessions' && can('bk.session.view') });
  const notes = useQuery({ queryKey: ['bk', 'case', caseId, 'notes'], queryFn: () => bkApi.notes(caseId).then((r) => r.data.data), enabled: tab === 'notes' && can('bk.note.view') });
  const plans = useQuery({ queryKey: ['bk', 'case', caseId, 'plans'], queryFn: () => bkApi.plans(caseId).then((r) => r.data.data), enabled: tab === 'plans' && can('bk.plan.view') });
  const interventions = useQuery({ queryKey: ['bk', 'case', caseId, 'interventions'], queryFn: () => bkApi.interventions(caseId).then((r) => r.data.data), enabled: tab === 'interventions' && can('bk.intervention.view') });
  const followUps = useQuery({ queryKey: ['bk', 'case', caseId, 'followups'], queryFn: () => bkApi.followUps(caseId).then((r) => r.data.data), enabled: tab === 'followups' && can('bk.follow-up.view') });
  const assessments = useQuery({ queryKey: ['bk', 'case', caseId, 'assessments'], queryFn: () => bkApi.assessments(caseId).then((r) => r.data.data), enabled: tab === 'assessments' && can('bk.assessment.view') });
  const documents = useQuery({ queryKey: ['bk', 'case', caseId, 'documents'], queryFn: () => bkApi.documents(caseId).then((r) => r.data.data), enabled: tab === 'documents' && can('bk.document.view') });

  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['bk', 'case', caseId] });
    void queryClient.invalidateQueries({ queryKey: ['bk', 'cases'] });
  };
  const onError = (error: unknown) => setNotice({ tone: 'danger', text: getErrorMessage(error) });
  const onSuccess = (text: string) => { setNotice({ tone: 'success', text }); refresh(); };

  const createSession = useMutation({
    mutationFn: () => bkApi.createSession(caseId, { scheduled_at: session.scheduled_at || undefined, objective: session.objective || undefined }).then((r) => r.data.data),
    onSuccess: () => { setSession({ scheduled_at: '', objective: '' }); onSuccess('Sesi dijadwalkan.'); },
    onError,
  });

  const createNote = useMutation({
    mutationFn: () => bkApi.createNote(caseId, note).then((r) => r.data.data),
    onSuccess: () => { setNote({ body: '', note_type: 'GENERAL', is_sensitive: false }); onSuccess('Catatan ditambahkan.'); },
    onError,
  });

  const createFollowUp = useMutation({
    mutationFn: () => bkApi.createFollowUp(caseId, followUp).then((r) => r.data.data),
    onSuccess: () => { setFollowUp({ title: '', due_date: '', type: 'CHECK_IN' }); onSuccess('Tindak lanjut dijadwalkan.'); },
    onError,
  });

  const createIntervention = useMutation({
    mutationFn: () => bkApi.createIntervention(caseId, intervention).then((r) => r.data.data),
    onSuccess: () => { setIntervention({ type: 'ACADEMIC_SUPPORT', title: '', target_date: '' }); onSuccess('Intervensi dibuat.'); },
    onError,
  });

  const completeFollowUp = useMutation({
    mutationFn: (followUpId: number) => bkApi.completeFollowUp(followUpId, { outcome: 'Selesai dari ruang kerja kasus.' }).then((r) => r.data.data),
    onSuccess: () => onSuccess('Tindak lanjut selesai.'),
    onError,
  });

  const updateGoal = useMutation({
    mutationFn: ({ goalId, progress }: { goalId: number; progress: number }) => bkApi.updateGoal(goalId, { progress }).then((r) => r.data.data),
    onSuccess: () => onSuccess('Kemajuan sasaran diperbarui.'),
    onError,
  });

  const closeCase = useMutation({
    mutationFn: () => bkApi.closeCase(caseId, closure.closure_reason, closure.summary || undefined, false).then((r) => r.data.data),
    onSuccess: () => onSuccess('Kasus ditutup.'),
    onError,
  });

  const escalate = useMutation({
    mutationFn: () => bkApi.escalateCase(caseId, 'COORDINATOR', 'Perlu perhatian koordinator.').then((r) => r.data.data),
    onSuccess: () => onSuccess('Kasus dieskalasi.'),
    onError,
  });

  if (detail.isLoading) return <Spinner />;
  if (detail.isError || !detail.data) return <EmptyState title="Kasus tidak dapat dibuka" description="Kasus ini mungkin di luar cakupan Anda." />;

  const row = detail.data.case as BkCaseRow;
  const blockers: string[] = detail.data.closure_blockers ?? [];
  const visibleTabs = TABS.filter((entry) => !entry.permission || can(entry.permission));

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-800">{row.case_number}</h1>
          <p className="mt-1 text-sm text-slate-500">
            {row.student?.full_name ?? `Siswa #${row.student_id}`} · {row.case_type} · dibuka {row.opened_at?.slice(0, 10)}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Badge tone={row.priority === 'URGENT' ? 'danger' : row.priority === 'HIGH' ? 'warning' : 'neutral'}>{row.priority}</Badge>
          <Badge tone="neutral">{row.status}</Badge>
          {row.risk_flag && <Badge tone="danger">perlu perhatian</Badge>}
          {can('bk.case.close') && ['OPEN', 'ASSESSMENT', 'INTERVENTION', 'MONITORING', 'REOPENED'].includes(row.status) && (
            <Button size="sm" variant="outline" loading={escalate.isPending} onClick={() => escalate.mutate()}>Eskalasi</Button>
          )}
        </div>
      </div>

      {notice && (
        <div role="status" className={notice.tone === 'success'
          ? 'rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800'
          : 'rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800'}>
          {notice.text}
        </div>
      )}

      <div className="flex flex-wrap gap-2">
        {visibleTabs.map((entry) => (
          <button key={entry.key} type="button" onClick={() => setTab(entry.key)}
            className={`rounded-lg border px-3 py-1.5 text-sm ${entry.key === tab ? 'border-brand-500 bg-brand-50 text-brand-800' : 'border-slate-200 text-slate-700 hover:bg-slate-50'}`}>
            {entry.label}
          </button>
        ))}
      </div>

      {tab === 'overview' && (
        <div className="grid gap-4 lg:grid-cols-2">
          <Card>
            <CardHeader><CardTitle className="text-sm">Ringkasan kasus</CardTitle></CardHeader>
            <CardBody className="space-y-1 text-sm text-slate-700">
              <p>Jenis: {row.case_type}</p>
              <p>Kategori: {row.category}</p>
              <p>Tingkat keparahan (operasional): {row.severity}</p>
              <p>Kerahasiaan: {detail.data.confidential_access ? row.confidentiality_level : 'terbatas untuk Anda'}</p>
              <p>Konselor: {row.counselor?.name ?? 'belum ditugaskan'}</p>
              <p>Tinjauan berikutnya: {row.target_review_date ?? '—'}</p>
              {row.tags && row.tags.length > 0 && (
                <p className="flex flex-wrap gap-1 pt-1">
                  {row.tags.map((tag) => <Badge key={tag.id} tone="neutral">{tag.name}</Badge>)}
                </p>
              )}
            </CardBody>
          </Card>

          <Card>
            <CardHeader><CardTitle className="text-sm">Penutupan kasus</CardTitle></CardHeader>
            <CardBody className="space-y-2 text-sm">
              {blockers.length === 0 ? (
                <p className="text-slate-600">Tidak ada pekerjaan yang menggantung — kasus siap ditutup.</p>
              ) : (
                <div className="rounded-md bg-amber-50 p-3 text-xs text-amber-900">
                  <p className="font-medium">Sebelum ditutup, tuntaskan dulu:</p>
                  <ul className="list-inside list-disc">{blockers.map((blocker) => <li key={blocker}>{blocker}</li>)}</ul>
                </div>
              )}

              {can('bk.case.close') && (
                <div className="space-y-2">
                  <Select value={closure.closure_reason} onChange={(event) => setClosure({ ...closure, closure_reason: event.target.value })} aria-label="Alasan penutupan">
                    {['GOAL_ACHIEVED', 'STABLE', 'TRANSFERRED', 'REFERRED', 'STUDENT_LEFT', 'NO_FURTHER_ACTION', 'DUPLICATE', 'OTHER'].map((reason) => (
                      <option key={reason} value={reason}>{reason}</option>
                    ))}
                  </Select>
                  <Textarea rows={2} placeholder="Ringkasan penutupan" value={closure.summary} onChange={(event) => setClosure({ ...closure, summary: event.target.value })} />
                  <Button size="sm" loading={closeCase.isPending} onClick={() => closeCase.mutate()}>Tutup kasus</Button>
                </div>
              )}
            </CardBody>
          </Card>
        </div>
      )}

      {tab === 'timeline' && (
        <Card>
          <CardHeader><CardTitle className="text-sm">Timeline (append-only)</CardTitle></CardHeader>
          <CardBody className="space-y-2 text-sm">
            {timeline.isLoading ? <Spinner /> : (timeline.data ?? []).length === 0 ? <EmptyState title="Belum ada peristiwa" description="Timeline terisi sejak referral dibuat." /> : (
              (timeline.data ?? []).map((event: BkTimelineEvent) => (
                <div key={event.id} className="border-b border-slate-100 pb-2 last:border-0">
                  <p className="text-slate-800">{event.summary}</p>
                  <p className="text-xs text-slate-500">
                    {event.occurred_at?.slice(0, 16).replace('T', ' ')} · {event.event_label}
                    {event.actor ? ` · ${event.actor}` : ''}
                    {event.status_from && event.status_to ? ` · ${event.status_from} → ${event.status_to}` : ''}
                  </p>
                </div>
              ))
            )}
          </CardBody>
        </Card>
      )}

      {tab === 'sessions' && (
        <Card>
          <CardHeader><CardTitle className="text-sm">Sesi konseling</CardTitle></CardHeader>
          <CardBody className="space-y-3 text-sm">
            {sessions.isLoading ? <Spinner /> : (sessions.data ?? []).length === 0 ? <p className="text-slate-500">Belum ada sesi.</p> : (
              (sessions.data ?? []).map((row2: BkSessionRow) => (
                <div key={row2.id} className="border-b border-slate-100 pb-2 last:border-0">
                  <p className="text-slate-800">{row2.session_number} · {row2.session_type} · {row2.status}</p>
                  <p className="text-xs text-slate-500">
                    {row2.scheduled_at?.slice(0, 16).replace('T', ' ')} · {row2.counselor ?? '—'} · kehadiran {row2.attendance_status}
                  </p>
                  {row2.session_summary && <p className="mt-1 text-slate-700">{row2.session_summary}</p>}
                  {row2.counselor_note ? (
                    <p className="mt-1 rounded bg-slate-50 p-2 text-xs text-slate-700">Catatan konselor: {row2.counselor_note}</p>
                  ) : row2.has_counselor_note ? (
                    <p className="mt-1 text-xs text-slate-500">Ada catatan konselor yang tidak ditampilkan untuk peran Anda.</p>
                  ) : null}
                </div>
              ))
            )}

            {can('bk.session.manage') && (
              <div className="space-y-2 rounded-md bg-slate-50 p-3">
                <Input type="datetime-local" value={session.scheduled_at} onChange={(event) => setSession({ ...session, scheduled_at: event.target.value })} aria-label="Waktu sesi" />
                <Input placeholder="Tujuan sesi" value={session.objective} onChange={(event) => setSession({ ...session, objective: event.target.value })} />
                <Button size="sm" loading={createSession.isPending} onClick={() => createSession.mutate()}>Jadwalkan sesi</Button>
              </div>
            )}
          </CardBody>
        </Card>
      )}

      {tab === 'notes' && (
        <Card>
          <CardHeader><CardTitle className="text-sm">Catatan kasus</CardTitle></CardHeader>
          <CardBody className="space-y-3 text-sm">
            {notes.isLoading ? <Spinner /> : (notes.data ?? []).length === 0 ? <p className="text-slate-500">Belum ada catatan.</p> : (
              (notes.data ?? []).map((row2: BkNoteRow) => (
                <div key={row2.id} className="border-b border-slate-100 pb-2 last:border-0">
                  <p className="text-slate-800">{row2.body}</p>
                  <p className="text-xs text-slate-500">
                    {row2.note_type} · {row2.author ?? '—'} · {row2.occurred_at?.slice(0, 16).replace('T', ' ')}
                    {row2.is_sensitive ? ' · rahasia' : ''}
                    {row2.amended_from_id ? ' · perbaikan catatan' : ''}
                  </p>
                </div>
              ))
            )}

            {can('bk.note.create') && (
              <div className="space-y-2 rounded-md bg-slate-50 p-3">
                <Textarea rows={3} placeholder="Isi catatan" value={note.body} onChange={(event) => setNote({ ...note, body: event.target.value })} />
                <div className="flex flex-wrap items-center gap-2">
                  <Select value={note.note_type} onChange={(event) => setNote({ ...note, note_type: event.target.value })} aria-label="Jenis catatan">
                    {['GENERAL', 'OBSERVATION', 'CONTACT', 'COUNSELOR_NOTE', 'SENSITIVE'].map((type) => <option key={type} value={type}>{type}</option>)}
                  </Select>
                  <label className="flex items-center gap-2 text-xs text-slate-600">
                    <input type="checkbox" checked={note.is_sensitive} onChange={(event) => setNote({ ...note, is_sensitive: event.target.checked })} />
                    Tandai rahasia (hanya pemegang akses konseling)
                  </label>
                  <Button size="sm" loading={createNote.isPending} disabled={note.body.trim().length < 3} onClick={() => createNote.mutate()}>Tambah catatan</Button>
                </div>
              </div>
            )}
          </CardBody>
        </Card>
      )}

      {tab === 'plans' && (
        <Card>
          <CardHeader><CardTitle className="text-sm">Rencana konseling &amp; sasaran</CardTitle></CardHeader>
          <CardBody className="space-y-3 text-sm">
            {plans.isLoading ? <Spinner /> : (plans.data ?? []).length === 0 ? <p className="text-slate-500">Belum ada rencana.</p> : (
              (plans.data ?? []).map((plan: BkPlanRow) => (
                <div key={plan.id} className="space-y-2 border-b border-slate-100 pb-3 last:border-0">
                  <p className="font-medium text-slate-800">{plan.plan_number} · {plan.title} <span className="text-xs text-slate-500">({plan.status})</span></p>
                  <p className="text-slate-600">{plan.problem_statement}</p>
                  {(plan.goals ?? []).map((goal: BkGoalRow) => (
                    <div key={goal.id} className="rounded-md bg-slate-50 p-2">
                      <p className="text-slate-800">{goal.goal}</p>
                      <p className="text-xs text-slate-500">Indikator: {goal.success_indicator ?? '—'} · kemajuan {goal.progress}% · {goal.status}</p>
                      {can('bk.plan.manage') && (
                        <div className="mt-1 flex items-center gap-2">
                          {[25, 50, 75, 100].map((progress) => (
                            <Button key={progress} size="sm" variant="ghost" onClick={() => updateGoal.mutate({ goalId: goal.id, progress })}>{progress}%</Button>
                          ))}
                        </div>
                      )}
                    </div>
                  ))}
                </div>
              ))
            )}
          </CardBody>
        </Card>
      )}

      {tab === 'interventions' && (
        <Card>
          <CardHeader><CardTitle className="text-sm">Intervensi</CardTitle></CardHeader>
          <CardBody className="space-y-3 text-sm">
            {interventions.isLoading ? <Spinner /> : (interventions.data ?? []).length === 0 ? <p className="text-slate-500">Belum ada intervensi.</p> : (
              (interventions.data ?? []).map((row2: BkInterventionRow) => (
                <div key={row2.id} className="border-b border-slate-100 pb-2 last:border-0">
                  <p className="text-slate-800">{row2.type} · {row2.title}</p>
                  <p className="text-xs text-slate-500">
                    {row2.status} · mulai {row2.start_date ?? '—'} · target {row2.target_date ?? '—'} · pemilik {row2.owner?.name ?? '—'}
                  </p>
                  {row2.result && <p className="text-slate-600">Hasil: {row2.result}</p>}
                </div>
              ))
            )}

            {can('bk.intervention.manage') && (
              <div className="space-y-2 rounded-md bg-slate-50 p-3">
                <Select value={intervention.type} onChange={(event) => setIntervention({ ...intervention, type: event.target.value })} aria-label="Jenis intervensi">
                  {['COUNSELING', 'MENTORING', 'ACADEMIC_SUPPORT', 'BEHAVIORAL_COACHING', 'PARENT_MEETING', 'TEACHER_COORDINATION', 'PEER_SUPPORT', 'BOARDING_INTERVENTION', 'ACTIVITY_INTERVENTION', 'ATTENDANCE_INTERVENTION', 'STUDY_PLAN', 'CAREER_GUIDANCE', 'REFERRAL', 'ENVIRONMENTAL_ADJUSTMENT', 'MONITORING'].map((type) => (
                    <option key={type} value={type}>{type}</option>
                  ))}
                </Select>
                <Input placeholder="Judul intervensi" value={intervention.title} onChange={(event) => setIntervention({ ...intervention, title: event.target.value })} />
                <Input type="date" value={intervention.target_date} onChange={(event) => setIntervention({ ...intervention, target_date: event.target.value })} aria-label="Target selesai" />
                <Button size="sm" loading={createIntervention.isPending} disabled={intervention.title.trim().length < 3} onClick={() => createIntervention.mutate()}>Buat intervensi</Button>
              </div>
            )}
          </CardBody>
        </Card>
      )}

      {tab === 'followups' && (
        <Card>
          <CardHeader><CardTitle className="text-sm">Tindak lanjut</CardTitle></CardHeader>
          <CardBody className="space-y-3 text-sm">
            {followUps.isLoading ? <Spinner /> : (followUps.data ?? []).length === 0 ? <p className="text-slate-500">Belum ada tindak lanjut.</p> : (
              (followUps.data ?? []).map((row2: BkFollowUpRow) => (
                <div key={row2.id} className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-2 last:border-0">
                  <div>
                    <p className="text-slate-800">{row2.title}</p>
                    <p className="text-xs text-slate-500">
                      {row2.type} · tenggat {row2.due_date ?? '—'} · {row2.status}
                      {row2.is_overdue ? ' · terlambat' : ''}
                    </p>
                  </div>
                  {can('bk.follow-up.manage') && ['PENDING', 'MISSED', 'RESCHEDULED'].includes(row2.status) && (
                    <Button size="sm" variant="outline" loading={completeFollowUp.isPending} onClick={() => completeFollowUp.mutate(row2.id)}>Selesaikan</Button>
                  )}
                </div>
              ))
            )}

            {can('bk.follow-up.manage') && (
              <div className="space-y-2 rounded-md bg-slate-50 p-3">
                <Input placeholder="Judul tindak lanjut" value={followUp.title} onChange={(event) => setFollowUp({ ...followUp, title: event.target.value })} />
                <div className="flex flex-wrap gap-2">
                  <Input type="date" value={followUp.due_date} onChange={(event) => setFollowUp({ ...followUp, due_date: event.target.value })} aria-label="Tenggat" />
                  <Select value={followUp.type} onChange={(event) => setFollowUp({ ...followUp, type: event.target.value })} aria-label="Jenis tindak lanjut">
                    {['CHECK_IN', 'SESSION', 'OBSERVATION', 'PARENT_CONTACT', 'TEACHER_FEEDBACK', 'PROGRESS_REVIEW', 'DOCUMENTATION', 'OTHER'].map((type) => (
                      <option key={type} value={type}>{type}</option>
                    ))}
                  </Select>
                  <Button size="sm" loading={createFollowUp.isPending} disabled={followUp.title.trim().length < 3 || followUp.due_date === ''} onClick={() => createFollowUp.mutate()}>
                    Jadwalkan
                  </Button>
                </div>
              </div>
            )}
          </CardBody>
        </Card>
      )}

      {tab === 'assessments' && (
        <Card>
          <CardHeader><CardTitle className="text-sm">Asesmen</CardTitle></CardHeader>
          <CardBody className="space-y-2 text-sm">
            {assessments.isLoading ? <Spinner /> : (assessments.data ?? []).length === 0 ? <p className="text-slate-500">Belum ada asesmen.</p> : (
              (assessments.data ?? []).map((row2) => (
                <div key={row2.id} className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-2 last:border-0">
                  <div>
                    <p className="text-slate-800">{row2.assessment_number} · {row2.template ?? 'template'}</p>
                    <p className="text-xs text-slate-500">{row2.target} · {row2.status} · tenggat {row2.due_date ?? '—'}</p>
                  </div>
                  <Badge tone={row2.status === 'COMPLETED' || row2.status === 'REVIEWED' ? 'success' : 'warning'}>{row2.status}</Badge>
                </div>
              ))
            )}
            <p className="text-xs text-slate-500">Asesmen bukan diagnosis: hasilnya menjadi bahan pertimbangan konselor.</p>
          </CardBody>
        </Card>
      )}

      {tab === 'documents' && (
        <Card>
          <CardHeader><CardTitle className="text-sm">Dokumen kasus</CardTitle></CardHeader>
          <CardBody className="space-y-2 text-sm">
            {documents.isLoading ? <Spinner /> : (documents.data ?? []).length === 0 ? <p className="text-slate-500">Belum ada dokumen.</p> : (
              (documents.data ?? []).map((row2, index) => (
                <div key={index} className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-2 last:border-0">
                  <div>
                    <p className="text-slate-800">{String(row2.title)}</p>
                    <p className="text-xs text-slate-500">
                      {String(row2.document_type)} · {String(row2.visibility)}
                      {row2.is_sensitive ? ' · rahasia' : ''} · {String(row2.document_date ?? '—')}
                    </p>
                  </div>
                  <span className="text-xs text-slate-500">{String(row2.uploaded_by ?? '')}</span>
                </div>
              ))
            )}
            <p className="text-xs text-slate-500">Dokumen rahasia hanya dapat diunduh pemegang akses konseling, dan setiap unduhan tercatat.</p>
          </CardBody>
        </Card>
      )}
    </div>
  );
}
