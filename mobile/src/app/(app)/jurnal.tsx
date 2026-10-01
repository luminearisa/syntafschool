import React, { useEffect, useMemo, useState } from 'react';
import { Text, View } from 'react-native';
import { Alert } from '@/lib/dialog';
import { Ionicons } from '@expo/vector-icons';
import dayjs from 'dayjs';
import { Hero } from '@/components/Hero';
import { Chips, Kv, Sheet, TextArea } from '@/components/forms';
import { Button, Card, EmptyState, ErrorNote, Loading, Pill, Row, Screen, Segmented, T } from '@/components/ui';
import {
  useJournalToday, useLessonPlanAction, useLessonPlans, useMyClasses, useSaveJournal, useSaveLessonPlan,
  type JournalToday, type LessonPlan,
} from '@/api/teaching';
import { errorMessage } from '@/api/client';
import { useAuth } from '@/store/auth';
import { colors } from '@/theme';
import { dateLong } from '@/lib/format';

type Tab = 'journal' | 'rpp';
type Item = JournalToday['items'][number];

export default function Jurnal() {
  const [tab, setTab] = useState<Tab>('journal');
  return (
    <Screen header={<Hero back subtitle="Jurnal mengajar & RPP" />}>
      <Segmented<Tab> value={tab} onChange={setTab} options={[{ key: 'journal', label: 'Jurnal mengajar' }, { key: 'rpp', label: 'RPP' }]} />
      {tab === 'journal' ? <JournalTab /> : <RppTab />}
    </Screen>
  );
}

// ================================================================= JURNAL
function JournalTab() {
  const [date, setDate] = useState(dayjs().format('YYYY-MM-DD'));
  const q = useJournalToday(date);
  const [open, setOpen] = useState<Item | null>(null);

  return (
    <>
      <Row
        left={<Text style={T.title}>{dateLong(date)}</Text>}
        right={
          <View style={{ flexDirection: 'row', gap: 14 }}>
            <Ionicons name="chevron-back-circle" size={30} color={colors.primary} onPress={() => setDate(dayjs(date).subtract(1, 'day').format('YYYY-MM-DD'))} />
            <Ionicons name="chevron-forward-circle" size={30} color={colors.primary} onPress={() => setDate(dayjs(date).add(1, 'day').format('YYYY-MM-DD'))} />
          </View>
        }
      />
      {q.isLoading ? <Loading /> : q.error ? <ErrorNote message={errorMessage(q.error)} onRetry={() => q.refetch()} /> : null}
      {q.data ? (
        <>
          <Card style={{ flexDirection: 'row', justifyContent: 'space-around' }}>
            <Stat label="Jadwal" value={q.data.total} />
            <Stat label="Sudah dijurnal" value={q.data.journaled} color={colors.success} />
            <Stat label="Belum" value={q.data.pending} color={q.data.pending ? colors.warning : colors.muted} />
          </Card>
          {q.data.items.length === 0 ? <EmptyState icon="cafe-outline" title="Tidak ada jadwal mengajar" /> : null}
          {q.data.items.map((it) => {
            const j = it.journal;
            const done = j?.is_completed;
            return (
              <Card key={it.schedule_id} onPress={() => setOpen(it)}>
                <Row
                  left={<Text style={T.title}>{it.subject?.name} · {it.school_class?.name}</Text>}
                  sub={<Text style={T.small}>{it.start_time}–{it.end_time}{j ? ` · ${j.topic}` : ''}</Text>}
                  right={<Pill label={done ? 'Selesai' : j ? 'Berjalan' : 'Belum diisi'} color={done ? colors.success : j ? colors.info : colors.warning} />}
                />
              </Card>
            );
          })}
        </>
      ) : null}
      <JournalSheet date={date} semesterId={q.data?.semester_id} item={open} onClose={() => setOpen(null)} />
    </>
  );
}

function Stat({ label, value, color = colors.ink }: { label: string; value: number; color?: string }) {
  return (
    <View style={{ alignItems: 'center' }}>
      <Text style={{ fontSize: 22, fontWeight: '800', color }}>{value}</Text>
      <Text style={T.small}>{label}</Text>
    </View>
  );
}

