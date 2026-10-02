import React, { useState } from 'react';
import { Text, View } from 'react-native';
import { Alert } from '@/lib/dialog';
import dayjs from 'dayjs';
import { Hero } from '@/components/Hero';
import { Chips, Kv, Sheet, TextArea } from '@/components/forms';
import { Button, Card, EmptyState, ErrorNote, Loading, Pill, Row, Screen, T } from '@/components/ui';
import {
  TYPES, useAssignmentAction, useCreateAssignment, useGrade, useMyAssignments, useSubmissions, type AssignmentRow, type SubmissionRow,
} from '@/api/assignments';
import { useEmployeeId, useMyClasses } from '@/api/teaching';
import { errorMessage } from '@/api/client';
import { colors } from '@/theme';
import { dateShort } from '@/lib/format';

const STATUS = { draft: { label: 'Draf', color: colors.muted }, published: { label: 'Terbit', color: colors.success }, closed: { label: 'Ditutup', color: colors.info } } as const;

export default function TugasGuru() {
  const q = useMyAssignments();
  const [open, setOpen] = useState<AssignmentRow | 'new' | null>(null);

  return (
    <Screen refreshing={q.isRefetching} onRefresh={() => q.refetch()} header={<Hero back subtitle="Tugas & penilaian pengumpulan" />}>
      <Button label="Buat tugas baru" icon="add-circle-outline" onPress={() => setOpen('new')} />
      {q.isLoading ? <Loading /> : q.error ? <ErrorNote message={errorMessage(q.error)} onRetry={() => q.refetch()} /> : null}
      {q.data?.length === 0 ? <EmptyState icon="document-text-outline" title="Belum ada tugas" /> : null}
      {q.data?.map((a) => (
        <Card key={a.id} onPress={() => setOpen(a)}>
          <Row left={<Text style={T.title} numberOfLines={2}>{a.title}</Text>} sub={<Text style={T.small}>{a.subject?.name} · {a.school_class?.name} · tenggat {dateShort(a.due_date)}</Text>} right={<Pill label={STATUS[a.status].label} color={STATUS[a.status].color} />} />
          <Text style={T.small}>{a.submission_count} terkumpul · {a.graded_count} dinilai{a.is_overdue && a.status === 'published' ? ' · lewat tenggat' : ''}</Text>
        </Card>
      ))}
      {open === 'new' ? <NewAssignment onClose={() => setOpen(null)} /> : null}
      {open && open !== 'new' ? <Detail a={open} onClose={() => setOpen(null)} /> : null}
    </Screen>
  );
}

function NewAssignment({ onClose }: { onClose: () => void }) {
  const classes = useMyClasses();
  const employeeId = useEmployeeId();
  const create = useCreateAssignment();
  const [classId, setClassId] = useState<number | null>(null);
  const [subjectId, setSubjectId] = useState<number | null>(null);
  const [type, setType] = useState<string>('tugas');
  const [title, setTitle] = useState('');
  const [desc, setDesc] = useState('');
  const [max, setMax] = useState('100');
  const [weight, setWeight] = useState('0');
  const [due, setDue] = useState(dayjs().add(7, 'day').format('YYYY-MM-DD'));

  const subjects = classes.data?.classes.find((c) => c.class_id === classId)?.subjects ?? [];
  const semesterId = classes.data?.semester?.id;

  const submit = (publish: boolean) => {
    if (!classId || !subjectId || !semesterId || !employeeId || !title.trim() || !desc.trim()) return Alert.alert('Lengkapi data', 'Kelas, mapel, judul, dan deskripsi wajib diisi.');
    if (!/^\d{4}-\d{2}-\d{2}$/.test(due) || !dayjs(due).isValid()) return Alert.alert('Tanggal tidak valid', 'Gunakan format TTTT-BB-HH.');
    create.mutate(
      { publish, form: { class_id: classId, subject_id: subjectId, teacher_id: employeeId, semester_id: semesterId, title: title.trim(), description: desc.trim(), type, max_score: parseFloat(max) || 100, weight: parseFloat(weight) || 0, assigned_date: dayjs().format('YYYY-MM-DD'), due_date: due, allow_late_submission: false } },
      { onSuccess: onClose, onError: (e) => Alert.alert('Gagal', errorMessage(e)) },
    );
  };

  return (
    <Sheet visible title="Tugas baru" onClose={onClose} footer={<><Button label="Simpan & terbitkan" icon="send-outline" onPress={() => submit(true)} loading={create.isPending} /><Button label="Simpan draf" variant="ghost" onPress={() => submit(false)} disabled={create.isPending} /></>}>
      <Chips label="Kelas *" value={classId} options={(classes.data?.classes ?? []).map((c) => ({ key: c.class_id, label: c.class_name }))} onChange={(k) => { setClassId(k); setSubjectId(null); }} />
      {classId ? <Chips label="Mata pelajaran *" value={subjectId} options={subjects.map((s) => ({ key: s.subject_id, label: s.subject_name }))} onChange={setSubjectId} /> : null}
      <Chips label="Jenis" value={type} options={TYPES.map((t) => ({ key: t, label: t }))} onChange={setType} />
      <TextArea label="Judul *" value={title} onChangeText={setTitle} multiline={false} />
      <TextArea label="Deskripsi *" value={desc} onChangeText={setDesc} />
      <View style={{ flexDirection: 'row', gap: 12 }}>
        <View style={{ flex: 1 }}><TextArea label="Nilai maks" value={max} onChangeText={setMax} keyboardType="decimal-pad" multiline={false} /></View>
        <View style={{ flex: 1 }}><TextArea label="Bobot (%)" value={weight} onChangeText={setWeight} keyboardType="decimal-pad" multiline={false} /></View>
      </View>
      <TextArea label="Tenggat (TTTT-BB-HH)" value={due} onChangeText={setDue} multiline={false} />
    </Sheet>
  );
}

