import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Check, ChevronDown, ChevronRight, Plus, RefreshCw, Users } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Can } from '@/components/common/Can';
import { usePermission } from '@/hooks/usePermission';
import { getErrorMessage } from '@/lib/axios';
import { religiousApi } from '../api/religiousApi';
import {
  displayStatus,
  ErrorCard,
  FormField,
  LoadingCard,
  ReligiousPageHeader,
  statusTone,
} from '../components/ReligiousUi';

interface ActivityForm {
  title: string;
  activity_type: string;
  description: string;
  mosque_id: string;
  capacity: string;
  target_population: string;
  registration_mode: string;
  starts_on: string;
  ends_on: string;
}

const blankActivity = (): ActivityForm => ({
  title: '', activity_type: 'KAJIAN', description: '', mosque_id: '', capacity: '',
  target_population: 'ALL_STUDENTS', registration_mode: 'OPEN', starts_on: '', ends_on: '',
});
const FALLBACK_TYPES = [
  'KAJIAN', 'CERAMAH', 'TAKLIM', 'DZIKIR', 'TAHFIDZ', 'HALAQAH', 'QURAN_READING',
  'QURAN_MEMORIZATION', 'FIQH_CLASS', 'AQIDAH_CLASS', 'ARABIC_CLASS', 'ISLAMIC_HISTORY',
  'CHARACTER_EDUCATION', 'RAMADAN', 'ISLAMIC_EVENT',
];

interface SessionForm {
  session_date: string;
  title: string;
  start_time: string;
  end_time: string;
  location: string;
  topic: string;
}
const blankSession = (): SessionForm => ({ session_date: '', title: '', start_time: '', end_time: '', location: '', topic: '' });