function JournalSheet({ date, semesterId, item, onClose }: { date: string; semesterId?: number; item: Item | null; onClose: () => void }) {
  const save = useSaveJournal();
  const j = item?.journal ?? null;
  const [topic, setTopic] = useState('');
  const [material, setMaterial] = useState('');
  const [activities, setActivities] = useState('');
  const [notes, setNotes] = useState('');
  const [present, setPresent] = useState('');
  const [jp, setJp] = useState('2');

  useEffect(() => {
    setTopic(j?.topic ?? ''); setMaterial(j?.material ?? ''); setActivities(j?.activities ?? ''); setNotes(j?.notes ?? '');
    setPresent(j ? String(j.student_present_count || '') : ''); setJp(j?.realized_jp ? String(j.realized_jp) : '2');
  }, [item?.schedule_id, j?.id]);

  if (!item || !semesterId) return <Sheet visible={false} title="" onClose={onClose}><View /></Sheet>;
  const locked = !!j?.is_completed;

  const submit = (finish: boolean) => {
    if (!topic.trim()) return Alert.alert('Topik wajib', 'Isi topik pembelajaran.');
    const realized = parseInt(jp, 10);
    if (finish && !(realized >= 1)) return Alert.alert('JP wajib', 'Isi JP terealisasi (minimal 1).');
    save.mutate(
      {
        existingId: j?.id,
        form: {
          schedule_id: item.schedule_id, class_id: item.class_id, subject_id: item.subject_id, semester_id: semesterId, time_slot_id: item.time_slot_id, date,
          topic: topic.trim(), material: material.trim() || undefined, activities: activities.trim() || undefined, notes: notes.trim() || undefined,
          student_present_count: present ? parseInt(present, 10) : undefined,
        },
        complete: finish ? { realized_jp: realized } : undefined,
      },
      { onSuccess: onClose, onError: (e) => Alert.alert('Gagal menyimpan', errorMessage(e)) },
    );
  };

  return (
    <Sheet
      visible
      title={`${item.subject?.name} · ${item.school_class?.name}`}
      onClose={onClose}
      footer={locked ? undefined : (
        <>
          <Button label="Simpan & tandai selesai" icon="checkmark-done-outline" onPress={() => submit(true)} loading={save.isPending} />
          <Button label="Simpan saja" variant="ghost" onPress={() => submit(false)} disabled={save.isPending} />
        </>
      )}
    >
      <Card>
        <Kv k="Tanggal" v={dateLong(date)} />
        <Kv k="Jam" v={`${item.start_time}–${item.end_time}`} />
        {locked ? <Pill label="Jurnal sudah selesai" color={colors.success} /> : null}
      </Card>
      <TextArea label="Topik *" value={topic} onChangeText={setTopic} editable={!locked} multiline={false} />
      <TextArea label="Materi" value={material} onChangeText={setMaterial} editable={!locked} />
      <TextArea label="Kegiatan" value={activities} onChangeText={setActivities} editable={!locked} />
      <TextArea label="Catatan" value={notes} onChangeText={setNotes} editable={!locked} />
      <View style={{ flexDirection: 'row', gap: 12 }}>
        <View style={{ flex: 1 }}><TextArea label="Siswa hadir" value={present} onChangeText={setPresent} keyboardType="number-pad" multiline={false} editable={!locked} /></View>
        <View style={{ flex: 1 }}><TextArea label="JP terealisasi" value={jp} onChangeText={setJp} keyboardType="number-pad" multiline={false} editable={!locked} hint="Tidak boleh melebihi alokasi JP." /></View>
      </View>
    </Sheet>
  );
}

// ==================================================================== RPP
const RPP_STATUS: Record<LessonPlan['status'], { label: string; color: string }> = {
  draft: { label: 'Draf', color: colors.muted },
  submitted: { label: 'Diajukan', color: colors.info },
  approved: { label: 'Disetujui', color: colors.success },
  rejected: { label: 'Ditolak', color: colors.danger },
};

function RppTab() {
  const canApprove = useAuth((s) => s.can('academic.approve'));
  const [scope, setScope] = useState<'mine' | 'review'>('mine');
  const q = useLessonPlans(scope);
  const [open, setOpen] = useState<LessonPlan | 'new' | null>(null);

  return (
    <>
      {canApprove ? <Segmented value={scope} onChange={setScope} options={[{ key: 'mine', label: 'RPP saya' }, { key: 'review', label: 'Perlu ditinjau' }]} /> : null}
      {scope === 'mine' ? <Button label="Buat RPP baru" icon="add-circle-outline" onPress={() => setOpen('new')} /> : null}
      {q.isLoading ? <Loading /> : q.error ? <ErrorNote message={errorMessage(q.error)} onRetry={() => q.refetch()} /> : null}
      {q.data?.length === 0 ? <EmptyState icon="document-text-outline" title={scope === 'mine' ? 'Belum ada RPP' : 'Tidak ada RPP menunggu'} /> : null}
      {q.data?.map((p) => {
        const st = RPP_STATUS[p.status];
        return (
          <Card key={p.id} onPress={() => setOpen(p)}>
            <Row left={<Text style={T.title} numberOfLines={2}>{p.title}</Text>} sub={<Text style={T.small}>{p.code} · {p.subject?.name}{p.school_class ? ` · ${p.school_class.name}` : ''}{scope === 'review' ? ` · ${p.teacher?.full_name}` : ''}</Text>} right={<Pill label={st.label} color={st.color} />} />
            {p.status === 'rejected' && p.rejection_reason ? <Text style={{ color: colors.danger, fontSize: 12.5 }}>Alasan: {p.rejection_reason}</Text> : null}
          </Card>
        );
      })}
      <RppSheet plan={open} reviewing={scope === 'review'} onClose={() => setOpen(null)} />
    </>
  );
}