function Detail({ a, onClose }: { a: AssignmentRow; onClose: () => void }) {
  const q = useSubmissions(a.id);
  const act = useAssignmentAction();
  const [grading, setGrading] = useState<SubmissionRow | null>(null);

  const run = (action: 'publish' | 'close') => act.mutate({ id: a.id, action }, { onSuccess: onClose, onError: (e) => Alert.alert('Gagal', errorMessage(e)) });

  return (
    <Sheet
      visible
      title={a.title}
      onClose={onClose}
      footer={a.status === 'draft' ? <Button label="Terbitkan" icon="send-outline" onPress={() => run('publish')} loading={act.isPending} /> : a.status === 'published' ? <Button label="Tutup tugas" variant="ghost" onPress={() => run('close')} loading={act.isPending} /> : undefined}
    >
      <Card>
        <Kv k="Kelas" v={`${a.subject?.name} · ${a.school_class?.name}`} />
        <Kv k="Jenis" v={a.type} />
        <Kv k="Nilai maks" v={a.max_score} />
        <Kv k="Tenggat" v={dateShort(a.due_date)} />
        <Text style={T.body}>{a.description}</Text>
      </Card>
      {q.isLoading ? <Loading /> : q.error ? <ErrorNote message={errorMessage(q.error)} onRetry={() => q.refetch()} /> : null}
      {q.data ? <Text style={T.title}>Pengumpulan · {q.data.meta.submitted}/{q.data.meta.total_students} terkumpul · {q.data.meta.graded} dinilai</Text> : null}
      {q.data?.data.map((s) => (
        <Card key={s.student_id} onPress={s.has_submitted ? () => setGrading(s) : undefined}>
          <Row
            left={<Text style={T.title} numberOfLines={1}>{s.full_name}</Text>}
            sub={<Text style={T.small}>{s.has_submitted ? `Dikumpulkan ${s.submitted_at ? dayjs(s.submitted_at).format('D MMM HH:mm') : ''}` : 'Belum mengumpulkan'}</Text>}
            right={s.status === 'graded' ? <Text style={{ fontSize: 20, fontWeight: '800', color: colors.primary }}>{s.score}</Text> : <Pill label={s.has_submitted ? (s.status === 'late' ? 'Terlambat' : 'Perlu dinilai') : 'Belum'} color={s.has_submitted ? colors.warning : colors.muted} />}
          />
        </Card>
      ))}
      {grading ? <GradeSheet a={a} s={grading} onClose={() => setGrading(null)} /> : null}
    </Sheet>
  );
}

function GradeSheet({ a, s, onClose }: { a: AssignmentRow; s: SubmissionRow; onClose: () => void }) {
  const grade = useGrade();
  const [score, setScore] = useState(s.score === null ? '' : String(s.score));
  const [feedback, setFeedback] = useState(s.feedback ?? '');

  const submit = () => {
    const n = parseFloat(score.replace(',', '.'));
    if (Number.isNaN(n) || n < 0 || n > a.max_score) return Alert.alert('Nilai tidak valid', `Nilai harus 0–${a.max_score}.`);
    if (!s.submission_id) return;
    grade.mutate({ assignmentId: a.id, submissionId: s.submission_id, score: n, feedback: feedback.trim() || undefined }, { onSuccess: onClose, onError: (e) => Alert.alert('Gagal', errorMessage(e)) });
  };

  return (
    <Sheet visible title={s.full_name} onClose={onClose} footer={<Button label="Simpan nilai" icon="checkmark-circle-outline" onPress={submit} loading={grade.isPending} />}>
      <TextArea label={`Nilai (maks ${a.max_score})`} value={score} onChangeText={setScore} keyboardType="decimal-pad" multiline={false} />
      <TextArea label="Umpan balik" value={feedback} onChangeText={setFeedback} />
    </Sheet>
  );
}