export function ReligiousActivitiesPage() {
  const queryClient = useQueryClient();
  const { can } = usePermission();
  const [statusFilter, setStatusFilter] = useState('');
  const [typeFilter, setTypeFilter] = useState('');
  const [formOpen, setFormOpen] = useState(false);
  const [form, setForm] = useState<ActivityForm>(blankActivity);
  const [sessionFormOpen, setSessionFormOpen] = useState(false);
  const [sessionForm, setSessionForm] = useState<SessionForm>(blankSession);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [participantType, setParticipantType] = useState<'STUDENT' | 'EMPLOYEE' | 'PUBLIC'>('STUDENT');
  const [participantId, setParticipantId] = useState('');
  const [participantName, setParticipantName] = useState('');

  const metaQuery = useQuery({
    queryKey: ['religious', 'meta'],
    queryFn: religiousApi.meta,
    enabled: can('religious.view'),
  });
  const mosqueQuery = useQuery({
    queryKey: ['religious', 'mosques', 'activity-options'],
    queryFn: () => religiousApi.listMosques({}),
    enabled: can('religious.mosque.view'),
  });
  const activitiesQuery = useQuery({
    queryKey: ['religious', 'activities', statusFilter, typeFilter],
    queryFn: () => religiousApi.activities({ status: statusFilter || undefined, activity_type: typeFilter || undefined }),
  });
  const sessionsQuery = useQuery({
    queryKey: ['religious', 'activity-sessions', selectedId],
    queryFn: () => religiousApi.activitySessions(selectedId!),
    enabled: selectedId !== null,
  });
  const participantsQuery = useQuery({
    queryKey: ['religious', 'activity-participants', selectedId],
    queryFn: () => religiousApi.activityParticipants(selectedId!),
    enabled: selectedId !== null,
  });

  const types = useMemo(
    () => metaQuery.data?.activity_types?.length ? metaQuery.data.activity_types : FALLBACK_TYPES,
    [metaQuery.data?.activity_types],
  );
  const registrationModes = metaQuery.data?.registration_modes?.length ? metaQuery.data.registration_modes : ['OPEN', 'INVITATION', 'MANDATORY', 'OPTIONAL', 'APPROVAL'];

  const createMutation = useMutation({
    mutationFn: () => religiousApi.createActivity({
      title: form.title.trim(),
      activity_type: form.activity_type,
      description: form.description.trim() || undefined,
      mosque_id: form.mosque_id ? Number(form.mosque_id) : undefined,
      capacity: form.capacity ? Number(form.capacity) : undefined,
      target_population: form.target_population,
      registration_mode: form.registration_mode,
      starts_on: form.starts_on || undefined,
      ends_on: form.ends_on || undefined,
    }),
    onSuccess: (activity) => {
      toast.success('Kegiatan keagamaan berhasil dibuat sebagai draf');
      setFormOpen(false);
      setForm(blankActivity());
      queryClient.invalidateQueries({ queryKey: ['religious', 'activities'] });
      setSelectedId(activity.id);
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });
  const publishMutation = useMutation({
    mutationFn: (id: number) => religiousApi.publishActivity(id),
    onSuccess: () => {
      toast.success('Kegiatan dipublikasikan');
      queryClient.invalidateQueries({ queryKey: ['religious', 'activities'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });
  const createSessionMutation = useMutation({
    mutationFn: () => religiousApi.createActivitySession(selectedId!, {
      session_date: sessionForm.session_date,
      title: sessionForm.title.trim() || undefined,
      start_time: sessionForm.start_time || undefined,
      end_time: sessionForm.end_time || undefined,
      location: sessionForm.location.trim() || undefined,
      topic: sessionForm.topic.trim() || undefined,
    }),
    onSuccess: () => {
      toast.success('Sesi kegiatan ditambahkan');
      setSessionFormOpen(false);
      setSessionForm(blankSession());
      queryClient.invalidateQueries({ queryKey: ['religious', 'activity-sessions', selectedId] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });
  const addParticipantMutation = useMutation({
    mutationFn: () => religiousApi.addActivityParticipant(selectedId!, {
      participant_type: participantType,
      student_id: participantType === 'STUDENT' && participantId ? Number(participantId) : undefined,
      employee_id: participantType === 'EMPLOYEE' && participantId ? Number(participantId) : undefined,
      person_name: participantType === 'PUBLIC' ? participantName.trim() : undefined,
    }),
    onSuccess: () => {
      toast.success('Peserta ditambahkan ke kegiatan');
      setParticipantId('');
      setParticipantName('');
      queryClient.invalidateQueries({ queryKey: ['religious', 'activity-participants', selectedId] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });
  const completeMutation = useMutation({
    mutationFn: (id: number) => religiousApi.completeActivity(id),
    onSuccess: () => {
      toast.success('Kegiatan ditandai selesai');
      queryClient.invalidateQueries({ queryKey: ['religious', 'activities'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const activities = activitiesQuery.data ?? [];
  const selected = activities.find((activity) => activity.id === selectedId) ?? null;

  const submitActivity = (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!form.title.trim()) {
      toast.error('Judul kegiatan wajib diisi.');
      return;
    }
    if (form.starts_on && form.ends_on && form.ends_on < form.starts_on) {
      toast.error('Tanggal selesai tidak boleh sebelum tanggal mulai.');
      return;
    }
    createMutation.mutate();
  };
  const submitSession = (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!sessionForm.session_date) {
      toast.error('Tanggal sesi wajib diisi.');
      return;
    }
    createSessionMutation.mutate();
  };
  const submitParticipant = (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (participantType === 'PUBLIC' ? !participantName.trim() : !participantId) {
      toast.error(participantType === 'PUBLIC' ? 'Nama peserta umum wajib diisi.' : 'ID peserta wajib diisi.');
      return;
    }
    addParticipantMutation.mutate();
  };

  return (
    <div className="space-y-5">
      <ReligiousPageHeader
        title="Kegiatan keagamaan"
        description="Kelola kajian, taklim, kelas Al-Qur’an, dan kegiatan keagamaan lain. Alur draf → publikasi → sesi → peserta tetap mengikuti izin backend."
        actions={
          <>
            <Button variant="outline" size="icon" aria-label="Muat ulang kegiatan" title="Muat ulang" onClick={() => activitiesQuery.refetch()}>
              <RefreshCw className={activitiesQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
            </Button>
            <Can perm="religious.activity.manage">
              <Button onClick={() => { setForm(blankActivity()); setFormOpen(true); }}><Plus className="h-4 w-4" /> Buat kegiatan</Button>
            </Can>
          </>
        }
      />

      <Card>
        <CardBody className="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2">
          <FormField label="Jenis kegiatan">
            <Select value={typeFilter} onChange={(event) => setTypeFilter(event.target.value)}>
              <option value="">Semua jenis</option>
              {types.map((type) => <option key={type} value={type}>{type.replace(/_/g, ' ')}</option>)}
            </Select>
          </FormField>
          <FormField label="Status">
            <Select value={statusFilter} onChange={(event) => setStatusFilter(event.target.value)}>
              <option value="">Semua status</option>
              {['DRAFT', 'PUBLISHED', 'COMPLETED', 'CANCELLED'].map((status) => <option key={status} value={status}>{displayStatus(status)}</option>)}
            </Select>
          </FormField>
        </CardBody>
      </Card>

      {activitiesQuery.isLoading ? (
        <LoadingCard label="Memuat kegiatan keagamaan..." />
      ) : activitiesQuery.isError ? (
        <ErrorCard message={getErrorMessage(activitiesQuery.error)} onRetry={() => activitiesQuery.refetch()} />
      ) : activities.length === 0 ? (
        <Card><EmptyState title="Belum ada kegiatan" description="Buat kegiatan baru atau ubah filter pencarian." /></Card>
      ) : (
        <div className="space-y-3">
          {activities.map((activity) => (
            <Card key={activity.id}>
              <div className="flex flex-col gap-4 p-4 lg:flex-row lg:items-center lg:justify-between">
                <button type="button" onClick={() => setSelectedId(selectedId === activity.id ? null : activity.id)} className="min-w-0 flex-1 text-left">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="text-base font-semibold text-slate-900">{activity.title}</span>
                    <Badge tone={statusTone(activity.status)}>{displayStatus(activity.status)}</Badge>
                    <Badge tone="info">{activity.activity_type.replace(/_/g, ' ')}</Badge>
                  </div>
                  <p className="mt-1 text-xs text-slate-500">
                    {activity.code ?? `ID ${activity.id}`} · {activity.starts_on ?? 'Tanggal belum ditentukan'}{activity.ends_on && activity.ends_on !== activity.starts_on ? ` – ${activity.ends_on}` : ''} · Kapasitas {activity.capacity ?? 'tidak dibatasi'}
                  </p>
                  {activity.target_population && <p className="mt-1 text-xs text-slate-500">Target: {activity.target_population.replace(/_/g, ' ')} · Pendaftaran: {activity.registration_mode ?? '—'}</p>}
                </button>
                <div className="flex flex-wrap items-center gap-2">
                  <Button size="sm" variant="outline" onClick={() => setSelectedId(selectedId === activity.id ? null : activity.id)}>
                    {selectedId === activity.id ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
                    Kelola
                  </Button>
                  {activity.status === 'DRAFT' && <Can perm="religious.activity.manage"><Button size="sm" onClick={() => publishMutation.mutate(activity.id)} loading={publishMutation.isPending}><Check className="h-4 w-4" /> Publikasikan</Button></Can>}
                  {activity.status === 'PUBLISHED' && <Can perm="religious.activity.manage"><Button size="sm" variant="outline" onClick={() => completeMutation.mutate(activity.id)} loading={completeMutation.isPending}>Tandai selesai</Button></Can>}
                </div>
              </div>
              {selectedId === activity.id && (
                <div className="border-t border-slate-100 bg-slate-50/50 p-4">
                  <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
                    <Card className="shadow-none">
                      <CardHeader className="flex flex-row items-center justify-between gap-2">
                        <div><CardTitle className="text-sm">Sesi kegiatan</CardTitle><p className="mt-1 text-xs text-slate-500">Jadwal pertemuan yang terhubung ke kegiatan ini.</p></div>
                        <Can perm="religious.activity.manage"><Button size="sm" variant="outline" onClick={() => { setSessionForm(blankSession()); setSessionFormOpen(true); }}><Plus className="h-4 w-4" /> Tambah sesi</Button></Can>
                      </CardHeader>
                      <CardBody className="p-0">
                        {sessionsQuery.isLoading ? <div className="p-4 text-sm text-slate-500">Memuat sesi...</div> : sessionsQuery.isError ? <div className="p-4 text-sm text-rose-700">{getErrorMessage(sessionsQuery.error)}</div> : (sessionsQuery.data ?? []).length === 0 ? <p className="p-4 text-sm text-slate-500">Belum ada sesi.</p> : (
                          <div className="divide-y divide-slate-100">
                            {sessionsQuery.data?.map((session) => (
                              <div key={session.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                <div><p className="text-sm font-medium text-slate-800">{session.title ?? session.topic ?? `Sesi ${session.session_number ?? ''}`}</p><p className="mt-1 text-xs text-slate-500">{session.session_date} · {session.start_time ?? '—'}{session.end_time ? `–${session.end_time}` : ''} · {session.location ?? activity.location ?? 'Lokasi belum ditentukan'}</p></div>
                                <Badge tone={statusTone(session.status)}>{displayStatus(session.status)}</Badge>
                              </div>
                            ))}
                          </div>
                        )}
                      </CardBody>
                    </Card>

                    <Card className="shadow-none">
                      <CardHeader>
                        <CardTitle className="text-sm">Peserta</CardTitle>
                        <p className="mt-1 text-xs text-slate-500">Ringkasan peserta aktif. Nama dan identitas peserta tetap mengikuti cakupan akses.</p>
                      </CardHeader>
                      <CardBody className="space-y-4">
                        {participantsQuery.isLoading ? <p className="text-sm text-slate-500">Memuat ringkasan peserta...</p> : participantsQuery.isError ? <p className="text-sm text-rose-700">{getErrorMessage(participantsQuery.error)}</p> : (
                          <div className="flex items-center justify-between rounded-lg bg-emerald-50 p-3">
                            <span className="flex items-center gap-2 text-sm text-emerald-900"><Users className="h-4 w-4" /> Total peserta</span>
                            <span className="text-xl font-semibold text-emerald-900">{participantsQuery.data?.total ?? 0}</span>
                          </div>
                        )}
                        <Can perm="religious.activity.manage">
                          <form onSubmit={submitParticipant} className="space-y-3 border-t border-slate-100 pt-4">
                            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Tambah peserta langsung</p>
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                              <FormField label="Jenis peserta">
                                <Select value={participantType} onChange={(event) => setParticipantType(event.target.value as typeof participantType)}>
                                  <option value="STUDENT">Siswa</option><option value="EMPLOYEE">Pegawai</option><option value="PUBLIC">Umum</option>
                                </Select>
                              </FormField>
                              {participantType === 'PUBLIC' ? <FormField label="Nama peserta"><Input value={participantName} onChange={(event) => setParticipantName(event.target.value)} required /></FormField> : <FormField label={`ID ${participantType === 'STUDENT' ? 'siswa' : 'pegawai'}`}><Input type="number" min="1" value={participantId} onChange={(event) => setParticipantId(event.target.value)} required /></FormField>}
                            </div>
                            <Button size="sm" variant="outline" type="submit" loading={addParticipantMutation.isPending}><Plus className="h-4 w-4" /> Tambahkan peserta</Button>
                          </form>
                        </Can>
                      </CardBody>
                    </Card>
                  </div>
                </div>
              )}
            </Card>
          ))}
        </div>
      )}

      <Modal open={formOpen} onClose={() => setFormOpen(false)} title="Buat kegiatan keagamaan" size="lg" footer={<><Button variant="outline" onClick={() => setFormOpen(false)}>Batal</Button><Button type="submit" form="religious-activity-form" loading={createMutation.isPending}>Simpan draf</Button></>}>
        <form id="religious-activity-form" onSubmit={submitActivity} className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormField label="Judul kegiatan" className="sm:col-span-2"><Input value={form.title} onChange={(event) => setForm({ ...form, title: event.target.value })} maxLength={200} required placeholder="Contoh: Kajian adab menuntut ilmu" /></FormField>
          <FormField label="Jenis kegiatan">
            <Select value={form.activity_type} onChange={(event) => setForm({ ...form, activity_type: event.target.value })} required>
              {types.map((type) => <option key={type} value={type}>{type.replace(/_/g, ' ')}</option>)}
            </Select>
          </FormField>
          <FormField label="Mode pendaftaran">
            <Select value={form.registration_mode} onChange={(event) => setForm({ ...form, registration_mode: event.target.value })}>
              {registrationModes.map((mode) => <option key={mode} value={mode}>{mode.replace(/_/g, ' ')}</option>)}
            </Select>
          </FormField>
          <FormField label="Target peserta">
            <Select value={form.target_population} onChange={(event) => setForm({ ...form, target_population: event.target.value })}>
              {['ALL_STUDENTS', 'GRADE', 'CLASS', 'DORMITORY', 'STUDENT_GROUP', 'TEACHER', 'STAFF', 'PARENT', 'PUBLIC', 'ALL'].map((target) => <option key={target} value={target}>{target.replace(/_/g, ' ')}</option>)}
            </Select>
          </FormField>
          <FormField label="Kapasitas"><Input type="number" min="0" value={form.capacity} onChange={(event) => setForm({ ...form, capacity: event.target.value })} placeholder="Kosongkan jika tanpa batas" /></FormField>
          {can('religious.mosque.view') && (
            <FormField label="Masjid (opsional)">
              <Select value={form.mosque_id} onChange={(event) => setForm({ ...form, mosque_id: event.target.value })}>
                <option value="">Tidak ditentukan</option>
                {(mosqueQuery.data ?? []).map((mosque) => <option key={mosque.id} value={mosque.id}>{mosque.name}</option>)}
              </Select>
            </FormField>
          )}
          <FormField label="Tanggal mulai"><Input type="date" value={form.starts_on} onChange={(event) => setForm({ ...form, starts_on: event.target.value })} /></FormField>
          <FormField label="Tanggal selesai"><Input type="date" value={form.ends_on} onChange={(event) => setForm({ ...form, ends_on: event.target.value })} /></FormField>
          <FormField label="Deskripsi" className="sm:col-span-2"><Textarea value={form.description} onChange={(event) => setForm({ ...form, description: event.target.value })} placeholder="Tujuan atau ringkasan kegiatan" /></FormField>
        </form>
      </Modal>

      <Modal open={sessionFormOpen} onClose={() => setSessionFormOpen(false)} title={`Tambah sesi · ${selected?.title ?? 'Kegiatan'}`} footer={<><Button variant="outline" onClick={() => setSessionFormOpen(false)}>Batal</Button><Button type="submit" form="religious-session-form" loading={createSessionMutation.isPending}>Simpan sesi</Button></>}>
        <form id="religious-session-form" onSubmit={submitSession} className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormField label="Tanggal sesi"><Input type="date" value={sessionForm.session_date} onChange={(event) => setSessionForm({ ...sessionForm, session_date: event.target.value })} required /></FormField>
          <FormField label="Topik"><Input value={sessionForm.title} onChange={(event) => setSessionForm({ ...sessionForm, title: event.target.value })} placeholder="Judul sesi" /></FormField>
          <FormField label="Waktu mulai"><Input type="time" value={sessionForm.start_time} onChange={(event) => setSessionForm({ ...sessionForm, start_time: event.target.value })} /></FormField>
          <FormField label="Waktu selesai"><Input type="time" value={sessionForm.end_time} onChange={(event) => setSessionForm({ ...sessionForm, end_time: event.target.value })} /></FormField>
          <FormField label="Lokasi"><Input value={sessionForm.location} onChange={(event) => setSessionForm({ ...sessionForm, location: event.target.value })} placeholder="Ruang atau masjid" /></FormField>
          <FormField label="Materi"><Input value={sessionForm.topic} onChange={(event) => setSessionForm({ ...sessionForm, topic: event.target.value })} /></FormField>
        </form>
      </Modal>
    </div>
  );
}