const lines = (s: string) => s.split('\n').map((x) => x.trim()).filter(Boolean);

function RppSheet({ plan, reviewing, onClose }: { plan: LessonPlan | 'new' | null; reviewing: boolean; onClose: () => void }) {
  const classes = useMyClasses();
  const save = useSaveLessonPlan();
  const act = useLessonPlanAction();
  const existing = plan && plan !== 'new' ? plan : null;

  const subjects = useMemo(() => {
    const m = new Map<number, string>();
    classes.data?.classes.forEach((c) => c.subjects.forEach((s) => m.set(s.subject_id, s.subject_name)));
    return [...m.entries()].map(([key, label]) => ({ key, label }));
  }, [classes.data]);

  const [subjectId, setSubjectId] = useState<number | null>(null);
  const [code, setCode] = useState('');
  const [title, setTitle] = useState('');
  const [jp, setJp] = useState('2');
  const [objectives, setObjectives] = useState('');
  const [activities, setActivities] = useState('');
  const [assessment, setAssessment] = useState('');
  const [reason, setReason] = useState('');

  useEffect(() => {
    setSubjectId(existing?.subject_id ?? null); setCode(existing?.code ?? ''); setTitle(existing?.title ?? ''); setJp(existing ? String(existing.duration_jp) : '2');
    setObjectives(existing?.learning_objectives.join('\n') ?? ''); setActivities(existing?.activities.join('\n') ?? ''); setAssessment(existing?.assessment_plan ?? ''); setReason('');
  }, [existing?.id, plan === 'new']);

  if (!plan) return <Sheet visible={false} title="" onClose={onClose}><View /></Sheet>;

  const editable = !existing || existing.is_editable;
  const semesterId = classes.data?.semester?.id ?? existing?.semester_id;
  const fail = (e: unknown) => Alert.alert('Gagal', errorMessage(e));

  const persist = (submit: boolean) => {
    if (!subjectId || !semesterId || !code.trim() || !title.trim()) return Alert.alert('Lengkapi data', 'Mapel, kode, dan judul RPP wajib diisi.');
    save.mutate(
      { id: existing?.id, submit, form: { subject_id: subjectId, semester_id: semesterId, code: code.trim(), title: title.trim(), duration_jp: parseInt(jp, 10) || 1, learning_objectives: lines(objectives), activities: lines(activities), assessment_plan: assessment.trim() || undefined } },
      { onSuccess: onClose, onError: fail },
    );
  };

  const review = (action: 'approve' | 'reject') => {
    if (!existing) return;
    if (action === 'reject' && reason.trim().length < 3) return Alert.alert('Alasan wajib', 'Isi alasan penolakan.');
    act.mutate({ id: existing.id, action, reason: reason.trim() }, { onSuccess: onClose, onError: fail });
  };

  const footer = reviewing && existing?.status === 'submitted' ? (
    <>
      <Button label="Setujui" icon="checkmark-circle-outline" onPress={() => review('approve')} loading={act.isPending} />
      <Button label="Tolak" variant="ghost" onPress={() => review('reject')} disabled={act.isPending} />
    </>
  ) : editable && !reviewing ? (
    <>
      <Button label="Simpan & ajukan" icon="send-outline" onPress={() => persist(true)} loading={save.isPending} />
      <Button label="Simpan draf" variant="ghost" onPress={() => persist(false)} disabled={save.isPending} />
    </>
  ) : undefined;

  return (
    <Sheet visible title={existing ? existing.code : 'RPP baru'} onClose={onClose} footer={footer}>
      {existing?.status === 'rejected' && existing.rejection_reason ? <Card style={{ borderColor: colors.danger, borderWidth: 1 }}><Text style={{ color: colors.danger, fontWeight: '700' }}>Ditolak: {existing.rejection_reason}</Text></Card> : null}
      <Chips label="Mata pelajaran *" value={subjectId} options={subjects} onChange={(k) => editable && setSubjectId(k)} />
      <TextArea label="Kode RPP *" value={code} onChangeText={setCode} multiline={false} editable={editable} />
      <TextArea label="Judul *" value={title} onChangeText={setTitle} multiline={false} editable={editable} />
      <TextArea label="Durasi (JP) *" value={jp} onChangeText={setJp} keyboardType="number-pad" multiline={false} editable={editable} />
      <TextArea label="Tujuan pembelajaran" hint="Satu tujuan per baris." value={objectives} onChangeText={setObjectives} editable={editable} />
      <TextArea label="Kegiatan pembelajaran" hint="Satu kegiatan per baris." value={activities} onChangeText={setActivities} editable={editable} />
      <TextArea label="Rencana penilaian" value={assessment} onChangeText={setAssessment} editable={editable} />
      {reviewing && existing?.status === 'submitted' ? <TextArea label="Alasan (wajib bila menolak)" value={reason} onChangeText={setReason} /> : null}
    </Sheet>
  );
}
